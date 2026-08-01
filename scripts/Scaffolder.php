<?php

namespace StaticPHP\Skeleton;

/**
 * Creates an application under src/ from a preset.
 *
 * One implementation, three callers: `staticphp app add`, the composer
 * post-create-project hook that lays down the first application, and CI, which
 * instantiates every preset so that the one nobody used this quarter is still known to
 * work.
 *
 * A preset is two directory trees copied on top of each other - presets/_base, then
 * presets/<name> - and nothing else. No config merging: rspack discovers applications by
 * globbing src/&#42;/Public/assets/src, the test and typecheck loops glob src/&#42; too, so an
 * application becomes visible to the toolchain by existing. The one exception is npm
 * dependencies, which are genuinely shared and live in the root package.json; a preset
 * declares what it needs in preset.json and they are merged in.
 */
class Scaffolder
{
    /**
     * Characters allowed in an application name.
     *
     * It becomes a directory under src/ and a path segment in generated urls, so keep it
     * to something that cannot walk out of the tree or need escaping.
     */
    private const NAME_PATTERN = '/^[A-Z][A-Za-z0-9]*$/';

    public function __construct(
        private string $root,
    ) {
    }

    /**
     * @return string[] Preset names, _base excluded - it is not a choice, it is the floor
     */
    public function presets(): array
    {
        $dir = $this->root . '/presets';
        if (is_dir($dir) === false) {
            return [];
        }

        $names = [];
        foreach ((array) scandir($dir) as $entry) {
            if (is_string($entry) === false || $entry[0] === '.' || $entry === '_base') {
                continue;
            }
            if (is_dir("{$dir}/{$entry}")) {
                $names[] = $entry;
            }
        }

        sort($names);

        return $names;
    }

    public function presetDescription(string $preset): string
    {
        $meta = $this->presetMeta($preset);

        return (is_string($meta['description'] ?? null) ? $meta['description'] : '');
    }

    /**
     * @return string[] Applications already present under src/
     */
    public function apps(): array
    {
        $dir = $this->root . '/src';
        if (is_dir($dir) === false) {
            return [];
        }

        $names = [];
        foreach ((array) scandir($dir) as $entry) {
            if (is_string($entry) === false || $entry[0] === '.') {
                continue;
            }
            // A directory is an application when it has a front controller
            if (is_file("{$dir}/{$entry}/Public/index.php")) {
                $names[] = $entry;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * Lay a preset down as src/<name>.
     *
     * @param  bool $force Overlay onto an existing application instead of refusing
     * @return string[] Lines describing what happened, for the caller to print
     * @throws \RuntimeException
     */
    public function create(string $name, string $preset, bool $force = false): array
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \RuntimeException(
                "Invalid application name \"{$name}\" - start with a capital and use letters and digits only"
            );
        }

        if (in_array($preset, $this->presets(), true) === false) {
            $known = implode(', ', $this->presets());
            throw new \RuntimeException("Unknown preset \"{$preset}\" (available: {$known})");
        }

        $target = "{$this->root}/src/{$name}";
        if (is_dir($target) && $force === false) {
            throw new \RuntimeException("src/{$name} already exists - pass --force to overlay onto it");
        }

        $log = [];
        foreach (['_base', $preset] as $layer) {
            $count = $this->copyTree("{$this->root}/presets/{$layer}", $target);
            $log[] = sprintf('  copied  presets/%s (%d files)', $layer, $count);
        }

        // preset.json describes the preset, it is not part of the application
        $leftover = "{$target}/preset.json";
        if (is_file($leftover)) {
            unlink($leftover);
        }

        // Without a .env the application boots with no APP_ENV, which reads as an unknown
        // environment - debug off, minified bundle names - and a brand new application
        // then looks broken because nothing built the .min.js files yet. It is gitignored,
        // so it has to be created rather than copied in as itself.
        if (is_file("{$target}/.env") === false && is_file("{$target}/.env.example")) {
            copy("{$target}/.env.example", "{$target}/.env");
            $log[] = '  created src/' . $name . '/.env from .env.example';
        }

        $log = array_merge($log, $this->mergeNpmDependencies($preset));

        return $log;
    }

    /**
     * Adds a preset's npm dependencies to the root package.json.
     *
     * Dependencies are shared across applications by design - one package.json, one
     * node_modules - so this is the one thing a preset cannot express by copying files.
     * Existing entries are left alone rather than downgraded.
     *
     * @return string[]
     */
    private function mergeNpmDependencies(string $preset): array
    {
        $meta = $this->presetMeta($preset);
        $wanted = (is_array($meta['npm'] ?? null) ? $meta['npm'] : []);
        if ($wanted === []) {
            return [];
        }

        $path = "{$this->root}/package.json";
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return ['  skipped package.json (not readable)'];
        }

        $package = json_decode($raw, true);
        if (is_array($package) === false) {
            return ['  skipped package.json (not parseable)'];
        }

        $log = [];
        $changed = false;
        foreach (['dependencies', 'devDependencies'] as $section) {
            $entries = (is_array($wanted[$section] ?? null) ? $wanted[$section] : []);

            // Both sides come out of json_decode, so neither the section nor the version
            // is typed until it is checked
            $current = (is_array($package[$section] ?? null) ? $package[$section] : []);

            foreach ($entries as $dependency => $constraint) {
                $dependency = (string) $dependency;
                if (isset($current[$dependency]) || is_scalar($constraint) === false) {
                    continue;
                }

                $current[$dependency] = (string) $constraint;
                $log[] = "  added   {$dependency} {$constraint} to package.json {$section}";
                $changed = true;
            }

            ksort($current);
            $package[$section] = $current;
        }

        if ($changed === false) {
            return [];
        }

        file_put_contents($path, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        return $log;
    }

    /**
     * @return string[] What to tell the user to run next
     */
    public function nextSteps(string $preset, string $name): array
    {
        $meta = $this->presetMeta($preset);
        $declared = (is_array($meta['next'] ?? null) ? $meta['next'] : []);

        $steps = [];
        foreach ($declared as $step) {
            if (is_scalar($step)) {
                $steps[] = (string) $step;
            }
        }

        $steps[] = "APP={$name} npm start   # serve it on 127.0.0.1:8081";

        return $steps;
    }

    /**
     * @return array<string, mixed>
     */
    private function presetMeta(string $preset): array
    {
        $path = "{$this->root}/presets/{$preset}/preset.json";
        if (is_file($path) === false) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return (is_array($decoded) ? $decoded : []);
    }

    /**
     * Recursive copy that does not overwrite. The preset layers are applied in order and
     * the overlay is expected to add files, not silently replace _base's.
     *
     * @return int Number of files written
     */
    private function copyTree(string $from, string $to): int
    {
        if (is_dir($from) === false) {
            return 0;
        }

        if (is_dir($to) === false && mkdir($to, 0755, true) === false) {
            throw new \RuntimeException("Could not create {$to}");
        }

        $written = 0;
        foreach ((array) scandir($from) as $entry) {
            if (is_string($entry) === false || $entry === '.' || $entry === '..') {
                continue;
            }

            $source = "{$from}/{$entry}";
            $target = "{$to}/{$entry}";

            if (is_dir($source)) {
                $written += $this->copyTree($source, $target);
                continue;
            }

            if (is_file($target)) {
                continue;
            }

            if (copy($source, $target) === false) {
                throw new \RuntimeException("Could not write {$target}");
            }

            $written++;
        }

        return $written;
    }
}
