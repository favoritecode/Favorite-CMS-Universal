# Admin Menus & Custom Pages in Plugins

Plugins can introduce custom administration panels seamlessly integrated into the Favorite CMS admin dashboard.

---

## 1. Registering a Top-Level Admin Menu

Use `add_admin_menu()` in your plugin bootstrap file:

```php
add_admin_menu(
    $slug       = 'analytics-dashboard',
    $title      = 'Analytics',
    $icon       = '📊',
    $handler    = function(\FavoriteCMS\Core\Request $request) {
        return '<h2>Traffic Analytics</h2><p>Here are your site statistics...</p>';
    },
    $capability = 'manage_options',
    $position   = 55
);
```

- Accessible at: `/admin/page/analytics-dashboard`.
- Automatically injected into the sidebar navigation.
- Automatically wrapped inside the consistent Favorite CMS admin layout.

---

## 2. Registering Sub-Menus

Use `add_admin_submenu()` to nest screens under a parent:

```php
add_admin_submenu(
    $parentSlug = 'analytics-dashboard',
    $slug       = 'analytics-settings',
    $title      = 'Settings',
    $handler    = function(\FavoriteCMS\Core\Request $request) {
        return '<h2>Analytics Configuration</h2>';
    },
    $capability = 'manage_options'
);
```

---

## 3. Handling Form Submissions

Form submissions in admin pages are handled by checking `$request->method() === 'POST'`:

```php
add_admin_menu('my-tool', 'My Tool', '🛠️', function(\FavoriteCMS\Core\Request $request) {
    if ($request->method() === 'POST') {
        // Validate CSRF token
        if (!hash_equals($_SESSION['_token'] ?? '', (string)$request->post('_token'))) {
            return '<div class="notice notice-error">Invalid CSRF token!</div>';
        }
        
        $val = $request->post('api_key');
        set_plugin_setting('my-tool', 'api_key', $val);
        echo '<div class="notice notice-success">Saved!</div>';
    }

    $current = plugin_setting('my-tool', 'api_key', '');
    ob_start();
    ?>
    <form method="POST" action="/admin/page/my-tool">
        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        <label>API Key:</label>
        <input type="text" name="api_key" value="<?php echo htmlspecialchars($current, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" class="btn btn-primary">Save</button>
    </form>
    <?php
    return ob_get_clean();
});
```
