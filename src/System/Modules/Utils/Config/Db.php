<?php

/**
 * Database configuration, See PDO documentation for connection string.
 * http://php.net/manual/en/pdo.construct.php for more information
 */

$config['db']['pdo']['default'] = [
    'string' => 'mysql:host=localhost;dbname=',
    'username' => '',
    'password' => '',

    // Appended to the DSN rather than sent as "SET NAMES" after connecting, so that PDO's
    // own idea of the connection charset matches reality. utf8mb4 rather than utf8 - the
    // latter is mysql's 3-byte alias and cannot store astral characters, emoji included.
    'charset' => 'utf8mb4',

    'persistent' => true,
    'wrap_column' => '`', // ` - for mysql, " - for postgresql
    'fetch_mode_objects' => false,

    // Leave false for real server-side prepared statements. Emulation quotes parameters
    // client-side and returns every column as a string, so an integer column comes back
    // as "5" and strict comparisons against it silently fail.
    'emulate_prepares' => false,

    'debug' => $config['debug'],
];
