#!/usr/bin/env php
<?php

/**
 * Bootstraps a freshly created project.
 *
 * Two entry points, because they are not equally safe to repeat:
 *
 *   composer setup         Creates the missing .env files. Idempotent, run it whenever.
 *   --new-project          Everything above, plus the one-way cleanup below. Composer runs
 *                          this from post-create-project-cmd; running it inside the
 *                          skeleton's own checkout would delete the skeleton's tooling.
 *
 * The .env files are gitignored, so they are absent from the dist archive composer unpacks -
 * without them the first `docker compose up` fails on a missing env_file, and the
 * application boots with no APP_ENV. Existing files are never overwritten.
 *
 * The cleanup drops what only ever described the skeleton itself: the scripts that publish
 * 4apps/staticphp to Packagist, the 1.x upgrade aids, and the package metadata. Left in
 * place they are at best noise and at worst wrong - a generated project that still calls
 * itself 4apps/staticphp, or a release script that offers to publish it.
 */

require_once __DIR__ . '/Scaffolder.php';
require_once __DIR__ . '/Presets.php';

use StaticPHP\Skeleton\Presets;
use StaticPHP\Skeleton\Scaffolder;

$root = dirname(__DIR__);
$newProject = in_array('--new-project', array_slice($argv, 1), true);

/**
 * The preset used when nobody says otherwise.
 */
const DEFAULT_PRESET = 'twig';

/**
 * Lays down the project's first application.
 *
 * No application is tracked in the skeleton - presets/ is the source - so there is nothing
 * to replace here, only something to create. Re-running is safe: the scaffolder refuses to
 * touch a directory that already exists.
 *
 * @param string $root Project root.
 * @param string $preset Preset name.
 * @return void
 */
function createFirstApp(string $root, string $preset): void
{
    $scaffolder = new Scaffolder($root);

    if ($scaffolder->apps() !== []) {
        echo '  skip    application (src/' . implode(', src/', $scaffolder->apps()) . " already present)\n";
        return;
    }

    try {
        foreach ($scaffolder->create('Application', $preset) as $line) {
            echo $line . "\n";
        }
    } catch (\RuntimeException $e) {
        echo '  failed  ' . $e->getMessage() . "\n";
    }
}

/**
 * Drops the block of .gitignore that only makes sense inside the skeleton.
 *
 * There, an application is a build artifact and tracking one would mean every fix landing
 * twice. In a generated project the application is the whole point and belongs in git.
 *
 * @param string $root Project root.
 * @return void
 */
function untrackSkeletonIgnores(string $root): void
{
    $path = $root . '/.gitignore';
    $contents = @file_get_contents($path);
    if ($contents === false) {
        return;
    }

    $stripped = preg_replace(
        '/^# SKELETON ONLY.*?^# END SKELETON ONLY\n/ms',
        '',
        $contents
    );

    if ($stripped === null || $stripped === $contents) {
        return;
    }

    file_put_contents($path, $stripped);
    echo "  reset   .gitignore (src/ is tracked in a real project)\n";
}

/**
 * Copies an example file to its real counterpart, optionally rewriting lines on the way.
 *
 * @param string $root Project root.
 * @param string $target Path of the file to create, relative to the project root.
 * @param callable|null $filter Receives the example's contents, returns what to write.
 * @return void
 */
function createFromExample(string $root, string $target, ?callable $filter = null): void
{
    $targetPath = $root . '/' . $target;
    $examplePath = $targetPath . '.example';

    if (is_file($targetPath)) {
        echo "  skip    {$target} (already exists)\n";
        return;
    }

    if (is_file($examplePath) === false) {
        echo "  missing {$target}.example\n";
        return;
    }

    $contents = file_get_contents($examplePath);
    if ($contents === false) {
        echo "  failed  reading {$target}.example\n";
        return;
    }

    if ($filter !== null) {
        $contents = $filter($contents);
    }

    if (file_put_contents($targetPath, $contents) === false) {
        echo "  failed  writing {$target}\n";
        return;
    }

    echo "  created {$target}\n";
}

/**
 * Points the container's user at the account running this script. Mismatched ids are the usual
 * cause of root-owned files appearing in the bind mounted source tree.
 *
 * @param string $contents Contents of .env.example.
 * @return string
 */
function applyLocalIds(string $contents): string
{
    if (function_exists('posix_getuid') === false) {
        return $contents;
    }

    $ids = ['LOCAL_USER_ID' => posix_getuid(), 'LOCAL_GROUP_ID' => posix_getgid()];
    foreach ($ids as $key => $value) {
        // A preg_replace failure returns null; keeping the unsubstituted text is better
        // than writing an empty .env
        $contents = preg_replace('/^' . $key . '=.*$/m', "{$key}={$value}", $contents) ?? $contents;
    }

    return $contents;
}

