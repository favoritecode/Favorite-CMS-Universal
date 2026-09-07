<?php

declare(strict_types=1);

namespace FavoriteCMS\Core;

/**
 * AccountMenu — Generic, extensible account and profile menu registry for Favorite CMS Core.
 *
 * Provides a clean public API for Core and plugins to register, unregister, and retrieve
 * frontend account menu items with role/capability filtering, custom conditions, and ordering.
 */
class AccountMenu
{
    /**
     * @var array<string, array{
     *     id: string,
     *     label: string,
     *     url: string,
     *     icon: ?string,
     *     order: int,
     *     capability: ?string,
     *     plugin: string,
     *     condition: mixed
     * }>
     */
    protected static array $items = [];

    /**
     * Tracks whether default Core account options have been registered.
     */
    protected static bool $coreRegistered = false;

    /**
     * Tracks whether lifecycle hooks (e.g. plugin deactivation listener) have been bound.
     */
    protected static bool $hooksBound = false;

    /**
     * Register a new account menu item.
     *
     * @param array{
     *     id: string,
     *     label: string,
     *     url: string,
     *     icon?: ?string,
     *     order?: int,
     *     capability?: ?string,
     *     plugin?: string,
     *     condition?: callable|bool|null
     * } $item
     * @return bool True on successful registration, false on validation failure.
     */
    public static function registerItem(array $item): bool
    {
        static::bindLifecycleHooks();

        // 1. Validate & sanitize ID
        $id = isset($item['id']) ? trim((string)$item['id']) : '';
        if ($id === '' || !preg_match('/^[a-zA-Z0-9_\-]+$/', $id)) {
            return false;
        }

        // 2. Validate & sanitize label (strip HTML tags to prevent XSS)
        $label = isset($item['label']) ? trim(strip_tags((string)$item['label'])) : '';
        if ($label === '') {
            return false;
        }

        // 3. Validate URL security
        $url = isset($item['url']) ? trim((string)$item['url']) : '';
        if ($url === '' || !static::isValidUrl($url)) {
            return false;
        }

        // 4. Optional icon identifier or safe symbol
        $icon = isset($item['icon']) ? trim((string)$item['icon']) : null;
        if ($icon !== null) {
            // Strip dangerous tags if SVG or text
            if (!str_starts_with($icon, '<svg')) {
                $icon = strip_tags($icon);
            }
            if ($icon === '') {
                $icon = null;
            }
        }

        // 5. Order / Priority (default 50)
        $order = isset($item['order']) ? (int)$item['order'] : 50;

        // 6. Capability requirement
        $capability = isset($item['capability']) && trim((string)$item['capability']) !== ''
            ? trim((string)$item['capability'])
            : null;

        // 7. Plugin / Source identifier
        $plugin = isset($item['plugin']) && trim((string)$item['plugin']) !== ''
            ? trim(strip_tags((string)$item['plugin']))
            : 'core';

        // 8. Custom visibility condition (callable or boolean)
        $condition = $item['condition'] ?? null;

        static::$items[$id] = [
            'id'         => $id,
            'label'      => $label,
            'url'        => $url,
            'icon'       => $icon,
            'order'      => $order,
            'capability' => $capability,
            'plugin'     => $plugin,
            'condition'  => $condition,
        ];

        return true;
    }

    /**
     * Convenience method to register an account menu item with explicit parameters.
     */
    public static function addItem(
        string $id,
        string $label,
        string $url,
        ?string $icon = null,
        int $order = 50,
        ?string $capability = null,
        string $plugin = 'core',
        mixed $condition = null
    ): bool {
        return static::registerItem([
            'id'         => $id,
            'label'      => $label,
            'url'        => $url,
            'icon'       => $icon,
            'order'      => $order,
            'capability' => $capability,
            'plugin'     => $plugin,
            'condition'  => $condition,
        ]);
    }

    /**
     * Remove an account menu item by its unique ID.
     */
    public static function removeItem(string $id): bool
    {
        $id = trim($id);
        if (isset(static::$items[$id])) {
            unset(static::$items[$id]);
            return true;
        }
        return false;
    }

