<?php

namespace Defaults\Controllers;

use PDO;
use StaticPHP\Core\Controllers\Controller;
use StaticPHP\Core\Exceptions\ErrorMessage\NotFound;
use StaticPHP\Core\Models\Config;
use StaticPHP\Core\Models\Router;
use StaticPHP\Utils\Models\Db;

/**
 * Environment report, as json.
 *
 * Answers the questions that are awkward to answer from inside the application: what does
 * php think the time is, what does the database think the time is, and are the two in the
 * same timezone. A date that is right in the query and wrong on the page - or a row that
 * lands in yesterday - is almost always this, and it is invisible until something prints
 * both clocks side by side.
 *
 * Only reachable in debug mode. It reports the environment, the session and the database
 * topology, none of which belongs in front of the public.
 */
class Debug extends Controller
{
    /**
     * Session keys whose values are replaced before the session is printed.
     *
     * Matched at any depth, by key name. Debug output gets pasted into tickets and chat.
     *
     * @var string[]
     */
    private static array $redactKeys = [
        'access',
        'api_key',
        'password',
        'pin_code',
        'secret',
        'token',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function index(): array
    {
        if (empty(Config::$items['debug'])) {
            // 404 rather than 403: an endpoint that answers "forbidden" has confirmed it
            // exists, and this one is worth not confirming
            throw new NotFound();
        }

        return [
            'application' => self::application(),
            'request' => self::request(),
            'php' => self::php(),
            'databases' => self::databases(),
            'session' => self::session(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function application(): array
    {
        return [
            'environment' => Config::$items['environment'] ?? 'unknown',
            'debug' => (bool) (Config::$items['debug'] ?? false),
            'base_url' => Config::$items['base_url'] ?? null,
            'module' => Router::$module,
            'controller' => Router::$controller,
            'method' => Router::$method,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function request(): array
    {
        return [
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'cli',
            'uri' => $_SERVER['REQUEST_URI'] ?? null,
            'remote_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'server_name' => $_SERVER['SERVER_NAME'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'https' => !empty($_SERVER['HTTPS']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function php(): array
    {
        $now = new \DateTime();

        return [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'time' => $now->format('Y-m-d H:i:s'),
            'timezone' => $now->getTimezone()->getName(),
            'utc_offset' => $now->format('P'),
            'locale' => setlocale(LC_TIME, '0'),
            'memory_peak' => memory_get_peak_usage(true),
            'extensions' => [
                'intl' => extension_loaded('intl'),
                'mbstring' => extension_loaded('mbstring'),
                'pdo' => extension_loaded('pdo'),
            ],
        ];
    }

    /**
     * Probes every configured connection, not only the one already in use.
     *
     * Opening them is the point - "the reporting replica is unreachable from this host" is
     * exactly the sort of thing this endpoint exists to tell you, so a connection that
     * fails is reported rather than thrown.
     *
     * @return array<string, mixed>
     */
    private static function databases(): array
    {
        // Config::$items is a bare array, so every step into it is mixed until it is checked
        $db = Config::$items['db'] ?? null;
        $configured = (is_array($db) && is_array($db['pdo'] ?? null)) ? $db['pdo'] : [];
        if ($configured === []) {
            return ['configured' => false];
        }

        $result = [];
        foreach (array_keys($configured) as $name) {
            $name = (string) $name;

            try {
                $result[$name] = self::database($name);
            } catch (\Throwable $e) {
                $result[$name] = [
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private static function database(string $name): array
    {
        $pdo = Db::init($name);

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $driver = (is_string($driver) ? $driver : '');

        $info = [
            'type' => self::driverLabel($driver),
            'driver' => $driver,
        ];

        // Each engine spells the same three questions differently, and two of them have no
        // answer at all for the third
        $row = match ($driver) {
            'pgsql' => Db::fetch(
                "
                    SELECT
                        CURRENT_TIMESTAMP AS timestamp,
                        CURRENT_DATE AS date,
                        current_setting('TimeZone') AS timezone,
                        current_setting('server_version') AS version
                ",
                [],
                $name
            ),

            'mysql' => Db::fetch(
                "
                    SELECT
                        NOW() AS `timestamp`,
                        CURDATE() AS `date`,
                        @@session.time_zone AS `timezone`,
                        @@system_time_zone AS `system_timezone`,
                        VERSION() AS `version`
                ",
                [],
                $name
            ),

            // sqlite has no session timezone of its own. 'now' is UTC, and 'localtime'
            // resolves through the php process timezone - so the answer to "what timezone
            // is the database in" is "whatever php is in", which is worth saying out loud
            'sqlite' => Db::fetch(
                "
                    SELECT
                        datetime('now', 'localtime') AS timestamp,
                        date('now', 'localtime') AS date,
                        datetime('now') AS utc_timestamp,
                        sqlite_version() AS version
                ",
                [],
                $name
            ),

            default => null,
        };

        if ($row === null || $row === false) {
            $info['note'] = "No time probe for driver '{$driver}'";

            return $info;
        }

        // fetch_mode_objects turns rows into stdClass, and this has to read the same either way
        $row = (array) $row;

        $info['version'] = $row['version'] ?? null;
        $info['time'] = $row['timestamp'] ?? null;
        $info['date'] = $row['date'] ?? null;

        if ($driver === 'sqlite') {
            $info['timezone'] = 'none - sqlite uses the php process timezone';
            $info['utc_time'] = $row['utc_timestamp'] ?? null;
        } else {
            $info['timezone'] = $row['timezone'] ?? null;
        }

        // MySQL reports SYSTEM when it defers to the host clock, which says nothing on its
        // own - the zone it actually resolves to is the one worth comparing against php
        if ($driver === 'mysql' && !empty($row['system_timezone'])) {
            $info['system_timezone'] = $row['system_timezone'];
        }

        return $info;
    }

    private static function driverLabel(string $driver): string
    {
        return match ($driver) {
            'pgsql' => 'PostgreSQL',
            'mysql' => 'MySQL / MariaDB',
            'sqlite' => 'SQLite',
            default => ucfirst($driver),
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function session(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        return self::redact($_SESSION);
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::$redactKeys, true)) {
                $data[$key] = '** removed **';
                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }
}
