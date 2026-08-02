<?php

namespace StaticPHP\Skeleton\Tests;

use PHPUnit\Framework\TestCase;
use StaticPHP\Skeleton\Upgrade\Cli as UpgradeCli;

/**
 * The one step that rewrites a file somebody has edited.
 *
 * The design rests on a claim worth checking rather than assuming: that with the upstream
 * release the project sits on as the merge base, the rewrites made when a project was
 * generated and the changes made by a later release land on different lines and merge
 * without a word. If that were false, composer.json would collide on every single upgrade.
 */
final class MergeTest extends TestCase
{
    use TempTree;

    protected function tearDown(): void
    {
        $this->removeTempTrees();
    }

    public function testDisjointEditsMergeWithoutAConflict(): void
    {
        $base = $this->tree(['f.txt' => "one\ntwo\nthree\n"]);
        $target = $this->tree(['f.txt' => "one\ntwo\nTHEIRS\n"]);
        $current = $this->tree(['f.txt' => "MINE\ntwo\nthree\n"]);

        self::assertTrue(UpgradeCli::merge('f.txt', $current, $base, $target));
        self::assertSame("MINE\ntwo\nTHEIRS\n", $this->read($current, 'f.txt'));
    }

    public function testTheGeneratedProjectRewriteSurvivesADependencyBump(): void
    {
        // Exactly the situation post_create_project.php creates: the project owns the name,
        // upstream owns the dependency versions, and both change over time
        $base = $this->tree(['composer.json' => $this->composer('4apps/staticphp', '^2.0.333')]);
        $target = $this->tree(['composer.json' => $this->composer('4apps/staticphp', '^2.1.400')]);
        $current = $this->tree(['composer.json' => $this->composer('vendor/app', '^2.0.333')]);

        self::assertTrue(UpgradeCli::merge('composer.json', $current, $base, $target));

        $merged = $this->read($current, 'composer.json');

        // The project keeps its identity and gains the newer framework
        self::assertStringContainsString('"name": "vendor/app"', $merged);
        self::assertStringContainsString('"4apps/staticphp-core": "^2.1.400"', $merged);
        self::assertStringNotContainsString('<<<<<<<', $merged);
        self::assertIsArray(json_decode($merged, true));
    }

    public function testAGenuineCollisionLeavesMarkersAndSaysSo(): void
    {
        $base = $this->tree(['f.txt' => "shared\n"]);
        $target = $this->tree(['f.txt' => "theirs\n"]);
        $current = $this->tree(['f.txt' => "mine\n"]);

        self::assertFalse(UpgradeCli::merge('f.txt', $current, $base, $target));

        $merged = $this->read($current, 'f.txt');

        self::assertStringContainsString('<<<<<<< mine', $merged);
        self::assertStringContainsString('>>>>>>> theirs', $merged);

        // Both sides are still there to choose between
        self::assertStringContainsString('mine', $merged);
        self::assertStringContainsString('theirs', $merged);
    }

    public function testAFileWithNoTrailingNewlineIsNotGivenOne(): void
    {
        $base = $this->tree(['f.txt' => 'one']);
        $target = $this->tree(['f.txt' => 'two']);
        $current = $this->tree(['f.txt' => 'one']);

        UpgradeCli::merge('f.txt', $current, $base, $target);

        self::assertSame('two', $this->read($current, 'f.txt'));
    }

    private function composer(string $name, string $core): string
    {
        return <<<JSON
        {
            "name": "{$name}",
            "description": "",
            "type": "project",
            "require": {
                "php": ">=8.4",
                "4apps/staticphp-core": "{$core}",
                "twig/twig": "^3.0"
            }
        }

        JSON;
    }
}
