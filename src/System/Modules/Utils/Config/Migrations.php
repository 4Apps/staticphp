<?php

/*
|--------------------------------------------------------------------------
| Migrations
|
| Override any of this from the application by creating Application/Config/Migrations.php
| and adding 'Migrations' to $config['autoload_configs'].
|--------------------------------------------------------------------------
*/

$config['migrations'] = [

    // Where the .sql files live. Kept outside Public/ deliberately - migrations describe
    // the whole schema and must never be reachable over http.
    'dir' => APP_PATH . '/Migrations',

    // Tracking table. Change it if 'migrations' is already taken; the tool refuses to
    // adopt an existing table that is not one of its own rather than guessing.
    'table' => 'migrations',

    // Which entry of $config['db']['pdo'] to migrate.
    'connection' => 'default',
];
