<?php

namespace System\Modules\Utils\Models\Migrations;

use PDO;
use System\Modules\Core\Models\Config;
use System\Modules\Utils\Models\Db;
use System\Modules\Utils\Models\Migrations\Drivers\Driver;

/**
 * The `staticphp migrate` command.
 *
 * Reached from ./staticphp before Bootstrap.php runs, so nothing here ever touches the
 * router. That is deliberate rather than incidental: routing config has no notion of a
 * cli-only route, so a migrations controller would also answer "POST /migrations/apply"
 * over http - on a tool whose entire job is to change the schema.
 *
 * Because it is dispatched before the autoloader exists, ./staticphp requires this file
 * directly. Everything it uses afterwards autoloads normally.
 */
class Cli
{
    /**
     * @var string
     * @access public
     */
    public const USAGE = <<<TXT
    Usage: staticphp migrate <command> [options]

    Commands:
      status              List every migration and what it is doing
      apply               Apply every pending migration, in order
      new <name>          Create an empty migration file
      baseline            Record migrations as applied WITHOUT running them
      repair <name>       Re-stamp a migration's checksum after a deliberate edit
      forget <name>       Drop a migration's tracking row; does not touch the schema

    Options:
      --check             status: exit 1 if anything is pending or blocked (for CI)
      --dry-run           apply: list what would run, change nothing
      --to=PREFIX         apply/baseline: stop after the migration with this timestamp
      --yes               baseline: accept every candidate without prompting
      --connection=NAME   Entry of config['db']['pdo'] to use (default: from config)
      --dir=PATH          Override the migrations directory
      --table=NAME        Override the tracking table
      --project=NAME      Application under src/ to load config from (default: Application)
      --help              This text

    TXT;

    /**
     * Options that are flags rather than name=value pairs.
     *
     * @var string[]
     * @access private
     */
    private const FLAGS = ['check', 'dry-run', 'yes'];

    /**
     * Run the command.
     *
     * @access public
     * @static
     * @param  string[] $arguments Everything after "migrate"
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

        return self::dispatch($command, $positional, $options);
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
            'dry-run' => false,
            'yes' => false,
            'to' => null,
            'connection' => null,
            'dir' => null,
            'table' => null,
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
     * Everything Bootstrap.php does that a schema tool needs, and nothing it does that a
     * schema tool does not - no router, no session, no view engine.
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

        if (empty(Config::$items['migrations'])) {
            Config::load(['Migrations'], 'Utils', 'System');
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
     * @return int
     */
    private static function dispatch(string $command, array $positional, array $options): int
    {
        $settings = (array) Config::get('migrations', []);
        $connectionName = $options['connection'] ?? ($settings['connection'] ?? 'default');
        $directory = $options['dir'] ?? ($settings['dir'] ?? APP_PATH . '/Migrations');
        $table = $options['table'] ?? ($settings['table'] ?? 'migrations');

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

        // A persistent connection is handed back to the pool without ending the session, so
        // a crashed run would leave pg_advisory_lock / GET_LOCK held by a connection nobody
        // owns and nobody can release. The lock's whole safety story is "the process dies,
        // the connection drops, the lock goes"; pooling breaks it.
        $dbConfig['persistent'] = false;

        try {
            $pdo = Db::init($connectionName, $dbConfig);
        } catch (\Throwable $e) {
            fwrite(STDERR, "error: could not connect to \"{$connectionName}\": {$e->getMessage()}\n");

            return 1;
        }

        $out = function (string $line = ''): void {
            echo $line . "\n";
        };

        $appliedBy = ((string) Config::get('version', 'unknown')) . ' @ ' . (gethostname() ?: 'unknown');

        try {
            $driver = Driver::forPdo($pdo, $dbConfig['string'] ?? null);
            $commands = new Commands($pdo, new Tracker($pdo, $driver, $table), $directory, $out);

            return self::runCommand($commands, $command, $positional, $options, $appliedBy);
        } catch (MigrationError $e) {
            fwrite(STDERR, "error: {$e->getMessage()}\n");

            return 1;
        } catch (\Throwable $e) {
            // Never exit 0 on an unexpected failure: an unattended deploy would read that as
            // "migrations succeeded" and go on to restart services against an unmigrated
            // schema.
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
     * @param  string   $appliedBy
     * @return int
     */
    private static function runCommand(
        Commands $commands,
        string $command,
        array $positional,
        array $options,
        string $appliedBy
    ): int {
        switch ($command) {
            case 'status':
                return $commands->status($options['check']);

            case 'apply':
                return $commands->apply($options['dry-run'], $options['to'], $appliedBy);

            case 'new':
                if ($positional === []) {
                    fwrite(STDERR, "error: new needs a name, as: staticphp migrate new \"add users table\"\n");

                    return 2;
                }

                return $commands->create(implode(' ', $positional), time());

            case 'baseline':
                $prompt = function (string $question): string {
                    echo $question;

                    return (string) fgets(STDIN);
                };

                return $commands->baseline($options['to'], $options['yes'], $appliedBy, $prompt);

            case 'repair':
                if ($positional === []) {
                    fwrite(STDERR, "error: repair needs a migration filename\n");

                    return 2;
                }

                return $commands->repair($positional[0]);

            case 'forget':
                if ($positional === []) {
                    fwrite(STDERR, "error: forget needs a migration filename\n");

                    return 2;
                }

                return $commands->forget($positional[0]);

            default:
                fwrite(STDERR, "error: unknown command \"{$command}\"\n\n");
                echo self::USAGE;

                return 2;
        }
    }
}
