<?php

use System\Modules\Core\Models\Load;
use System\Modules\Core\Models\Logger;
use System\Modules\Core\Models\Config;
use System\Modules\Core\Models\Router;
use System\Modules\Core\Models\Timers;
use System\Modules\Utils\Models\ExtendedDateTime;

// Set microtime
$microtime = microtime(true);

// Autoload
require_once dirname(__FILE__) . '/Autoload.php';

// Load default config file and routing
Config::load(['Config', 'Routing']);

// Set debug
Config::$items['now'] = time();
Config::$items['date_time'] = new ExtendedDateTime();
Config::$items['debug'] = (
    Config::get('debug')
    || in_array(
        Config::get('client_ip', '127.0.0.1'),
        (array)Config::get('debug_ips', [])
    )
);
ini_set(
    'error_reporting',
    (!empty(Config::$items['debug']) ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_STRICT)
);
ini_set('display_errors', (int)Config::get('debug'));

// Autoload additional config files
$autoload_configs = Config::get('autoload_configs');
if ($autoload_configs !== false) {
    foreach ($autoload_configs as $item) {
        $tmp = explode('/', $item);
        $count = count($tmp);
        if ($count == 3) {
            Config::load([$tmp[2]], $tmp[1], $tmp[0]);
        } elseif ($count == 2) {
            Config::load([$tmp[1]], $tmp[0]);
        } else {
            Config::load([$tmp[0]]);
        }
    }
}

// Register error handlers
Load::helper(['ErrorHandlers'], 'Core', 'System');
set_error_handler(
    'sp_error_handler',
    (!empty(Config::$items['debug']) ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_STRICT)
);
set_exception_handler('sp_exception_handler');

// Load twig
if (Config::get('disable_twig') !== true) {
    if (is_file(VENDOR_PATH . '/twig/twig/src/Token.php') !== true) {
        throw new Exception(
            'Twig Not Found! If you installed StaticPHP manually, not using'
                . ' composer, please see README.md to where to place the twig library.'
        );
    }

    Config::$items['view_loader'] = new \Twig\Loader\FilesystemLoader(
        [
            APP_MODULES_PATH,
            APP_PATH,
            SYS_MODULES_PATH . '/Core/Views'
        ]
    );
    Config::$items['view_engine'] = new \Twig\Environment(
        Config::$items['view_loader'],
        [
            'cache' => (
                Config::get('debug') == true
                ? false
                : APP_PATH . '/Cache/Views/'
            ),
            'debug' => Config::get('debug'),
            // 'strict_variables' => Config::get('debug'),
        ]
    );

    // Register default filters and functions
    // Site url filter
    $filter = new \Twig\TwigFilter(
        'siteUrl',
        function ($url = '', $prefix = null, $current_prefix = true) {
            return Router::siteUrl($url, $prefix, $current_prefix);
        }
    );
    Config::get('view_engine')->addFilter($filter);

    // Site url function
    $function = new \Twig\TwigFunction(
        'siteUrl',
        function ($url = '', $prefix = null, $current_prefix = true) {
            return Router::siteUrl($url, $prefix, $current_prefix);
        }
    );
    Config::get('view_engine')->addFunction($function);

    // Start timer function
    $function = new \Twig\TwigFunction(
        'startTimer',
        function () {
            Timers::startTimer();
        }
    );
    Config::get('view_engine')->addFunction($function);

    // Stop timer function
    $function = new \Twig\TwigFunction(
        'stopTimer',
        function ($name) {
            Timers::stopTimer($name);
        }
    );
    Config::get('view_engine')->addFunction($function);

    // Mark time function
    $function = new \Twig\TwigFunction(
        'markTime',
        function ($name) {
            Timers::markTime($name);
        }
    );
    Config::get('view_engine')->addFunction($function);

    // Debug output function
    $function = new \Twig\TwigFunction(
        'debugOutput',
        function () {
            return Logger::debugOutput();
        }
    );
    Config::get('view_engine')->addFunction($function);

    // CSRF helpers - csrfToken(), csrfFieldName() and csrfField().
    // Registering them only makes the token available to templates; validating incoming
    // requests is the application's job, see System\Modules\Utils\Models\Csrf.
    \System\Modules\Utils\Models\Csrf::registerTwig();
}

// Autoload helpers
$autoload_helpers = Config::get('autoload_helpers');
if ($autoload_helpers !== false) {
    foreach ($autoload_helpers as $item) {
        $tmp = explode('/', $item);
        $count = count($tmp);
        if ($count == 3) {
            Load::helper([$tmp[2]], $tmp[1], $tmp[0]);
        } elseif ($count == 2) {
            Load::helper([$tmp[1]], $tmp[0]);
        } else {
            Load::helper([$tmp[0]]);
        }
    }
}

// Init router
Router::init();
