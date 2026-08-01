#!/usr/bin/env php
<?php

/**
 * Integration checks for the translation layer against a live Postgres and MySQL.
 *
 * Not part of the phpunit suites, which cover the same behaviour against sqlite and need
 * no server. What cannot be checked there is that the three drivers really do agree: the
 * upsert spellings differ per driver (ON CONFLICT, INSERT IGNORE, ON DUPLICATE KEY UPDATE),
 * "key" and "value" are reserved words in mysql, and the reason keys are addressed by hash
 * at all is a mysql index limit that sqlite does not have.
 *
 * Usage:
 *   I18N_PGSQL_DSN='pgsql:host=127.0.0.1;dbname=test' \
 *   I18N_PGSQL_USER=postgres I18N_PGSQL_PASS=secret \
 *   I18N_MYSQL_DSN='mysql:host=127.0.0.1;dbname=test' \
 *   I18N_MYSQL_USER=root I18N_MYSQL_PASS=secret \
 *   php scripts/i18n_integration.php
 *
 * A database with no DSN set is skipped rather than failed, so this is usable with only
 * one of the two to hand.
 */

use System\Modules\Utils\Models\Db;
use System\Modules\Utils\Models\i18n;
use System\Modules\Utils\Models\Translation\Catalog;
use System\Modules\Utils\Models\Translation\Commands;
use System\Modules\Utils\Models\Translation\Locales;
use System\Modules\Utils\Models\Translation\Store;

$basePath = dirname(__DIR__) . '/src';

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

function settings(string $connection): array
{
    return [
        'available' => [
            ['name' => 'Latvia', 'code' => 'lv', 'languages' => ['lv', 'en', 'ru']],
            ['name' => 'Estonia', 'code' => 'ee', 'languages' => ['et', 'en']],
        ],
        'url_format' => '{{country}}-{{language}}',
        'redirect' => false,
        'negotiate' => false,
        'auto_register' => true,
        'missing_suffix' => '*',
        'fallback' => true,
        'strict' => true,
        'set_locale' => false,
        'cache' => 'none',
        'cache_prefix' => 'language_',
        'cache_ttl' => null,
        'db_config' => $connection,
        'db_scheme' => '',
        'tables' => [],
    ];
}

function dropTables(string $connection): void
{
    foreach (['i18n_translations', 'i18n_keys', 'i18n_cached'] as $table) {
        try {
            Db::query("DROP TABLE IF EXISTS {$table}", [], $connection);
        } catch (\Throwable $e) {
            // A table that is not there is exactly the state this is aiming for
        }
    }
}

/**
 * The shipped install template, statement by statement.
 *
 * Split on semicolons rather than handed to exec() in one piece: mysql's driver will not
 * run a multi-statement string through a prepared statement, which is the same reason the
 * migration engine has a driver abstraction in the first place.
 */
function installSchema(string $connection, string $driver, string $basePath): void
{
    $sql = (string) file_get_contents("{$basePath}/System/Modules/Utils/Files/I18n/install.{$driver}.sql");

    foreach (explode(';', $sql) as $statement) {
        $statement = trim(preg_replace('/^\s*--.*$/m', '', $statement) ?? '');
        if ($statement === '') {
            continue;
        }

        Db::query($statement, [], $connection);
    }
}

