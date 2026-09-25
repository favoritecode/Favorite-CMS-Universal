# Plugin Development Guide

This guide is the complete developer reference for building extensions and plugins for **Favorite CMS Universal v1.0.0**.

---

## 1. Plugin Architecture Overview

Favorite CMS Universal provides a modular, generic plugin subsystem (`FavoriteCMS\Plugins\PluginManager`). All third-party business logic—including ecommerce, booking engines, media streamers, forums, and developer utilities—is engineered as independent plugins decoupled from the core codebase.

### Plugin Directory Structure
Plugins reside in the `plugins/` directory:
```
plugins/
└── my-plugin/
    ├── plugin.json               # [Required] Plugin manifest
    ├── plugin.php                # [Required] Plugin entry point
    ├── database/
    │   └── migrations/           # Automated schema migrations
    │       └── 001_create_plugin_table.php
    ├── src/                      # PSR-4 or custom classes
    │   └── MyPlugin.php
    ├── assets/                   # Static CSS, JS, images
    │   ├── css/
    │   │   └── style.css
    │   └── js/
    │       └── script.js
    └── templates/                # Custom view templates
        └── admin-page.php
```

---

## 2. The Plugin Manifest (`plugin.json`)

The `plugin.json` manifest declares metadata, system requirements, dependencies, and database tables created by the plugin:

```json
{
    "id": "my-plugin",
    "name": "My Custom Plugin",
    "version": "1.0.0",
    "author": "Your Name / Organization",
    "description": "Adds custom functionality to Favorite CMS Universal.",
    "requires_php": "8.1.0",
    "dependencies": [],
    "tables": [
        "plugin_custom_data"
    ],
    "entry_point": "plugin.php"
}
```

### Manifest Fields
- **`id` (string, required):** Unique slug matching the plugin's folder name.
- **`name` (string, required):** Human-readable title displayed in the Admin panel.
- **`version` (string, required):** Semantic version string (e.g. `1.0.0`).
- **`author` (string):** Plugin developer or studio name.
- **`description` (string):** Summary of features provided.
- **`requires_php` (string):** Minimum PHP version (e.g. `8.1.0`). Verified during activation.
- **`dependencies` (array):** List of other plugin IDs that must be active before this plugin can be enabled.
- **`tables` (array):** List of un-prefixed table names created by this plugin. Used by the core `Database` layer to apply dynamic table prefixing (e.g. `fc_plugin_custom_data`).
- **`entry_point` (string):** Main bootstrap script relative to plugin root (defaults to `plugin.php`).

---

## 3. Plugin Bootstrap Lifecycle

When Favorite CMS Universal boots active plugins (`PluginManager::bootActivePlugins()`):
1. Includes the entry point script (`plugin.php`). The core `$app` instance is available in scope.
2. Checks for a standard bootstrap class:
   ```php
   FavoriteCMS\Plugins\{PascalCasePluginId}Plugin
   ```
3. If that class exists and defines a static `bootstrap(\FavoriteCMS\Core\Application $app)` method, it is invoked automatically.

### Fault-Tolerant Execution
All plugin loading is wrapped in try-catch error shields. If an active plugin raises an unhandled exception or syntax error:
- The error is logged to `storage/logs/favorite_cms.log`.
- The broken plugin is skipped for that request.
- The rest of the CMS core and admin panel continue running without crashing.

---

## 4. Activation, Deactivation, & Migrations

### Activation (`/admin/plugins`)
When an administrator activates the plugin:
1. Manifest validity, PHP version compatibility, and dependencies are verified.
2. The plugin entry point is loaded into memory.
3. Declared database tables in `tables` are registered with `Database::registerPrefixableTables()`.
4. **Database Migrations:** If `database/migrations/` exists inside the plugin folder, `Migrator` runs all pending migration files automatically.
5. Fires the `plugin.activated` hook:
   ```php
   add_action('plugin.activated', function(string $pluginId) {
       if ($pluginId === 'my-plugin') {
           // Seed default plugin settings
       }
   });
   ```
6. The plugin identifier is appended to the active plugins list in `settings`.

### Deactivation
When an administrator deactivates the plugin:
1. Fires the `plugin.deactivated` hook (`Hook::doAction('plugin.deactivated', $pluginId)`).
2. Removes the plugin identifier from the active list.
3. The plugin is no longer loaded on subsequent requests. Database tables and settings remain preserved.

---

## 5. Registering Custom Routes

Plugins can register public frontend routes or API endpoints via `FavoriteCMS\Core\Router`:

```php
use FavoriteCMS\Core\Router;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;

// Frontend GET route
Router::get('/my-plugin/status', function(Request $request) {
    return Response::json([
        'status'  => 'ok',
        'message' => 'Plugin is functioning normally.'
    ]);
});

// Dynamic parameter route
Router::get('/items/{id}', function(Request $request, array $params) {
    $itemId = (int)$params['id'];
    return Response::make("Viewing item #{$itemId}");
});

// Handling form submissions
Router::post('/my-plugin/submit', function(Request $request) {
    csrf_verify(); // Enforce CSRF protection
    $name = sanitize_text_field($request->post('name', ''));
    // Process form...
    return Response::redirect('/my-plugin/status');
});
```

