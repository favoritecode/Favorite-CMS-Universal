# Hooks and Events Subsystem

Favorite CMS Universal v1.0.0 provides a priority-based **Action and Filter Hook Engine** (`FavoriteCMS\Core\Hook`), enabling themes and plugins to hook into the application lifecycle without altering core files.

---

## Actions vs. Filters

| Feature | Action Hooks (`add_action`) | Filter Hooks (`add_filter`) |
|---|---|---|
| **Purpose** | Execute custom code or side-effects at specific moments during request lifecycle. | Intercept, modify, and return a specific value before output or storage. |
| **Return Value** | Ignored. | Modified value is returned. |
| **Dispatch Method** | `do_action('tag', ...$args)` | `$val = apply_filters('tag', $val, ...$args)` |

---

## 1. Action Hooks

### Registering an Action (`add_action`)
```php
add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
```

#### Example: Running initialization code
```php
add_action('init', function($app) {
    // Perform custom setup when the core is initialized
}, 20);
```

### Dispatching an Action (`do_action`)
```php
\FavoriteCMS\Core\Hook::doAction('after_post_published', $post);
```

---

## 2. Filter Hooks

### Registering a Filter (`add_filter`)
```php
add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
```

#### Example: Modifying post content
```php
add_filter('the_content', function(string $content) {
    // Append custom disclaimer to articles
    return $content . '<p class="disclaimer">Views expressed are personal.</p>';
}, 10);
```

### Applying Filters (`apply_filters`)
```php
$title = \FavoriteCMS\Core\Hook::applyFilters('the_title', $post->title, $post);
```

---

## 3. Core Lifecycle Hooks Reference

| Hook Name | Type | Arguments Passed | When Fired |
|---|---|---|---|
| **`init`** | Action | `$app` (Application instance) | Fired during request bootstrap after all active plugins and the active theme's `functions.php` are loaded. |
| **`widgets_init`** | Action | None | Fired when `WidgetRegistry` boots. Used to register custom widget classes via `WidgetRegistry::getInstance()->register()`. |
| **`plugin.activated`** | Action | `$pluginId` (string) | Fired when an administrator activates a plugin in the admin panel. |
| **`plugin.deactivated`** | Action | `$pluginId` (string) | Fired when a plugin is deactivated in the admin panel. |
| **`wp_head`** | Action | None | Fired inside `<head>` of frontend themes. Used for CSS links, meta tags, and analytics. |
| **`wp_footer`** | Action | None | Fired immediately before `</body>` in frontend themes. Used for scripts. |
| **`admin_head`** | Action | None | Fired in the `<head>` of the administrative layout. |
| **`admin_footer`** | Action | None | Fired before `</body>` in the administrative layout. |
| **`the_content`** | Filter | `$content` (string) | Applied to post and page content before frontend rendering. |
| **`the_title`** | Filter | `$title` (string) | Applied to post/page titles. |
| **`the_excerpt`** | Filter | `$excerpt` (string) | Applied to post excerpts. |
