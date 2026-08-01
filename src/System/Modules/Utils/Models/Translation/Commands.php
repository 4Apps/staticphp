<?php

namespace System\Modules\Utils\Models\Translation;

/**
 * What `staticphp i18n` actually does.
 *
 * Output goes through an injected callable rather than echo, so the tests drive the same
 * code paths the command line does.
 */
final class Commands
{
    /**
     * @var callable
     * @access private
     */
    private $out;

    /**
     * @access public
     * @param Store    $store
     * @param Locales  $locales
     * @param array    $config Contents of config['i18n']
     * @param callable $out    Receives one line at a time, without its newline
     */
    public function __construct(
        private readonly Store $store,
        private readonly Locales $locales,
        private readonly array $config,
        callable $out,
    ) {
        $this->out = $out;
    }

    /**
     * Configured languages, how much of each is translated, and what is in the way.
     *
     * @access public
     * @param  bool $check Exit 1 when anything is untranslated
     * @return int
     */
    public function status(bool $check = false): int
    {
        if ($this->requireSchema() === false) {
            return 1;
        }

        $keys = $this->store->keys();
        $total = count($keys);

        $this->line('driver: ' . $this->store->driver());
        $this->line("keys:   {$total}");
        $this->line('');

        if ($total === 0) {
            $this->line('Nothing registered yet. Strings appear here the first time a page asks for them.');

            return 0;
        }

        $this->line(sprintf('%-12s %-10s %8s %8s  %s', 'LANGUAGE', 'ICU', 'DONE', 'MISSING', 'STATE'));

        $outstanding = 0;
        foreach ($this->locales->all() as $locale) {
            $missing = count($this->missingFor($locale->key()));
            $done = $total - $missing;
            $outstanding += $missing;

            $this->line(sprintf(
                '%-12s %-10s %8d %8d  %s',
                $locale->key(),
                $locale->icuLocale,
                $done,
                $missing,
                $missing === 0 ? 'complete' : 'INCOMPLETE'
            ));
        }

        $orphans = array_diff(
            array_keys($this->store->languages()),
            array_map(fn(Locale $locale): string => $locale->key(), $this->locales->all())
        );

        if ($orphans !== []) {
            $this->line('');
            $this->line('Languages with rows but no configuration: ' . implode(', ', $orphans));
        }

        if ($check === true && $outstanding > 0) {
            return 1;
        }

        return 0;
    }

    /**
     * List the untranslated keys for one language, or for every configured one.
     *
     * @access public
     * @param  ?string $languageKey
     * @return int
     */
    public function missing(?string $languageKey = null): int
    {
        if ($this->requireSchema() === false) {
            return 1;
        }

        $languageKeys = $languageKey !== null
            ? [$languageKey]
            : array_map(fn(Locale $locale): string => $locale->key(), $this->locales->all());

        foreach ($languageKeys as $key) {
            $missing = $this->missingFor($key);

            $this->line("# {$key} (" . count($missing) . ')');
            foreach ($missing as $source) {
                $this->line('  ' . $source);
            }
            $this->line('');
        }

        return 0;
    }

    /**
     * @access public
     * @return int
     */
    public function keys(): int
    {
        if ($this->requireSchema() === false) {
            return 1;
        }

        foreach ($this->store->keys() as $key) {
            $this->line($key['key']);
        }

        return 0;
    }

    /**
     * @access public
     * @param  string $languageKey
     * @param  string $key
     * @param  string $value
     * @return int
     */
    public function set(string $languageKey, string $key, string $value): int
    {
        if ($this->requireSchema() === false) {
            return 1;
        }

        if ($this->store->setTranslation($key, $languageKey, $value) === false) {
            $this->line('error: could not write the translation');

            return 1;
        }

        $this->store->markStale($languageKey);
        $this->line("{$languageKey}: " . $this->truncate($key) . ' -> ' . $this->truncate($value));

        return 0;
    }

