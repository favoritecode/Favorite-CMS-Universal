# Plugin Settings Storage API

Favorite CMS provides an isolated key-value settings storage engine for plugins, powered by the `plugin_settings` database table and `FavoriteCMS\Models\PluginSetting`.

---

## 1. Reading Settings

Use the `plugin_setting()` helper:

```php
// Retrieve setting with fallback
$apiKey = plugin_setting('my-plugin', 'api_key', 'default_value');

// Arrays and nested objects are automatically decoded from JSON
$options = plugin_setting('my-plugin', 'advanced_options', [
    'notify_on_comment' => true,
    'max_items'         => 25,
]);
```

---

## 2. Storing Settings

Use `set_plugin_setting()`:

```php
// Storing scalar values
set_plugin_setting('my-plugin', 'api_key', 'sk_live_123456');

// Storing complex arrays (automatically JSON-encoded)
set_plugin_setting('my-plugin', 'advanced_options', [
    'notify_on_comment' => false,
    'max_items'         => 50,
]);
```

---

## 3. Bulk Retrieval & Cleanup

You can interact directly with `FavoriteCMS\Models\PluginSetting`:

```php
use FavoriteCMS\Models\PluginSetting;

// Get all settings for this plugin as an associative array
$allSettings = PluginSetting::forPlugin('my-plugin');

// Delete a specific setting
PluginSetting::deleteSetting('my-plugin', 'api_key');

// Purge all settings (done automatically on plugin uninstall)
PluginSetting::deleteSetting('my-plugin');
```
