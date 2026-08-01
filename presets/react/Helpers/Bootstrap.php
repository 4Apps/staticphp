<?php

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


// The csrf token lives in the session, so anything posting from the browser needs this.
// Change the name before deploying next to another application on the same host.
session_set_cookie_params(0);
session_name('APP_SESSION');
session_start();
