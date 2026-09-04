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
        return \FavoriteCMS\Models\User::find($id);
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


