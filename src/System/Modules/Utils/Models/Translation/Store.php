<?php

namespace System\Modules\Utils\Models\Translation;

use PDO;
use System\Modules\Utils\Models\Db;

/**
 * Every database access the translation layer makes, for postgres, mysql and sqlite.
 *
 * Two things are load bearing here.
 *
 * Keys are looked up by a sha256 of the source text rather than by the text itself. The
 * source text is the key, and source text is a whole english sentence - sometimes a
 * paragraph. Postgres will index that, mysql's InnoDB will not: a unique index on a utf8mb4
 * column tops out at 768 characters. Hashing moves the uniqueness onto a fixed 64 byte
 * column and the length limit disappears on all three drivers. Postgres and mysql can both
 * compute it in sql, which is what makes the upgrade from the old schema a plain migration.
 *
 * Outside strict mode a failure here never propagates. A translation layer that takes the
 * site down when the database blinks is worse than one that renders english.
 */
final class Store
{
    /**
     * @var string
     * @access private
     */
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * @var ?string
     * @access private
     */
    private ?string $driver = null;

    /**
     * @var bool
     * @access private
     */
    private bool $degraded = false;

    /**
     * @var ?string
     * @access private
     */
    private ?string $lastError = null;

    /**
     * @access public
     * @param string $connection Entry of config['db']['pdo']
     * @param string $scheme     Schema to qualify tables with, or an empty string
     * @param array  $tables     Names for the keys, translations and cached tables
     * @param bool   $strict     Rethrow instead of degrading
     */
    public function __construct(
        private readonly string $connection = 'default',
        private readonly string $scheme = '',
        private readonly array $tables = [],
        private readonly bool $strict = false,
    ) {
    }

    /**
     * @access public
     * @return string One of: pgsql, mysql, sqlite
     * @throws TranslationError When the driver is not one this class writes sql for
     */
    public function driver(): string
    {
        if ($this->driver !== null) {
            return $this->driver;
        }

        $name = (string) Db::init($this->connection)->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (in_array($name, ['pgsql', 'mysql', 'sqlite'], true) === false) {
            throw new TranslationError("i18n has no sql for the \"{$name}\" driver");
        }

        return $this->driver = $name;
    }

    /**
     * @access public
     * @return bool Whether a database call has failed during this request
     */
    public function isDegraded(): bool
    {
        return $this->degraded;
    }

