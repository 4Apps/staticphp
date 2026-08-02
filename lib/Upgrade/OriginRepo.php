<?php

namespace StaticPHP\Skeleton\Upgrade;

/**
 * Read-only access to the skeleton's own repository.
 *
 * An upgrade is a three-way merge whose base is the upstream version the project currently
 * sits on, so both sides of the comparison come from here rather than from anything stored
 * in the project. That is the whole reason there is no local baseline copy to lose: the only
 * state a project keeps is a tag name, and even that can be recovered by scoring.
 *
 * The clone is bare, shared by every project on the machine, and lives under the cache
 * directory because it is derived - deleting it costs one clone and nothing else.
 */
class OriginRepo
{
    private string $cache;

    public function __construct(
        private string $url,
    ) {
        $this->cache = self::cachePath($url);
    }

    public function url(): string
    {
        return $this->url;
    }

    public static function cachePath(string $url): string
    {
        $home = (string) (getenv('XDG_CACHE_HOME') ?: '');
        if ($home === '') {
            $home = (string) (getenv('HOME') ?: sys_get_temp_dir()) . '/.cache';
        }

        $slug = preg_replace('#[^A-Za-z0-9._-]+#', '-', $url) ?? 'origin';

        return $home . '/staticphp/' . trim($slug, '-') . '.git';
    }

    /**
     * Clone on first use, fetch afterwards. Called once per run, before anything is written,
     * so an unreachable origin fails the command rather than half-upgrading a project.
     *
     * @throws \RuntimeException
     */
    public function sync(): void
    {
        if (is_dir($this->cache . '/objects')) {
            $this->git(['fetch', '--tags', '--prune', '--quiet', 'origin'], 'fetching');
            return;
        }

        $parent = dirname($this->cache);
        if (is_dir($parent) === false && mkdir($parent, 0755, true) === false && is_dir($parent) === false) {
            throw new \RuntimeException("Could not create the cache directory {$parent}");
        }

        // --mirror rather than --bare: it configures a refspec, so later fetches actually
        // update tags. A bare clone records the url and no refspec, and silently never
        // learns about a new release.
        self::run(['git', 'clone', '--mirror', '--quiet', $this->url, $this->cache], 'cloning ' . $this->url);
    }

    /**
     * @return list<string> Newest first
     */
    public function tags(): array
    {
        $output = $this->git(['tag', '--list', '--sort=-v:refname'], 'listing tags');

        $tags = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $tags[] = $line;
            }
        }

        return $tags;
    }

    /**
     * @throws \RuntimeException
     */
    public function latestTag(): string
    {
        $tags = $this->tags();
        if ($tags === []) {
            throw new \RuntimeException("The origin {$this->url} has no tags to upgrade to");
        }

        return $tags[0];
    }

    public function hasTag(string $tag): bool
    {
        try {
            $this->git(['rev-parse', '--verify', '--quiet', $tag . '^{commit}'], 'resolving ' . $tag);
        } catch (\RuntimeException) {
            return false;
        }

        return true;
    }

    /**
     * One file's contents at a tag, without unpacking anything.
     *
     * @return string|null Null when the tag has no such file
     */
    public function show(string $tag, string $path): ?string
    {
        try {
            return $this->git(['show', $tag . ':' . $path], "reading {$path} at {$tag}");
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * Unpacks a tag, or one subtree of it, into a directory.
     *
     * git archive rather than a worktree checkout: it takes a tag and a path and writes a
     * tar, with no index, no working copy and no chance of leaving the cache dirty.
     *
     * @param  string|null $subtree Path within the tag, or null for the whole tree
     * @throws \RuntimeException
     */
    public function export(string $tag, ?string $subtree, string $into): void
    {
        if (is_dir($into) === false && mkdir($into, 0755, true) === false && is_dir($into) === false) {
            throw new \RuntimeException("Could not create {$into}");
        }

        $tar = $into . '/.export.tar';

        $args = ['archive', '--format=tar', '--output=' . $tar, $tag];
        if ($subtree !== null) {
            $args[] = $subtree;
        }

        $this->git($args, "exporting {$tag}");

        self::run(['tar', '-xf', $tar, '-C', $into], "unpacking {$tag}");

        unlink($tar);
    }

    /**
     * @param  list<string> $args
     * @throws \RuntimeException
     */
    private function git(array $args, string $what): string
    {
        return self::run(array_merge(['git', '--git-dir=' . $this->cache], $args), $what);
    }

    /**
     * @param  list<string> $command
     * @throws \RuntimeException
     */
    private static function run(array $command, string $what): string
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $process = @proc_open($command, $descriptors, $pipes);
        if (is_resource($process) === false) {
            throw new \RuntimeException("Could not run {$command[0]} while {$what}");
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $code = proc_close($process);

        if ($code !== 0) {
            $detail = trim($stderr) !== '' ? trim($stderr) : trim($stdout);

            throw new \RuntimeException("Failed while {$what}: " . ($detail !== '' ? $detail : "exit {$code}"));
        }

        return $stdout;
    }
}
