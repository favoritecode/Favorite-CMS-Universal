<?php

declare(strict_types=1);

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Core\Container;

if (!function_exists('app')) {
    function app(string $abstract = null)
    {
        $container = Container::getInstance();
        if (is_null($abstract)) {
            return $container;
        }
        return $container->get($abstract);
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? false;
        if ($value === false) {
            return $default;
        }
        switch (strtolower((string)$value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'empty':
            case '(empty)':
                return '';
            case 'null':
            case '(null)':
                return null;
        }
        if (($valueLength = strlen($value)) > 1 && $value[0] === '"' && $value[$valueLength - 1] === '"') {
            return substr($value, 1, -1);
        }
        return $value;
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return app(Config::class)->get($key, $default);
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return rtrim(APP_ROOT . DIRECTORY_SEPARATOR . $path, DIRECTORY_SEPARATOR);
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return base_path('storage' . ($path ? DIRECTORY_SEPARATOR . $path : ''));
    }
}

if (!function_exists('public_path')) {
    function public_path(string $path = ''): string
    {
        return base_path('public' . ($path ? DIRECTORY_SEPARATOR . $path : ''));
    }
}

if (!function_exists('themes_path')) {
    function themes_path(string $path = ''): string
    {
        return base_path('themes' . ($path ? DIRECTORY_SEPARATOR . $path : ''));
    }
}

if (!function_exists('plugins_path')) {
    function plugins_path(string $path = ''): string
    {
        return base_path('plugins' . ($path ? DIRECTORY_SEPARATOR . $path : ''));
    }
}

if (!function_exists('e')) {
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        $baseUrl = config('app.url', 'http://localhost');
        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        return url('assets/' . ltrim($path, '/'));
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }
}

if (!function_exists('view')) {
    function view(string $template, array $data = []): string
    {
        // Simple implementation
        $path = base_path("resources/views/{$template}.php");
        if (!file_exists($path)) {
            throw new \Exception("View not found: {$template}");
        }
        extract($data);
        ob_start();
        include $path;
        return ob_get_clean();
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['_token'])) {
            $_SESSION['_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('old')) {
    function old(string $key, mixed $default = ''): mixed
    {
        return $_SESSION['_old_input'][$key] ?? $default;
    }
}

if (!function_exists('session')) {
    function session(string $key = null, mixed $default = null): mixed
    {
        if (is_null($key)) {
            return $_SESSION;
        }
        return $_SESSION[$key] ?? $default;
    }
}

if (!function_exists('flash')) {
    function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }
}

if (!function_exists('now')) {
    function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}

if (!function_exists('str_slug')) {
    function str_slug(string $str): string
    {
        $str = preg_replace('~[^\pL\d]+~u', '-', $str);
        $str = iconv('utf-8', 'us-ascii//TRANSLIT', $str);
        $str = preg_replace('~[^-\w]+~', '', $str);
        $str = trim($str, '-');
        $str = preg_replace('~-+~', '-', $str);
        return strtolower($str);
    }
}

if (!function_exists('abort')) {
    function abort(int $code, string $message = ''): never
    {
        http_response_code($code);
        echo $message ?: "Error $code";
        exit(1);
    }
}

if (!function_exists('clean_post_content')) {
    function clean_post_content(string $content, mixed $user = null): string
    {
        if (trim($content) === '') {
            return '';
        }
        return \FavoriteCMS\Services\ContentSanitizer::clean($content, $user);
    }
}

// -----------------------------------------------------------------------------
// Plugin Hook & Event APIs
// -----------------------------------------------------------------------------
if (!function_exists('add_action')) {
    function add_action(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        \FavoriteCMS\Core\Hook::addAction($tag, $callback, $priority, $acceptedArgs);
    }
}

if (!function_exists('do_action')) {
    function do_action(string $tag, mixed ...$args): void
    {
        \FavoriteCMS\Core\Hook::doAction($tag, ...$args);
    }
}

if (!function_exists('has_action')) {
    function has_action(string $tag): bool
    {
        return \FavoriteCMS\Core\Hook::hasAction($tag);
    }
}

