<?php

namespace System\Modules\Utils\Models\Migrations\Drivers;

use PDO;
use System\Modules\Utils\Models\Migrations\MigrationFile;

/**
 * SQLite: transactional DDL, but no server-side lock of any kind.
 *
 * Behaves like Postgres for everything the engine cares about - a failed migration inside
 * a transaction rolls back cleanly, DDL included. The one thing it cannot do is
 * pg_advisory_lock / GET_LOCK, because there is no server; the lock is a flock() on a file
 * beside the database.
 *
 * Note for migration authors: SQLite's ALTER TABLE cannot drop a constraint or change a
 * column type, so the official remedy is the 12-step table rebuild - and that needs
 * PRAGMA foreign_keys = OFF, which is silently ignored inside a transaction. Such a
 * migration must therefore carry the `-- migrations:no-transaction` directive, or it will
 * appear to work while leaving dangling foreign key rows.
 */
class SqliteDriver implements DriverInterface
{
    /**
     * Path to the database file, or null for an in-memory database.
     *
     * @var ?string
     * @access private
     */
    private ?string $databasePath;

    /**
     * Open lock file handle, held for as long as the lock is.
     *
     * @var resource|null
     * @access private
     */
    private $lockHandle = null;

    /**
     * @access public
     * @param ?string $databasePath Null disables locking, which is correct for :memory:
     */
    public function __construct(?string $databasePath)
    {
        $this->databasePath = $databasePath;
    }

    /**
     * Pull the file path out of a PDO DSN.
     *
     * "sqlite::memory:" and the empty "sqlite:" (an anonymous temp database) both have no
     * path and no possibility of a second process, so both come back null.
     *
     * @access public
     * @static
     * @param  string $dsn
     * @return ?string
     */
    public static function pathFromDsn(string $dsn): ?string
    {
        $path = substr($dsn, strlen('sqlite:'));
        if ($path === '' || $path === ':memory:') {
            return null;
        }

        return $path;
    }

    public function name(): string
    {
        return 'sqlite';
    }

    public function supportsTransactionalDdl(): bool
    {
        return true;
    }

    /**
     * SQLite autocommits each statement separately outside an explicit transaction, so the
     * directive holds however many statements the file contains - which is exactly what a
     * table rebuild needs.
     */
    public function noTransactionFileError(MigrationFile $file): ?string
    {
        return null;
    }

    public function createTableSql(string $table): string
    {
        return "
            CREATE TABLE IF NOT EXISTS {$table} (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT NOT NULL UNIQUE,
                checksum    TEXT NOT NULL,
                applied_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                duration_ms INTEGER,
                applied_by  TEXT NOT NULL
            )
        ";
    }

    /**
     * pragma_table_info() is the table-valued form of PRAGMA table_info, so the table name
     * can be bound instead of interpolated. Returns nothing for a table that does not
     * exist, which is what the caller wants.
     */
    public function columns(PDO $pdo, string $table): array
    {
        $statement = $pdo->prepare('SELECT name FROM pragma_table_info(?)');
        $statement->execute([$table]);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '"' . $identifier . '"';
    }

    /**
     * An exclusive flock() on "<database>.migrate.lock".
     *
     * The lock is on a sidecar rather than on the database file itself, because SQLite
     * takes its own locks on that one and a stray flock() would be a good way to deadlock
     * against the driver.
     */
    public function lock(PDO $pdo): void
    {
        if ($this->databasePath === null) {
            return;
        }

        $handle = @fopen($this->databasePath . '.migrate.lock', 'c');
        if ($handle === false) {
            throw new \RuntimeException(
                "Cannot open the migration lock file next to {$this->databasePath}; "
                . 'the directory must be writable.'
            );
        }

        if (flock($handle, LOCK_EX) === false) {
            fclose($handle);

            throw new \RuntimeException('Could not acquire the migration lock file.');
        }

        $this->lockHandle = $handle;
    }

    public function unlock(PDO $pdo): void
    {
        if ($this->lockHandle === null) {
            return;
        }

        flock($this->lockHandle, LOCK_UN);
        fclose($this->lockHandle);
        $this->lockHandle = null;
    }
}
