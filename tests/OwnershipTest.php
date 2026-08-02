<?php

namespace StaticPHP\Skeleton\Tests;

use PHPUnit\Framework\TestCase;
use StaticPHP\Skeleton\Upgrade\Ownership;

/**
 * The patterns in upgrade.json decide what an upgrade is allowed to touch, so a pattern that
 * matches more than it looks like it does is a way to lose work.
 */
final class OwnershipTest extends TestCase
{
    use TempTree;

    protected function tearDown(): void
    {
        $this->removeTempTrees();
    }

    public function testADirectoryPatternCoversEverythingBeneathIt(): void
    {
        $ownership = Ownership::of(['/vendor/']);

        self::assertTrue($ownership->isIgnored('vendor/a/b/c.php'));
        self::assertTrue($ownership->isIgnored('vendor/one.php'));

        // The directory entry itself, not a path inside it
        self::assertFalse($ownership->isIgnored('vendor'));

        // A sibling that merely starts with the same letters
        self::assertFalse($ownership->isIgnored('vendored/a.php'));
    }

    public function testAWildcardMatchesOneSegmentOnly(): void
    {
        $ownership = Ownership::of(['/src/*/']);

        self::assertTrue($ownership->isIgnored('src/Application/Public/index.php'));
        self::assertTrue($ownership->isIgnored('src/Shop/Config/App.php'));

        // src/.gitkeep is a file directly under src/, not an application
        self::assertFalse($ownership->isIgnored('src/.gitkeep'));
    }

    public function testAFilePatternMatchesTheWholePath(): void
    {
        $ownership = Ownership::of(['/.env']);

        self::assertTrue($ownership->isIgnored('.env'));

        // The one that ships with the skeleton and must keep being upgraded
        self::assertFalse($ownership->isIgnored('.env.example'));
        self::assertFalse($ownership->isIgnored('docker/.env'));
    }

    public function testSkipsCoversBothIgnoredAndStripped(): void
    {
        $ownership = Ownership::of(['/vendor/'], ['/README.md'], ['/CHANGELOG.md']);

        self::assertTrue($ownership->skips('vendor/a.php'));
        self::assertTrue($ownership->skips('CHANGELOG.md'));

        // "once" files are still examined - they are reported, just never written
        self::assertFalse($ownership->skips('README.md'));
        self::assertTrue($ownership->isOnce('README.md'));
    }

    public function testTheFirstReadableCandidateWins(): void
    {
        $second = $this->tree(['upgrade.json' => (string) json_encode(['ignore' => ['/second/']])]);

        $ownership = Ownership::load(['/nonexistent/upgrade.json', $second . '/upgrade.json']);

        self::assertTrue($ownership->isIgnored('second/a.php'));
    }

    public function testAMissingFileFallsBackToSomethingSafe(): void
    {
        $ownership = Ownership::load(['/nonexistent/upgrade.json']);

        // A project whose release predates upgrade.json still must not have src/ overwritten
        self::assertTrue($ownership->isIgnored('src/Application/Config/App.php'));
        self::assertTrue($ownership->isIgnored('.env'));
    }

    public function testApplicationRulesComeFromTheNestedBlock(): void
    {
        $dir = $this->tree([
            'upgrade.json' => (string) json_encode([
                'ignore' => ['/src/*/'],
                'app' => ['ignore' => ['/Cache/'], 'once' => ['/Config/App.php']],
            ]),
        ]);

        $ownership = Ownership::load([$dir . '/upgrade.json'], true);

        self::assertTrue($ownership->isIgnored('Cache/twig/x.php'));
        self::assertTrue($ownership->isOnce('Config/App.php'));

        // The root list describes paths that do not exist inside an application
        self::assertFalse($ownership->isIgnored('src/Whatever/x.php'));
    }

    public function testRulesCanComeStraightFromTheRepository(): void
    {
        $ownership = Ownership::fromJson((string) json_encode(['strip' => ['/CHANGELOG.md']]));

        self::assertInstanceOf(Ownership::class, $ownership);
        self::assertSame(['/CHANGELOG.md'], $ownership->stripList());
    }

    public function testUnusableJsonReportsItselfSoTheCallerCanFallBack(): void
    {
        self::assertNull(Ownership::fromJson(null));
        self::assertNull(Ownership::fromJson(''));
        self::assertNull(Ownership::fromJson('not json'));
    }

    public function testTheShippedRulesProtectTheThingsThatMatter(): void
    {
        $ownership = Ownership::load([dirname(__DIR__) . '/upgrade.json']);

        // Applications are upgraded by their own command, never by the skeleton one
        self::assertTrue($ownership->isIgnored('src/Application/Modules/Defaults/Controllers/Welcome.php'));

        // Lock files are generated; a text merge of one is worse than useless
        self::assertTrue($ownership->isIgnored('composer.lock'));
        self::assertTrue($ownership->isIgnored('package-lock.json'));

        // Replaced wholesale when the project was generated
        self::assertTrue($ownership->isOnce('README.md'));

        // Deleted when the project was generated, and must never be offered back
        self::assertTrue($ownership->isStripped('scripts/release_tag.bash'));
        self::assertTrue($ownership->isStripped('CHANGELOG.md'));

        // The workflow tests the skeleton - it scaffolds presets and rehearses upgrades -
        // so a project inheriting it would start with a red build it never asked for
        self::assertTrue($ownership->isStripped('.github/workflows/ci.yml'));

        // As does the suite covering the scaffolder and the upgrader
        self::assertTrue($ownership->isStripped('tests/UpgraderTest.php'));
        self::assertTrue($ownership->isStripped('phpunit.xml'));

        // Things a project genuinely wants upgraded
        self::assertFalse($ownership->skips('rspack.config.js'));
        self::assertFalse($ownership->skips('docker/app/Dockerfile'));
        self::assertFalse($ownership->skips('presets/_base/Public/index.php'));
        self::assertFalse($ownership->skips('composer.json'));
    }
}