if (!function_exists('remove_action')) {
    function remove_action(string $tag): void
    {
        \FavoriteCMS\Core\Hook::removeAction($tag);
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        \FavoriteCMS\Core\Hook::addFilter($tag, $callback, $priority, $acceptedArgs);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $tag, mixed $value, mixed ...$args): mixed
    {
        return \FavoriteCMS\Core\Hook::applyFilters($tag, $value, ...$args);
    }
}

if (!function_exists('has_filter')) {
    function has_filter(string $tag): bool
    {
        return \FavoriteCMS\Core\Hook::hasFilter($tag);
    }
}

if (!function_exists('remove_filter')) {
    function remove_filter(string $tag): void
    {
        \FavoriteCMS\Core\Hook::removeFilter($tag);
    }
}

// -----------------------------------------------------------------------------
// Plugin Dynamic Routing & Admin Menu APIs
// -----------------------------------------------------------------------------
if (!function_exists('add_route')) {
    function add_route(string|array $methods, string $path, callable|array $handler): void
    {
        \FavoriteCMS\Core\Router::match($methods, $path, $handler);
    }
}

if (!function_exists('add_admin_menu')) {
    function add_admin_menu(
        string $slug,
        string $title,
        ?string $icon = '🔌',
        ?callable $handler = null,
        string $capability = 'manage_options',
        int $position = 50
    ): void {
        \FavoriteCMS\Core\AdminMenu::addMenu($slug, $title, $icon, $handler, $capability, $position);
    }
}

if (!function_exists('add_admin_submenu')) {
    function add_admin_submenu(
        string $parentSlug,
        string $slug,
        string $title,
        ?callable $handler = null,
        string $capability = 'manage_options'
    ): void {
        \FavoriteCMS\Core\AdminMenu::addSubMenu($parentSlug, $slug, $title, $handler, $capability);
    }
}

// -----------------------------------------------------------------------------
// Plugin Settings & Storage APIs
// -----------------------------------------------------------------------------
if (!function_exists('plugin_setting')) {
    function plugin_setting(string $pluginId, string $key, mixed $default = null): mixed
    {
        return \FavoriteCMS\Models\PluginSetting::get($pluginId, $key, $default);
    }
}

if (!function_exists('set_plugin_setting')) {
    function set_plugin_setting(string $pluginId, string $key, mixed $value): void
    {
        \FavoriteCMS\Models\PluginSetting::set($pluginId, $key, $value);
    }
}

// -----------------------------------------------------------------------------
// Site Settings & Global Currency APIs
// -----------------------------------------------------------------------------
if (!function_exists('get_setting')) {
    function get_setting(string $group, string $key, mixed $default = null): mixed
    {
        return \FavoriteCMS\Models\Setting::get($group, $key, $default);
    }
}

if (!function_exists('set_setting')) {
    function set_setting(string $group, string $key, mixed $value, string $type = 'string'): void
    {
        \FavoriteCMS\Models\Setting::set($group, $key, $value, $type);
    }
}

if (!function_exists('primary_currency')) {
    function primary_currency(): string
    {
        return \FavoriteCMS\Core\Currency::getPrimaryCurrency();
    }
}

