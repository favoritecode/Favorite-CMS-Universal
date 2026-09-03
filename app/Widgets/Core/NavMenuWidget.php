<?php

declare(strict_types=1);

namespace FavoriteCMS\Widgets\Core;

use FavoriteCMS\Models\Menu;
use FavoriteCMS\Widgets\AbstractWidget;

class NavMenuWidget extends AbstractWidget
{
    protected string $id = 'nav_menu';
    protected string $name = 'Navigation Menu';
    protected string $description = 'Add a custom navigation menu to your sidebar or footer.';
    protected string $category = 'Navigation';
    protected string $icon = '🧭';

    public function getSchema(): array
    {
        $menuOptions = ['0' => '— Select a Menu —'];
        try {
            $menus = Menu::all();
            foreach ($menus as $m) {
                $menuOptions[(string)$m->id] = $m->name;
            }
        } catch (\Throwable) {}

        return [
            'title' => [
                'type'    => 'text',
                'label'   => 'Title',
                'default' => 'Menu',
            ],
            'menu_id' => [
                'type'    => 'select',
                'label'   => 'Select Menu',
                'options' => $menuOptions,
                'default' => '0',
            ],
        ];
    }

    public function render(array $settings = [], array $args = []): string
    {
        $settings = $this->resolveSettings($settings);
        $menuId   = (int)($settings['menu_id'] ?? 0);

        if ($menuId <= 0) {
            return '';
        }

        try {
            $menu = Menu::find($menuId);
        } catch (\Throwable) {
            $menu = null;
        }

        if (!$menu) {
            return '';
        }

        $items = $menu->getItems();
        if (empty($items)) {
            return '';
        }

        $currentUri = $_SERVER['REQUEST_URI'] ?? '/';

        $html = '<ul class="widget-list widget-nav-menu">';
        foreach ($items as $item) {
            $url    = $item->url ?? '#';
            $title  = htmlspecialchars($item->title, ENT_QUOTES, 'UTF-8');
            $active = ($currentUri === $url) ? ' class="active"' : '';
            $target = !empty($item->target) ? ' target="' . htmlspecialchars($item->target, ENT_QUOTES, 'UTF-8') . '"' : '';

            $html .= '<li><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"' . $active . $target . '>' . $title . '</a></li>';
        }
        $html .= '</ul>';

        return $this->wrapOutput($html, $settings, $args);
    }
}

