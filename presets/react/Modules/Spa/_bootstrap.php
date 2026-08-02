<?php

use StaticPHP\Core\Models\Config;

// view_data is a bag inside a bag, and Config::$items is a bare array, so the inner one has
// to be proved an array before writing into it - otherwise a string left there by something
// else would be silently indexed as one.
if (is_array(Config::$items['view_data'] ?? null) === false) {
    Config::$items['view_data'] = [];
}

// Which bundle the layout loads. The name is the entry filename under assets/src -
// app.tsx builds to app.bundle.js - see rspack.config.js.
Config::$items['view_data']['js_include'] = 'app';
