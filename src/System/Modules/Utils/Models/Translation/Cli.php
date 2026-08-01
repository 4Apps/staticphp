<?php

namespace System\Modules\Utils\Models\Translation;

use System\Modules\Core\Models\Config;
use System\Modules\Utils\Models\Db;

/**
 * The `staticphp i18n` command.
 *
 * Reached from ./staticphp before Bootstrap.php runs, for the same reason the migrations
 * command is: routing config has no notion of a cli-only route, so anything reachable
 * through a controller is also reachable over http - and this one deletes keys.
 */
class Cli
{
    /**
     * @var string
     * @access public
     */
    public const USAGE = <<<TXT
    Usage: staticphp i18n <command> [options]

    Commands:
      status                     Configured languages and how much of each is translated
      missing [<language>]       List untranslated keys
      keys                       List every registered key
      set <language> <key> <value>
                                 Write one translation
      export <language>          Write a language out as csv or json
      import <language> <file>   Read a language back in
      scan                       Compare the source tree against the database
      prune                      Delete keys the source tree no longer references
      clear [<language>]         Mark warmed copies stale
      install                    Write the schema into the migrations directory

    Options:
      --check             status: exit 1 if anything is untranslated (for CI)
      --write             scan: register the keys found in source
      --yes               prune: do not ask
      --overwrite         import: replace translations that are already there
      --upgrade           install: emit the upgrade from the pre-2.0 schema instead
      --format=FORMAT     export/import: csv or json (default: csv, import auto-detects)
      --out=PATH          export: write to this file instead of stdout
      --path=PATH         scan/prune: source tree to read (default: the application)
      --connection=NAME   Entry of config['db']['pdo'] to use (default: from config)
      --project=NAME      Application under src/ to load config from (default: Application)
      --help              This text

    TXT;

    /**
     * Options that are flags rather than name=value pairs.
     *
     * @var string[]
     * @access private
     */
    private const FLAGS = ['check', 'write', 'yes', 'overwrite', 'upgrade'];

    /**
     * Run the command.
     *
     * @access public
     * @static
     * @param  string[] $arguments Everything after "i18n"
     * @param  string   $basePath  Repository root, the directory holding src/
     * @return int Process exit code
     */
    public static function run(array $arguments, string $basePath): int
    {
        if (PHP_SAPI !== 'cli') {
            return 1;
        }

        $parsed = self::parse($arguments);
        if (is_int($parsed) === true) {
            return $parsed;
        }

        [$command, $positional, $options] = $parsed;

        $bootstrapped = self::bootstrap($basePath, $options['project']);
        if ($bootstrapped !== 0) {
            return $bootstrapped;
        }

        return self::dispatch($command, $positional, $options, $basePath);
    }

    /**
     * Split arguments into command, positional arguments and options.
     *
     * @access private
     * @static
     * @param  string[] $arguments
     * @return array|int The triple, or an exit code when there is nothing to run
     */
    private static function parse(array $arguments): array|int
    {
        $command = null;
        $positional = [];
        $options = [
            'check' => false,
            'write' => false,
            'yes' => false,
            'overwrite' => false,
            'upgrade' => false,
            'format' => null,
            'out' => null,
            'path' => null,
            'connection' => null,
            'project' => 'Application',
        ];

        foreach ($arguments as $argument) {
            if ($argument === '--help' || $argument === '-h') {
                echo self::USAGE;

                return 0;
            }

            if (str_starts_with($argument, '--') === false) {
                if ($command === null) {
                    $command = $argument;
                } else {
                    $positional[] = $argument;
                }

                continue;
            }

            $name = substr($argument, 2);
            $value = null;
            if (str_contains($name, '=') === true) {
                [$name, $value] = explode('=', $name, 2);
            }

            if (array_key_exists($name, $options) === false) {
                fwrite(STDERR, "error: unknown option --{$name}\n");

                return 2;
            }

            if (in_array($name, self::FLAGS, true) === true) {
                $options[$name] = true;
                continue;
            }

            if ($value === null || $value === '') {
                fwrite(STDERR, "error: --{$name} needs a value, as --{$name}=something\n");

                return 2;
            }

            $options[$name] = $value;
        }

        if ($command === null) {
            echo self::USAGE;

            return 2;
        }

        return [$command, $positional, $options];
    }

