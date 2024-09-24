<?php

namespace System\Modules\Core\Controllers;

use System\Modules\Core\Models\Load;
use System\Modules\Core\Models\Config;
use System\Modules\Core\Models\Router;

/**
 * StaticPHP's base controller, sets various class variables and offers additional methods.
 */
class Controller
{
    public static ?string $module_url = null;
    public static ?string $controller_url = null;
    public static ?string $method_url = null;


    /**
     *  Constructor - Called on each request.
     */
    public static function construct(?string $class = null, ?string $method = null)
    {
        // Get full urls to current controller and its method
        self::$module_url = self::moduleUrl();
        self::$method_url = self::methodUrl();
        self::$controller_url = self::controllerUrl();

        // Pass these to the view, too
        Config::$items['view_data']['current_url'] = Router::$parsed_url;
        Config::$items['view_data']['module_url'] = self::$module_url;
        Config::$items['view_data']['controller_url'] = self::$controller_url;
        Config::$items['view_data']['method_url'] = self::$method_url;

        // Add Router's preferences
        Config::$items['view_data']['module'] = Router::$module;
        Config::$items['view_data']['controller'] = Router::$controller;
        Config::$items['view_data']['class'] = Router::$class;
        Config::$items['view_data']['method'] = Router::$method;

        Config::$items['view_data']['module_url_rel'] = Router::$module_url;
        Config::$items['view_data']['controller_url_rel'] = Router::$controller_url;
        Config::$items['view_data']['method_url_rel'] = Router::$method_url;
    }


    /**
     *  Destructor - Called on each request after data is sent to browser.
     */
    public static function destruct()
    {
        // Not implemented
    }

    /**
     * Generates module url
     */
    public static function moduleUrl(): string
    {
        return Router::siteUrl(Router::$module_url);
    }

    /**
     * Generates method url
     */
    public static function methodUrl(): string
    {
        return Router::siteUrl(Router::$method_url);
    }

    /**
     * Generates controller url
     */
    public static function controllerUrl(): string
    {
        return Router::siteUrl(Router::$controller_url);
    }

    /**
     *  Render a view. This method instead of Load::view() prefixes paths with current module directory.
     */
    public static function render(array $views, $view_data = []): void
    {
        $views = (array)$views;
        foreach ($views as $key => $item) {
            $views[$key] = Router::$module . "/Views/{$item}";
        }

        Load::view($views, $view_data);
    }


    /**
     *  Write $contents to the output. Arrays are jsonified.
     */
    public static function write(string|array $contents): void
    {
        if (is_array($contents)) {
            echo json_encode($contents);
        } else {
            echo $contents;
        }
    }
}