---

## 6. Registering Admin Menus (`AdminMenu`)

Plugins can register custom top-level admin menu items and submenus using `FavoriteCMS\Core\AdminMenu`:

```php
use FavoriteCMS\Core\AdminMenu;
use FavoriteCMS\Core\Response;

add_action('init', function() {
    // 1. Top-level Menu Item
    AdminMenu::addMenu(
        'my-plugin',             // URL slug (/admin/my-plugin)
        'My Plugin',             // Menu title
        '📦',                    // Icon emoji or SVG
        'render_my_plugin_page', // Callback handler function
        'manage_options',        // Capability required (Admin)
        60                       // Menu position
    );

    // 2. Submenu Item
    AdminMenu::addSubMenu(
        'my-plugin',             // Parent slug
        'my-plugin-settings',    // Submenu slug (/admin/my-plugin-settings)
        'Settings',              // Submenu title
        'render_my_plugin_settings',
        'manage_options'
    );
});

function render_my_plugin_page(): Response
{
    ob_start();
    ?>
    <div class="wrap">
        <h1>My Plugin Management</h1>
        <p>Welcome to the custom plugin control panel.</p>
    </div>
    <?php
    $content = ob_get_clean();
    return Response::make($content);
}
```

---

## 7. Database Migrations in Plugins

To create custom database tables, create migration classes in `plugins/my-plugin/database/migrations/`:

### Example Migration: `001_create_plugin_items_table.php`
```php
<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

class CreatePluginItemsTable
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `plugin_items` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `title` VARCHAR(255) NOT NULL,
                `content` TEXT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS `plugin_items`;");
    }
}
```

---

## 8. Storing Plugin Settings

Use the core `Setting` model to store plugin options cleanly without creating new tables:

```php
use FavoriteCMS\Models\Setting;

// Save a setting under the plugin group
Setting::set('my_plugin', 'api_key', 'pk_live_123456', 'string');
Setting::set('my_plugin', 'feature_enabled', true, 'boolean');
Setting::set('my_plugin', 'allowed_ips', ['127.0.0.1', '192.168.1.1'], 'json');

// Retrieve settings
$apiKey  = Setting::get('my_plugin', 'api_key', '');
$enabled = (bool)Setting::get('my_plugin', 'feature_enabled', false);
$ips     = Setting::get('my_plugin', 'allowed_ips', []);
```

---

## 9. Security Best Practices for Plugins

1. **Always Verify CSRF on POST Actions:**
   ```php
   if ($request->isMethod('POST')) {
       csrf_verify();
   }
   ```
2. **Authorize Capabilities:**
   ```php
   if (!current_user_can('manage_options')) {
       return Response::make('Access Denied', 403);
   }
   ```
3. **Use Prepared Statements:**
   ```php
   $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
   $results = $db->select("SELECT * FROM `plugin_items` WHERE `title` LIKE ?", ["%{$search}%"]);
   ```
4. **Escape All Outputs:**
   ```php
   <p>Hello, <?php echo esc_html($item->title); ?>!</p>
   ```

---

## 10. Complete Minimal Working Plugin Example

Here is a complete, production-ready minimal plugin you can create in `plugins/demo-counter/`:

### File 1: `plugins/demo-counter/plugin.json`
```json
{
    "id": "demo-counter",
    "name": "Demo Page View Counter",
    "version": "1.0.0",
    "author": "Favorite Developer",
    "description": "Demonstrates hooks, routing, and database queries in Favorite CMS.",
    "requires_php": "8.1.0",
    "dependencies": [],
    "tables": []
}
```

### File 2: `plugins/demo-counter/plugin.php`
```php
<?php

declare(strict_types=1);

use FavoriteCMS\Core\Router;
use FavoriteCMS\Core\AdminMenu;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Setting;

// 1. Hook into public article rendering to append a view notice
add_filter('the_content', function(string $content) {
    $views = (int)Setting::get('demo_counter', 'total_views', 0) + 1;
    Setting::set('demo_counter', 'total_views', $views, 'integer');

    $badge = "<div style='padding: 8px 12px; background: #eff6ff; border-left: 4px solid #3b82f6; margin: 16px 0;'>
                <strong>Page Views:</strong> {$views}
              </div>";

    return $content . $badge;
});

// 2. Register an admin dashboard page
add_action('init', function() {
    AdminMenu::addMenu(
        'demo-counter',
        'View Counter',
        '📊',
        function() {
            $views = Setting::get('demo_counter', 'total_views', 0);
            return Response::make("
                <div style='padding: 24px; font-family: sans-serif;'>
                    <h2>Demo Page View Counter</h2>
                    <p>Articles have been viewed a total of <strong>{$views}</strong> times.</p>
                </div>
            ");
        },
        'manage_options'
    );
});
```