// -----------------------------------------------------------------------------
// Current User & Capability APIs
// -----------------------------------------------------------------------------
if (!function_exists('current_user')) {
    function current_user(): ?\FavoriteCMS\Models\User
    {
        $id = (int)($_SESSION['auth_user_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $user = \FavoriteCMS\Models\User::find($id);
        if (!$user) {
            return null;
        }
        if ($user->isBanned()) {
            unset(
                $_SESSION['auth_user_id'],
                $_SESSION['auth_user_name'],
                $_SESSION['auth_user_email'],
                $_SESSION['auth_user_role']
            );
            return null;
        }
        return $user;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        $user = current_user();
        if (!$user) {
            return false;
        }
        return $user->hasPermission($capability);
    }
}

// -----------------------------------------------------------------------------
// Logging API
// -----------------------------------------------------------------------------
if (!function_exists('cms_log')) {
    function cms_log(string $message, string $level = 'info', array $context = []): void
    {
        \FavoriteCMS\Core\Logger::log($level, $message, $context);
    }
}

// -----------------------------------------------------------------------------
// Widget & Theme Layout APIs
// -----------------------------------------------------------------------------
if (!function_exists('register_widget')) {
    /**
     * Public API for Core and Plugins to register custom widgets.
     */
    function register_widget(\FavoriteCMS\Widgets\WidgetInterface|string $widget): void
    {
        \FavoriteCMS\Widgets\WidgetRegistry::getInstance()->register($widget);
    }
}

if (!function_exists('render_region')) {
    /**
     * Render all active widgets in a theme region.
     */
    function render_region(string $regionId, array $args = []): string
    {
        $manager = new \FavoriteCMS\Widgets\WidgetInstanceManager();
        return $manager->renderRegion($regionId, $args);
    }
}

if (!function_exists('has_region_widgets')) {
    /**
     * Check if a theme region has any visible widgets.
     */
    function has_region_widgets(string $regionId): bool
    {
        $manager = new \FavoriteCMS\Widgets\WidgetInstanceManager();
        return $manager->hasRegionWidgets($regionId);
    }
}

if (!function_exists('get_theme_mod')) {
    /**
     * Retrieve a theme customization setting value.
     */
    function get_theme_mod(string $name, mixed $default = null): mixed
    {
        $service = new \FavoriteCMS\Themes\ThemeLayoutService(\FavoriteCMS\Core\Application::getInstance());
        return $service->getThemeMod($name, $default);
    }
}

if (!function_exists('set_theme_mod')) {
    /**
     * Set a theme customization setting value.
     */
    function set_theme_mod(string $name, mixed $value): void
    {
        $service = new \FavoriteCMS\Themes\ThemeLayoutService(\FavoriteCMS\Core\Application::getInstance());
        $service->setThemeMod($name, $value);
    }
}

if (!function_exists('sanitize_branding_url')) {
    /**
     * Strictly validate and sanitize a branding (logo or favicon) URL or path.
     * Allows http://, https://, and safe local root-relative paths (/uploads/..., /favicon.ico).
     * Strictly rejects dangerous schemes (javascript:, data:, vbscript:, file:, etc.), protocol-relative URLs (//...),
     * directory traversal (..), control characters, and malformed strings.
     */
    function sanitize_branding_url(?string $url): string
    {
        if ($url === null) {
            return '';
        }

        $trimmed = trim($url);
        if ($trimmed === '') {
            return '';
        }

        // Reject control characters
        if (preg_match('/[\x00-\x1F\x7F]/', $trimmed)) {
            return '';
        }

        // Reject protocol-relative URLs e.g. "//evil.com"
        if (str_starts_with($trimmed, '//')) {
            return '';
        }

        // Check if root-relative path (e.g. /uploads/2026/09/logo.png or /favicon.ico)
        if (str_starts_with($trimmed, '/')) {
            if (str_contains($trimmed, '..')) {
                return '';
            }
            if (!preg_match('~^/[a-zA-Z0-9_\-./]+(\?[a-zA-Z0-9_\-./=&%]*)?$~', $trimmed)) {
                return '';
            }
            return $trimmed;
        }

        // Parse full URL
        $parsed = parse_url($trimmed);
        if ($parsed === false || empty($parsed['scheme']) || empty($parsed['host'])) {
            return '';
        }

        $scheme = strtolower($parsed['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        if (!filter_var($trimmed, FILTER_VALIDATE_URL)) {
            return '';
        }

        return $trimmed;
    }
}

if (!function_exists('get_site_logo_source')) {
    /**
     * Get the active logo source: 'upload', 'url', or 'default'.
     */
    function get_site_logo_source(): string
    {
        try {
            $source = (string)\FavoriteCMS\Models\Setting::get('general', 'site_logo_source', '');
            if ($source === 'upload' || $source === 'url') {
                return $source;
            }
            if (!empty(\FavoriteCMS\Models\Setting::get('general', 'site_logo_upload_path', ''))) {
                return 'upload';
            }
            if (!empty(\FavoriteCMS\Models\Setting::get('general', 'site_logo_url', ''))) {
                return 'url';
            }
        } catch (\Throwable $e) {
            // Ignore
        }
        return 'default';
    }
}

if (!function_exists('get_site_favicon_source')) {
    /**
     * Get the active favicon source: 'upload', 'url', or 'default'.
     */
    function get_site_favicon_source(): string
    {
        try {
            $source = (string)\FavoriteCMS\Models\Setting::get('general', 'site_favicon_source', '');
            if ($source === 'upload' || $source === 'url') {
                return $source;
            }
            if (!empty(\FavoriteCMS\Models\Setting::get('general', 'site_favicon_upload_path', ''))) {
                return 'upload';
            }
            if (!empty(\FavoriteCMS\Models\Setting::get('general', 'site_favicon_url', ''))) {
                return 'url';
            }
        } catch (\Throwable $e) {
            // Ignore
        }
        return 'default';
    }
}

if (!function_exists('get_site_logo_url')) {
    /**
     * Retrieve the site logo URL.
     * Determines active source ('upload' vs 'url' vs 'default'), validates the URL,
     * checks general setting first, then theme mod fallback, then default.
     */
    function get_site_logo_url(string $default = ''): string
    {
        try {
            $source = (string)\FavoriteCMS\Models\Setting::get('general', 'site_logo_source', '');
            $uploadPath = sanitize_branding_url((string)\FavoriteCMS\Models\Setting::get('general', 'site_logo_upload_path', ''));
            $customUrl = sanitize_branding_url((string)\FavoriteCMS\Models\Setting::get('general', 'site_logo_url', ''));

            if ($source === 'upload' && $uploadPath !== '') {
                return $uploadPath;
            }
            if ($source === 'url' && $customUrl !== '') {
                return $customUrl;
            }

            // Fallback to available if source not specified
            if ($uploadPath !== '') {
                return $uploadPath;
            }
            if ($customUrl !== '') {
                return $customUrl;
            }
        } catch (\Throwable $e) {
            // DB might not be connected yet during installer
        }

        try {
            if (function_exists('get_theme_mod')) {
                $themeMod = sanitize_branding_url((string)get_theme_mod('site_logo_url'));
                if ($themeMod !== '') {
                    return $themeMod;
                }
            }
        } catch (\Throwable $e) {
            // Ignore
        }

        return sanitize_branding_url($default);
    }
}

if (!function_exists('get_site_favicon_url')) {
    /**
     * Retrieve the site favicon URL.
     * Determines active source ('upload' vs 'url' vs 'default'), validates the URL,
     * checks general setting first, then theme mod fallback, then default.
     */
    function get_site_favicon_url(string $default = '/favicon.ico'): string
    {
        try {
            $source = (string)\FavoriteCMS\Models\Setting::get('general', 'site_favicon_source', '');
            $uploadPath = sanitize_branding_url((string)\FavoriteCMS\Models\Setting::get('general', 'site_favicon_upload_path', ''));
            $customUrl = sanitize_branding_url((string)\FavoriteCMS\Models\Setting::get('general', 'site_favicon_url', ''));

            if ($source === 'upload' && $uploadPath !== '') {
                return $uploadPath;
            }
            if ($source === 'url' && $customUrl !== '') {
                return $customUrl;
            }

            // Fallback to available if source not specified
            if ($uploadPath !== '') {
                return $uploadPath;
            }
            if ($customUrl !== '') {
                return $customUrl;
            }
        } catch (\Throwable $e) {
            // DB might not be connected yet during installer
        }

        try {
            if (function_exists('get_theme_mod')) {
                $themeMod = sanitize_branding_url((string)get_theme_mod('site_favicon_url'));
                if ($themeMod !== '') {
                    return $themeMod;
                }
            }
        } catch (\Throwable $e) {
            // Ignore
        }

        $sanitizedDefault = sanitize_branding_url($default);
        return $sanitizedDefault !== '' ? $sanitizedDefault : '/favicon.ico';
    }
}

// -----------------------------------------------------------------------------
// Frontend Account & Profile Menu APIs
// -----------------------------------------------------------------------------

if (!function_exists('register_account_menu_item')) {
    /**
     * Public API for Core and Plugins to register an account menu item.
     *
     * Supported array keys:
     * - id (string, required): unique item identifier
     * - label (string, required): display text (HTML-stripped)
     * - url (string, required): destination URL or root-relative path
     * - icon (string, optional): icon identifier ('user', 'settings', 'log-out', etc.) or safe SVG
     * - order (int, optional): sort order (default 50, lower numbers first)
     * - capability (string, optional): required user permission (null for any logged-in user)
     * - plugin (string, optional): plugin identifier (default 'core')
     * - condition (callable|bool|null, optional): dynamic visibility callback fn(?User $user): bool
     *
     * @param array $item Item definition
     * @return bool True if registered, false on validation failure
     */
    function register_account_menu_item(array $item): bool
    {
        return \FavoriteCMS\Core\AccountMenu::registerItem($item);
    }
}

if (!function_exists('unregister_account_menu_item')) {
    /**
     * Public API to remove an account menu item by ID.
     */
    function unregister_account_menu_item(string $id): bool
    {
        return \FavoriteCMS\Core\AccountMenu::removeItem($id);
    }
}

if (!function_exists('get_account_menu_items')) {
    /**
     * Retrieve all account menu items visible to the authenticated user.
     * Returns an empty array for guests.
     *
     * @param ?object $user Optional user object (defaults to current_user())
     * @return array<string, array>
     */
    function get_account_menu_items(?object $user = null): array
    {
        return \FavoriteCMS\Core\AccountMenu::getItems($user);
    }
}

if (!function_exists('has_account_menu_items')) {
    /**
     * Check if the authenticated user has any visible account menu items.
     * Returns false for guests.
     */
    function has_account_menu_items(?object $user = null): bool
    {
        return !empty(\FavoriteCMS\Core\AccountMenu::getItems($user));
    }
}

if (!function_exists('render_account_menu')) {
    /**
     * Render the theme-agnostic accessible account profile menu dropdown.
     * Returns empty string for unauthenticated guests.
     *
     * @param array $options Rendering options (show_avatar, show_name, show_role, etc.)
     * @return string Safe HTML
     */
    function render_account_menu(array $options = []): string
    {
        return \FavoriteCMS\Core\AccountMenu::render($options);
    }
}

if (!function_exists('get_user_avatar_url')) {
    /**
     * Retrieve the avatar URL of the given user or current logged-in user.
     * Returns null if no avatar is set or user is unauthenticated.
     */
    function get_user_avatar_url(?object $user = null): ?string
    {
        $user = $user ?? (function_exists('current_user') ? current_user() : null);
        if (!$user || empty($user->avatar)) {
            return null;
        }
        $url = (string)$user->avatar;
        return \FavoriteCMS\Core\AccountMenu::isValidUrl($url) ? $url : null;
    }
}

if (!function_exists('get_user_display_name')) {
    /**
     * Retrieve the display name of the given user or current logged-in user.
     */
    function get_user_display_name(?object $user = null): string
    {
        $user = $user ?? (function_exists('current_user') ? current_user() : null);
        if (!$user) {
            return 'Guest';
        }
        return (string)($user->name ?: ($user->username ?: 'User'));
    }
}

// -----------------------------------------------------------------------------
// Site URL, Base Path & Theme Asset APIs
// -----------------------------------------------------------------------------

if (!function_exists('site_base_path')) {
    /**
     * The URL path prefix of the installation ('' for root installs, '/cms' for subdirectory installs).
     */
    function site_base_path(): string
    {
        $base = trim((string)($GLOBALS['favorite_cms_base_path'] ?? ''), '/');
        return $base === '' ? '' : '/' . $base;
    }
}

if (!function_exists('site_path')) {
    /**
     * Build a browser URL for a site-relative path, honoring subdirectory installs.
     *
     * Root-relative paths (/post/example) receive the base path exactly once. Absolute URLs,
     * protocol-relative URLs, fragments, query-only and relative values are returned unchanged,
     * as are paths that already start with the base path.
     */
    function site_path(string $path = '/'): string
    {
        $path = trim($path);
        if ($path === '') {
            $path = '/';
        }

        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $path;
        }

        $base = site_base_path();
        if ($base === '' || $path === $base || str_starts_with($path, $base . '/') || str_starts_with($path, $base . '?') || str_starts_with($path, $base . '#')) {
            return $path;
        }

        return $base . $path;
    }
}

if (!function_exists('site_request_path')) {
    /**
     * Normalized site-relative path of a URI (defaults to the current request): no base path,
     * no query string, no fragment and no trailing slash ('/' for the homepage).
     */
    function site_request_path(?string $uri = null): string
    {
        $uri = $uri ?? (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? '/' . ltrim($path, '/') : '/';

        $base = site_base_path();
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base));
        }

        $path = rtrim($path, '/');
        return $path === '' ? '/' : $path;
    }
}

