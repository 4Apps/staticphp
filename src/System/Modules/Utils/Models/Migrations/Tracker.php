<?php

namespace System\Modules\Utils\Models\Migrations;

use PDO;
use System\Modules\Utils\Models\Migrations\Drivers\DriverInterface;

/**
 * Reads and writes the migration tracking table.
 *
 * The table bootstraps itself on every invocation rather than being migration zero -
 * otherwise there would be nothing to record the fact that it was created.
 */
class Tracker
{
    /**
     * @var string[]
     * @access public
     */
    public const EXPECTED_COLUMNS = ['id', 'name', 'checksum', 'applied_at', 'duration_ms', 'applied_by'];

    private PDO $pdo;
    private DriverInterface $driver;
    private string $table;
    private string $quotedTable;

    /**
     * @access public
     * @param PDO             $pdo
     * @param DriverInterface $driver
     * @param string          $table
     */
    public function __construct(PDO $pdo, DriverInterface $driver, string $table = 'migrations')
    {
        // Rejected rather than escaped, matching Db::wrapColumn(). The table name comes
        // from configuration, so anything outside this shape is a mistake worth naming
        // instead of quietly quoting into a working query.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new MigrationError("Invalid migrations table name: \"{$table}\"");
        }

        $this->pdo = $pdo;
        $this->driver = $driver;
        $this->table = $table;
        $this->quotedTable = $driver->quoteIdentifier($table);
    }

    /**
     * @access public
     * @return DriverInterface
     */
    public function driver(): DriverInterface
    {
        return $this->driver;
    }

    /**
     * Create the tracking table if needed, and refuse to use one that is not ours.
     *
     * @access public
     * @return void
     */
    public function ensureTable(): void
    {
        $this->pdo->exec($this->driver->createTableSql($this->quotedTable));

        // CREATE TABLE IF NOT EXISTS matches on name alone, and "migrations" is about as
        // generic a table name as exists. Without this check an unrelated pre-existing
        // table is silently adopted and the next query fails with a bare "column name does
        // not exist", with nothing pointing here - exactly when an operator is adopting a
        // database and least able to guess.
        $found = array_map('strtolower', $this->driver->columns($this->pdo, $this->table));
        $missing = array_diff(self::EXPECTED_COLUMNS, $found);

        if ($missing === []) {
            return;
        }

        throw new MigrationError(
            "Table \"{$this->table}\" exists but is not a migration tracking table: missing column(s) "
            . implode(', ', $missing) . '; it has ' . (implode(', ', $found) ?: 'no columns') . '. '
            . 'Point the tracker at a free name via the migrations config, or drop/rename the '
            . 'existing table if it is not in use.'
        );
    }

    /**
     * @access public
     * @return AppliedRow[]
     */
    public function appliedRows(): array
    {
        $sql = "SELECT name, checksum, applied_at, duration_ms FROM {$this->quotedTable} ORDER BY name";

        // FETCH_ASSOC explicitly: the connection may be configured for objects, and this
        // must not depend on the application's fetch_mode_objects setting.
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            fn(array $row) => new AppliedRow(
                name: (string) $row['name'],
                checksum: (string) $row['checksum'],
                appliedAt: (string) $row['applied_at'],
                durationMs: $row['duration_ms'] === null ? null : (int) $row['duration_ms']
            ),
            $rows
        );
    }

    /**
     * Record a completed migration in one statement.
     *
     * Used where DDL is transactional, so this INSERT rides inside the migration's own
     * transaction and the two land together or not at all.
     *
     * @access public
     * @param  string $name
     * @param  string $checksum
     * @param  int    $durationMs
     * @param  string $appliedBy
     * @return void
     */
    public function record(string $name, string $checksum, int $durationMs, string $appliedBy): void
    {
        $sql = "INSERT INTO {$this->quotedTable} (name, checksum, duration_ms, applied_by) VALUES (?, ?, ?, ?)";
        $this->pdo->prepare($sql)->execute([$name, $checksum, $durationMs, $appliedBy]);
    }

    /**
     * Stake a claim on a migration that is about to run, with no duration yet.
     *
     * The MySQL path. Since a failure there cannot be rolled back, the only way to avoid
     * silently re-running a half-applied file is to write the row first: a row with a null
     * duration means "started, never confirmed", which States reports as FAILED and `apply`
     * refuses to run past. A hard kill mid-migration leaves exactly that, which is the
     * point - the alternative is a PENDING file that already half-exists.
     *
     * @access public
     * @param  string $name
     * @param  string $checksum
     * @param  string $appliedBy
     * @return void
     */
    public function claim(string $name, string $checksum, string $appliedBy): void
    {
        $sql = "INSERT INTO {$this->quotedTable} (name, checksum, duration_ms, applied_by) VALUES (?, ?, NULL, ?)";
        $this->pdo->prepare($sql)->execute([$name, $checksum, $appliedBy]);
    }

    /**
     * Turn a claim into a completed migration.
     *
     * @access public
     * @param  string $name
     * @param  int    $durationMs
     * @return void
     */
    public function confirm(string $name, int $durationMs): void
    {
        $sql = "UPDATE {$this->quotedTable} SET duration_ms = ? WHERE name = ?";
        $this->pdo->prepare($sql)->execute([$durationMs, $name]);
    }

    /**
     * Drop a tracking row.
     *
     * Used to withdraw a claim when the migration failed before changing anything, and by
     * `forget` when an operator resolves a FAILED or MISSING state by hand.
     *
     * @access public
     * @param  string $name
     * @return bool True when a row was actually removed
     */
    public function forget(string $name): bool
    {
        $statement = $this->pdo->prepare("DELETE FROM {$this->quotedTable} WHERE name = ?");
        $statement->execute([$name]);

        return $statement->rowCount() === 1;
    }

    /**
     * Re-stamp one migration's checksum after a deliberate edit.
     *
     * @access public
     * @param  string $name
     * @param  string $checksum
     * @return bool False when no such row exists
     */
    public function updateChecksum(string $name, string $checksum): bool
    {
        $statement = $this->pdo->prepare("UPDATE {$this->quotedTable} SET checksum = ? WHERE name = ?");
        $statement->execute([$checksum, $name]);

        return $statement->rowCount() === 1;
    }

    /**
     * Run $body under the exclusive migration lock.
     *
     * The lock is released whatever happens, and a failure to release never replaces the
     * body's own exception as what the caller sees - that would hide the real problem
     * behind a cleanup detail.
     *
     * @access public
     * @param  callable $body
     * @return mixed Whatever $body returned
     */
    public function withLock(callable $body): mixed
    {
        $this->driver->lock($this->pdo);

        try {
            $result = $body();
        } catch (\Throwable $e) {
            try {
                $this->driver->unlock($this->pdo);
            } catch (\Throwable $ignored) {
                // Deliberately swallowed; $e is the one that matters
            }

            throw $e;
        }

        $this->driver->unlock($this->pdo);

        return $result;
    }
}
