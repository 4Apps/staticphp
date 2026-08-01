<?php

namespace System\Modules\Core\Models;

use System\Modules\Core\Models\Config;
use System\Modules\Core\Models\Router;

/**
 * Core class for loading resources.
 */
class Load
{
    /**
     * Generate UUID v4.
     *
     * Uses random_bytes rather than mt_rand: the Mersenne Twister's state can be
     * recovered from a modest amount of observed output, which would make every
     * subsequent value predictable - including the filenames derived from it below.
     *
     * @access public
     * @static
     * @return string
     */
    public static function uuid4(): string
    {
        $data = random_bytes(16);

        // Set version to 0100 and bits 6-7 to 10, per RFC 4122
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Generate sha1 hash from random v4 uuid.
     *
     * @see Load::uuid4()
     * @access public
     * @static
     * @return string
     */
    public static function randomHash(): string
    {
        return bin2hex(random_bytes(20));
    }

    /**
     * Generate hashed path.
     *
     * Generate hashed path to avoid reaching files per directory limit ({@link http://stackoverflow.com/a/466596}).
     * By default it will create directories 2 levels deep and 2 symbols long, for example,
     * for a filename /www/upload/files/image.jpg, it will generate filename /www/upload/files/ge/ma/image.jpg and
     * optionally create all directories. It is also suggested for cases where image name is not important to set
     * $randomize to true. This way generated filename becomes a sha1 hash and will provide better file distribution
     * between directories.
     *
     * @see Load::randomHash()
     * @access public
     * @static
     * @param  string   $filename
     * @param  bool     $randomize             (default: false)
     * @param  bool     $createDirectories    (default: false)
     * @param  int      $levelsDeep           (default: 2)
     * @param  int      $directoryNameLength (default: 2)
     * @return string[] An array of string objects:
     *                  <ul>
     *                  <li>'hash_dir' Contains only hashed directory (e.g. ge/ma);</li>
     *                  <li>'hash_file' hash_dir + filename (ge/ma/image.jpg);</li>
     *                  <li>'filename' Filename without extension;</li>
     *                  <li>'ext' File extension;</li>
     *                  <li>'dir' Absolute path to file's containing directory, including hashed directories
     *                        (/www/upload/files/ge/ma/);</li>
     *                  <li>'file' Full path to a file.</li>
     *                  </ul>
     */
    public static function hashedPath(
        string $filename,
        bool $randomize = false,
        bool $createDirectories = false,
        int $levelsDeep = 2,
        int $directoryNameLength = 2
    ): array {
        // Explode path to get filename
        $parts = explode(DIRECTORY_SEPARATOR, $filename);

        // Predefine array elements
        $data['hash_dir'] = '';
        $data['hash_file'] = '';

        // Get filename and extension
        $data['filename'] = explode('.', array_pop($parts));
        $data['ext'] = (count($data['filename']) > 1 ? array_pop($data['filename']) : '');
        $data['filename'] = (empty($randomize) ? implode('.', $data['filename']) : self::randomHash());

        if (strlen($data['filename']) < $levelsDeep * $directoryNameLength) {
            throw new \Exception(
                '
                    Filename length too small to satisfy
                    how much sub-directories and how long
                    each directory name should be made.
                '
            );
        }

        // Put directory together
        $dir = (empty($parts) ? '' : implode('/', $parts) . '/');

        // Create hashed directory
        for ($i = 1; $i <= $levelsDeep; ++$i) {
            $data['hash_dir'] .= substr($data['filename'], -1 * $directoryNameLength * $i, $directoryNameLength);
            $data['hash_dir'] .= '/';
        }

        // Put other stuff together
        $data['dir'] = str_replace($data['hash_dir'], '', $dir) . $data['hash_dir'];
        $data['file'] = $data['dir'] . $data['filename'] . (empty($data['ext']) ? '' : '.' . $data['ext']);
        $data['hash_file'] = $data['hash_dir'] . $data['filename'] . (empty($data['ext']) ? '' : '.' . $data['ext']);

        // Create directories
        if (!empty($createDirectories) && !is_dir($data['dir'])) {
            mkdir($data['dir'], 0770, true);
        }

        return $data;
    }

    /**
     * Delete file and directories created by Load::hashedPath.
     *
     * @see Load::hashedPath
     * @access public
     * @static
     * @param  string $filename
     * @return void
     */
    public static function deleteHashedFile(string $filename): void
    {
        $path = self::hashedPath($filename);

        // Trim off / from end
        $path['hash_dir'] = rtrim($path['hash_dir'], '/');
        $path['dir'] = rtrim($path['dir'], '/');

        // Explode hash directories to get the count of them
        $expl = explode('/', $path['hash_dir']);

        // Unlink the file
        if (is_file($path['file'])) {
            unlink($path['file']);
        }

        // Remove directories
        foreach ($expl as $null) {
            if (!@rmdir($path['dir'])) {
                break;
            }

            $path['dir'] = dirname($path['dir']);
        }
    }

    /*
    |-------------------------------------------------------------------------------------------------------------------
    | File Loading
    |-------------------------------------------------------------------------------------------------------------------
    */

    /**
     * Load configuration files.
     *
     * Load configuration files from current application's config directory (APP_PATH/config) or
     * from other application by providing name in $project parameter.
     *
     * @access public
     * @static
     * @param  array $files
     * @param  string|null  $project (default: null)
     * @return void
     */
    public static function config(
        array $files,
        ?string $module = null,
        ?string $project = null,
        ?array &$config = null
    ): void {
        if ($config === null) {
            $config = &Config::$items;
        } else {
            Config::$items = &$config;
        }

        foreach ((array) $files as $key => $name) {
            $project1 = $project;
            if (is_numeric($key) === false) {
                $project1 = $name;
                $name = $key;
            }

            $file = '';
            if (!empty($module)) {
                $file = (empty($project1) ? APP_MODULES_PATH : BASE_PATH . "/{$project1}/Modules");
                $file .= "/{$module}";
            } else {
                $file = (empty($project1) ? APP_PATH : BASE_PATH . "/{$project1}");
            }
            $file .= "/Config/{$name}.php";

            require($file);
        }
    }

    /**
     * Load controller files.
     *
     * Load controller files from current application's $module/controllers directory or
     * from other $project/$module/controllers by providing $project name.
     *
     * @access public
     * @static
     * @param  array $files
     * @param  string|null  $project (default: null)
     * @return void
     */
    public static function controller(array $files, ?string $module = null, ?string $project = null): void
    {
        foreach ((array) $files as $key => $name) {
            $project1 = $project;
            if (is_numeric($key) === false) {
                $project1 = $name;
                $name = $key;
            }

            $file = '';
            if (!empty($module)) {
                $file = (empty($project1) ? APP_MODULES_PATH : BASE_PATH . "/{$project1}/Modules");
                $file .= "/{$module}";
            } else {
                $file = (empty($project1) ? APP_PATH : BASE_PATH . "/{$project1}");
            }
            $file .= "/Controllers/{$name}.php";

            require($file);
        }
    }

    /**
     * Load model files.
     *
     * Load model files from current application's $module/models directory or
     * from other $project/$module/models by providing $project name.
     *
     * @access public
     * @static
     * @param  array $files
     * @param  string|null  $project (default: null)
     * @return void
     */
    public static function model(array $files, ?string $module = null, ?string $project = null): void
    {
        foreach ((array) $files as $key => $name) {
            $project1 = $project;
            if (is_numeric($key) === false) {
                $project1 = $name;
                $name = $key;
            }

            $file = '';
            if (!empty($module)) {
                $file = (empty($project1) ? APP_MODULES_PATH : BASE_PATH . "/{$project1}/Modules");
                $file .= "/{$module}";
            } else {
                $file = (empty($project1) ? APP_PATH : BASE_PATH . "/{$project1}");
            }
            $file .= "/Models/{$name}.php";

            require($file);
        }
    }

    /**
     * Load helper files.
     *
     * Load helper files from current application's $module/helpers directory or
     * from other $project/$module/helpers by providing $project name.
     *
     * @access public
     * @static
     * @param  array $files
     * @param  string|null  $project (default: null)
     * @return void
     */
    public static function helper(array $files, ?string $module = null, ?string $project = null): void
    {
        foreach ((array) $files as $key => $name) {
            $project1 = $project;
            if (is_numeric($key) === false) {
                $project1 = $name;
                $name = $key;
            }

            $file = '';
            if (!empty($module)) {
                $file = (empty($project1) ? APP_MODULES_PATH : BASE_PATH . "/{$project1}/Modules");
                $file .= "/{$module}";
            } else {
                $file = (empty($project1) ? APP_PATH : BASE_PATH . "/{$project1}");
            }
            $file .= "/Helpers/{$name}.php";

            require($file);
        }
    }

    /**
     * Keys whose values must never be handed to a template.
     *
     * @var string
     * @access private
     */
    private const SENSITIVE_KEY_PATTERN = '/(pass|passwd|pwd|secret|token|api_?key|credential|salt|dsn|private)/i';

    /**
     * Environment values exposed to templates.
     *
     * The whole of $_ENV used to be a template global, so with symfony/dotenv loading a
     * .env file every template could read the database password. Only the keys named in
     * $config['view_env_keys'] are exposed now.
     *
     * @access private
     * @static
     * @return array
     */
    private static function safeEnvForViews(): array
    {
        $allowed = (array) Config::get('view_env_keys', []);

        $env = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $_ENV)) {
                $env[$key] = $_ENV[$key];
            }
        }

        return $env;
    }

    /**
     * Configuration exposed to templates, with credentials removed.
     *
     * Config::$items holds the database configuration among other things, so handing it
     * over whole let any template read connection passwords.
     *
     * @access private
     * @static
     * @param  ?array $config (default: null)
     * @param  int    $depth  (default: 0)
     * @return array
     */
    private static function safeConfigForViews(?array $config = null, int $depth = 0): array
    {
        $config = ($config === null ? Config::$items : $config);

        $safe = [];
        foreach ($config as $key => $value) {
            if (preg_match(self::SENSITIVE_KEY_PATTERN, (string) $key)) {
                continue;
            }

            // The view engine and loader are objects that reach back into everything else
            if ($key === 'view_engine' || $key === 'view_loader' || $key === 'db') {
                continue;
            }

            if (is_array($value)) {
                // Guard against a config array that references itself
                $safe[$key] = ($depth >= 16 ? [] : self::safeConfigForViews($value, $depth + 1));
                continue;
            }

            $safe[$key] = $value;
        }

        return $safe;
    }

    /**
     * Render a view or multiple views.
     *
     * Render views from current application's view directory (APP_PATH/views).
     * Setting $return to true, instead of outputing, rendered view's html will be returned.
     *
     * @access public
     * @static
     * @param  array $files
     * @param  array        $data  (default: [])
     * @param  bool         $return (default: false)
     * @return string|bool
     */
    public static function view(array $files, array &$data = [], bool $return = false): string|bool
    {
        static $globalsAdded = false;

        // Check for global views variables, can be set, for example, by controller's constructor
        if (!empty(Config::$items['view_data'])) {
            $data = (array) $data + (array) Config::$items['view_data'];
        }

        if (empty(Config::$items['view_engine'])) {
            if (!empty($return)) {
                return false;
            }

            $config = self::safeConfigForViews();
            foreach ((array) $files as $key => $file) {
                $path = APP_MODULES_PATH . "/{$file}";
                if (Router::pathIsWithin($path, APP_MODULES_PATH) === false) {
                    throw new \RuntimeException("View outside of the modules directory: \"{$file}\"");
                }

                require $path;
            }

            return true;
        }

        // Add default view data
        if (empty($globalsAdded)) {
            Config::$items['view_engine']->addGlobal('env', self::safeEnvForViews());
            Config::$items['view_engine']->addGlobal('now', Config::$items['now']);
            Config::$items['view_engine']->addGlobal('date_time', Config::$items['date_time']);
            Config::$items['view_engine']->addGlobal('config', self::safeConfigForViews());
            Config::$items['view_engine']->addGlobal('session', $_SESSION ?? []);
            Config::$items['view_engine']->addGlobal('cookie', $_COOKIE ?? []);
            Config::$items['view_engine']->addGlobal('base_url', Router::$base_url);
            Config::$items['view_engine']->addGlobal('namespace', Router::$namespace);
            Config::$items['view_engine']->addGlobal('class', Router::$class);
            Config::$items['view_engine']->addGlobal('method', Router::$method);
            Config::$items['view_engine']->addGlobal('segments', Router::$segments);
            $globalsAdded = true;
        }

        // Load view data
        $contents = '';
        foreach ((array) $files as $key => $file) {
            $contents .= Config::$items['view_engine']->render($file, (array) $data);
        }

        // Output or return view data
        if (empty($return)) {
            echo $contents;

            return true;
        }

        return $contents;
    }
}
