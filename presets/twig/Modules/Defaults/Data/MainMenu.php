<?php

namespace Defaults\Data;

use StaticPHP\Core\Models\Router;
use StaticPHP\Core\Models\Config;
use StaticPHP\Presentation\Models\Menu\Menu;
use StaticPHP\Presentation\Models\Menu\MenuType;

class MainMenu extends Menu
{
    public function __construct()
    {
        $this->type = MenuType::MAIN_MENU;
        $this->menuList = [
            [
                'title' => 'Example',
                'url' => '%base_url/defaults/welcome/example',
                'show' => function () {
                    return Config::$items['debug'] == true;
                },
                'active' => function () {
                    return Router::$method == 'example';
                }
            ],
            [
                'title' => 'Test Page',
                'url' => '%base_url/defaults/test/test',
                'show' => function () {
                    return Config::$items['debug'] == true;
                },
                'active' => function () {
                    return Router::$method == 'testMe';
                }
            ],
            [
                'title' => 'JSON Test Page',
                'url' => '%base_url/defaults/test/test/json',
                'show' => function () {
                    return Config::$items['debug'] == true;
                },
                'active' => function () {
                    return Router::$method == 'testMe';
                }
            ],
            [
                'title' => 'Error Example',
                'url' => '%base_url/defaults/welcome/index/error',
                'show' => function () {
                    return Config::$items['debug'] == true;
                },
                'active' => function () {
                    return Router::$method == 'example';
                }
            ],
            [
                'title' => 'Error JSON Example',
                'url' => '%base_url/defaults/welcome/index/error/json',
                'show' => function () {
                    return Config::$items['debug'] == true;
                },
                'active' => function () {
                    return Router::$method == 'example';
                }
            ],
        ];
    }
}
