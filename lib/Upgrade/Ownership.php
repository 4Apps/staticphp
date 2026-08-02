<?php

namespace StaticPHP\Skeleton\Upgrade;

/**
 * Which files an upgrade is allowed to touch.
 *
 * Three lists, loaded from upgrade.json:
 *
 *   ignore   never examined at all
 *   once     recorded at creation and never upgraded again
 *   strip    removed when a project is generated, so never offered back
 *
 * The rules ship with the skeleton rather than being compiled into the upgrader, and they
 * are read from the version being upgraded *to*. That is deliberate: the incoming release
 * is the one that knows what it owns, and a project created before this file existed still
 * upgrades correctly instead of falling back to guesswork.
 *
 * Patterns are anchored at the tree root. A trailing slash matches a directory and
 * everything beneath it; `*` matches one path segment.
 */
class Ownership
{
    /**
     * Used when upgrade.json is missing on both sides - a project generated before the file
     * existed, upgrading to a release that predates it. Conservative on purpose: the entries
     * that would destroy work if forgotten, and nothing that is merely tidy.
     *
     * @var array<string, list<string>>
     */
    private const FALLBACK = [
        'ignore' => ['/src/*/', '/.env', '/.staticphp/', '/vendor/', '/node_modules/'],
        'once' => ['/README.md', '/.version'],
        'strip' => [],
    ];

    /**
     * @param list<string> $ignore
     * @param list<string> $once
     * @param list<string> $strip
     */
    private function __construct(
        private array $ignore,
        private array $once,
        private array $strip,
    ) {
    }

    /**
     * @param string[] $candidates Paths to upgrade.json, best source first; the first
     *                             readable one wins
     */
    public static function load(array $candidates, bool $forApp = false): self
    {
        foreach ($candidates as $path) {
            $decoded = self::decode($path);
            if ($decoded !== null) {
                return self::fromArray($decoded, $forApp);
            }
        }

        if ($forApp === true) {
            return new self(['/.env', '/Cache/', '/Files/'], [], []);
        }

        return new self(self::FALLBACK['ignore'], self::FALLBACK['once'], self::FALLBACK['strip']);
    }

    /**
     * Rules straight out of the release being upgraded to, read from the repository rather
     * than from a file on disk.
     *
     * An application upgrade compares against a composed preset, and a composed preset never
     * contains upgrade.json - so without this the rules would quietly come from whatever the
     * project happens to have, which is the version being replaced.
     *
     * @return self|null Null when there is nothing usable, so the caller can fall back
     */
    public static function fromJson(?string $json, bool $forApp = false): ?self
    {
        if ($json === null || trim($json) === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        if (is_array($decoded) === false) {
            return null;
        }

        return self::fromArray(self::stringKeys($decoded), $forApp);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private static function fromArray(array $decoded, bool $forApp): self
    {
        // An application is a subtree with its own rules; the root lists describe paths that
        // do not exist inside one
        if ($forApp === true) {
            $nested = $decoded['app'] ?? null;
            $decoded = (is_array($nested) ? self::stringKeys($nested) : []);
        }

        return new self(
            self::stringList($decoded['ignore'] ?? null),
            self::stringList($decoded['once'] ?? null),
            self::stringList($decoded['strip'] ?? null),
        );
    }

    /**
     * @param list<string> $ignore
     * @param list<string> $once
     * @param list<string> $strip
     */
    public static function of(array $ignore = [], array $once = [], array $strip = []): self
    {
        return new self($ignore, $once, $strip);
    }

    /**
     * Ignored and stripped paths both mean "do not consider this file", but for different
     * reasons, so they stay separate lists and are only merged at the point of asking.
     */
    public function skips(string $path): bool
    {
        return $this->isIgnored($path) || $this->isStripped($path);
    }

    public function isIgnored(string $path): bool
    {
        return self::matchesAny($path, $this->ignore);
    }

    public function isOnce(string $path): bool
    {
        return self::matchesAny($path, $this->once);
    }

    public function isStripped(string $path): bool
    {
        return self::matchesAny($path, $this->strip);
    }

    /**
     * @return list<string>
     */
    public function stripList(): array
    {
        return $this->strip;
    }

    /**
     * @param list<string> $patterns
     */
    private static function matchesAny(string $path, array $patterns): bool
    {
        $path = ltrim($path, '/');

        foreach ($patterns as $pattern) {
            if (preg_match(self::toRegex($pattern), $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * A trailing slash means "this directory and everything under it"; without one the
     * pattern has to match the whole path. A star stops at a slash, so a one-segment
     * wildcard cannot swallow a nested path it was never meant to describe.
     */
    private static function toRegex(string $pattern): string
    {
        $pattern = ltrim($pattern, '/');
        $directory = str_ends_with($pattern, '/');
        $body = rtrim($pattern, '/');

        $escaped = str_replace('\*', '[^/]+', preg_quote($body, '#'));

        return '#^' . $escaped . ($directory ? '/' : '$') . '#';
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(string $path): ?array
    {
        if (is_file($path) === false) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return (is_array($decoded) ? self::stringKeys($decoded) : null);
    }

    /**
     * json_decode gives back array<mixed, mixed>; everything downstream wants string keys.
     *
     * @param  array<mixed, mixed> $values
     * @return array<string, mixed>
     */
    private static function stringKeys(array $values): array
    {
        $keyed = [];
        foreach ($values as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (is_array($value) === false) {
            return [];
        }

        $list = [];
        foreach ($value as $entry) {
            if (is_string($entry) && $entry !== '') {
                $list[] = $entry;
            }
        }

        return $list;
    }
}
