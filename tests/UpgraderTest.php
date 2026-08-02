<?php

namespace StaticPHP\Skeleton\Tests;

use PHPUnit\Framework\TestCase;
use StaticPHP\Skeleton\Upgrade\Ownership;
use StaticPHP\Skeleton\Upgrade\Upgrader;

/**
 * The classification table, which is the part of the upgrade that decides whether somebody's
 * work survives. Everything here is three plain directories - no git, no network, no prompt.
 */
final class UpgraderTest extends TestCase
{
    use TempTree;

    protected function tearDown(): void
    {
        $this->removeTempTrees();
    }

    public function testEveryRowOfTheClassificationTable(): void
    {
        $base = $this->tree([
            'update.txt' => 'a',
            'keep.txt' => 'a',
            'conflict.txt' => 'a',
            'delete.txt' => 'a',
            'orphan.txt' => 'a',
            'gone.txt' => 'a',
            'agreed.txt' => 'a',
            'untouched.txt' => 'a',
            'README.md' => 'a',
            '.env' => 'a',
        ]);

        $target = $this->tree([
            'update.txt' => 'b',
            'keep.txt' => 'a',
            'conflict.txt' => 'b',
            'gone.txt' => 'b',
            'agreed.txt' => 'b',
            'untouched.txt' => 'a',
            'README.md' => 'b',
            'added.txt' => 'b',
            'collide.txt' => 'b',
            '.env' => 'b',
        ]);

        $current = $this->tree([
            'update.txt' => 'a',
            'keep.txt' => 'mine',
            'conflict.txt' => 'mine',
            'delete.txt' => 'a',
            'orphan.txt' => 'mine',
            'agreed.txt' => 'b',
            'untouched.txt' => 'a',
            'README.md' => 'mine',
            'collide.txt' => 'mine',
            '.env' => 'mine',
        ]);

        $plan = Upgrader::classify($current, $base, $target, Ownership::of(['/.env'], ['/README.md']));

        self::assertEquals(
            [
                // Upstream moved, the project did not
                'update.txt' => Upgrader::UPDATE,
                // Upstream added it
                'added.txt' => Upgrader::ADD,
                // The project moved it, upstream did not
                'keep.txt' => Upgrader::KEEP,
                // Both moved, and not to the same place
                'conflict.txt' => Upgrader::CONFLICT,
                // Both invented the same path independently
                'collide.txt' => Upgrader::CONFLICT,
                // Upstream dropped a file the project had not touched
                'delete.txt' => Upgrader::DELETE,
                // Upstream dropped one the project had changed
                'orphan.txt' => Upgrader::ORPHANED,
                // The project deleted it
                'gone.txt' => Upgrader::GONE,
                // Replaced at creation, so never upgraded
                'README.md' => Upgrader::ONCE,
            ],
            $plan
        );

        // Nothing to do, so absent rather than reported: nobody moved untouched.txt, both
        // sides independently arrived at the same content for agreed.txt, and .env is ignored
        self::assertArrayNotHasKey('untouched.txt', $plan);
        self::assertArrayNotHasKey('agreed.txt', $plan);
        self::assertArrayNotHasKey('.env', $plan);
    }

    public function testAFileDeclinedEarlierKeepsItsOwnMergeBase(): void
    {
        $oldBase = $this->tree(['pinned.txt' => 'v1']);
        $base = $this->tree(['pinned.txt' => 'v2']);
        $target = $this->tree(['pinned.txt' => 'v3']);
        $current = $this->tree(['pinned.txt' => 'v2']);

        // Against the current base the project looks untouched, so this would silently update
        self::assertSame(
            ['pinned.txt' => Upgrader::UPDATE],
            Upgrader::classify($current, $base, $target, Ownership::of())
        );

        // Against the base it was declined at, the project's copy is a local change and the
        // decision is put back in front of the user rather than absorbed
        self::assertSame(
            ['pinned.txt' => Upgrader::CONFLICT],
            Upgrader::classify($current, $base, $target, Ownership::of(), ['pinned.txt' => $oldBase])
        );
    }