/**
 * @param string $root Project root.
 * @param string $target Path to delete, relative to the project root.
 * @return void
 */
function removeFile(string $root, string $target): void
{
    $path = $root . '/' . $target;

    if (is_file($path) === false) {
        echo "  gone    {$target}\n";
        return;
    }

    if (unlink($path) === false) {
        echo "  failed  removing {$target}\n";
        return;
    }

    echo "  removed {$target}\n";
}

/**
 * Strips the skeleton's identity out of composer.json. Nothing here is published - the
 * package is a project - but a generated application should not answer to the skeleton's
 * name, and post-create-project-cmd can never fire again once it has run.
 *
 * "name" is one of the keys composer folds into the lock file's content-hash, so rewriting
 * it invalidates composer.lock and the next `composer install` refuses to run. The
 * post-create-project-cmd entry follows this with `composer update --lock`, which rewrites
 * the hash and nothing else.
 *
 * @param string $root Project root.
 * @return void
 */
function resetComposerMetadata(string $root): void
{
    $path = $root . '/composer.json';

    $contents = file_get_contents($path);
    if ($contents === false) {
        echo "  failed  reading composer.json\n";
        return;
    }

    $composer = json_decode($contents, true);
    if (is_array($composer) === false) {
        echo "  failed  parsing composer.json\n";
        return;
    }

    $composer['name'] = 'vendor/app';
    $composer['description'] = '';
    $composer['license'] = 'proprietary';
    unset($composer['authors']);

    if (is_array($composer['scripts'] ?? null)) {
        unset($composer['scripts']['post-create-project-cmd']);
    }

    $encoded = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    if (file_put_contents($path, $encoded) === false) {
        echo "  failed  writing composer.json\n";
        return;
    }

    echo "  reset   composer.json metadata\n";
}

/**
 * @param string $root Project root.
 * @param string $target Path to write, relative to the project root.
 * @param string $contents What to write.
 * @return void
 */
function replaceFile(string $root, string $target, string $contents): void
{
    if (file_put_contents($root . '/' . $target, $contents) === false) {
        echo "  failed  writing {$target}\n";
        return;
    }

    echo "  reset   {$target}\n";
}

echo "Setting up the project:\n";

createFromExample($root, '.env', 'applyLocalIds');

// Asked before anything is written, because it decides what the application is. Composer
// passes its stdin through and detaches it under --no-interaction, so Presets::choose()
// prompts a human and silently takes the default in CI. `composer setup` runs this too,
// which is what gives a fresh checkout of the skeleton an application to work with.
createFirstApp($root, Presets::choose(new Scaffolder($root), DEFAULT_PRESET));

if ($newProject === true) {
    untrackSkeletonIgnores($root);

    // Publishes 4apps/staticphp to Packagist. An application tags its own releases however
    // it likes; what it must not inherit is a script offering to publish the skeleton.
    removeFile($root, 'scripts/version.bash');
    removeFile($root, 'scripts/release_tag.bash');

    // 1.x -> 2.0 migration aids. A project starting on 2.0 has nothing to upgrade.
    removeFile($root, 'scripts/upgrade_v2_namespaces.bash');
    removeFile($root, 'UPGRADE.md');

    // The skeleton's own history, back to 0.8. Not this project's.
    removeFile($root, 'CHANGELOG.md');

    resetComposerMetadata($root);

    // scripts/build_info.bash reads this and reports v<major.minor>.<commit count>. Left at
    // the skeleton's number, a brand new application would introduce itself as 2.0.
    replaceFile($root, '.version', "0.1\n");

    replaceFile($root, 'README.md', <<<'MARKDOWN'
    # Application

    Built on [StaticPHP](https://github.com/gintsmurans/staticphp) - framework documentation,
    configuration reference and upgrade notes live there.

    ## Running it

        composer setup            # creates .env and src/Application/.env when missing
        npm install               # scss and typescript toolchain
        docker compose up -d develop

    Or without docker, serve `src/Application/Public` directly:

        php -S 0.0.0.0:8081 -t src/Application/Public

    ## Checks

        composer test             # phpunit
        composer lint             # phpcs
        ./scripts/build_info.bash # stamps .build_info.json from .version and git

    MARKDOWN);
}

echo "\nNext steps:\n";
echo "  - review .env and src/Application/.env\n";
echo "  - npm install, for the scss and typescript build\n";
echo "  - docker compose up -d develop, or serve src/Application/Public with php -S\n";