    /**
     * Write one language out as csv or json.
     *
     * @access public
     * @param  string  $languageKey
     * @param  string  $format csv or json
     * @param  ?string $file   Null writes to the output callable
     * @return int
     */
    public function export(string $languageKey, string $format = 'csv', ?string $file = null): int
    {
        if ($this->requireSchema() === false) {
            return 1;
        }

        $strings = $this->store->translations($languageKey);

        if ($format === 'json') {
            $body = (string) json_encode($strings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif ($format === 'csv') {
            $handle = fopen('php://temp', 'r+');
            fputcsv($handle, ['key', 'value'], ',', '"', '');
            foreach ($strings as $key => $value) {
                fputcsv($handle, [$key, $value ?? ''], ',', '"', '');
            }
            rewind($handle);
            $body = (string) stream_get_contents($handle);
            fclose($handle);
        } else {
            $this->line("error: unknown format \"{$format}\", expected csv or json");

            return 2;
        }

        if ($file === null) {
            $this->line(rtrim($body, "\n"));

            return 0;
        }

        if (file_put_contents($file, $body) === false) {
            $this->line("error: could not write {$file}");

            return 1;
        }

        $this->line(count($strings) . " strings written to {$file}");

        return 0;
    }

    /**
     * Read a csv or json file back into one language.
     *
     * @access public
     * @param  string $languageKey
     * @param  string $file
     * @param  string $format    csv, json, or auto to pick from the extension
     * @param  bool   $overwrite Replace translations that are already there
     * @return int
     */
    public function import(string $languageKey, string $file, string $format = 'auto', bool $overwrite = false): int
    {
        if ($this->requireSchema() === false) {
            return 1;
        }

        if (is_file($file) === false) {
            $this->line("error: no such file {$file}");

            return 2;
        }

        if ($format === 'auto') {
            $format = strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'json' ? 'json' : 'csv';
        }

        $rows = $format === 'json' ? $this->readJson($file) : $this->readCsv($file);
        if ($rows === null) {
            $this->line("error: could not read {$file} as {$format}");

            return 2;
        }

        $existing = $overwrite === true ? [] : $this->store->translations($languageKey);

        $written = 0;
        $skipped = 0;
        foreach ($rows as $key => $value) {
            if ($key === '') {
                continue;
            }

            if ($overwrite === false && ($existing[$key] ?? null) !== null) {
                ++$skipped;
                continue;
            }

            $id = $this->store->ensureKey($key);
            if ($id === null || $this->store->putTranslation($id, $languageKey, $value) === false) {
                $this->line('error: could not write ' . $this->truncate($key));

                return 1;
            }

            ++$written;
        }

        $this->store->markStale($languageKey);
        $this->line("{$languageKey}: {$written} written, {$skipped} left alone");

        return 0;
    }

    /**
     * Compare the source tree against the database.
     *
     * @access public
     * @param  string[] $paths
     * @param  bool     $write Register keys the source has and the database does not
     * @return int
     */
    public function scan(array $paths, bool $write = false): int
    {
        if ($this->requireSchema() === false) {
            return 1;
        }

        $found = Scanner::scan($paths);
        $known = array_column($this->store->keys(), 'key');

        $new = array_diff(array_keys($found), $known);
        $unused = array_diff($known, array_keys($found));

        $this->line('found in source:  ' . count($found));
        $this->line('registered:       ' . count($known));
        $this->line('');

        $this->line('# in source, not registered (' . count($new) . ')');
        foreach ($new as $key) {
            $this->line('  ' . $this->truncate($key) . '   ' . $found[$key][0]);
        }

        $this->line('');
        $this->line('# registered, not found in source (' . count($unused) . ')');
        foreach ($unused as $key) {
            $this->line('  ' . $this->truncate($key));
        }

        // Said out loud rather than left for someone to discover: a key built at runtime is
        // invisible here, so "not found in source" is a shortlist to review, not a verdict
        $this->line('');
        $this->line('Only literal strings are visible to the scanner. Check before pruning.');

        if ($write === false || $new === []) {
            return 0;
        }

        $default = $this->locales->default()->key();
        $suffix = (string) ($this->config['missing_suffix'] ?? '*');
        foreach ($new as $key) {
            $id = $this->store->ensureKey($key);
            if ($id !== null) {
                $this->store->putTranslation($id, $default, $key . $suffix, false);
            }
        }

        $this->store->markStale();
        $this->line('');
        $this->line(count($new) . " keys registered under {$default}");

        return 0;
    }

    /**
     * Delete keys that the source tree no longer references.
     *
     * @access public
     * @param  string[] $paths
     * @param  bool     $yes
     * @param  callable $prompt Asked for confirmation, returns the answer
     * @return int
     */
    public function prune(array $paths, bool $yes, callable $prompt): int
    {
        if ($this->requireSchema() === false) {
            return 1;
        }

        $found = Scanner::scan($paths);
        $unused = array_values(array_diff(array_column($this->store->keys(), 'key'), array_keys($found)));

        if ($unused === []) {
            $this->line('Nothing to prune.');

            return 0;
        }

        foreach ($unused as $key) {
            $this->line('  ' . $this->truncate($key));
        }

        $this->line('');
        $this->line(count($unused) . ' keys, and every translation of them, would be deleted.');
        $this->line('A key built at runtime looks unused here. Read the list.');

        if ($yes === false) {
            $answer = strtolower(trim((string) $prompt('Delete them? [y/N] ')));
            if ($answer !== 'y' && $answer !== 'yes') {
                $this->line('Nothing deleted.');

                return 0;
            }
        }

        $deleted = 0;
        foreach ($unused as $key) {
            if ($this->store->deleteKey($key) === true) {
                ++$deleted;
            }
        }

        $this->store->markStale();
        $this->line("{$deleted} keys deleted.");

        return 0;
    }

    /**
     * Mark warmed copies stale.
     *
     * @access public
     * @param  ?string $languageKey Null for every language
     * @return int
     */
    public function clear(?string $languageKey = null): int
    {
        $this->store->markStale($languageKey);
        $this->line('Marked ' . ($languageKey ?? 'every language') . ' stale.');

        return 0;
    }

    /**
     * Copy the schema for this driver into the migrations directory.
     *
     * It becomes a migration rather than something this command applies itself, so that the
     * schema arrives on every environment the same way the rest of the schema does, and so
     * that "has i18n been installed here" has the same answer as every other table.
     *
     * @access public
     * @param  bool   $upgrade       Emit the upgrade from the pre-existing schema instead
     * @param  string $migrationsDir
     * @param  string $filesDir      Where the templates live
     * @param  int    $now
     * @return int
     */
    public function install(bool $upgrade, string $migrationsDir, string $filesDir, int $now): int
    {
        $driver = $this->store->driver();
        $kind = $upgrade === true ? 'upgrade' : 'install';
        $template = rtrim($filesDir, '/') . "/{$kind}.{$driver}.sql";

        if (is_file($template) === false) {
            $this->line("error: no {$kind} template for {$driver}");
            if ($upgrade === true) {
                $this->line('The schema this upgrades from only ever shipped for postgres.');
            }

            return 2;
        }

        if (is_dir($migrationsDir) === false) {
            $this->line("error: no migrations directory at {$migrationsDir}");

            return 2;
        }

        $name = gmdate('Y-m-d-His', $now) . '-i18n-' . $kind . '.sql';
        $target = rtrim($migrationsDir, '/') . '/' . $name;

        if (is_file($target) === true) {
            $this->line("error: {$target} already exists");

            return 1;
        }

        if (copy($template, $target) === false) {
            $this->line("error: could not write {$target}");

            return 1;
        }

        $this->line("Wrote {$target}");
        $this->line('Review it, then: staticphp migrate apply');

        return 0;
    }

    /*
     * =============================================== Internals =======================================================
     */

    /**
     * Keys with nothing usable in them for a language.
     *
     * The auto-registered placeholder counts as missing - it is the source text with a
     * marker on it, which is exactly what "nobody has translated this" looks like.
     *
     * @access private
     * @param  string $languageKey
     * @return string[]
     */
    private function missingFor(string $languageKey): array
    {
        $suffix = (string) ($this->config['missing_suffix'] ?? '*');
        $missing = [];

        foreach ($this->store->translations($languageKey) as $key => $value) {
            if ($value === null || $value === '' || $value === $key . $suffix) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * @access private
     * @return bool
     */
    private function requireSchema(): bool
    {
        if ($this->store->isInstalled() === true) {
            return true;
        }

        $this->line('error: the i18n tables are not there');
        $this->line('  staticphp i18n install     writes the schema as a migration');
        $this->line('  staticphp migrate apply    applies it');

        return false;
    }

    /**
     * @access private
     * @param  string $file
     * @return ?array<string, string>
     */
    private function readJson(string $file): ?array
    {
        $decoded = json_decode((string) file_get_contents($file), true);
        if (is_array($decoded) === false) {
            return null;
        }

        $rows = [];
        foreach ($decoded as $key => $value) {
            $rows[(string) $key] = (string) ($value ?? '');
        }

        return $rows;
    }

    /**
     * @access private
     * @param  string $file
     * @return ?array<string, string>
     */
    private function readCsv(string $file): ?array
    {
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return null;
        }

        $rows = [];
        $first = true;
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }

            // Skip the header this command's own export writes, but do not require one
            if ($first === true) {
                $first = false;
                if (($row[0] ?? '') === 'key' && ($row[1] ?? '') === 'value') {
                    continue;
                }
            }

            $rows[(string) ($row[0] ?? '')] = (string) ($row[1] ?? '');
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @access private
     * @param  string $text
     * @param  int    $length
     * @return string
     */
    private function truncate(string $text, int $length = 70): string
    {
        $text = str_replace(["\n", "\r"], ' ', $text);

        return mb_strlen($text) <= $length ? $text : mb_substr($text, 0, $length - 1) . "\u{2026}";
    }

    /**
     * @access private
     * @param  string $line
     * @return void
     */
    private function line(string $line = ''): void
    {
        ($this->out)($line);
    }
}
