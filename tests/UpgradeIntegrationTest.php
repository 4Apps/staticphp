<?php

namespace StaticPHP\Skeleton\Tests;

use PHPUnit\Framework\TestCase;
use StaticPHP\Skeleton\Manifest;
use StaticPHP\Skeleton\App\Scaffolder;
use StaticPHP\Skeleton\Upgrade\Cli as UpgradeCli;

/**
 * The whole command, against a real two-tag repository built in a temp directory.
 *
 * Hermetic but not mocked: git actually clones, archives and merges here, because the parts
 * most likely to break are the ones where this code hands work to git and reads the result
 * back. No network - the origin is a path on disk, which git treats the same as any remote.
 */
final class UpgradeIntegrationTest extends TestCase
{
    use TempTree;

    /**
     * The skeleton at its first release.
     *
     * @var array<string, string>
     */
    private const V1 = [
        'upgrade.json' => '{"ignore":["/.env","/.staticphp/","/src/*/"],'
            . '"once":["/README.md"],"strip":["/CHANGELOG.md"],"app":{"ignore":["/Cache/"]}}',
        'composer.json' => '{"name":"4apps/staticphp"}',
        'README.md' => "upstream readme v1\n",
        'CHANGELOG.md' => "skeleton history\n",
        'rspack.config.js' => "// v1\n",
        'docker/run.bash' => "echo v1\n",
        'docker/removed.bash' => "echo doomed\n",
        'presets/_base/Public/index.php' => "<?php\n// base v1\n",
        'presets/_base/phpunit.xml' => "<phpunit/>\n",
        'presets/twig/preset.json' => '{"description":"twig"}',
        'presets/twig/Views/page.html' => "<p>v1</p>\n",
    ];

    /**
     * What the second release changes. docker/removed.bash is deleted rather than rewritten.
     *
     * @var array<string, string>
     */
    private const V2 = [
        'rspack.config.js' => "// v2\n",
        'docker/run.bash' => "echo v2\n",
        'docker/added.bash' => "echo new\n",
        'README.md' => "upstream readme v2\n",
        'presets/_base/Public/index.php' => "<?php\n// base v2\n",
    ];

    private string $origin;
    private string $project;

    protected function setUp(): void
    {
        // Keep the bare clone out of the developer's real cache
        putenv('XDG_CACHE_HOME=' . $this->makeDir());

        $this->origin = $this->buildOrigin();
        $this->project = $this->buildProject();
    }

    protected function tearDown(): void
    {
        putenv('XDG_CACHE_HOME');
        $this->removeTempTrees();
    }

    public function testTheSilentChangesLandAndCollisionsAreDeferred(): void
    {
        self::assertSame(0, $this->upgrade(['--to=v2.0.0', '--yes']));

        // Changed upstream, untouched here
        self::assertSame("echo v2\n", $this->read($this->project, 'docker/run.bash'));

        // New upstream file
        self::assertSame("echo new\n", $this->read($this->project, 'docker/added.bash'));

        // Removed upstream, untouched here
        self::assertFileDoesNotExist($this->project . '/docker/removed.bash');

        // Changed on both sides: --yes applies the safe set and never resolves a collision
        self::assertSame("// mine\n", $this->read($this->project, 'rspack.config.js'));

        // Replaced when the project was generated, so never upgraded
        self::assertSame("project readme\n", $this->read($this->project, 'README.md'));

        // Deleted when the project was generated, so never offered back
        self::assertFileDoesNotExist($this->project . '/CHANGELOG.md');

        // Applications come along, onto the same tag. Leaving them behind would mean a
        // project whose presets/ moved while the application scaffolded from them did not,
        // and the clean-worktree guard makes "run the second command afterwards" impossible
        // without an intervening commit.
        self::assertSame("<?php\n// base v2\n", $this->read($this->project, 'src/App/Public/index.php'));

        $manifest = Manifest::load($this->project);
        self::assertSame('v2.0.0', $manifest->skeleton);
        self::assertSame('v2.0.0', $manifest->appVersion('App'));

        // The deferred file keeps the base it was deferred at, so it is raised again
        self::assertSame(['rspack.config.js' => 'v1.0.0'], $manifest->pinned);
    }

    public function testADryRunWritesNothing(): void
    {
        $before = $this->snapshot();

        $output = '';
        self::assertSame(0, $this->upgrade(['--to=v2.0.0', '--dry-run'], $output));

        self::assertStringContainsString('Dry run', $output);
        self::assertSame($before, $this->snapshot());
    }

    public function testThePlanNamesEveryOutcome(): void
    {
        $output = '';
        $this->upgrade(['--to=v2.0.0', '--dry-run'], $output);

        self::assertStringContainsString('docker/run.bash', $output);
        self::assertStringContainsString('docker/added.bash', $output);
        self::assertStringContainsString('rspack.config.js', $output);
        self::assertStringContainsString('conflict', $output);
    }

    public function testAProjectWithNoRecordedVersionIsMatchedAgainstTheTags(): void
    {
        $manifest = Manifest::load($this->project);
        $manifest->skeleton = null;
        $manifest->save($this->project);
        $this->commit('drop the recorded version');

        $output = '';
        self::assertSame(0, $this->upgrade(['--to=v2.0.0', '--yes'], $output));

        self::assertStringContainsString('Best match: v1.0.0', $output);

        // Having worked the base out, it behaves exactly as if it had been recorded
        self::assertSame("echo v2\n", $this->read($this->project, 'docker/run.bash'));
        self::assertSame("// mine\n", $this->read($this->project, 'rspack.config.js'));
    }