function run(string $driver, string $dsn, string $user, string $password, string $basePath): void
{
    $connection = "i18n_{$driver}";

    echo "\n=== {$driver} ===\n";

    try {
        Db::init($connection, [
            'string' => $dsn,
            'username' => $user,
            'password' => $password,
            'persistent' => false,
            'wrap_column' => $driver === 'mysql' ? '`' : '"',
        ]);
    } catch (\Throwable $e) {
        check("connect: {$e->getMessage()}", false);

        return;
    }

    dropTables($connection);

    $config = settings($connection);
    $locales = Locales::fromConfig($config);
    $store = new Store($connection, '', [], true);

    check('driver is reported as ' . $driver, $store->driver() === $driver);
    check('schema is reported missing before it is installed', (new Store($connection, '', [], false))
        ->isInstalled() === false);

    installSchema($connection, $driver, $basePath);
    check('shipped install template applies', $store->isInstalled() === true);

    /*
    | Keys
    */

    $first = $store->ensureKey('Log in');
    $second = $store->ensureKey('Log in');
    check('registering the same key twice returns one id', $first !== null && $first === $second);

    // Reserved words in mysql, which is why every identifier this class writes is quoted
    check('a key column named "key" round-trips', $store->keys()[0]['key'] === 'Log in');

    // The reason keys are addressed by hash: a unique index on utf8mb4 tops out at 768
    // characters in InnoDB, and source text is a whole sentence
    $long = str_repeat('A long source paragraph that nobody would index directly. ', 100);
    $longId = $store->ensureKey($long);
    check('a 5700 character key registers', $longId !== null);
    check('and comes back byte for byte', in_array($long, array_column($store->keys(), 'key'), true));

    $unicode = 'Sveiki, čau! Привет — 你好';
    $store->ensureKey($unicode);
    check('a multi-byte key round-trips', in_array($unicode, array_column($store->keys(), 'key'), true));

    /*
    | Translations
    */

    $store->putTranslation($first, 'lv_lv', 'Pieslēgties');
    $store->putTranslation($first, 'lv_en', 'Log in');
    $store->putTranslation($first, 'lv_ru', 'Войти');

    check('a value column named "value" round-trips', $store->translations('lv_lv')['Log in'] === 'Pieslēgties');
    check('a language with no row reads as null', $store->translations('ee_et')['Log in'] === null);

    // The upsert spelling differs per driver, so that it really is an upsert is worth
    // asserting on each of them rather than on sqlite alone
    $store->putTranslation($first, 'lv_lv', 'Ienākt');
    check('overwriting updates in place', $store->translations('lv_lv')['Log in'] === 'Ienākt');
    check('and leaves one row', count(rows($connection, $first, 'lv_lv')) === 1);

    // The update this replaced had no language in its WHERE clause
    check('writing one language leaves the others alone', $store->translations('lv_ru')['Log in'] === 'Войти');

    $store->putTranslation($first, 'lv_lv', 'Never written', false);
    check('a non-overwriting write leaves an existing translation', $store->translations('lv_lv')['Log in'] === 'Ienākt');

    $store->putTranslation($longId, 'lv_lv', 'Garš');
    check('the long key can be translated too', $store->translations('lv_lv')[$long] === 'Garš');

    $languages = $store->languages();
    check('languages are counted', ($languages['lv_lv'] ?? 0) === 2 && ($languages['lv_ru'] ?? 0) === 1);

    /*
    | Freshness
    */

    check('a language starts stale', $store->isFresh('lv_lv') === false);
    $store->markFresh('lv_lv');
    check('and can be marked fresh', $store->isFresh('lv_lv') === true);
    $store->markFresh('lv_lv');
    check('marking it twice is not an error', $store->isFresh('lv_lv') === true);
    $store->markStale('lv_lv');
    check('and stale again', $store->isFresh('lv_lv') === false);

    /*
    | The facade
    */

    i18n::reset();
    i18n::inject($config, $locales->byKey('lv_lv'), $store, new Catalog($store, 'none'));

    check('translate reads the stored value', i18n::translate('Log in') === 'Ienākt');
    check('an unknown string registers itself', i18n::translate('Sign out') === 'Sign out*');
    check('and lands in the database', $store->translations('lv_lv')['Sign out'] === 'Sign out*');

    i18n::reset();
    i18n::inject($config, $locales->byKey('lv_en'), $store, new Catalog($store, 'none'));
    check('a missing translation falls back to the country default', i18n::translate('Sign out') === 'Sign out*');

    $plural = '{n, plural, zero{# failu} one{# fails} other{# faili}}';
    $store->setTranslation($plural, 'lv_lv', $plural);

    i18n::reset();
    i18n::inject($config, $locales->byKey('lv_lv'), $store, new Catalog($store, 'none'));
    check('plurals follow the target language', i18n::format($plural, ['n' => 21]) === '21 fails');
    check('and its other categories', i18n::format($plural, ['n' => 11]) === '11 failu');

    /*
    | Commands
    */

    $lines = [];
    $out = function (string $line = '') use (&$lines): void {
        $lines[] = $line;
    };

    $commands = new Commands($store, $locales, $config, $out);
    check('status runs', $commands->status() === 0);
    check('status names the driver', str_contains(implode("\n", $lines), "driver: {$driver}"));

    $file = sys_get_temp_dir() . "/sp_i18n_{$driver}.csv";
    check('export writes a file', $commands->export('lv_lv', 'csv', $file) === 0);
    check('import reads it back', $commands->import('ee_et', $file, 'csv') === 0);
    check('into the named language', $store->translations('ee_et')['Log in'] === 'Ienākt');
    unlink($file);

    /*
    | Degrading
    */

    $loose = new Store($connection, '', [], false);
    i18n::reset();
    i18n::inject($config, $locales->byKey('lv_lv'), $loose, new Catalog($loose, 'none'));

    dropTables($connection);

    check('a dead schema renders source strings', i18n::translate('Log in') === 'Log in*');
    check('and says so', i18n::isDegraded() === true);

    i18n::reset();

    if ($driver === 'pgsql') {
        checkUpgrade($connection, $basePath);
    }

    dropTables($connection);
    Db::close($connection);
}

/**
 * Apply the upgrade template to a copy of the schema it upgrades from.
 *
 * Postgres only, because the old schema only ever shipped for postgres. Every step of it
 * is destructive, so it is worth knowing it does what it says before anyone runs it
 * against a database with five years of translations in it.
 */
