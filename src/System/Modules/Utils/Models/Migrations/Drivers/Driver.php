<?php

namespace System\Modules\Utils\Models\Migrations\Drivers;

use PDO;
use System\Modules\Utils\Models\Migrations\MigrationError;

/**
 * Picks the driver strategy for an open connection.
 */
class Driver
{
    /**
     * @access public
     * @static
     * @param  PDO     $pdo
     * @param  ?string $dsn Only sqlite needs it, to find the database file for locking
     * @return DriverInterface
     */
    public static function forPdo(PDO $pdo, ?string $dsn = null): DriverInterface
    {
        $name = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        return match ($name) {
            'pgsql' => new PostgresDriver(),
            'mysql' => new MySqlDriver(),
            'sqlite' => new SqliteDriver($dsn === null ? null : SqliteDriver::pathFromDsn($dsn)),
            default => throw new MigrationError(
                "Migrations do not support the \"{$name}\" PDO driver; "
                . 'supported drivers are pgsql, mysql and sqlite.'
            ),
        };
    }
}