    public function testAnApplicationIsUpgradedFromItsPreset(): void
    {
        ob_start();
        $code = UpgradeCli::runApp(['App', '--to=v2.0.0', '--yes'], $this->project);
        ob_end_clean();

        self::assertSame(0, $code);

        self::assertSame("<?php\n// base v2\n", $this->read($this->project, 'src/App/Public/index.php'));
        self::assertSame('v2.0.0', Manifest::load($this->project)->appVersion('App'));
    }

    public function testUpgradingTwiceIsANoOp(): void
    {
        self::assertSame(0, $this->upgrade(['--to=v2.0.0', '--yes']));
        $this->commit('upgrade');

        $after = $this->snapshot();

        $output = '';
        self::assertSame(0, $this->upgrade(['--to=v2.0.0', '--yes'], $output));

        self::assertStringContainsString('already on v2.0.0', $output);
        self::assertSame($after, $this->snapshot());
    }

    public function testADirtyWorktreeIsRefused(): void
    {
        file_put_contents($this->project . '/docker/run.bash', "echo meddled\n");

        $output = '';
        self::assertSame(1, $this->upgrade(['--to=v2.0.0', '--yes'], $output));

        // Nothing was written, so the meddling is still the only change
        self::assertSame("echo meddled\n", $this->read($this->project, 'docker/run.bash'));
        self::assertFileDoesNotExist($this->project . '/docker/added.bash');
    }

    public function testAnUnknownTargetIsRejected(): void
    {
        $before = $this->snapshot();

        self::assertSame(1, $this->upgrade(['--to=v9.9.9', '--yes']));
        self::assertSame($before, $this->snapshot());
    }

    /**
     * @param string[] $args
     */
    private function upgrade(array $args, string &$output = ''): int
    {
        ob_start();
        try {
            $code = UpgradeCli::run($args, $this->project);
        } finally {
            $output = (string) ob_get_clean();
        }

        return $code;
    }

    private function buildOrigin(): string
    {
        $dir = $this->makeDir();

        foreach (self::V1 as $path => $contents) {
            $this->put($dir, $path, $contents);
        }

        $this->git($dir, ['init', '--quiet', '--initial-branch=main']);
        $this->git($dir, ['config', 'user.email', 'test@example.invalid']);
        $this->git($dir, ['config', 'user.name', 'Upgrade Test']);
        $this->git($dir, ['config', 'commit.gpgsign', 'false']);
        $this->git($dir, ['add', '-A']);
        $this->git($dir, ['commit', '--quiet', '-m', 'v1']);
        $this->git($dir, ['tag', 'v1.0.0']);

        unlink($dir . '/docker/removed.bash');
        foreach (self::V2 as $path => $contents) {
            $this->put($dir, $path, $contents);
        }

        $this->git($dir, ['add', '-A']);
        $this->git($dir, ['commit', '--quiet', '-m', 'v2']);
        $this->git($dir, ['tag', 'v2.0.0']);

        return $dir;
    }

    /**
     * A project as it would look after composer create-project: the first release's files,
     * the generation-time rewrites applied, one application scaffolded, and a local edit on
     * top so that there is something for the upgrade to collide with.
     */
    private function buildProject(): string
    {
        $dir = $this->makeDir();

        foreach (self::V1 as $path => $contents) {
            $this->put($dir, $path, $contents);
        }

        // What post_create_project.php does
        $this->put($dir, 'composer.json', '{"name":"vendor/app"}');
        $this->put($dir, 'README.md', "project readme\n");
        unlink($dir . '/CHANGELOG.md');

        (new Scaffolder($dir))->create('App', 'twig');

        $manifest = Manifest::load($dir);
        $manifest->origin = $this->origin;
        $manifest->skeleton = 'v1.0.0';
        $manifest->setApp('App', 'twig', 'v1.0.0');
        $manifest->save($dir);

        // The developer's own change, on a file the next release also touches
        $this->put($dir, 'rspack.config.js', "// mine\n");

        $this->git($dir, ['init', '--quiet', '--initial-branch=main']);
        $this->git($dir, ['config', 'user.email', 'test@example.invalid']);
        $this->git($dir, ['config', 'user.name', 'Upgrade Test']);
        $this->git($dir, ['config', 'commit.gpgsign', 'false']);
        $this->git($dir, ['add', '-A']);
        $this->git($dir, ['commit', '--quiet', '-m', 'generated']);

        return $dir;
    }

    private function commit(string $message): void
    {
        $this->git($this->project, ['add', '-A']);
        $this->git($this->project, ['commit', '--quiet', '-m', $message]);
    }

    /**
     * Every tracked path and its hash, so a test can assert that nothing moved.
     *
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $state = [];
        foreach (\StaticPHP\Skeleton\Upgrade\Upgrader::files($this->project) as $path) {
            if (str_starts_with($path, '.git/')) {
                continue;
            }

            $state[$path] = (string) hash_file('sha256', $this->project . '/' . $path);
        }

        return $state;
    }

    /**
     * @param string[] $args
     */
    private function git(string $dir, array $args): void
    {
        $command = 'git -C ' . escapeshellarg($dir);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }

        $output = [];
        $code = 0;
        exec($command . ' 2>&1', $output, $code);

        if ($code !== 0) {
            self::fail('git ' . implode(' ', $args) . ' failed: ' . implode("\n", $output));
        }
    }
}
