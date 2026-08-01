<?php

namespace System\Modules\Utils\Models\Migrations\Drivers;

use PDO;
use System\Modules\Utils\Models\Migrations\MigrationFile;

/**
 * The four things the migration engine has to ask a database that every database answers
 * differently.
 *
 * Everything else - the state machine, the checksums, the command flow - is driver
 * agnostic, and stays that way by routing all divergence through here rather than through
 * `if ($driver === 'mysql')` scattered across the commands.
 */
interface DriverInterface
{
    /**
     * PDO driver name, as returned by PDO::ATTR_DRIVER_NAME.
     *
     * @access public
     * @return string
     */
    public function name(): string;

    /**
     * Whether a failed migration inside a transaction is rolled back.
     *
     * False on MySQL, where DDL performs an implicit commit server-side: by the time the
     * error reaches PHP the transaction is already gone, and PDO::rollBack() throws
     * "There is no active transaction". Callers use this to decide whether the tracking
     * row can ride along in the migration's own transaction, and to report honestly what
     * a failure left behind.
     *
     * @access public
     * @return bool
     */
    public function supportsTransactionalDdl(): bool;

    /**
     * Why this database cannot honour `-- migrations:no-transaction` for this file, or
     * null when it can.
     *
     * Only Postgres has something to say: it wraps a multi-statement send in an implicit
     * transaction, so the directive is only genuinely honoured for a single statement.
     * MySQL runs everything outside a transaction anyway, and SQLite autocommits each
     * statement separately, so for both the directive holds however many statements the
     * file has.
     *
     * @access public
     * @param  MigrationFile $file
     * @return ?string
     */
    public function noTransactionFileError(MigrationFile $file): ?string;

    /**
     * CREATE TABLE IF NOT EXISTS for the tracking table.
     *
     * @access public
     * @param  string $table Already validated and quoted by the caller
     * @return string
     */
    public function createTableSql(string $table): string;

    /**
     * Column names of $table, empty when it does not exist.
     *
     * @access public
     * @param  PDO    $pdo
     * @param  string $table Raw, unquoted
     * @return string[]
     */
    public function columns(PDO $pdo, string $table): array;

    /**
     * Quote an identifier for this database.
     *
     * @access public
     * @param  string $identifier Already validated by the caller
     * @return string
     */
    public function quoteIdentifier(string $identifier): string;

    /**
     * Take the exclusive migration lock, blocking until it is free.
     *
     * @access public
     * @param  PDO $pdo
     * @return void
     */
    public function lock(PDO $pdo): void;

    /**
     * Release the lock. Must not throw if the lock was never held.
     *
     * @access public
     * @param  PDO $pdo
     * @return void
     */
    public function unlock(PDO $pdo): void;
}
