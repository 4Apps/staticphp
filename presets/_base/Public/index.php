<?php

//tideways_enable(TIDEWAYS_FLAGS_NO_SPANS);

// Composer first. It is what locates the framework - staticphp-core is an ordinary
// dependency now - so nothing below can run before it. It used to be included last, from
// inside the framework, and only when twig was enabled.
require dirname(__DIR__, 3) . '/vendor/autoload.php';

// The framework no longer guesses where the application is; installed under vendor it
// would find its own demo app. Declaring this here is also what allows several
// applications in one repository - src/site1, src/site2 - each with its own front
// controller and its own Modules directory.
//
// APP_PATH, APP_MODULES_PATH, BASE_PATH and VENDOR_PATH derive from it. Any of them can be
// defined here instead when this layout does not suit.
define('PUBLIC_PATH', __DIR__);

require StaticPHP\Core\Bootstrap::FILE;

/*
$data = tideways_disable();
file_put_contents(
    sys_get_temp_dir() . "/" . uniqid() . ".yourapp.xhprof",
    serialize($data)
);
*/