    /**
     * Remove all account menu items registered by a specific plugin.
     * Invoked automatically on plugin deactivation and uninstallation.
     */
    public static function removeByPlugin(string $pluginId): int
    {
        $pluginId = trim($pluginId);
        if ($pluginId === '') {
            return 0;
        }

        $removed = 0;
        foreach (static::$items as $id => $item) {
            if ($item['plugin'] === $pluginId) {
                unset(static::$items[$id]);
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * Check if an account menu item is registered.
     */
    public static function hasItem(string $id): bool
    {
        return isset(static::$items[trim($id)]);
    }

    /**
     * Get a registered account menu item by ID.
     */
    public static function getItem(string $id): ?array
    {
        return static::$items[trim($id)] ?? null;
    }

    /**
     * Get all raw registered items (unfiltered).
     */
    public static function getAllItems(): array
    {
        return static::$items;
    }

    /**
     * Register the default Core-owned account options.
     *
     * Core provides only generic user/account functionality:
     * - Profile
     * - Account Settings
     * - Administration / Dashboard (if permitted)
     * - Logout
     *
     * Domain and business options (Orders, Balance, Cart, Downloads) belong strictly to plugins.
     */
    public static function registerCoreItems(): void
    {
        if (static::$coreRegistered) {
            return;
        }

        // 1. Profile (Single account management destination)
        static::registerItem([
            'id'         => 'profile',
            'label'      => 'Profile',
            'url'        => '/admin/users/profile',
            'icon'       => 'user',
            'order'      => 10,
            'capability' => null,
            'plugin'     => 'core',
        ]);

        // 2. Administration (Dashboard - authorized users only)
        static::registerItem([
            'id'         => 'dashboard',
            'label'      => 'Administration',
            'url'        => '/admin',
            'icon'       => 'layout-dashboard',
            'order'      => 30,
            'capability' => 'manage_options',
            'plugin'     => 'core',
        ]);

        // 3. Logout
        static::registerItem([
            'id'         => 'logout',
            'label'      => 'Log Out',
            'url'        => '/admin/logout',
            'icon'       => 'log-out',
            'order'      => 100,
            'capability' => null,
            'plugin'     => 'core',
        ]);

        static::$coreRegistered = true;
    }

    /**
     * Retrieve all account menu items visible to the authenticated user.
     *
     * Returns an empty array if the user is unauthenticated (guest).
     * Filters items based on capability requirements and custom condition callbacks.
     * Sorts items in ascending order by priority.
     *
     * @param ?object $user Current user object or null (resolves current_user() by default).
     * @return array<string, array>
     */
    public static function getItems(?object $user = null): array
    {
        if ($user === null && function_exists('current_user')) {
            $user = current_user();
        }

        // Security: Unauthenticated guests receive NO account menu items.
        if ($user === null) {
            return [];
        }

        static::registerCoreItems();

        // Allow plugins/themes to hook in and register items dynamically
        if (class_exists(Hook::class)) {
            Hook::doAction('account_menu_init', $user);
        }

        $visible = [];
        foreach (static::$items as $id => $item) {
            // Capability / Permission verification
            if (!empty($item['capability'])) {
                $hasCapability = false;
                if (method_exists($user, 'hasPermission')) {
                    $hasCapability = (bool)$user->hasPermission($item['capability']);
                } elseif (function_exists('current_user_can')) {
                    $hasCapability = current_user_can($item['capability']);
                }

                if (!$hasCapability) {
                    continue;
                }
            }

            // Custom condition callback or boolean check
            if (isset($item['condition'])) {
                if (is_callable($item['condition'])) {
                    try {
                        if (!call_user_func($item['condition'], $user)) {
                            continue;
                        }
                    } catch (\Throwable $e) {
                        error_log("Error in AccountMenu condition for item '{$id}': " . $e->getMessage());
                        continue;
                    }
                } elseif ($item['condition'] === false) {
                    continue;
                }
            }

            $visible[$id] = $item;
        }

        // Sort items by order ascending (stable comparison)
        uasort($visible, fn($a, $b) => ($a['order'] ?? 50) <=> ($b['order'] ?? 50));

        // Allow plugins to filter final item list
        if (class_exists(Hook::class)) {
            $filtered = Hook::applyFilters('account_menu_items', $visible, $user);
            if (is_array($filtered)) {
                return $filtered;
            }
        }

        return $visible;
    }

    /**
     * Render the theme-agnostic, accessible HTML for the frontend account profile dropdown.
     *
     * @param array{
     *     user?: ?object,
     *     show_avatar?: bool,
     *     show_name?: bool,
     *     show_role?: bool,
     *     container_class?: string,
     *     dropdown_class?: string
     * } $options
     * @return string Safe HTML markup or empty string for guests.
     */
    public static function render(array $options = []): string
    {
        $user = $options['user'] ?? (function_exists('current_user') ? current_user() : null);
        if ($user === null) {
            return '';
        }

        $items = static::getItems($user);
        if (empty($items)) {
            return '';
        }

        $showAvatar = $options['show_avatar'] ?? true;
        $showName   = $options['show_name'] ?? true;
        $showRole   = $options['show_role'] ?? true;
        $containerClass = htmlspecialchars($options['container_class'] ?? 'cms-account-menu', ENT_QUOTES, 'UTF-8');
        $dropdownClass  = htmlspecialchars($options['dropdown_class'] ?? 'cms-account-dropdown', ENT_QUOTES, 'UTF-8');

        $displayName = htmlspecialchars($user->name ?? $user->username ?? 'Account', ENT_QUOTES, 'UTF-8');
        $initial = strtoupper(substr($user->name ?? $user->username ?? 'U', 0, 1));
        $avatarUrl = !empty($user->avatar) && static::isValidUrl($user->avatar) ? htmlspecialchars($user->avatar, ENT_QUOTES, 'UTF-8') : null;

        $roles = method_exists($user, 'getRoles') ? $user->getRoles() : [];
        $roleName = !empty($roles[0]->name) ? htmlspecialchars($roles[0]->name, ENT_QUOTES, 'UTF-8') : 'Member';

        ob_start();
        ?>
        <div class="<?php echo $containerClass; ?>" id="cms-account-menu">
            <button type="button" 
                    class="cms-account-trigger" 
                    id="cms-account-trigger" 
                    aria-haspopup="true" 
                    aria-expanded="false" 
                    aria-label="User account menu for <?php echo $displayName; ?>">
                <?php if ($showAvatar): ?>
                    <span class="cms-account-avatar" aria-hidden="true">
                        <?php if ($avatarUrl): ?>
                            <img src="<?php echo $avatarUrl; ?>" alt="<?php echo $displayName; ?>" class="cms-account-avatar-img">
                        <?php else: ?>
                            <span class="cms-account-avatar-initial"><?php echo $initial; ?></span>
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
                <?php if ($showName): ?>
                    <span class="cms-account-name"><?php echo $displayName; ?></span>
                <?php endif; ?>
                <svg class="cms-account-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="6 9 12 15 18 9"></polyline>
                </svg>
            </button>
            <div class="<?php echo $dropdownClass; ?>" id="cms-account-dropdown" role="menu" aria-labelledby="cms-account-trigger">
                <div class="cms-account-header">
                    <span class="cms-account-user-name"><?php echo $displayName; ?></span>
                    <?php if ($showRole && $roleName): ?>
                        <span class="cms-account-user-role"><?php echo $roleName; ?></span>
                    <?php endif; ?>
                </div>
                <ul class="cms-account-list" role="none">
                    <?php foreach ($items as $item): ?>
                        <?php
                        $itemId = htmlspecialchars($item['id'], ENT_QUOTES, 'UTF-8');
                        $itemLabel = htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8');
                        $itemUrl = htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8');
                        $isLogout = ($item['id'] === 'logout');
                        ?>
                        <?php if ($isLogout): ?>
                            <li class="cms-account-divider" role="separator"></li>
                        <?php endif; ?>
                        <li class="cms-account-item cms-account-item-<?php echo $itemId; ?>" role="none">
                            <a href="<?php echo $itemUrl; ?>" class="cms-account-link <?php echo $isLogout ? 'is-logout' : ''; ?>" role="menuitem">
                                <?php if (!empty($item['icon'])): ?>
                                    <span class="cms-account-icon" aria-hidden="true">
                                        <?php echo static::renderIcon($item['icon']); ?>
                                    </span>
                                <?php endif; ?>
                                <span class="cms-account-text"><?php echo $itemLabel; ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php
        $html = (string)ob_get_clean();

        if (class_exists(Hook::class)) {
            $html = (string)Hook::applyFilters('render_account_menu', $html, $items, $user, $options);
        }

        return $html;
    }

    /**
     * Render an icon identifier or safe inline SVG.
     */
    public static function renderIcon(?string $icon): string
    {
        if ($icon === null || $icon === '') {
            return '';
        }

        // If custom inline SVG is provided
        if (str_starts_with($icon, '<svg')) {
            // Strip any potentially dangerous elements/attributes
            if (preg_match('/<script|onload|onerror|javascript:/i', $icon)) {
                return '';
            }
            return $icon;
        }

        // Standard Feather / Lucide icon mappings
        return match ($icon) {
            'user' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>',
            'settings' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>',
            'layout-dashboard', 'dashboard' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"></rect><rect x="14" y="3" width="7" height="5"></rect><rect x="14" y="12" width="7" height="9"></rect><rect x="3" y="16" width="7" height="5"></rect></svg>',
            'log-out', 'logout' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>',
            default => '<span class="cms-account-icon-fallback">' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '</span>',
        };
    }

    /**
     * Bind lifecycle hooks to clean up plugin-registered items when plugins are deactivated/uninstalled.
     */
    protected static function bindLifecycleHooks(): void
    {
        if (static::$hooksBound || !class_exists(Hook::class)) {
            return;
        }

        Hook::addAction('plugin.deactivated', function (string $pluginId): void {
            static::removeByPlugin($pluginId);
        });

        Hook::addAction('plugin.uninstalled', function (string $pluginId): void {
            static::removeByPlugin($pluginId);
        });

        static::$hooksBound = true;
    }

    /**
     * Reset the registry state to initial defaults. Useful for testing environments.
     */
    public static function reset(): void
    {
        static::$items = [];
        static::$coreRegistered = false;
        static::$hooksBound = false;
    }

    /**
     * Validate URL format and security.
     * Allows root-relative paths (/...) and safe http/https URLs.
     * Rejects javascript:, data:, vbscript:, file:, protocol-relative (//), and control characters.
     */
    public static function isValidUrl(string $url): bool
    {
        $trimmed = trim($url);
        if ($trimmed === '' || str_starts_with($trimmed, '//')) {
            return false;
        }

        // Root-relative path
        if (str_starts_with($trimmed, '/')) {
            return !preg_match('/[\x00-\x1F\x7F]/', $trimmed);
        }

        // Absolute URL
        $scheme = parse_url($trimmed, PHP_URL_SCHEME);
        if ($scheme === false || $scheme === null) {
            return false;
        }

        return in_array(strtolower($scheme), ['http', 'https'], true);
    }
}
