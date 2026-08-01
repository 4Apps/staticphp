<?php

/**
 * Bootstrap for the application's test suite.
 *
 * Composer resolves StaticPHP\ and everything in vendor; the application autoloader in
 * Core/Helpers/Autoload.php resolves the module namespaces. This file used to carry its
 * own copy of that autoloader, which drifted from the real one.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

define('PUBLIC_PATH', dirname(__DIR__) . '/Public');

require StaticPHP\Core\Bootstrap::AUTOLOAD;
