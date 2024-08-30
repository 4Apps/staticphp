<?php

namespace System\Modules\Presentation\Models\Menu;

use Twig\TwigFunction;
use System\Modules\Core\Models\Load;
use System\Modules\Core\Models\Config;
use System\Modules\Core\Models\Router;
use System\Modules\Core\Controllers\Controller;
use System\Modules\Presentation\Models\Menu\MenuType;

/**
 * Generate Menus
 */
class Menu
{
    public MenuType $type;
    public array $menuList = [];
    public $preMenuList = '';
    public $postMenuList = '';

    public bool $parentActive = false;

    private array $itemDefaults = [
        'title' => 'No Title',
        'end_icon' => '',
        'before_icon' => '',
        'after_icon' => '',
        'url' => '',
        'show' => true,
        'active' => false,
        'nav_class' => '',
        'link_class' => '',
        'contents' => '',
    ];

    public function __construct($parentActive = false)
    {
        if (is_callable($parentActive)) {
            $this->parentActive = $parentActive();
        } else {
            $this->parentActive = $parentActive;
        }
    }

    private function prepareUrl($url)
    {
        return str_replace(
            [
                '%base_url',
                '%module_url',
                '%controller_url',
                '%method_url',
                '%module',
                '%controller',
                '%class',
                '%method',
            ],
            [
                Router::$base_url,
                Controller::moduleUrl(),
                Controller::controllerUrl(),
                Controller::methodUrl(),
                Router::$module,
                Router::$controller,
                Router::$class,
                Router::$method,
            ],
            $url
        );
    }

    public function html()
    {
        $preMenuContent = '';
        if (is_callable($this->preMenuList)) {
            $preMenuContent = ($this->preMenuList)();
        } else {
            $preMenuContent = $this->preMenuList;
        }
        $postMenuContent = '';
        if (is_callable($this->postMenuList)) {
            $postMenuContent = ($this->postMenuList)();
        } else {
            $postMenuContent = $this->postMenuList;
        }

        $menuItems = [];
        foreach ($this->menuList as $item) {
            // Merge defaults
            $item = array_merge($this->itemDefaults, $item);

            // Can we show the item?
            $shouldShow = is_callable($item['show']) ? $item['show']() : $item['show'];
            if ($shouldShow === false) {
                continue;
            }

            // Is the item active?
            $item['active'] = is_callable($item['active']) ? $item['active']() : $item['active'];

            // Custom contents
            if (is_callable($item['contents'])) {
                $item['contents'] = $item['contents']($item);
            }

            // Fix url
            $item['url'] = $this->prepareUrl($item['url']);

            $menuItems[] = $item;
        }

        $viewData = [
            'parent_active' => $this->parentActive,
            'pre_menu_content' => $preMenuContent,
            'post_menu_content' => $postMenuContent,
            'menu_items' => $menuItems,
        ];
        return Load::view(["Views/components/menu_type_{$this->type->value}.html"], $viewData, true);
    }

    // MARK: Twig
    public static function registerTwig()
    {
        $function = new TwigFunction(
            'DisplayMenu',
            function (Menu $instance) {
                return $instance->html();
            },
            ['is_safe' => ['html']]
        );
        Config::$items['view_engine']->addFunction($function);
    }

    public static function registerMenu(Menu $instance)
    {
        if ($instance->type == MenuType::MAIN_MENU) {
            Config::$items['view_data']['menu_main'] = $instance;
        }
        if ($instance->type == MenuType::SUB_MENU) {
            Config::$items['view_data']['menu_submenu'] = $instance;
        }
        if ($instance->type == MenuType::TABS) {
            Config::$items['view_data']['menu_tabs'] = $instance;
        }
    }

    public static function hideMenus($menuFlags)
    {
        if ($menuFlags & MenuType::MAIN_MENU->value) {
            unset(Config::$items['view_data']['menu_main']);
        }
        if ($menuFlags & MenuType::SUB_MENU->value) {
            unset(Config::$items['view_data']['menu_submenu']);
        }
        if ($menuFlags & MenuType::TABS->value) {
            unset(Config::$items['view_data']['menu_tabs']);
        }
    }
}
