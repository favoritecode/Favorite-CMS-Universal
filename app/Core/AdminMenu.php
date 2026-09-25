<?php

declare(strict_types=1);

namespace FavoriteCMS\Core;

class AdminMenu
{
    /**
     * @var array<string, array{slug: string, title: string, icon: string, handler: ?callable, capability: string, position: int, submenus: array}>
     */
    protected static array $menus = [];

    /**
     * Register a top-level admin menu item and page.
     */
    public static function addMenu(
        string $slug,
        string $title,
        ?string $icon = '🔌',
        ?callable $handler = null,
        string $capability = 'manage_options',
        int $position = 50
    ): void {
        $slug = trim($slug, '/');
        static::$menus[$slug] = [
            'slug'       => $slug,
            'title'      => $title,
            'icon'       => $icon ?? '🔌',
            'handler'    => $handler,
            'capability' => $capability,
            'position'   => $position,
            'submenus'   => [],
        ];
    }

    /**
     * Register a sub-menu item under a parent menu.
     */
    public static function addSubMenu(
        string $parentSlug,
        string $slug,
        string $title,
        ?callable $handler = null,
        string $capability = 'manage_options'
    ): void {
        $parentSlug = trim($parentSlug, '/');
        $slug = trim($slug, '/');

        if (!isset(static::$menus[$parentSlug])) {
            static::addMenu($parentSlug, ucfirst(str_replace(['-', '_'], ' ', $parentSlug)));
        }

        static::$menus[$parentSlug]['submenus'][$slug] = [
            'slug'       => $slug,
            'title'      => $title,
            'handler'    => $handler,
            'capability' => $capability,
        ];
    }

    /**
     * Get all registered admin menus sorted by position.
     */
    public static function getMenus(): array
    {
        $menus = static::$menus;
        uasort($menus, fn($a, $b) => ($a['position'] ?? 50) <=> ($b['position'] ?? 50));
        return $menus;
    }

    /**
     * Find a registered admin page by its slug (checks top-level and submenus).
     */
    public static function findPage(string $slug): ?array
    {
        $slug = trim($slug, '/');

        if (isset(static::$menus[$slug])) {
            return static::$menus[$slug];
        }

        foreach (static::$menus as $menu) {
            if (isset($menu['submenus'][$slug])) {
                return $menu['submenus'][$slug];
            }
        }

        return null;
    }

    public static function reset(): void
    {
        static::$menus = [];
    }
}