if (!function_exists('is_current_url')) {
    /**
     * Whether a menu/link URL points at the current request path.
     * Handles base paths, query strings, fragments, trailing slashes and same-host absolute URLs.
     */
    function is_current_url(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || $url === '#' || str_starts_with($url, '#')) {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }

        if (isset($parts['scheme']) || isset($parts['host'])) {
            if (!in_array(strtolower((string)($parts['scheme'] ?? 'http')), ['http', 'https'], true) || empty($parts['host'])) {
                return false;
            }
            $requestHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
            $urlHost = strtolower((string)$parts['host']) . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
            if ($requestHost === '' || $urlHost !== $requestHost) {
                return false;
            }
        } elseif (!str_starts_with($url, '/')) {
            return false;
        }

        $path = (string)($parts['path'] ?? '/');
        return site_request_path($path === '' ? '/' : $path) === site_request_path();
    }
}

if (!function_exists('menu_item_url')) {
    /**
     * Resolve the browser URL of a stored menu item without rewriting stored menu data.
     */
    function menu_item_url(object|array $item): string
    {
        $url = trim((string)(is_array($item) ? ($item['url'] ?? '') : ($item->url ?? '')));
        return $url === '' ? '#' : site_path($url);
    }
}

if (!function_exists('active_theme_id')) {
    /**
     * Identifier of the active theme directory (validated, defaults to 'default').
     */
    function active_theme_id(): string
    {
        try {
            $theme = (string)\FavoriteCMS\Models\Setting::get('theme', 'active_theme', 'default');
        } catch (\Throwable) {
            $theme = 'default';
        }
        return preg_match('/^[A-Za-z0-9_-]+$/', $theme) === 1 ? $theme : 'default';
    }
}

