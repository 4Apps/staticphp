<?php

namespace StaticPHP\Skeleton\Upgrade;

/**
 * Compares three directories and says what should happen to each file.
 *
 * Deliberately knows nothing about git, tags, presets or terminals. It is handed a current
 * tree, a base tree and a target tree, and that is the entire interface - which is what
 * lets `staticphp upgrade` and `staticphp app upgrade` be the same code with different
 * directories, and what lets the classification table be tested without a repository, a
 * network connection or a prompt.
 *
 * The base tree is the upstream version the project sits on, so a file the developer never
 * touched is identical to its base and resolves silently. Only files that changed on both
 * sides need a human.
 */
class Upgrader
{
    /** Upstream added a file the project does not have. */
    public const ADD = 'add';

    /** Upstream changed a file the project left alone. */
    public const UPDATE = 'update';

    /** The project changed a file upstream left alone. */
    public const KEEP = 'keep';

    /** Upstream removed a file the project left alone. */
    public const DELETE = 'delete';

    /** Both sides changed it. The only outcome that needs a decision. */
    public const CONFLICT = 'conflict';

    /** Replaced when the project was generated and never upgraded since. */
    public const ONCE = 'once';

    /** Upstream removed a file the project had changed - kept, and said out loud. */
    public const ORPHANED = 'orphaned';

    /** The project deleted it. Stays deleted. */
    public const GONE = 'gone';

    /** Decision verbs for apply(). */
    public const TAKE = 'take';
    public const DROP = 'drop';

    /**
     * Outcomes that are safe to apply without asking. ADD is here because a file the
     * project does not have cannot be destroyed by writing it.
     *
     * @var list<string>
     */
    public const SILENT = [self::ADD, self::UPDATE];

    /**
     * @param  array<string, string> $baseOverrides Path => a different base directory, for
     *                                              files declined during an earlier upgrade
     *                                              and therefore still based on an older tag
     * @return array<string, string> Relative path => outcome. Paths that need nothing done
     *                               are absent rather than reported as unchanged.
     */
    public static function classify(
        string $current,
        string $base,
        string $target,
        Ownership $ownership,
        array $baseOverrides = [],
    ): array {
        $paths = self::union(self::files($base), self::files($target));
        $paths = self::union($paths, array_keys($baseOverrides));

        $plan = [];
        foreach ($paths as $path) {
            if ($ownership->skips($path)) {
                continue;
            }

            $outcome = self::outcomeFor($current, $baseOverrides[$path] ?? $base, $target, $path);
            if ($outcome === null) {
                continue;
            }

            // A file the project replaced wholesale at creation would otherwise conflict on
            // every upgrade forever, with nothing worth salvaging in the merge
            if ($ownership->isOnce($path) && in_array($outcome, [self::GONE, self::KEEP], true) === false) {
                $outcome = self::ONCE;
            }

            $plan[$path] = $outcome;
        }

        ksort($plan);

        return $plan;
    }

    /**
     * How closely a tree resembles a reference tree, over the paths they have in common.
     *
     * This is what identifies the version a project was created from when it has no
     * manifest: run it against each tag and take the best ratio. Measured against the
     * skeleton the winner is unambiguous - a correct tag scores an order of magnitude
     * higher than a neighbouring release.
     *
     * @return array{matched: int, total: int}
     */
    public static function score(string $current, string $reference, Ownership $ownership): array
    {
        $matched = 0;
        $total = 0;

        foreach (self::files($reference) as $path) {
            if ($ownership->skips($path) || is_file($current . '/' . $path) === false) {
                continue;
            }

            $total++;
            if (self::same($current . '/' . $path, $reference . '/' . $path)) {
                $matched++;
            }
        }

        return ['matched' => $matched, 'total' => $total];
    }