    public function testOnceOnlyOverridesOutcomesThatWouldWrite(): void
    {
        $base = $this->tree(['README.md' => 'a', 'LICENSE' => 'a']);
        $target = $this->tree(['README.md' => 'a', 'LICENSE' => 'b']);
        $current = $this->tree(['README.md' => 'mine']);

        $plan = Upgrader::classify($current, $base, $target, Ownership::of([], ['/README.md', '/LICENSE']));

        // Nobody is going to write README.md, so it stays a plain local change
        self::assertSame(Upgrader::KEEP, $plan['README.md']);
        // The project deleted LICENSE; "once" must not resurrect it
        self::assertSame(Upgrader::GONE, $plan['LICENSE']);
    }

    public function testNestedPathsAreClassifiedTheSameAsRootOnes(): void
    {
        $base = $this->tree(['docker/app/run.bash' => 'a', 'a/b/c/deep.txt' => 'a']);
        $target = $this->tree(['docker/app/run.bash' => 'b', 'a/b/c/deep.txt' => 'a']);
        $current = $this->tree(['docker/app/run.bash' => 'a', 'a/b/c/deep.txt' => 'mine']);

        self::assertSame(
            ['a/b/c/deep.txt' => Upgrader::KEEP, 'docker/app/run.bash' => Upgrader::UPDATE],
            Upgrader::classify($current, $base, $target, Ownership::of())
        );
    }

    public function testApplyWritesTakesAndRemovesDrops(): void
    {
        $target = $this->tree(['new.txt' => 'x', 'nested/deep.txt' => 'y']);
        $current = $this->tree(['old/gone.txt' => 'z']);

        Upgrader::apply(
            [
                'new.txt' => Upgrader::TAKE,
                'nested/deep.txt' => Upgrader::TAKE,
                'old/gone.txt' => Upgrader::DROP,
            ],
            $current,
            $target
        );

        self::assertSame('x', $this->read($current, 'new.txt'));
        self::assertSame('y', $this->read($current, 'nested/deep.txt'));
        self::assertFileDoesNotExist($current . '/old/gone.txt');

        // A directory left empty by a deletion goes too, rather than lingering as a stub
        self::assertDirectoryDoesNotExist($current . '/old');
    }

    public function testApplyKeepsTheExecutableBit(): void
    {
        $target = $this->tree(['scripts/run.bash' => '#!/bin/bash']);
        $current = $this->tree([]);

        chmod($target . '/scripts/run.bash', 0755);

        Upgrader::apply(['scripts/run.bash' => Upgrader::TAKE], $current, $target);

        self::assertSame(0755, fileperms($current . '/scripts/run.bash') & 0777);
    }

    public function testApplyNeverPrunesPastTheTreeRoot(): void
    {
        $current = $this->tree(['only.txt' => 'a']);

        Upgrader::apply(['only.txt' => Upgrader::DROP], $current, $this->tree([]));

        self::assertDirectoryExists($current);
    }

    public function testScoreCountsOnlyPathsBothTreesHave(): void
    {
        $reference = $this->tree(['a' => '1', 'b' => '2', 'c' => '3']);
        $current = $this->tree(['a' => '1', 'b' => 'different']);

        // c is absent locally, so it counts for neither side - a project that deleted a file
        // should not be judged to have come from a different release because of it
        self::assertSame(['matched' => 1, 'total' => 2], Upgrader::score($current, $reference, Ownership::of()));
    }

    public function testScoreIgnoresWhatOwnershipIgnores(): void
    {
        $reference = $this->tree(['kept' => '1', 'vendor/lib.php' => '1']);
        $current = $this->tree(['kept' => '1', 'vendor/lib.php' => 'different']);

        self::assertSame(
            ['matched' => 1, 'total' => 1],
            Upgrader::score($current, $reference, Ownership::of(['/vendor/']))
        );
    }

    public function testSymlinksAreNotFollowedOutOfTheTree(): void
    {
        $outside = $this->tree(['secret.txt' => 'nope']);
        $dir = $this->tree(['real.txt' => 'yes']);

        symlink($outside, $dir . '/escape');
        symlink($outside . '/secret.txt', $dir . '/link.txt');

        self::assertSame(['real.txt'], Upgrader::files($dir));
    }
}