if (!function_exists('theme_asset_url')) {
    /**
     * Base-path-aware URL for a static asset of the active (or given) theme, e.g.
     * theme_asset_url('assets/css/style.css'). A file modification version is appended for cache busting.
     */
    function theme_asset_url(string $asset, ?string $themeId = null): string
    {
        $themeId = ($themeId !== null && preg_match('/^[A-Za-z0-9_-]+$/', $themeId) === 1) ? $themeId : active_theme_id();
        $asset = ltrim(str_replace('\\', '/', trim($asset)), '/');

        if ($asset === '' || str_contains($asset, '..')) {
            return site_path('/themes/' . $themeId . '/');
        }

        $url = site_path('/themes/' . $themeId . '/' . $asset);
        $file = APP_ROOT . '/themes/' . $themeId . '/' . $asset;
        if (is_file($file)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'v=' . filemtime($file);
        }

        return $url;
    }
}

if (!function_exists('site_language')) {
    /**
     * BCP 47 language tag for the <html lang> attribute.
     * Uses the optional general.site_language setting, then config app.locale, then 'en'.
     */
    function site_language(): string
    {
        $lang = '';
        try {
            $lang = trim((string)\FavoriteCMS\Models\Setting::get('general', 'site_language', ''));
        } catch (\Throwable) {
            $lang = '';
        }
        if ($lang === '') {
            try {
                $lang = trim((string)config('app.locale', 'en'));
            } catch (\Throwable) {
                $lang = 'en';
            }
        }

        $lang = str_replace('_', '-', $lang);
        if (function_exists('apply_filters')) {
            $lang = (string)apply_filters('site_language', $lang);
        }

        return preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $lang) === 1 ? $lang : 'en';
    }
}

// -----------------------------------------------------------------------------
// Site Date & Timezone APIs
// -----------------------------------------------------------------------------

if (!function_exists('site_timezone')) {
    /**
     * Retrieve the active site IANA timezone identifier (e.g. 'UTC', 'Asia/Dhaka').
     */
    function site_timezone(): string
    {
        return \FavoriteCMS\Core\DateTime::getTimezone();
    }
}

if (!function_exists('format_date')) {
    /**
     * Format a stored UTC timestamp, Unix epoch integer, or DateTimeInterface into site timezone.
     */
    function format_date(mixed $datetime, string $format = 'M j, Y \a\t g:i a', ?string $timezone = null): string
    {
        return \FavoriteCMS\Core\DateTime::format($datetime, $format, $timezone);
    }
}

