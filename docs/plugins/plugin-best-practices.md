# Plugin Development Best Practices

To ensure maximum security, stability, and compatibility across **Favorite CMS Universal** updates, all plugins should adhere to these core principles.

---

## 1. What Plugins Should NEVER Do

1. **NEVER Modify Core Source Files**:
   - Do not edit files inside `app/`, `config/`, or `database/`.
   - Use action hooks, filters, and dynamic routes to achieve your goals.
2. **NEVER Modify Theme Files Directly**:
   - A plugin should not write directly to `themes/`.
   - Provide template fallbacks in your plugin that themes can optionally override.
3. **NEVER Modify Another Plugin's Files**:
   - Inter-plugin communication must occur via hooks or public services, never file tampering.
4. **NEVER Direct SQL with Unescaped Variables**:
   - Always use parameterized PDO prepared statements: `$db->select("SELECT * FROM table WHERE id = ?", [$id])`.
5. **NEVER Output Raw User Input Without Escaping**:
   - Prevent XSS by escaping all frontend strings with `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')`.

---

## 2. Recommended Patterns

### Table Prefixing
Always use the configured database prefix when creating or querying custom plugin tables:
```php
$db = app(\FavoriteCMS\Core\Database::class);
$tableName = $db->prefix() . 'my_plugin_items';

$db->execute("CREATE TABLE IF NOT EXISTS `{$tableName}` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
```

### Template Resolution Hierarchy
When rendering public views, allow the active theme to override your plugin templates:
```
1. Check Active Theme:   themes/{active_theme}/plugins/{plugin-slug}/{template}.php
2. Fallback to Plugin:  plugins/{plugin-slug}/views/{template}.php
```

Example implementation:
```php
function render_plugin_view(string $pluginSlug, string $view, array $data = []): string {
    $activeTheme = \FavoriteCMS\Models\Setting::get('theme', 'active_theme', 'default');
    $themeOverride = APP_ROOT . "/themes/{$activeTheme}/plugins/{$pluginSlug}/{$view}.php";
    $pluginDefault = APP_ROOT . "/plugins/{$pluginSlug}/views/{$view}.php";

    $fileToInclude = file_exists($themeOverride) ? $themeOverride : $pluginDefault;

    extract($data, EXTR_SKIP);
    ob_start();
    include $fileToInclude;
    return (string)ob_get_clean();
}
```

### Isolation & Error Handling
Wrap complex external API calls or third-party integrations in `try-catch` blocks. The Favorite CMS `PluginManager` isolates plugin boots, but runtime exceptions inside custom route handlers should be handled gracefully with friendly user notices.