    /**
     * Define the framework paths, register the autoloader and load configuration.
     *
     * @access private
     * @static
     * @param  string $basePath
     * @param  string $project
     * @return int 0 on success, otherwise an exit code
     */
    private static function bootstrap(string $basePath, string $project): int
    {
        $projectPublic = "{$basePath}/src/{$project}/Public";
        if (is_dir($projectPublic) === false) {
            fwrite(STDERR, "error: project \"{$project}\" not found at {$projectPublic}\n");

            return 2;
        }

        if (defined('PUBLIC_PATH') === false) {
            define('PUBLIC_PATH', $projectPublic);
        }

        require_once "{$basePath}/src/System/Modules/Core/Helpers/Autoload.php";

        Config::load(['Config']);

        foreach ((array) Config::get('autoload_configs', []) as $item) {
            $parts = explode('/', $item);
            if (count($parts) === 3) {
                Config::load([$parts[2]], $parts[1], $parts[0]);
            } elseif (count($parts) === 2) {
                Config::load([$parts[1]], $parts[0]);
            } else {
                Config::load([$parts[0]]);
            }
        }

        // Framework defaults for anything the application did not load itself
        if (empty(Config::$items['db']['pdo'])) {
            Config::load(['Db'], 'Utils', 'System');
        }

        if (empty(Config::$items['i18n'])) {
            Config::load(['i18n'], 'Utils', 'System');
        }

        return 0;
    }

    /**
     * Connect and run the requested command.
     *
     * @access private
     * @static
     * @param  string   $command
     * @param  string[] $positional
     * @param  array    $options
     * @param  string   $basePath
     * @return int
     */
    private static function dispatch(string $command, array $positional, array $options, string $basePath): int
    {
        $settings = (array) Config::get('i18n', []);
        $connectionName = $options['connection'] ?? ($settings['db_config'] ?? 'default');

        $dbConfig = Config::$items['db']['pdo'][$connectionName] ?? null;
        if ($dbConfig === null) {
            $known = implode(', ', array_keys(Config::$items['db']['pdo'] ?? []));
            fwrite(
                STDERR,
                "error: no database connection \"{$connectionName}\" in config['db']['pdo']"
                . ($known === '' ? "\n" : " (configured: {$known})\n")
            );

            return 2;
        }

        try {
            Db::init($connectionName, $dbConfig);
        } catch (\Throwable $e) {
            fwrite(STDERR, "error: could not connect to \"{$connectionName}\": {$e->getMessage()}\n");

            return 1;
        }

        $out = function (string $line = ''): void {
            echo $line . "\n";
        };

        try {
            // strict: a command that silently degraded would report "0 keys" for a database
            // it never reached, and somebody would believe it
            $store = new Store(
                $connectionName,
                (string) ($settings['db_scheme'] ?? ''),
                (array) ($settings['tables'] ?? []),
                true,
            );

            $commands = new Commands($store, Locales::fromConfig($settings), $settings, $out);

            return self::runCommand($commands, $command, $positional, $options, $basePath);
        } catch (TranslationError $e) {
            fwrite(STDERR, "error: {$e->getMessage()}\n");

            return 1;
        } catch (\Throwable $e) {
            fwrite(STDERR, 'error: ' . get_class($e) . ": {$e->getMessage()}\n");

            return 1;
        }
    }

    /**
     * @access private
     * @static
     * @param  Commands $commands
     * @param  string   $command
     * @param  string[] $positional
     * @param  array    $options
     * @param  string   $basePath
     * @return int
     */
    private static function runCommand(
        Commands $commands,
        string $command,
        array $positional,
        array $options,
        string $basePath
    ): int {
        $paths = $options['path'] !== null
            ? array_map('trim', explode(',', (string) $options['path']))
            : [APP_PATH];

        switch ($command) {
            case 'status':
                return $commands->status($options['check']);

            case 'missing':
                return $commands->missing($positional[0] ?? null);

            case 'keys':
                return $commands->keys();

            case 'set':
                if (count($positional) < 3) {
                    fwrite(STDERR, "error: set needs a language, a key and a value\n");

                    return 2;
                }

                return $commands->set($positional[0], $positional[1], implode(' ', array_slice($positional, 2)));

            case 'export':
                if ($positional === []) {
                    fwrite(STDERR, "error: export needs a language, as: staticphp i18n export lv_en\n");

                    return 2;
                }

                return $commands->export($positional[0], (string) ($options['format'] ?? 'csv'), $options['out']);

            case 'import':
                if (count($positional) < 2) {
                    fwrite(STDERR, "error: import needs a language and a file\n");

                    return 2;
                }

                return $commands->import(
                    $positional[0],
                    $positional[1],
                    (string) ($options['format'] ?? 'auto'),
                    $options['overwrite']
                );

            case 'scan':
                return $commands->scan($paths, $options['write']);

            case 'prune':
                $prompt = function (string $question): string {
                    echo $question;

                    return (string) fgets(STDIN);
                };

                return $commands->prune($paths, $options['yes'], $prompt);

            case 'clear':
                return $commands->clear($positional[0] ?? null);

            case 'install':
                if (empty(Config::$items['migrations'])) {
                    Config::load(['Migrations'], 'Utils', 'System');
                }

                $migrations = (array) Config::get('migrations', []);

                return $commands->install(
                    $options['upgrade'],
                    (string) ($migrations['dir'] ?? APP_PATH . '/Migrations'),
                    "{$basePath}/src/System/Modules/Utils/Files/I18n",
                    time()
                );

            default:
                fwrite(STDERR, "error: unknown command \"{$command}\"\n\n");
                echo self::USAGE;

                return 2;
        }
    }
}
