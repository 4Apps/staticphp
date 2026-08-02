<?php

namespace StaticPHP\Skeleton\Upgrade;

use StaticPHP\Skeleton\App\Scaffolder;
use StaticPHP\Skeleton\Manifest;

/**
 * `staticphp upgrade` and `staticphp app upgrade` - pulling a later skeleton release into a
 * project that has been edited since it was generated.
 *
 * Everything that talks to a person lives here. The comparison is Upgrader's, the repository
 * access is OriginRepo's and the rules are Ownership's, so this file is argument parsing, a
 * plan to read, and prompts for the handful of files that genuinely collided.
 *
 * The merge engine is `git merge-file`. There is no hand-written three-way merge, and no
 * backup or rollback either: the guard refuses to run on a dirty worktree, which is what
 * makes `git checkout .` the undo.
 */
class Cli
{
    public const DESCRIPTION = 'Merge a later release of the skeleton into this project';

    public const USAGE = <<<TXT
    Merges a later release of the skeleton into this project.

    Three trees are compared - what you have, the release you are on, and the release you
    are going to - so a file you never touched takes the new version silently, a file only
    you changed is left alone, and you are only asked about the ones that changed on both
    sides.

    Usage:
      staticphp upgrade [--to=<tag>] [--from=<tag>] [--dry-run] [--yes]
      staticphp app upgrade <Name> [same options]

    The first form also carries every application the manifest records onto the same tag.
    The second does one application on its own.

    Options:
      --to=<tag>     Release to move to (default: the newest the origin has)
      --from=<tag>   Release to treat as the base, overriding .staticphp/manifest.json.
                     Without it, an unrecorded project is matched against the origin's tags
                     and the best fit is confirmed before anything is written.
      --dry-run      Print the plan and stop
      --yes          Apply the silent changes and defer every collision. Never resolves one
                     unattended - that is what makes it safe in a script.
      --help         This text

    For a collision: [d] diff  [m] merge  [t] take theirs  [k] keep mine  [s] skip.
    "m" is a real three-way merge and can leave conflict markers, which are listed again at
    the end and set a non-zero exit code. "s" defers, so the file is raised again next time
    rather than quietly absorbed.

    Refuses to run on a dirty worktree: `git checkout .` is the undo, and there is no backup
    logic behind it.

    TXT;

    /**
     * Plan headings, in the order they are printed. Conflicts last, because that is where
     * the reader has to do something.
     *
     * @var array<string, string>
     */
    private const GROUPS = [
        Upgrader::UPDATE => 'changed upstream, untouched here',
        Upgrader::ADD => 'new upstream file',
        Upgrader::DELETE => 'removed upstream',
        Upgrader::KEEP => 'your change, untouched upstream',
        Upgrader::ORPHANED => 'removed upstream, but you changed it - kept',
        Upgrader::GONE => 'you deleted it - staying deleted',
        Upgrader::ONCE => 'yours since creation, never upgraded',
        Upgrader::CONFLICT => 'changed on both sides',
    ];

    /**
     * Temp directories to remove when the process ends, however it ends.
     *
     * @var list<string>
     */
    private static array $scratch = [];

    /**
     * @param  string[] $args Arguments after "upgrade"
     * @return int Exit code
     */
    public static function run(array $args, string $root): int
    {
        if (self::wantsHelp($args)) {
            echo self::USAGE;
            return 0;
        }

        $options = self::parse($args);
        if (is_string($options)) {
            fwrite(STDERR, $options . "\n");
            return 1;
        }

        $problem = self::guard($root);
        if ($problem !== null) {
            fwrite(STDERR, $problem . "\n");
            return 1;
        }

        $manifest = Manifest::load($root);

        try {
            $repo = self::synced($manifest);
            $to = self::resolveTo($repo, $options['to']);

            $from = self::resolveFrom($options['from'], $manifest->skeleton, $repo, $root, null, 'skeleton files');
            if ($from === null) {
                return 1;
            }

            $exit = self::perform($root, $root, 'skeleton files', $repo, $manifest, null, $from, $to, $options, null);
            if ($exit !== 0) {
                return $exit;
            }

            return self::upgradeApps($root, $repo, $manifest, $to, $options);
        } catch (\RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            return 1;
        }
    }

