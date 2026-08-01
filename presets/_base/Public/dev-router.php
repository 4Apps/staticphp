<?php

/**
 * Dev router
 */

$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? '';
if (is_string($serverSoftware) === false || strpos($serverSoftware, 'Development Server') === false) {
    echo 'Should only be used in development';
    exit;
}

chdir(__DIR__);
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$requested_uri = explode('?', is_string($requestUri) ? $requestUri : '')[0];
$filePath = realpath(ltrim($requested_uri, '/'));
if ($filePath && is_dir($filePath)) {
    // attempt to find an index file
    foreach (['index.php', 'index.html'] as $indexFile) {
        if ($filePath = realpath($filePath . DIRECTORY_SEPARATOR . $indexFile)) {
            break;
        }
    }
}

if ($filePath && is_file($filePath)) {
    // 1. check that file is not outside of this directory for security
    // 2. check for circular reference to router.php
    // 3. don't serve dotfiles
    if (
        strpos($filePath, __DIR__ . DIRECTORY_SEPARATOR) === 0
        && $filePath != __DIR__ . DIRECTORY_SEPARATOR . 'dev-router.php'
        && substr(basename($filePath), 0, 1) != '.'
    ) {
        if (strtolower(substr($filePath, -4)) == '.php') {
            // php file; serve through interpreter
            include $filePath;
        } else {
            // asset file; serve from filesystem
            return false;
        }
    } else {
        // disallowed file
        header("HTTP/1.1 404 Not Found");
        echo "404 Not Found";
    }
} else {
    // rewrite to our index file
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    include __DIR__ . DIRECTORY_SEPARATOR . 'index.php';
}
