<?php

/**
 * Runs the front controller for a url and prints the response body followed by the
 * resulting HTTP status code on its own final line.
 *
 * Used by WelcomeTest to assert real status codes. It has to be a separate process
 * because http_response_code() is per-process state and the front controller calls exit().
 *
 * Usage: php status_probe.php "/some/url" [--prod]
 */

$url = $argv[1] ?? '';

if (in_array('--prod', $argv, true)) {
    // The public error pages are only reachable with debug off, and debug is forced back
    // on for anything coming from an address in debug_ips - which every cli request looks
    // like, since REMOTE_ADDR is unset and the lookup defaults to localhost
    define('SP_ENVIRONMENT', 'prod');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
}

// Request::populateFromCli() reads these
$_SERVER['argv'] = ['index.php', $url];
$_SERVER['argc'] = 2;

$body = '';

// The front controller exits on the error paths, so the body is captured from a shutdown
// function rather than after the require
register_shutdown_function(function () use (&$body) {
    $captured = ob_get_level() > 0 ? (string) ob_get_clean() : '';
    $status = http_response_code();

    echo $captured . "\n" . ($status === false ? 200 : (int) $status);
});

ob_start();
require dirname(__DIR__) . '/Public/index.php';
