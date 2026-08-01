<?php

namespace Defaults\Controllers\Test;

use StaticPHP\Core\Models\Router;
use StaticPHP\Core\Controllers\Controller;

class Test extends Controller
{
    private static function getPage(): string
    {
        return Router::$module . " -> " . Router::$controller . " -> " . Router::$method;
    }

    public static function index(): void
    {
        echo self::getPage();
    }

    /**
     * An array return is encoded as json by the router.
     *
     * @return array<string, string>
     */
    public static function json(): array
    {
        return [
            'status' => 'OK',
            'page' => self::getPage()
        ];
    }
}
