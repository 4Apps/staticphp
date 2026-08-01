<?php

namespace StaticPHP\Skeleton;

require_once __DIR__ . '/Scaffolder.php';

/**
 * `staticphp app ...` - creating and listing the applications under src/.
 *
 * Dispatched from the cli entry point alongside migrate and i18n, before the routing
 * bootstrap: creating an application has no business being reachable over http.
 */
class AppCli
{
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
            default => self::usage($root),
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

    private static function usage(string $root): int
    {
        $scaffolder = new Scaffolder($root);

        echo "Usage:\n";
        echo "  staticphp app add <Name> [--preset=<name>] [--force]\n";
        echo "  staticphp app list\n\n";
        echo "Presets:\n";

        foreach ($scaffolder->presets() as $preset) {
            printf("  %-10s %s\n", $preset, $scaffolder->presetDescription($preset));
        }

        return 1;
    }
}
