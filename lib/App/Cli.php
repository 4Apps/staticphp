<?php

namespace StaticPHP\Skeleton\App;

use StaticPHP\Skeleton\Upgrade\Cli as UpgradeCli;

/**
 * `staticphp app ...` - creating, listing and upgrading the applications under src/.
 *
 * Dispatched from the cli entry point alongside migrate and i18n, before the routing
 * bootstrap: creating an application has no business being reachable over http.
 */
class Cli
{
    public const DESCRIPTION = 'Create, list and upgrade the applications under src/';

    /**
     * @param  string[] $args Arguments after "app"
     * @return int Exit code
     */
    public static function run(array $args, string $root): int
    {
        $action = array_shift($args) ?? '';

        return match ($action) {
            'add' => self::add($args, $root),
            'list' => self::list($root),
            'upgrade' => UpgradeCli::runApp($args, $root),
            '--help', '-h', 'help' => self::usage($root, 0),
            default => self::usage($root, 1),
        };
    }

    /**
     * @param string[] $args
     */
    private static function add(array $args, string $root): int
    {
        $scaffolder = new Scaffolder($root);

        $name = '';
        $preset = '';
        $force = false;

        foreach ($args as $arg) {
            if ($arg === '--force') {
                $force = true;
                continue;
            }
            if (str_starts_with($arg, '--preset=')) {
                $preset = substr($arg, strlen('--preset='));
                continue;
            }
            if (str_starts_with($arg, '-')) {
                fwrite(STDERR, "Unknown option: {$arg}\n");
                return 1;
            }
            if ($name === '') {
                $name = $arg;
            }
        }

        if ($name === '') {
            fwrite(STDERR, "Usage: staticphp app add <Name> [--preset=<name>] [--force]\n");
            return 1;
        }

        if ($preset === '') {
            $preset = Presets::choose($scaffolder, 'twig');
        }

        try {
            $log = $scaffolder->create($name, $preset, $force);
        } catch (\RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            return 1;
        }

        echo "Created src/{$name} from the {$preset} preset:\n";
        foreach ($log as $line) {
            echo $line . "\n";
        }

        echo "\nNext:\n";
        foreach ($scaffolder->nextSteps($preset, $name) as $step) {
            echo "  {$step}\n";
        }

        return 0;
    }

    private static function list(string $root): int
    {
        $scaffolder = new Scaffolder($root);
        $apps = $scaffolder->apps();

        if ($apps === []) {
            echo "No applications under src/\n";
            return 0;
        }

        echo "Applications:\n";
        foreach ($apps as $app) {
            $entries = glob("{$root}/src/{$app}/Public/assets/src/*.{ts,tsx,js,jsx}", GLOB_BRACE);
            $bundles = array_map(
                static fn(string $path): string => pathinfo($path, PATHINFO_FILENAME),
                array_filter((array) $entries, static fn($p) => is_string($p) && str_ends_with($p, '.d.ts') === false)
            );

            printf("  %-20s %s\n", $app, ($bundles === [] ? '(no js entry)' : 'bundles: ' . implode(', ', $bundles)));
        }

        return 0;
    }

    /**
     * @param int $code What to exit with - asking for help succeeded, mistyping a
     *                  subcommand did not, and both want the same text
     */
    private static function usage(string $root, int $code): int
    {
        $scaffolder = new Scaffolder($root);

        echo "Applications live under src/, one directory each, sharing this project's\n";
        echo "composer and npm dependencies. They are created from presets/ rather than\n";
        echo "tracked, so a fresh checkout starts with none.\n\n";
        echo "Usage:\n";
        echo "  staticphp app add <Name> [--preset=<name>] [--force]\n";
        echo "  staticphp app list\n";
        echo "  staticphp app upgrade <Name> [--to=<tag>] [--from=<tag>] [--dry-run] [--yes]\n\n";
        echo "  add       --force overlays onto an existing directory instead of refusing.\n";
        echo "            Without --preset it asks, and takes twig when it cannot.\n";
        echo "  upgrade   Re-applies the preset's changes to an application already created\n";
        echo "            from it. `staticphp upgrade` does this for every application at\n";
        echo "            once; this is for doing one on its own.\n\n";
        echo "Presets:\n";

        foreach ($scaffolder->presets() as $preset) {
            printf("  %-10s %s\n", $preset, $scaffolder->presetDescription($preset));
        }

        return $code;
    }
}
