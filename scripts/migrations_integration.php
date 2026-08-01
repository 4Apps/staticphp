#!/usr/bin/env php
<?php

/**
 * Integration checks for the migration engine against a live Postgres and MySQL.
 *
 * Not part of the phpunit suites, which cover the engine against sqlite and need no
 * server. What cannot be checked there is the behaviour that only appears when DDL is not
 * transactional - the claim-before-run path, and the FAILED state it produces - so that is
 * what this concentrates on.
 *
 * Usage:
 *   MIGRATIONS_PGSQL_DSN='pgsql:host=127.0.0.1;dbname=test' \
 *   MIGRATIONS_PGSQL_USER=postgres MIGRATIONS_PGSQL_PASS=secret \
 *   MIGRATIONS_MYSQL_DSN='mysql:host=127.0.0.1;dbname=test' \
 *   MIGRATIONS_MYSQL_USER=root MIGRATIONS_MYSQL_PASS=secret \
 *   php scripts/migrations_integration.php
 *
 * A database with no DSN set is skipped rather than failed, so this is usable with only
 * one of the two to hand.
 */

use StaticPHP\Utils\Models\Migrations\Commands;
use StaticPHP\Utils\Models\Migrations\Drivers\Driver;
use StaticPHP\Utils\Models\Migrations\State;
use StaticPHP\Utils\Models\Migrations\States;
use StaticPHP\Utils\Models\Migrations\Discovery;
use StaticPHP\Utils\Models\Migrations\Tracker;

$basePath = dirname(__DIR__) . '/src';
require "{$basePath}/System/Utils/Models/Migrations/MigrationError.php";

