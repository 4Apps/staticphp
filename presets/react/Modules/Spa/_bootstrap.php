<?php

use StaticPHP\Core\Models\Config;

// Which bundle the layout loads. The name is the entry filename under assets/src -
// app.tsx builds to app.bundle.js - see rspack.config.js.
Config::$items['view_data']['js_include'] = 'app';