function checkUpgrade(string $connection, string $basePath): void
{
    dropTables($connection);

    // Verbatim from the file this replaced, System/Modules/Utils/Files/i18n_pg.sql
    Db::query('CREATE TABLE i18n_cached (id text NOT NULL PRIMARY KEY, created bigint NOT NULL DEFAULT 0)', [], $connection);
    Db::query(
        'CREATE TABLE i18n_keys (id serial PRIMARY KEY, created bigint NOT NULL DEFAULT 0,'
        . ' "key" text NOT NULL DEFAULT \'\', CONSTRAINT i18n_keys_key_key UNIQUE ("key"))',
        [],
        $connection
    );
    Db::query(
        'CREATE TABLE i18n_translations (id serial PRIMARY KEY, key_id integer, created bigint NOT NULL DEFAULT 0,'
        . ' language varchar(24) NOT NULL, "value" text NOT NULL DEFAULT \'\')',
        [],
        $connection
    );

    Db::query('INSERT INTO i18n_keys ("key", created) VALUES (?, ?)', ['Log in', 1000], $connection);
    Db::query('INSERT INTO i18n_keys ("key", created) VALUES (?, ?)', ['Sign out', 1001], $connection);

    // Two rows for one (key, language) - what the missing unique constraint allowed
    Db::query(
        'INSERT INTO i18n_translations (key_id, language, "value", created) VALUES (1, ?, ?, ?), (1, ?, ?, ?)',
        ['lv_lv', 'first', 1000, 'lv_lv', 'newest', 2000],
        $connection
    );
    Db::query(
        'INSERT INTO i18n_translations (key_id, language, "value", created) VALUES (2, ?, ?, ?)',
        ['lv_lv', 'Sign out*', 1001],
        $connection
    );
    // An orphan of each kind the old schema allowed
    Db::query('INSERT INTO i18n_translations (key_id, language, "value") VALUES (NULL, ?, ?)', ['lv_lv', 'x'], $connection);
    Db::query('INSERT INTO i18n_translations (key_id, language, "value") VALUES (999, ?, ?)', ['lv_lv', 'y'], $connection);
    Db::query('INSERT INTO i18n_cached (id, created) VALUES (?, ?)', ['lv_lv', 1000], $connection);

    $sql = (string) file_get_contents("{$basePath}/System/Modules/Utils/Files/I18n/upgrade.pgsql.sql");

    foreach (explode(';', $sql) as $statement) {
        $statement = trim(preg_replace('/^\s*--.*$/m', '', $statement) ?? '');
        if ($statement !== '') {
            Db::query($statement, [], $connection);
        }
    }

    $store = new Store($connection, '', [], true);

    check('upgrade: the schema is usable afterwards', $store->isInstalled() === true);
    check('upgrade: orphaned translations are gone', count(
        Db::query('SELECT id FROM i18n_translations', [], $connection)->fetchAll(PDO::FETCH_ASSOC)
    ) === 2);
    check('upgrade: the newest of a duplicate pair survives', $store->translations('lv_lv')['Log in'] === 'newest');
    check('upgrade: warmed copies are invalidated', $store->isFresh('lv_lv') === false);

    $hash = Db::query('SELECT key_hash FROM i18n_keys WHERE "key" = ?', ['Log in'], $connection)
        ->fetch(PDO::FETCH_ASSOC)['key_hash'];
    check('upgrade: the backfilled hash is what php computes', $hash === hash('sha256', 'Log in'));

    check('upgrade: updated is seeded from created', (int) Db::query(
        'SELECT updated FROM i18n_translations WHERE key_id = 2',
        [],
        $connection
    )->fetch(PDO::FETCH_ASSOC)['updated'] === 1001);

    $duplicated = true;
    try {
        Db::query(
            'INSERT INTO i18n_translations (key_id, language, "value") VALUES (1, ?, ?)',
            ['lv_lv', 'again'],
            $connection
        );
    } catch (\Throwable $e) {
        $duplicated = false;
    }
    check('upgrade: a duplicate is now refused', $duplicated === false);

    // And the code path that needs all of it
    check('upgrade: the store writes through it', $store->setTranslation('Log in', 'lv_ru', 'Войти') === true);
}

/**
 * Raw rows for one key and language, to count them.
 */
function rows(string $connection, int $keyId, string $language): array
{
    return Db::query(
        'SELECT id FROM i18n_translations WHERE key_id = ? AND language = ?',
        [$keyId, $language],
        $connection
    )->fetchAll(PDO::FETCH_ASSOC);
}

foreach (['pgsql', 'mysql'] as $driver) {
    $prefix = 'I18N_' . strtoupper($driver);
    $dsn = getenv("{$prefix}_DSN");

    if ($dsn === false || $dsn === '') {
        echo "\n=== {$driver} ===\n  [skip] no {$prefix}_DSN\n";
        continue;
    }

    run(
        $driver,
        $dsn,
        (string) getenv("{$prefix}_USER"),
        (string) getenv("{$prefix}_PASS"),
        $basePath
    );
}

printf("\n%d checks, %d failures\n", $checks, $failures);

exit($failures === 0 ? 0 : 1);
