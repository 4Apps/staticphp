<?php

namespace Defaults\Controllers\Test;

use StaticPHP\Core\Models\Router;
use StaticPHP\Core\Controllers\Controller;

class Test extends Controller
{
    private static function getPage()
    {
        return Router::$module . " -> " . Router::$controller . " -> " . Router::$method;
    }

    public static function index()
    {
        echo self::getPage();
    }

    public static function json()
    {
        return [
            'status' => 'OK',
            'page' => self::getPage()
        ];
    }
}
