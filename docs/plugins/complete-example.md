# Complete Working Plugin Example: `hello-favorite`

Favorite CMS includes an official reference plugin located at:
```
plugins/hello-favorite/
```

This plugin demonstrates every major public extension API in action.

---

## 1. Directory Structure

```
plugins/hello-favorite/
├── plugin.json               <-- Manifest
├── plugin.php                <-- Bootstrap & routes
├── templates/
│   └── greeting.php          <-- Custom template view
└── assets/
    └── css/style.css         <-- Static stylesheet
```

---

## 2. Manifest (`plugin.json`)

```json
{
    "id": "hello-favorite",
    "name": "Hello Favorite",
    "version": "1.0.0",
    "description": "Official reference plugin demonstrating Favorite CMS public APIs: dynamic routes, admin pages, permissions, settings, hooks, templates, assets, and logging.",
    "author": "Favorite CMS Team",
    "requires_php": "8.1.0",
    "dependencies": [],
    "entry_point": "plugin.php"
}
```

---

## 3. Features Implemented in `plugin.php`

1. **Lifecycle Logging**: Hooks into the `init` action to write diagnostic information via `cms_log()`.
2. **Dynamic Frontend Route**: Registers `/hello-favorite` and `/hello-favorite/{name}` using `add_route()`.
3. **Template Rendering**: Renders `templates/greeting.php` using `app(Engine::class)->render()`.
4. **Admin Panel**: Registers `/admin/page/hello-favorite` with form submission handling and CSRF validation using `add_admin_menu()`.
5. **Settings Storage**: Stores greeting preferences in `plugin_settings` using `set_plugin_setting()` and `plugin_setting()`.
6. **Activation Hook**: Listens for `plugin.activated` to seed default configuration.
