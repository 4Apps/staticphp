<?php

namespace System\Modules\Utils\Models\Translation;

use System\Modules\Utils\Models\Cache\Cache;

/**
 * Warmed string tables, one per language key.
 *
 * The database holds a per-language freshness flag; this reads it, and only goes back to
 * the string tables when the flag says the warmed copy is stale or the warmed copy has
 * been evicted.
 */
final class Catalog
{
    /**
     * Tables already materialised during this request.
     *
     * @var array<string, array<string, ?string>>
     * @access private
     */
    private array $loaded = [];

    /**
     * @access public
     * @param Store   $store
     * @param string  $mode      One of: external, internal, none
     * @param string  $prefix    Prepended to cache keys and generated file names
     * @param ?string $directory Where the internal cache writes, null to disable it
     * @param ?int    $ttl       Seconds to keep an external cache entry
     */
    public function __construct(
        private readonly Store $store,
        private readonly string $mode = 'external',
        private readonly string $prefix = 'language_',
        private readonly ?string $directory = null,
        private readonly ?int $ttl = null,
    ) {
    }

    /**
     * Whole string table for one language.
     *
     * @access public
     * @param  string $languageKey
     * @return array<string, ?string>
     */
    public function strings(string $languageKey): array
    {
        if (isset($this->loaded[$languageKey]) === true) {
            return $this->loaded[$languageKey];
        }

        // With no warming there is nothing for the freshness flag to describe, so asking
        // about it is a round trip that can only ever be answered "reload anyway"
        if ($this->mode === 'none') {
            return $this->loaded[$languageKey] = $this->store->translations($languageKey);
        }

        if ($this->store->isFresh($languageKey) === true) {
            $cached = $this->read($languageKey);
            if ($cached !== null) {
                return $this->loaded[$languageKey] = $cached;
            }
        }

        $strings = $this->store->translations($languageKey);

        // A degraded store returns an empty table, which is not the same thing as a language
        // with no strings. Warming that would cache the outage and mark it authoritative
        if ($this->store->isDegraded() === false) {
            $this->write($languageKey, $strings);
            $this->store->markFresh($languageKey);
        }

        return $this->loaded[$languageKey] = $strings;
    }

    /**
     * Whether a table has already been materialised this request.
     *
     * @access public
     * @param  string $languageKey
     * @return bool
     */
    public function isLoaded(string $languageKey): bool
    {
        return isset($this->loaded[$languageKey]);
    }

    /**
     * Remember a string without going back to the database for it.
     *
     * @access public
     * @param  string  $languageKey
     * @param  string  $key
     * @param  ?string $value
     * @return void
     */
    public function remember(string $languageKey, string $key, ?string $value): void
    {
        $this->loaded[$languageKey][$key] = $value;
    }

    /**
     * Mark a language stale everywhere and drop what this request had of it.
     *
     * @access public
     * @param  ?string $languageKey Null for every language
     * @return void
     */
    public function invalidate(?string $languageKey = null): void
    {
        $this->store->markStale($languageKey);

        if ($languageKey === null) {
            $this->loaded = [];

            return;
        }

        unset($this->loaded[$languageKey]);
    }

    /*
     * =============================================== Backends ========================================================
     */

    /**
     * @access private
     * @param  string $languageKey
     * @return ?array<string, ?string> Null on a miss
     */
    private function read(string $languageKey): ?array
    {
        if ($this->mode === 'none') {
            return null;
        }

        if ($this->mode === 'internal') {
            $file = $this->file($languageKey);
            if ($file === null || is_file($file) === false) {
                return null;
            }

            $strings = include $file;

            return is_array($strings) === true ? $strings : null;
        }

        try {
            $strings = Cache::get($this->prefix . $languageKey);
        } catch (\Throwable $e) {
            // No backend registered, or the backend is unreachable. Either way this is a
            // miss, and the database below still has the strings
            return null;
        }

        return is_array($strings) === true ? $strings : null;
    }

    /**
     * @access private
     * @param  string                 $languageKey
     * @param  array<string, ?string> $strings
     * @return void
     */
    private function write(string $languageKey, array $strings): void
    {
        if ($this->mode === 'none') {
            return;
        }

        if ($this->mode === 'internal') {
            $this->writeFile($languageKey, $strings);

            return;
        }

        try {
            Cache::set($this->prefix . $languageKey, $strings, $this->ttl);
        } catch (\Throwable $e) {
            // Same as a read failure: the strings are still in the database
        }
    }

    /**
     * Generate the php file holding one language.
     *
     * Written to a sibling temp file and renamed into place. rename() is atomic within a
     * filesystem, so a concurrent request either includes the whole previous file or the
     * whole new one - never the half of a new one that has been flushed so far.
     *
     * var_export() rather than hand rolled quoting: the writer this replaced escaped
     * apostrophes with str_replace and ran the value through stripslashes first, so a
     * translation ending in a backslash emitted 'foo\'; and every subsequent request died
     * on a parse error.
     *
     * @access private
     * @param  string                 $languageKey
     * @param  array<string, ?string> $strings
     * @return void
     */
    private function writeFile(string $languageKey, array $strings): void
    {
        $file = $this->file($languageKey);
        if ($file === null) {
            return;
        }

        $directory = dirname($file);
        if (is_dir($directory) === false && mkdir($directory, 0775, true) === false && is_dir($directory) === false) {
            return;
        }

        $temporary = $file . '.' . getmypid() . '.tmp';
        $contents = "<?php\n\n// Generated by staticphp i18n, do not edit\n\nreturn "
            . var_export($strings, true) . ";\n";

        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            return;
        }

        if (rename($temporary, $file) === false) {
            unlink($temporary);

            return;
        }

        if (function_exists('opcache_invalidate') === true) {
            opcache_invalidate($file, true);
        }
    }

    /**
     * @access private
     * @param  string $languageKey
     * @return ?string
     */
    private function file(string $languageKey): ?string
    {
        if ($this->directory === null) {
            return null;
        }

        // The key reaches the filesystem, and it is built from configuration rather than
        // from the url - but a traversal here would write php into an arbitrary directory,
        // so it is checked rather than trusted
        if (preg_match('/^[A-Za-z0-9_-]+$/', $languageKey) !== 1) {
            throw new TranslationError("Invalid i18n language key \"{$languageKey}\"");
        }

        return rtrim($this->directory, '/') . '/' . $this->prefix . $languageKey . '.php';
    }
}
