<?php

namespace System\Modules\Utils\Models\Migrations\Drivers;

use PDO;
use System\Modules\Utils\Models\Migrations\MigrationFile;

/**
 * MySQL and MariaDB: no transactional DDL.
 *
 * CREATE/ALTER/DROP TABLE perform an implicit commit inside the server, so wrapping a
 * migration in a transaction protects nothing. PDO::inTransaction() goes false the moment
 * the first DDL statement lands, and a later rollBack() throws rather than undoing
 * anything. Everything the engine does differently here follows from that one fact.
 */
class MySqlDriver implements DriverInterface
{
    /**
     * Name for GET_LOCK. Namespaced so it cannot collide with an application lock.
     *
     * @var string
     * @access public
     */
    public const LOCK_NAME = 'staticphp_migrations';

    /**
     * Seconds GET_LOCK waits before giving up. Not infinite, because unlike a Postgres
     * advisory lock this one survives on a connection the server has not yet noticed is
     * dead, and a deploy hanging forever is worse than one that fails loudly.
     *
     * @var int
     * @access public
     */
    public const LOCK_TIMEOUT = 60;

    public function name(): string
    {
        return 'mysql';
    }

    public function supportsTransactionalDdl(): bool
    {
        return false;
    }

    /**
     * Nothing to refuse: DDL here never runs inside a transaction anyway, so the directive
     * describes what already happens rather than requesting anything.
     */
    public function noTransactionFileError(MigrationFile $file): ?string
    {
        return null;
    }

    /**
     * VARCHAR(255) rather than TEXT for `name`, because it carries a UNIQUE index and
     * InnoDB cannot index a TEXT column without a prefix length. 255 utf8mb4 characters
     * is 1020 bytes, inside the 3072-byte limit.
     */
    public function createTableSql(string $table): string
    {
        return "
            CREATE TABLE IF NOT EXISTS {$table} (
                id          BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name        VARCHAR(255) NOT NULL UNIQUE,
                checksum    VARCHAR(64) NOT NULL,
                applied_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                duration_ms INTEGER NULL,
                applied_by  VARCHAR(255) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
    }

    public function columns(PDO $pdo, string $table): array
    {
        $sql = '
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ?
        ';

        $statement = $pdo->prepare($sql);
        $statement->execute([$table]);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '`' . $identifier . '`';
    }

    public function lock(PDO $pdo): void
    {
        $statement = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $statement->execute([self::LOCK_NAME, self::LOCK_TIMEOUT]);

        // 1 is acquired, 0 is timed out, NULL is an error. Only 1 may proceed - treating a
        // timeout as success is how two deploys end up interleaving DDL.
        if ((string) $statement->fetchColumn() !== '1') {
            throw new \RuntimeException(
                'Could not acquire the migration lock within ' . self::LOCK_TIMEOUT
                . ' seconds; another migration run is probably in progress.'
            );
        }
    }

    public function unlock(PDO $pdo): void
    {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([self::LOCK_NAME]);
    }
}