    /**
     * Carries out decisions already made. Conflicts are not resolved here - the caller
     * merges them and passes TAKE only once it has something it wants written.
     *
     * @param  array<string, string> $decisions Relative path => TAKE or DROP
     * @return list<string> What was done, for the summary
     * @throws \RuntimeException
     */
    public static function apply(array $decisions, string $current, string $target): array
    {
        $log = [];
        ksort($decisions);

        foreach ($decisions as $path => $verb) {
            $to = $current . '/' . $path;

            if ($verb === self::DROP) {
                if (is_file($to) && unlink($to) === false) {
                    throw new \RuntimeException("Could not remove {$path}");
                }
                self::pruneEmpty(dirname($to), $current);
                $log[] = "removed {$path}";
                continue;
            }

            $dir = dirname($to);
            if (is_dir($dir) === false && mkdir($dir, 0755, true) === false && is_dir($dir) === false) {
                throw new \RuntimeException("Could not create " . dirname($path));
            }

            if (copy($target . '/' . $path, $to) === false) {
                throw new \RuntimeException("Could not write {$path}");
            }

            // Presets ship executable scripts; copy() keeps content but not the mode
            $mode = @fileperms($target . '/' . $path);
            if ($mode !== false) {
                @chmod($to, $mode & 0777);
            }

            $log[] = "wrote {$path}";
        }

        return $log;
    }

    /**
     * The classification table. Returns null when there is nothing to do.
     */
    private static function outcomeFor(string $current, string $base, string $target, string $path): ?string
    {
        $inBase = is_file($base . '/' . $path);
        $inTarget = is_file($target . '/' . $path);
        $inCurrent = is_file($current . '/' . $path);

        if ($inBase === false) {
            if ($inCurrent === false) {
                return self::ADD;
            }

            // Both sides invented the same path independently
            return self::same($current . '/' . $path, $target . '/' . $path) ? null : self::CONFLICT;
        }

        if ($inTarget === false) {
            if ($inCurrent === false) {
                return null;
            }

            return self::same($current . '/' . $path, $base . '/' . $path) ? self::DELETE : self::ORPHANED;
        }

        if ($inCurrent === false) {
            return self::GONE;
        }

        $upstreamMoved = self::same($base . '/' . $path, $target . '/' . $path) === false;
        $locallyMoved = self::same($current . '/' . $path, $base . '/' . $path) === false;

        if ($upstreamMoved === false) {
            return $locallyMoved ? self::KEEP : null;
        }

        if ($locallyMoved === false) {
            return self::UPDATE;
        }

        // Both moved, but to the same place - somebody already applied this by hand
        return self::same($current . '/' . $path, $target . '/' . $path) ? null : self::CONFLICT;
    }

    /**
     * Relative paths of every regular file under a directory.
     *
     * Only base and target are ever walked, never the project itself, so vendor/ and
     * node_modules/ cost nothing. Symlinks are skipped rather than followed: an upgrade has
     * no business chasing one out of the tree.
     *
     * @return list<string>
     */
    public static function files(string $dir, string $prefix = ''): array
    {
        if (is_dir($dir) === false) {
            return [];
        }

        $found = [];
        foreach ((array) scandir($dir) as $entry) {
            if (is_string($entry) === false || $entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            $relative = ($prefix === '' ? $entry : $prefix . '/' . $entry);

            if (is_link($path)) {
                continue;
            }

            if (is_dir($path)) {
                $found = array_merge($found, self::files($path, $relative));
                continue;
            }

            if (is_file($path)) {
                $found[] = $relative;
            }
        }

        return $found;
    }

    public static function same(string $a, string $b): bool
    {
        if (is_file($a) === false || is_file($b) === false) {
            return false;
        }

        if (filesize($a) !== filesize($b)) {
            return false;
        }

        return hash_file('sha256', $a) === hash_file('sha256', $b);
    }

    /**
     * @param  list<string> $a
     * @param  list<string> $b
     * @return list<string>
     */
    private static function union(array $a, array $b): array
    {
        return array_values(array_unique(array_merge($a, $b)));
    }

    /**
     * Removes directories left empty by a deletion, stopping at the tree root so an upgrade
     * cannot walk up out of the project.
     */
    private static function pruneEmpty(string $dir, string $stopAt): void
    {
        $stopAt = rtrim($stopAt, '/');

        while ($dir !== $stopAt && str_starts_with($dir, $stopAt . '/')) {
            $entries = @scandir($dir);
            if (is_array($entries) === false || count($entries) > 2) {
                return;
            }

            if (@rmdir($dir) === false) {
                return;
            }

            $dir = dirname($dir);
        }
    }
}