    /**
     * @param  string[] $args Arguments after "app upgrade"
     * @return int Exit code
     */
    public static function runApp(array $args, string $root): int
    {
        if (self::wantsHelp($args)) {
            echo self::USAGE;
            return 0;
        }

        $name = '';
        $rest = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '-')) {
                $rest[] = $arg;
                continue;
            }
            if ($name === '') {
                $name = $arg;
            }
        }

        if ($name === '') {
            fwrite(STDERR, "Usage: staticphp app upgrade <Name> [--to=<tag>] [--from=<tag>] [--dry-run] [--yes]\n");
            return 1;
        }

        $options = self::parse($rest);
        if (is_string($options)) {
            fwrite(STDERR, $options . "\n");
            return 1;
        }

        $current = $root . '/src/' . $name;
        if (is_dir($current) === false) {
            fwrite(STDERR, "No such application: src/{$name}\n");
            return 1;
        }

        $problem = self::guard($root);
        if ($problem !== null) {
            fwrite(STDERR, $problem . "\n");
            return 1;
        }

        $manifest = Manifest::load($root);

        try {
            $repo = self::synced($manifest);
            $to = self::resolveTo($repo, $options['to']);

            $preset = $manifest->appPreset($name) ?? self::detectPreset($repo, $to, $current, $name);
            if ($preset === null) {
                return 1;
            }

            $recorded = $manifest->appVersion($name);
            $from = self::resolveFrom($options['from'], $recorded, $repo, $current, $preset, "src/{$name}");
            if ($from === null) {
                return 1;
            }

            return self::perform($root, $current, "src/{$name}", $repo, $manifest, $preset, $from, $to, $options, $name);
        } catch (\RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            return 1;
        }
    }

    /**
     * Carries the same target tag through every application the manifest knows about.
     *
     * Part of `staticphp upgrade` rather than a second command you have to remember, because
     * the clean-worktree guard would refuse that second command until the first one had been
     * committed - which is a miserable way to find out that presets/ moved and your
     * application did not. `staticphp app upgrade` stays available for doing just one.
     *
     * @param array{to: ?string, from: ?string, dryRun: bool, yes: bool} $options
     */
    private static function upgradeApps(
        string $root,
        OriginRepo $repo,
        Manifest $manifest,
        string $to,
        array $options,
    ): int {
        // Snapshot the names: recording a new version below writes back into $manifest->apps
        foreach (array_keys($manifest->apps) as $name) {
            $current = $root . '/src/' . $name;
            $preset = $manifest->appPreset($name);

            if (is_dir($current) === false || $preset === null) {
                continue;
            }

            $from = self::resolveFrom(null, $manifest->appVersion($name), $repo, $current, $preset, "src/{$name}");
            if ($from === null) {
                echo "Skipping src/{$name}.\n";
                continue;
            }

            $code = self::perform($root, $current, "src/{$name}", $repo, $manifest, $preset, $from, $to, $options, $name);
            if ($code !== 0) {
                return $code;
            }
        }

        return 0;
    }

    /**
     * The shared body. The two entry points differ only in which directory is "current" and
     * whether the upstream side is a whole tag or one composed preset out of it.
     *
     * @param array{to: ?string, from: ?string, dryRun: bool, yes: bool} $options
     */
    private static function perform(
        string $root,
        string $current,
        string $label,
        OriginRepo $repo,
        Manifest $manifest,
        ?string $preset,
        string $from,
        string $to,
        array $options,
        ?string $appName,
    ): int {
        if ($from === $to) {
            echo "{$label}: already on {$to}.\n";
            return 0;
        }

        echo "\nUpgrading {$label} {$from} -> {$to}\n";

        $baseTree = self::exportTree($repo, $from, $preset);
        $targetTree = self::exportTree($repo, $to, $preset);

        // Rules come from the release being upgraded to: the incoming version is the one
        // that knows what it owns. The project's own copy is only a fallback for upgrading
        // from a release that predates the file.
        $ownership = Ownership::fromJson($repo->show($to, 'upgrade.json'), $preset !== null)
            ?? Ownership::load([$root . '/upgrade.json'], $preset !== null);

        // Pins are recorded against the project root so that two applications cannot claim
        // the same key, and are stripped back to tree-relative paths here
        $prefix = ($appName === null ? '' : "src/{$appName}/");

        $overrides = [];
        foreach (self::pinnedTags($manifest, $from, $prefix) as $tag => $paths) {
            $tree = self::exportTree($repo, $tag, $preset);
            foreach ($paths as $path) {
                $overrides[$path] = $tree;
            }
        }

        $plan = Upgrader::classify($current, $baseTree, $targetTree, $ownership, $overrides);

        self::renderPlan($plan);

        if ($plan === []) {
            echo "\nNothing to do.\n";
            self::recordVersion($manifest, $root, $appName, $preset, $to);
            return 0;
        }

        if ($options['dryRun'] === true) {
            echo "\nDry run - nothing written.\n";
            return 0;
        }

        $interactive = ($options['yes'] === false);
        if ($interactive === true && self::canAsk() === false) {
            echo "\nNot a terminal - nothing written. Re-run with --yes to apply the silent changes.\n";
            return 0;
        }

        $decisions = self::decideSilent($plan, $interactive);
        if ($decisions === null) {
            echo "Aborted.\n";
            return 1;
        }

        $conflicts = self::resolveConflicts($plan, $current, $baseTree, $targetTree, $manifest, $from, $prefix, $interactive);
        foreach ($conflicts['take'] as $path) {
            $decisions[$path] = Upgrader::TAKE;
        }

        Upgrader::apply($decisions, $current, $targetTree);

        self::recordVersion($manifest, $root, $appName, $preset, $to);

        return self::summarise($decisions, $conflicts['markers'], $current);
    }

    /**
     * @param  array<string, string> $plan
     * @return array<string, string>|null Null when the user declines
     */
    private static function decideSilent(array $plan, bool $interactive): ?array
    {
        $silent = array_filter($plan, static fn(string $o): bool => in_array($o, Upgrader::SILENT, true));
        $deletes = array_filter($plan, static fn(string $o): bool => $o === Upgrader::DELETE);

        $count = count($silent) + count($deletes);
        if ($count === 0) {
            return [];
        }

        if ($interactive === true) {
            $answer = strtolower(trim(self::readLine("\nApply the {$count} non-conflicting changes? [Y/n] ")));
            if ($answer !== '' && $answer !== 'y' && $answer !== 'yes') {
                return null;
            }
        }

        $decisions = [];
        foreach (array_keys($silent) as $path) {
            $decisions[$path] = Upgrader::TAKE;
        }
        foreach (array_keys($deletes) as $path) {
            $decisions[$path] = Upgrader::DROP;
        }

        return $decisions;
    }

    /**
     * @param  array<string, string> $plan
     * @return array{take: list<string>, markers: list<string>}
     */
    private static function resolveConflicts(
        array $plan,
        string $current,
        string $base,
        string $target,
        Manifest $manifest,
        string $from,
        string $prefix,
        bool $interactive,
    ): array {
        $take = [];
        $markers = [];

        foreach ($plan as $path => $outcome) {
            if ($outcome !== Upgrader::CONFLICT) {
                continue;
            }

            // --yes never resolves a collision unattended; it defers instead
            if ($interactive === false) {
                $manifest->pin($prefix . $path, $from);
                continue;
            }

            switch (self::askConflict($path, $current, $target)) {
                case 't':
                    $take[] = $path;
                    $manifest->unpin($prefix . $path);
                    break;

                case 'm':
                    if (self::merge($path, $current, $base, $target) === false) {
                        $markers[] = $path;
                    }
                    $manifest->unpin($prefix . $path);
                    break;

                case 'k':
                    // A decision, not a deferral - do not raise it again
                    $manifest->unpin($prefix . $path);
                    break;

                default:
                    $manifest->pin($prefix . $path, $from);
                    break;
            }
        }

        return ['take' => $take, 'markers' => $markers];
    }

    private static function askConflict(string $path, string $current, string $target): string
    {
        while (true) {
            echo "\n{$path} - changed upstream and locally\n";
            $answer = strtolower(trim(self::readLine(
                '  [d] diff  [m] merge  [t] take theirs  [k] keep mine  [s] skip (default): '
            )));

            if ($answer === 'd') {
                self::showDiff($current . '/' . $path, $target . '/' . $path);
                continue;
            }

            if (in_array($answer, ['m', 't', 'k'], true)) {
                return $answer;
            }

            return 's';
        }
    }

    /**
     * Three-way merges one file in place.
     *
     * Without -p, git merge-file writes the result straight into the first argument, which
     * is exactly the file being upgraded. Capturing stdout instead would mean reassembling
     * the bytes by hand and getting trailing newlines subtly wrong.
     *
     * Public because it is the one step that rewrites a file the developer has edited, and
     * it is worth testing on its own rather than only through a prompt.
     *
     * @return bool True when the merge came out clean
     */
    public static function merge(string $path, string $current, string $base, string $target): bool
    {
        $command = sprintf(
            'git merge-file -L mine -L base -L theirs %s %s %s',
            escapeshellarg($current . '/' . $path),
            escapeshellarg($base . '/' . $path),
            escapeshellarg($target . '/' . $path)
        );

        $output = [];
        $code = 0;
        exec($command . ' 2>&1', $output, $code);

        // A positive code is the number of conflicts and the file is still written, with
        // markers where the sides overlapped. Anything else means git could not run.
        if ($code < 0 || $code > 127) {
            echo "  could not merge {$path} - left unchanged\n";
            return false;
        }

        if ($code > 0) {
            echo "  merged with {$code} conflict(s) - markers left in {$path}\n";
            return false;
        }

        echo "  merged cleanly\n";

        return true;
    }

    private static function showDiff(string $mine, string $theirs): void
    {
        // --no-index makes git diff work on loose files. It exits 1 when they differ, which
        // is the expected case here rather than a failure.
        $command = sprintf(
            'git diff --no-index --color %s %s',
            escapeshellarg(is_file($mine) ? $mine : '/dev/null'),
            escapeshellarg(is_file($theirs) ? $theirs : '/dev/null')
        );

        passthru($command . ' || true');
    }

    /**
     * @param array<string, string> $plan
     */
    private static function renderPlan(array $plan): void
    {
        echo "\n";

        foreach (self::GROUPS as $outcome => $description) {
            $paths = array_keys(array_filter($plan, static fn(string $o): bool => $o === $outcome));
            if ($paths === []) {
                continue;
            }

            printf("  %-9s %3d  %s\n", $outcome, count($paths), $description);

            foreach (array_slice($paths, 0, 8) as $path) {
                echo "                    {$path}\n";
            }

            if (count($paths) > 8) {
                printf("                    ... and %d more\n", count($paths) - 8);
            }
        }
    }

    /**
     * @param  array<string, string> $decisions
     * @param  list<string> $markers
     * @return int Exit code - non-zero when something still needs a human
     */
    private static function summarise(array $decisions, array $markers, string $current): int
    {
        $written = count(array_filter($decisions, static fn(string $v): bool => $v === Upgrader::TAKE));
        $removed = count($decisions) - $written;

        echo "\nDone: {$written} written, {$removed} removed.\n";

        $follow = [];
        foreach (array_keys($decisions) as $path) {
            if ($path === 'composer.json') {
                $follow[] = 'composer update';
            }
            if ($path === 'package.json') {
                $follow[] = 'npm install';
            }
        }

        if ($follow !== []) {
            echo "\nRun next:\n";
            foreach (array_unique($follow) as $step) {
                echo "  {$step}\n";
            }
        }

        if ($markers !== []) {
            echo "\nConflict markers left in:\n";
            foreach ($markers as $path) {
                echo "  {$current}/{$path}\n";
            }
            echo "Resolve them before committing.\n";

            return 1;
        }

        return 0;
    }

    /**
     * Pinned paths grouped by the tag they were declined at, scoped to one tree and with
     * any already sitting on the base dropped as redundant.
     *
     * @return array<string, list<string>>
     */
    private static function pinnedTags(Manifest $manifest, string $from, string $prefix): array
    {
        $byTag = [];

        foreach ($manifest->pinned as $key => $tag) {
            if ($prefix !== '' && str_starts_with($key, $prefix) === false) {
                continue;
            }

            // A skeleton run must not pick up an application's pins
            if ($prefix === '' && str_starts_with($key, 'src/')) {
                continue;
            }

            if ($tag === $from) {
                continue;
            }

            $byTag[$tag][] = substr($key, strlen($prefix));
        }

        return $byTag;
    }

    private static function synced(Manifest $manifest): OriginRepo
    {
        $repo = new OriginRepo($manifest->origin);

        echo "Syncing {$manifest->origin}\n";
        $repo->sync();

        return $repo;
    }

    /**
     * @throws \RuntimeException
     */
    private static function resolveTo(OriginRepo $repo, ?string $wanted): string
    {
        if ($wanted === null) {
            return $repo->latestTag();
        }

        if ($repo->hasTag($wanted) === false) {
            throw new \RuntimeException("Unknown tag \"{$wanted}\". Available: " . implode(', ', $repo->tags()));
        }

        return $wanted;
    }

    /**
     * The tag a tree is currently on: what was asked for, what the manifest records, or -
     * failing both - whichever tag the tree most resembles.
     */
    private static function resolveFrom(
        ?string $explicit,
        ?string $recorded,
        OriginRepo $repo,
        string $current,
        ?string $preset,
        string $label,
    ): ?string {
        if ($explicit !== null) {
            if ($repo->hasTag($explicit) === false) {
                fwrite(STDERR, "Unknown tag \"{$explicit}\"\n");
                return null;
            }

            return $explicit;
        }

        if ($recorded !== null && $repo->hasTag($recorded)) {
            return $recorded;
        }

        echo "No recorded version for {$label} - matching it against the origin's tags.\n";

        $best = self::detect($repo, $current, $preset);
        if ($best === null) {
            fwrite(STDERR, "Could not work out which version {$label} came from - pass --from=<tag>\n");
            return null;
        }

        printf("Best match: %s (%d of %d files identical)\n", $best['tag'], $best['matched'], $best['total']);

        if (self::canAsk() === false) {
            return $best['tag'];
        }

        $answer = strtolower(trim(self::readLine('Use it as the upgrade base? [Y/n] ')));
        if ($answer !== '' && $answer !== 'y' && $answer !== 'yes') {
            return null;
        }

        return $best['tag'];
    }

    /**
     * @return array{tag: string, matched: int, total: int}|null
     */
    private static function detect(OriginRepo $repo, string $current, ?string $preset): ?array
    {
        $ownership = Ownership::load([], $preset !== null);

        $bestTag = null;
        $bestMatched = 0;
        $bestTotal = 0;
        $bestRatio = -1.0;

        foreach ($repo->tags() as $tag) {
            try {
                $tree = self::exportTree($repo, $tag, $preset);
            } catch (\RuntimeException) {
                // A tag from before the preset existed cannot be composed. Not an error -
                // it simply is not where this tree came from.
                continue;
            }

            $score = Upgrader::score($current, $tree, $ownership);
            if ($score['total'] === 0) {
                continue;
            }

            $ratio = $score['matched'] / $score['total'];
            if ($ratio > $bestRatio) {
                $bestTag = $tag;
                $bestMatched = $score['matched'];
                $bestTotal = $score['total'];
                $bestRatio = $ratio;
            }
        }

        if ($bestTag === null || $bestMatched === 0) {
            return null;
        }

        return ['tag' => $bestTag, 'matched' => $bestMatched, 'total' => $bestTotal];
    }

    /**
     * Which preset an application came from, when the manifest does not say. The preset
     * whose composed tree the application most resembles wins.
     */
    private static function detectPreset(OriginRepo $repo, string $tag, string $current, string $name): ?string
    {
        $exported = self::scratchDir();
        $repo->export($tag, 'presets', $exported);

        $ownership = Ownership::load([], true);

        $bestPreset = null;
        $bestMatched = 0;

        foreach ((array) glob($exported . '/presets/*', GLOB_ONLYDIR) as $dir) {
            if (is_string($dir) === false || basename($dir) === '_base') {
                continue;
            }

            $preset = basename($dir);
            $score = Upgrader::score($current, self::composeInto($exported . '/presets', $preset), $ownership);

            if ($score['total'] > 0 && $score['matched'] > $bestMatched) {
                $bestPreset = $preset;
                $bestMatched = $score['matched'];
            }
        }

        if ($bestPreset === null) {
            fwrite(STDERR, "Could not work out which preset src/{$name} came from - recreate the manifest by hand\n");
            return null;
        }

        echo "Preset looks like: {$bestPreset}\n";

        return $bestPreset;
    }

    /**
     * The upstream side of a comparison: a whole tag, or one composed preset out of it.
     *
     * Both sides come from the repository rather than from presets/ on disk, because
     * `staticphp upgrade` may already have moved the on-disk copy forward - which would
     * quietly make the merge base wrong.
     */
    private static function exportTree(OriginRepo $repo, string $tag, ?string $preset): string
    {
        $dir = self::scratchDir();

        if ($preset === null) {
            $repo->export($tag, null, $dir);
            return $dir;
        }

        $repo->export($tag, 'presets', $dir);

        return self::composeInto($dir . '/presets', $preset);
    }

    private static function composeInto(string $presetsDir, string $preset): string
    {
        $composed = self::scratchDir();
        Scaffolder::compose($presetsDir, $preset, $composed);

        return $composed;
    }

    private static function recordVersion(
        Manifest $manifest,
        string $root,
        ?string $appName,
        ?string $preset,
        string $to,
    ): void {
        if ($appName !== null && $preset !== null) {
            $manifest->setApp($appName, $preset, $to);
        } else {
            $manifest->skeleton = $to;
        }

        $manifest->save($root);
    }

    /**
     * @param string[] $args
     */
    private static function wantsHelp(array $args): bool
    {
        return in_array('--help', $args, true) || in_array('-h', $args, true);
    }

    /**
     * @param  string[] $args
     * @return array{to: ?string, from: ?string, dryRun: bool, yes: bool}|string An error
     */
    private static function parse(array $args): array|string
    {
        $options = ['to' => null, 'from' => null, 'dryRun' => false, 'yes' => false];

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--to=')) {
                $options['to'] = substr($arg, strlen('--to='));
                continue;
            }
            if (str_starts_with($arg, '--from=')) {
                $options['from'] = substr($arg, strlen('--from='));
                continue;
            }
            if ($arg === '--dry-run') {
                $options['dryRun'] = true;
                continue;
            }
            if ($arg === '--yes' || $arg === '-y') {
                $options['yes'] = true;
                continue;
            }

            return "Unknown option: {$arg}\n\nRun `staticphp upgrade --help` for what it takes.";
        }

        return $options;
    }

    /**
     * @return string|null The reason to refuse, or null to go ahead
     */
    private static function guard(string $root): ?string
    {
        foreach (['git', 'tar'] as $binary) {
            $found = [];
            $code = 0;
            exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null', $found, $code);
            if ($code !== 0) {
                return "{$binary} is required and was not found on PATH";
            }
        }

        $composer = @file_get_contents($root . '/composer.json');
        if (is_string($composer)) {
            $decoded = json_decode($composer, true);
            if (is_array($decoded) && ($decoded['name'] ?? null) === '4apps/staticphp') {
                return 'This is the skeleton itself - there is nothing upstream of it to merge.';
            }
        }

        if (is_dir($root . '/.git') === false) {
            return 'Not a git repository. The undo for a bad upgrade is `git checkout .`, '
                . 'so this refuses to run without one.';
        }

        $status = [];
        $code = 0;
        exec('git -C ' . escapeshellarg($root) . ' status --porcelain 2>/dev/null', $status, $code);

        if ($code === 0 && $status !== []) {
            return "The worktree has uncommitted changes. Commit or stash them first, so that "
                . "`git checkout .` can undo the upgrade:\n  " . implode("\n  ", array_slice($status, 0, 10));
        }

        return null;
    }

    /**
     * @throws \RuntimeException
     */
    private static function scratchDir(): string
    {
        $dir = sys_get_temp_dir() . '/staticphp-upgrade-' . bin2hex(random_bytes(6));
        if (mkdir($dir, 0700, true) === false) {
            throw new \RuntimeException("Could not create {$dir}");
        }

        if (self::$scratch === []) {
            register_shutdown_function(static function (): void {
                foreach (self::$scratch as $path) {
                    self::removeTree($path);
                }
            });
        }

        self::$scratch[] = $dir;

        return $dir;
    }

    private static function removeTree(string $dir): void
    {
        if (is_dir($dir) === false) {
            return;
        }

        foreach ((array) scandir($dir) as $entry) {
            if (is_string($entry) === false || $entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_dir($path) && is_link($path) === false) {
                self::removeTree($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($dir);
    }

    private static function canAsk(): bool
    {
        return defined('STDIN') && stream_isatty(STDIN);
    }

    private static function readLine(string $prompt): string
    {
        echo $prompt;

        $line = fgets(STDIN);

        return ($line === false ? '' : $line);
    }
}
