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

    /** Maximum nesting depth rendered for child menu items. */
    protected const MAX_DEPTH = 5;

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

        return $this->wrapOutput($this->renderItems($items, 0), $settings, $args);
    }

    /**
     * Render menu items recursively so child items are never discarded.
     */
    protected function renderItems(array $items, int $depth): string
    {
        $html = '<ul class="' . ($depth === 0 ? 'widget-list widget-nav-menu' : 'sub-menu') . '">';

        foreach ($items as $item) {
            $url      = menu_item_url($item);
            $title    = htmlspecialchars((string)($item->title ?? ''), ENT_QUOTES, 'UTF-8');
            $children = (!empty($item->children) && is_array($item->children)) ? $item->children : [];

            $liClasses = [];
            $customClass = trim(preg_replace('/[^A-Za-z0-9_\- ]/', '', (string)($item->css_class ?? '')) ?? '');
            if ($customClass !== '') {
                $liClasses[] = $customClass;
            }
            if ($children !== [] && $depth < self::MAX_DEPTH) {
                $liClasses[] = 'menu-item-has-children';
            }

            $active = is_current_url($url) ? ' class="active" aria-current="page"' : '';

            $target = (string)($item->target ?? '');
            $targetAttr = '';
            if (in_array($target, ['_blank', '_self', '_parent', '_top'], true)) {
                $targetAttr = ' target="' . $target . '"' . ($target === '_blank' ? ' rel="noopener"' : '');
            }

            $html .= '<li' . ($liClasses !== [] ? ' class="' . htmlspecialchars(implode(' ', $liClasses), ENT_QUOTES, 'UTF-8') . '"' : '') . '>';
            $html .= '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"' . $active . $targetAttr . '>' . $title . '</a>';
            if ($children !== [] && $depth < self::MAX_DEPTH) {
                $html .= $this->renderItems($children, $depth + 1);
            }
            $html .= '</li>';
        }

        return $html . '</ul>';
    }
}