    /**
     * @access public
     * @return ?string Message of the first failure, for a status command to report
     */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Whether the schema this class expects is actually present.
     *
     * @access public
     * @return bool
     */
    public function isInstalled(): bool
    {
        // Not routed through guard(): this is a probe, and "the table is not there" is the
        // answer it exists to give. In strict mode guard() would rethrow it, so the command
        // that asks in order to print install instructions would die printing a stack trace
        // instead.
        try {
            Db::query('SELECT 1 FROM ' . $this->table('keys') . ' WHERE 1 = 0', [], $this->connection);

            return true;
        } catch (TranslationError $e) {
            throw $e;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /*
     * =============================================== Keys ============================================================
     */

    /**
     * @access public
     * @param  string $key Source text
     * @return ?int
     */
    public function keyId(string $key): ?int
    {
        return $this->guard(function () use ($key): ?int {
            $row = Db::query(
                'SELECT id FROM ' . $this->table('keys') . ' WHERE key_hash = ?',
                [hash('sha256', $key)],
                $this->connection
            )->fetch(PDO::FETCH_ASSOC);

            return $row === false || $row === null ? null : (int) $row['id'];
        });
    }

    /**
     * Register a key if it is not there yet, and return its id.
     *
     * The insert ignores a conflict rather than checking first and inserting second,
     * because two requests hitting an unseen string at the same moment would both find
     * nothing and both insert - and one of them would take the unique violation with it.
     *
     * @access public
     * @param  string $key Source text
     * @return ?int Null when the store is degraded
     */
    public function ensureKey(string $key): ?int
    {
        return $this->guard(function () use ($key): ?int {
            $table = $this->table('keys');
            $columns = '(key_hash, ' . $this->quote('key') . ', created)';
            $sql = match ($this->driver()) {
                'pgsql' => "INSERT INTO {$table} {$columns} VALUES (?, ?, ?) ON CONFLICT (key_hash) DO NOTHING",
                'mysql' => "INSERT IGNORE INTO {$table} {$columns} VALUES (?, ?, ?)",
                'sqlite' => "INSERT OR IGNORE INTO {$table} {$columns} VALUES (?, ?, ?)",
            };

            Db::query($sql, [hash('sha256', $key), $key, time()], $this->connection);

            $row = Db::query(
                "SELECT id FROM {$table} WHERE key_hash = ?",
                [hash('sha256', $key)],
                $this->connection
            )->fetch(PDO::FETCH_ASSOC);

            return $row === false || $row === null ? null : (int) $row['id'];
        });
    }

    /**
     * Every key, oldest first.
     *
     * @access public
     * @return array<int, array{id: int, key: string}>
     */
    public function keys(): array
    {
        return $this->guard(function (): array {
            $rows = Db::query(
                'SELECT id, ' . $this->quote('key') . ' AS source FROM ' . $this->table('keys') . ' ORDER BY id',
                [],
                $this->connection
            )->fetchAll(PDO::FETCH_ASSOC);

            return array_map(
                fn(array $row): array => ['id' => (int) $row['id'], 'key' => (string) $row['source']],
                $rows
            );
        }, []);
    }

    /**
     * Drop a key and, through the foreign key, its translations.
     *
     * @access public
     * @param  string $key Source text
     * @return bool
     */
    public function deleteKey(string $key): bool
    {
        return $this->guard(function () use ($key): bool {
            $id = $this->keyId($key);
            if ($id === null) {
                return false;
            }

            // Done explicitly rather than left to ON DELETE CASCADE: sqlite only enforces
            // foreign keys when PRAGMA foreign_keys is on, which is off by default and is a
            // per-connection setting this class does not own
            Db::query(
                'DELETE FROM ' . $this->table('translations') . ' WHERE key_id = ?',
                [$id],
                $this->connection
            );
            Db::query('DELETE FROM ' . $this->table('keys') . ' WHERE id = ?', [$id], $this->connection);

            return true;
        }, false);
    }

    /*
     * =============================================== Translations ====================================================
     */

    /**
     * Whole string table for one language, source text mapped to translation.
     *
     * A key with no row for this language maps to null, which is what tells the caller to
     * try the fallback chain. An empty string is a deliberate "translate this to nothing"
     * and is kept distinct from it.
     *
     * @access public
     * @param  string $languageKey
     * @return array<string, ?string>
     */
    public function translations(string $languageKey): array
    {
        return $this->guard(function () use ($languageKey): array {
            $rows = Db::query(
                'SELECT k.' . $this->quote('key') . ' AS source, t.' . $this->quote('value') . ' AS translation'
                . ' FROM ' . $this->table('keys') . ' k'
                . ' LEFT JOIN ' . $this->table('translations') . ' t'
                . ' ON t.key_id = k.id AND t.language = ?'
                . ' ORDER BY k.id',
                [$languageKey],
                $this->connection
            )->fetchAll(PDO::FETCH_ASSOC);

            $strings = [];
            foreach ($rows as $row) {
                $strings[(string) $row['source']] = $row['translation'] === null
                    ? null
                    : (string) $row['translation'];
            }

            return $strings;
        }, []);
    }

    /**
     * Write a translation, inserting or updating as needed.
     *
     * @access public
     * @param  int    $keyId
     * @param  string $languageKey
     * @param  string $value
     * @param  bool   $overwrite   False to leave an existing translation alone
     * @return bool
     */
    public function putTranslation(int $keyId, string $languageKey, string $value, bool $overwrite = true): bool
    {
        return $this->guard(function () use ($keyId, $languageKey, $value, $overwrite): bool {
            $table = $this->table('translations');
            $columns = '(key_id, language, ' . $this->quote('value') . ', created, updated)';
            $now = time();

            if ($overwrite === false) {
                $sql = match ($this->driver()) {
                    'pgsql' => "INSERT INTO {$table} {$columns} VALUES (?, ?, ?, ?, ?)"
                        . ' ON CONFLICT (key_id, language) DO NOTHING',
                    'mysql' => "INSERT IGNORE INTO {$table} {$columns} VALUES (?, ?, ?, ?, ?)",
                    'sqlite' => "INSERT OR IGNORE INTO {$table} {$columns} VALUES (?, ?, ?, ?, ?)",
                };
            } else {
                $value_ = $this->quote('value');
                $sql = match ($this->driver()) {
                    'pgsql', 'sqlite' => "INSERT INTO {$table} {$columns} VALUES (?, ?, ?, ?, ?)"
                        . " ON CONFLICT (key_id, language) DO UPDATE SET {$value_} = excluded.{$value_},"
                        . ' updated = excluded.updated',
                    // The VALUES() form this replaces has been deprecated since mysql
                    // 8.0.20; the row alias is the supported spelling
                    'mysql' => "INSERT INTO {$table} {$columns} VALUES (?, ?, ?, ?, ?) AS new"
                        . " ON DUPLICATE KEY UPDATE {$value_} = new.{$value_}, updated = new.updated",
                };
            }

            Db::query($sql, [$keyId, $languageKey, $value, $now, $now], $this->connection);

            return true;
        }, false);
    }

    /**
     * Write a translation addressed by its source text, registering the key if needed.
     *
     * @access public
     * @param  string $key         Source text
     * @param  string $languageKey
     * @param  string $value
     * @return bool
     */
    public function setTranslation(string $key, string $languageKey, string $value): bool
    {
        $id = $this->ensureKey($key);
        if ($id === null) {
            return false;
        }

        return $this->putTranslation($id, $languageKey, $value);
    }

    /**
     * Languages that have at least one translation row, with their row counts.
     *
     * @access public
     * @return array<string, int>
     */
    public function languages(): array
    {
        return $this->guard(function (): array {
            $rows = Db::query(
                'SELECT language, COUNT(*) AS total FROM ' . $this->table('translations')
                . ' GROUP BY language ORDER BY language',
                [],
                $this->connection
            )->fetchAll(PDO::FETCH_ASSOC);

            $counts = [];
            foreach ($rows as $row) {
                $counts[(string) $row['language']] = (int) $row['total'];
            }

            return $counts;
        }, []);
    }

    /*
     * =============================================== Freshness =======================================================
     */

    /**
     * Whether a warmed copy of this language may be trusted.
     *
     * The flag lives in the database rather than beside the cached copy so that one
     * translator saving one string invalidates every application server at once, without
     * any of them being told.
     *
     * @access public
     * @param  string $languageKey
     * @return bool
     */
    public function isFresh(string $languageKey): bool
    {
        return $this->guard(function () use ($languageKey): bool {
            $row = Db::query(
                'SELECT id FROM ' . $this->table('cached') . ' WHERE id = ?',
                [$languageKey],
                $this->connection
            )->fetch(PDO::FETCH_ASSOC);

            return $row !== false && $row !== null;
        }, false);
    }

    /**
     * @access public
     * @param  string $languageKey
     * @return void
     */
    public function markFresh(string $languageKey): void
    {
        $this->guard(function () use ($languageKey): bool {
            $table = $this->table('cached');
            $sql = match ($this->driver()) {
                'pgsql' => "INSERT INTO {$table} (id, created) VALUES (?, ?) ON CONFLICT (id) DO NOTHING",
                'mysql' => "INSERT IGNORE INTO {$table} (id, created) VALUES (?, ?)",
                'sqlite' => "INSERT OR IGNORE INTO {$table} (id, created) VALUES (?, ?)",
            };

            Db::query($sql, [$languageKey, time()], $this->connection);

            return true;
        }, false);
    }

    /**
     * @access public
     * @param  ?string $languageKey Null to invalidate every language
     * @return void
     */
    public function markStale(?string $languageKey = null): void
    {
        $this->guard(function () use ($languageKey): bool {
            if ($languageKey === null) {
                Db::query('DELETE FROM ' . $this->table('cached') . ' WHERE 1 = 1', [], $this->connection);

                return true;
            }

            Db::query(
                'DELETE FROM ' . $this->table('cached') . ' WHERE id = ?',
                [$languageKey],
                $this->connection
            );

            return true;
        }, false);
    }

    /*
     * =============================================== Internals =======================================================
     */

    /**
     * Qualified and quoted name of one of the three tables.
     *
     * @access private
     * @param  string $which One of: keys, translations, cached
     * @return string
     */
    private function table(string $which): string
    {
        $defaults = ['keys' => 'i18n_keys', 'translations' => 'i18n_translations', 'cached' => 'i18n_cached'];
        $name = (string) ($this->tables[$which] ?? $defaults[$which]);

        return ($this->scheme === '' ? '' : $this->quote($this->scheme) . '.') . $this->quote($name);
    }

    /**
     * Validate an identifier and wrap it for the current driver.
     *
     * Rejected rather than escaped, the same way Db::wrapColumn() handles it - an
     * identifier that is not a plain identifier is a configuration mistake, and quietly
     * rewriting it would only hide where the mistake is.
     *
     * @access private
     * @param  string $identifier
     * @return string
     * @throws TranslationError
     */
    private function quote(string $identifier): string
    {
        if (preg_match(self::IDENTIFIER, $identifier) !== 1) {
            throw new TranslationError("Invalid i18n identifier \"{$identifier}\"");
        }

        return $this->driver() === 'mysql' ? "`{$identifier}`" : "\"{$identifier}\"";
    }

    /**
     * Run a database call, degrading instead of throwing unless strict mode says otherwise.
     *
     * TranslationError is always rethrown: it only ever means the configuration is wrong,
     * and there is no useful page to render past that.
     *
     * @access private
     * @param  callable $work
     * @param  mixed    $onFailure Returned when the call fails and strict mode is off
     * @return mixed
     */
    private function guard(callable $work, mixed $onFailure = null): mixed
    {
        try {
            return $work();
        } catch (TranslationError $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($this->strict === true) {
                throw $e;
            }

            // Logged once. A database that is down fails every string on the page, and a
            // few thousand identical lines per request helps nobody
            if ($this->degraded === false) {
                $this->degraded = true;
                $this->lastError = $e->getMessage();
                error_log('i18n: ' . $e->getMessage());
            }

            return $onFailure;
        }
    }
}
