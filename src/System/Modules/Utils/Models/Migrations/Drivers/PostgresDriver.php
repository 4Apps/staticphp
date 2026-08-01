<?php

namespace System\Modules\Utils\Models\Migrations\Drivers;

use PDO;
use System\Modules\Utils\Models\Migrations\Discovery;
use System\Modules\Utils\Models\Migrations\MigrationFile;

/**
 * Postgres: transactional DDL, session-level advisory locks.
 *
 * The easy case. A migration and its tracking row commit together or not at all.
 */
class PostgresDriver implements DriverInterface
{
    /**
     * One fixed key for the whole system. Two processes applying migrations at once would
     * interleave DDL and duplicate tracking rows, so `apply` serialises on this.
     *
     * @var int
     * @access public
     */
    public const LOCK_KEY = 4829173465872001;

    public function name(): string
    {
        return 'pgsql';
    }

    public function supportsTransactionalDdl(): bool
    {
        return true;
    }

    /**
     * Postgres wraps a multi-statement simple-Query message in an implicit transaction, so
     * a no-transaction file only genuinely runs outside one when it holds exactly one
     * statement. The whole file goes to PDO::exec() in a single call with no statement
     * splitter, so the only honest answer is to refuse.
     */
    public function noTransactionFileError(MigrationFile $file): ?string
    {
        if (Discovery::countStatements($file->sql) <= 1) {
            return null;
        }

        return "{$file->name} is marked `" . Discovery::NO_TRANSACTION_DIRECTIVE . '` but contains more than'
            . ' one statement. Postgres runs a multi-statement send inside an implicit transaction, which'
            . ' would defeat the directive. Split the file so each no-transaction migration contains'
            . ' exactly one statement.';
    }

    public function createTableSql(string $table): string
    {
        return "
            CREATE TABLE IF NOT EXISTS {$table} (
                id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                name        TEXT NOT NULL UNIQUE,
                checksum    TEXT NOT NULL,
                applied_at  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                duration_ms INTEGER,
                applied_by  TEXT NOT NULL
            )
        ";
    }

    /**
     * The schema is resolved from the oid rather than matched on table_name alone, so a
     * same-named table in another schema cannot answer for the one search_path picks.
     */
    public function columns(PDO $pdo, string $table): array
    {
        $sql = '
            SELECT column_name
            FROM information_schema.columns
            WHERE (table_schema, table_name) = (
                SELECT n.nspname, c.relname
                FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE c.oid = to_regclass(?)
            )
        ';

        $statement = $pdo->prepare($sql);
        $statement->execute([$table]);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '"' . $identifier . '"';
    }

    public function lock(PDO $pdo): void
    {
        $pdo->prepare('SELECT pg_advisory_lock(?)')->execute([self::LOCK_KEY]);
    }

    public function unlock(PDO $pdo): void
    {
        $pdo->prepare('SELECT pg_advisory_unlock(?)')->execute([self::LOCK_KEY]);
    }
}
