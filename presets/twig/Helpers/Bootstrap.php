<?php

use StaticPHP\Presentation\Models\Menu\Menu;
use Defaults\Data\MainMenu;

// This is the right place to set headers, start a session, etc.

// Send content type and charset header
header('Content-type: text/html; charset=utf-8');

// Set locales
// setlocale(LC_TIME, 'lv_LV.utf8', 'lv_LV.UTF-8');
// setlocale(LC_NUMERIC, 'lv_LV.utf8', 'lv_LV.UTF-8');
// setlocale(LC_CTYPE, 'lv_LV.utf8', 'lv_LV.UTF-8');
// date_default_timezone_set('Europe/Riga');


// Init db - Before uncommenting add at the use section: "use StaticPHP\Utils\Models\Db;"
// Db::init();


// Start session
/*
session_set_cookie_params(0);
session_name('MY_SESSION_NAME');
session_start();
*/

// register twig functions
Menu::registerTwig();

// Default menu
Menu::registerMenu(new MainMenu());
