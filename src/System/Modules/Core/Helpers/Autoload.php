<?php

use System\Modules\Core\Models\Config;

if (defined('PUBLIC_PATH') == false) {
    define(
        'PUBLIC_PATH',
        realpath(dirname(__FILE__) . '/../../../..')
            . '/Application/Public'
    );
}

if (defined('APP_PATH') == false) {
    define('APP_PATH', dirname(PUBLIC_PATH));
    define('APP_MODULES_PATH', APP_PATH . '/Modules');
    define('BASE_PATH', dirname(APP_PATH));
    define('SYS_PATH', BASE_PATH . '/System');
    define('SYS_MODULES_PATH', SYS_PATH . '/Modules');

    $vendorPath = BASE_PATH . '/vendor';
    if (is_dir($vendorPath) == false) {
        $vendorPath = realpath(BASE_PATH . '/../vendor');
    }
    define('VENDOR_PATH', $vendorPath);
}

spl_autoload_register(
    function ($classname) {
        $classname = str_replace('\\', '/', $classname);
        $classname = ltrim($classname, '/');

        // Class names reach this point from url segments via the router, so a name
        // containing ".." would otherwise turn into an include of an arbitrary file.
        // Every component has to be a plain identifier.
        foreach (explode('/', $classname) as $part) {
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $part) !== 1) {
                return;
            }
        }

        $roots = [APP_MODULES_PATH, APP_PATH, SYS_MODULES_PATH, SYS_PATH, BASE_PATH];
        foreach ($roots as $root) {
            $file = "{$root}/{$classname}.php";
            if (is_file($file) === false) {
                continue;
            }

            // Defence in depth: confirm the resolved path really is under the root,
            // in case a symlink inside the tree points somewhere else.
            $realFile = realpath($file);
            $realRoot = realpath($root);
            if ($realFile === false || $realRoot === false) {
                continue;
            }

            if (str_starts_with($realFile, rtrim($realRoot, '/') . '/') === false) {
                continue;
            }

            include $realFile;

            return;
        }
    },
    true
);

// Load composer autoload
if (Config::get('disable_twig') !== true) {
    include_once VENDOR_PATH . '/autoload.php';
}
