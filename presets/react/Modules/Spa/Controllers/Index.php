<?php

namespace Spa\Controllers;

use StaticPHP\Core\Controllers\Controller;
use StaticPHP\Utils\Models\Csrf;

/**
 * Serves the shell the react app mounts into.
 *
 * Every client side route lands here - see the routing config - so react router owns the
 * url once the page has loaded.
 */
class Index extends Controller
{
    public static function index(): void
    {
        self::render(['index.html'], [
            // Handed to the browser in a data attribute rather than fetched, so the very
            // first POST does not need a round trip to get a token
            'csrf_token' => Csrf::token(),
            'api_base' => '/api/items',
        ]);
    }
}
