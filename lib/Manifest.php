<?php

namespace StaticPHP\Skeleton;

/**
 * Where a project's files came from.
 *
 * The entire persisted state of the upgrade feature: an origin url and a tag per tree. It
 * is tracked in git, but nothing depends on it surviving - when it is missing, scoring the
 * project against the origin's tags reconstructs it, which is the same code path that
 * retrofits a project created before any of this existed.
 *
 * `pinned` is the one per-file entry. A file the user declined during an upgrade keeps the
 * tag it was declined at as its merge base, so the next run offers it again rather than
 * concluding the decision was permanent. Normally empty; losing it costs one extra prompt.
 */
class Manifest
{
    public const DIR = '.staticphp';
    public const FILE = '.staticphp/manifest.json';
    public const DEFAULT_ORIGIN = 'https://github.com/4Apps/staticphp.git';

    /**
     * @param array<string, array{preset: string, version: string|null}> $apps
     * @param array<string, string> $pinned
     */
    public function __construct(
        public string $origin = self::DEFAULT_ORIGIN,
        public ?string $skeleton = null,
        public array $apps = [],
        public array $pinned = [],
    ) {
    }

    public static function exists(string $root): bool
    {
        return is_file($root . '/' . self::FILE);
    }

    /**
     * Never fails. An unreadable or corrupt manifest is treated as an absent one, because
     * the recovery for both is the same and refusing to run would leave no way back.
     */
    public static function load(string $root): self
    {
        $raw = @file_get_contents($root . '/' . self::FILE);
        if ($raw === false) {
            return new self();
        }

        $data = json_decode($raw, true);
        if (is_array($data) === false) {
            return new self();
        }

        $origin = (is_string($data['origin'] ?? null) && $data['origin'] !== '')
            ? $data['origin']
            : self::DEFAULT_ORIGIN;

        $skeleton = (is_string($data['skeleton'] ?? null) && $data['skeleton'] !== '')
            ? $data['skeleton']
            : null;

        return new self(
            $origin,
            $skeleton,
            self::readApps($data['apps'] ?? null),
            self::readPinned($data['pinned'] ?? null),
        );
    }

    /**
     * @throws \RuntimeException
     */
    public function save(string $root): void
    {
        $dir = $root . '/' . self::DIR;
        if (is_dir($dir) === false && mkdir($dir, 0755, true) === false && is_dir($dir) === false) {
            throw new \RuntimeException("Could not create {$dir}");
        }

        ksort($this->apps);
        ksort($this->pinned);

        $data = [
            'version' => 1,
            'origin' => $this->origin,
            'skeleton' => $this->skeleton,
            'apps' => (object) $this->apps,
            'pinned' => (object) $this->pinned,
        ];

        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || file_put_contents($root . '/' . self::FILE, $encoded . "\n") === false) {
            throw new \RuntimeException('Could not write ' . self::FILE);
        }
    }

    public function appVersion(string $name): ?string
    {
        return $this->apps[$name]['version'] ?? null;
    }

    public function appPreset(string $name): ?string
    {
        return $this->apps[$name]['preset'] ?? null;
    }

    public function setApp(string $name, string $preset, ?string $version): void
    {
        $this->apps[$name] = ['preset' => $preset, 'version' => $version];
    }

    /**
     * The merge base for one file: whatever it was pinned at, otherwise the tree's own tag.
     */
    public function baseFor(string $path, string $fallback): string
    {
        return $this->pinned[$path] ?? $fallback;
    }

    public function pin(string $path, string $tag): void
    {
        $this->pinned[$path] = $tag;
    }

    public function unpin(string $path): void
    {
        unset($this->pinned[$path]);
    }

    /**
     * @return array<string, array{preset: string, version: string|null}>
     */
    private static function readApps(mixed $value): array
    {
        if (is_array($value) === false) {
            return [];
        }

        $apps = [];
        foreach ($value as $name => $entry) {
            if (is_string($name) === false || is_array($entry) === false) {
                continue;
            }

            $preset = $entry['preset'] ?? null;
            if (is_string($preset) === false) {
                continue;
            }

            // A version of null is normal rather than broken: scaffolding knows which preset
            // it used but not which release it was, so the first upgrade detects that
            $version = $entry['version'] ?? null;

            $apps[$name] = ['preset' => $preset, 'version' => (is_string($version) ? $version : null)];
        }

        return $apps;
    }

    /**
     * @return array<string, string>
     */
    private static function readPinned(mixed $value): array
    {
        if (is_array($value) === false) {
            return [];
        }

        $pinned = [];
        foreach ($value as $path => $tag) {
            if (is_string($path) && is_string($tag)) {
                $pinned[$path] = $tag;
            }
        }

        return $pinned;
    }
}
