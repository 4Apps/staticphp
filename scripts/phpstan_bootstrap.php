#!/usr/bin/env php
<?php

/**
 * Loaded by phpstan through bootstrapFiles, never by the application.
 *
 * StaticPHP\Core\Helpers\Autoload defines these at runtime, deriving each from the
 * PUBLIC_PATH that the front controller declares. Nothing static can follow that, so
 * without this every config file reads as a wall of "constant not found" - and worse, the
 * expressions built from them degrade to mixed, which hides the findings that matter.
 *
 * The values are irrelevant; only the names and types are. They are strings at runtime and
 * strings here.
 */

define('PUBLIC_PATH', __DIR__ . '/../src/Application/Public');
define('APP_PATH', dirname(PUBLIC_PATH));
define('APP_MODULES_PATH', APP_PATH . '/Modules');
define('BASE_PATH', dirname(APP_PATH));
define('VENDOR_PATH', BASE_PATH . '/vendor');