spl_autoload_register(function ($class) use ($basePath) {
    $path = $basePath . '/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$failures = 0;
$checks = 0;

function check(string $what, bool $passed): void
{
    global $failures, $checks;

    $checks++;
    if ($passed === false) {
        $failures++;
    }

    printf("  [%s] %s\n", $passed ? ' ok ' : 'FAIL', $what);
}

function makeDir(): string
{
    $dir = sys_get_temp_dir() . '/sp_mig_int_' . bin2hex(random_bytes(6));
    mkdir($dir);

    return $dir;
}

function cleanDir(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($dir);
}

/**
 * @param  array<string, string> $files name => sql
 * @return array{0: Commands, 1: Tracker, 2: string}
 */
function harness(PDO $pdo, string $dsn, array $files): array
{
    $dir = makeDir();
    foreach ($files as $name => $sql) {
        file_put_contents($dir . '/' . $name, $sql);
    }

    $verbose = getenv('MIGRATIONS_VERBOSE') !== false;

    $tracker = new Tracker($pdo, Driver::forPdo($pdo, $dsn), 'sp_test_migrations');
    $commands = new Commands($pdo, $tracker, $dir, function (string $line = '') use ($verbose) {
        if ($verbose === true) {
            echo "      | {$line}\n";
        }
    });

    return [$commands, $tracker, $dir];
}

/**
 * @return array<string, \StaticPHP\Utils\Models\Migrations\State>
 */
function statesOf(Tracker $tracker, string $dir): array
{
    $byName = [];
    foreach (States::compute(Discovery::discover($dir), $tracker->appliedRows()) as $state) {
        $byName[$state->name] = $state->state;
    }

    return $byName;
}

/**
 * @return array{0: PDO, 1: string}|null [pdo, dsn]
 */
function connect(string $prefix): ?array
{
    $dsn = getenv("MIGRATIONS_{$prefix}_DSN");
    if ($dsn === false || $dsn === '') {
        return null;
    }

    $pdo = new PDO(
        $dsn,
        (string) getenv("MIGRATIONS_{$prefix}_USER"),
        (string) getenv("MIGRATIONS_{$prefix}_PASS"),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    return [$pdo, $dsn];
}

function dropAll(PDO $pdo): void
{
    foreach (['sp_test_migrations', 'sp_alpha', 'sp_beta', 'sp_gamma'] as $table) {
        try {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        } catch (\Throwable $e) {
            // Best effort
        }
    }
}

// ---------------------------------------------------------------------------------------

foreach (['PGSQL' => 'postgres', 'MYSQL' => 'mysql'] as $prefix => $label) {
    $connection = connect($prefix);
    if ($connection === null) {
        echo "\n=== {$label}: skipped (MIGRATIONS_{$prefix}_DSN not set) ===\n";
        continue;
    }

    [$pdo, $dsn] = $connection;
    echo "\n=== {$label} ===\n";
    dropAll($pdo);

    $driver = Driver::forPdo($pdo, $dsn);
    $transactional = $driver->supportsTransactionalDdl();
    check(
        "driver reports transactional ddl = " . var_export($transactional, true),
        $transactional === ($label === 'postgres')
    );

    // --- A clean apply ------------------------------------------------------------------
    [$commands, $tracker, $dir] = harness($pdo, $dsn, [
        '2026-08-01-100000-alpha.sql' => 'CREATE TABLE sp_alpha (id INTEGER PRIMARY KEY);',
        '2026-08-01-110000-beta.sql' => 'CREATE TABLE sp_beta (id INTEGER PRIMARY KEY);',
    ]);

    check('apply succeeds', $commands->apply(false, null, 'integration') === 0);
    check('both migrations recorded', count($tracker->appliedRows()) === 2);
    check('every state is applied', statesOf($tracker, $dir) === [
        '2026-08-01-100000-alpha.sql' => State::APPLIED,
        '2026-08-01-110000-beta.sql' => State::APPLIED,
    ]);
    check('re-apply is a no-op', $commands->apply(false, null, 'integration') === 0);
    cleanDir($dir);
    dropAll($pdo);

    // --- A failing migration -------------------------------------------------------------
    //
    // The whole point of the exercise. On postgres this rolls back and leaves nothing. On
    // mysql the first statement has already committed by the time the second fails, so the
    // claim stays behind as FAILED rather than the file looking untouched.
    [$commands, $tracker, $dir] = harness($pdo, $dsn, [
        '2026-08-01-100000-half.sql' =>
            "CREATE TABLE sp_alpha (id INTEGER PRIMARY KEY);\n"
            . "CREATE TABLE sp_alpha (id INTEGER PRIMARY KEY);",
    ]);

    check('a failing migration exits non-zero', $commands->apply(false, null, 'integration') !== 0);

    $alphaExists = true;
    try {
        $pdo->query('SELECT 1 FROM sp_alpha');
    } catch (\Throwable $e) {
        $alphaExists = false;
    }

    $states = statesOf($tracker, $dir);
    if ($transactional === true) {
        check('nothing was left behind', $alphaExists === false);
        check('nothing was recorded', $states['2026-08-01-100000-half.sql'] === State::PENDING);
    } else {
        check('the first statement did land (implicit commit)', $alphaExists === true);
        check(
            'the half-applied migration is FAILED, not PENDING',
            $states['2026-08-01-100000-half.sql'] === State::FAILED
        );

        // The critical property: a second apply must refuse rather than silently re-run a
        // file whose first statement already exists.
        check('a second apply refuses to continue', $commands->apply(false, null, 'integration') !== 0);

        check('forget clears it', $commands->forget('2026-08-01-100000-half.sql') === 0);
        check(
            'after forget it is pending again',
            statesOf($tracker, $dir)['2026-08-01-100000-half.sql'] === State::PENDING
        );
    }

    cleanDir($dir);
    dropAll($pdo);

    // --- Drift ---------------------------------------------------------------------------
    [$commands, $tracker, $dir] = harness($pdo, $dsn, [
        '2026-08-01-100000-alpha.sql' => 'CREATE TABLE sp_alpha (id INTEGER PRIMARY KEY);',
    ]);

    $commands->apply(false, null, 'integration');
    file_put_contents(
        $dir . '/2026-08-01-100000-alpha.sql',
        "-- edited\nCREATE TABLE sp_alpha (id INTEGER PRIMARY KEY);"
    );

    check('an edited file is DRIFT', statesOf($tracker, $dir)['2026-08-01-100000-alpha.sql'] === State::DRIFT);
    check('drift blocks apply', $commands->apply(false, null, 'integration') !== 0);
    check('repair clears it', $commands->repair('2026-08-01-100000-alpha.sql') === 0);
    check(
        'after repair it is applied',
        statesOf($tracker, $dir)['2026-08-01-100000-alpha.sql'] === State::APPLIED
    );

    cleanDir($dir);
    dropAll($pdo);

    // --- Tracking table adoption ---------------------------------------------------------
    $pdo->exec('CREATE TABLE sp_gamma (something INTEGER)');
    $refused = false;
    try {
        (new Tracker($pdo, $driver, 'sp_gamma'))->ensureTable();
    } catch (\Throwable $e) {
        $refused = str_contains($e->getMessage(), 'not a migration tracking table');
    }

    check('an unrelated table of the same name is refused', $refused);
    dropAll($pdo);

    // --- Locking -------------------------------------------------------------------------
    $tracker = new Tracker($pdo, $driver, 'sp_test_migrations');
    $ran = $tracker->withLock(fn() => 'body ran');
    check('withLock runs the body and releases', $ran === 'body ran');

    $released = true;
    try {
        $tracker->withLock(fn() => throw new RuntimeException('boom'));
        $released = false;
    } catch (RuntimeException $e) {
        // The lock must be free again even though the body threw
        $tracker->withLock(fn() => null);
    }

    check('the lock is released when the body throws', $released);
    dropAll($pdo);
}

echo "\n";
printf("%d checks, %d failures\n", $checks, $failures);

exit($failures === 0 ? 0 : 1);
