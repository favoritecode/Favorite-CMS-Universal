# Public Core APIs

This document serves as the contract between the Favorite CMS Core and extension authors. These APIs are guaranteed stable and will not break within a major version release.

---

## 1. Global Hook Functions

### `add_action(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): void`
Registers a callback to be triggered when action `$tag` is fired.

### `do_action(string $tag, mixed ...$args): void`
Fires all callbacks attached to `$tag` in priority order.

### `add_filter(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): void`
Registers a filter transformation callback on `$tag`.

### `apply_filters(string $tag, mixed $value, mixed ...$args): mixed`
Passes `$value` through all callbacks attached to `$tag` and returns the final value.

---

## 2. Dynamic Routing & Admin Menu Functions

### `add_route(string|array $methods, string $path, callable|array $handler): void`
Registers a dynamic frontend HTTP endpoint. Supports path parameters: `/my-plugin/{id}`.

### `add_admin_menu(string $slug, string $title, ?string $icon = '🔌', ?callable $handler = null, string $capability = 'manage_options', int $position = 50): void`
Registers a top-level administration panel at `/admin/page/{slug}` and injects it into the admin sidebar.

### `add_admin_submenu(string $parentSlug, string $slug, string $title, ?callable $handler = null, string $capability = 'manage_options'): void`
Registers a child menu item under a parent admin menu.

---

## 3. Configuration & Settings Functions

### `plugin_setting(string $pluginId, string $key, mixed $default = null): mixed`
Reads an isolated setting from the `plugin_settings` table. Decodes JSON arrays automatically.

### `set_plugin_setting(string $pluginId, string $key, mixed $value): void`
Writes an isolated setting to the `plugin_settings` table.

---

## 4. User & Authorization Functions

### `current_user(): ?FavoriteCMS\Models\User`
Returns the currently authenticated `User` model, or `null` if unauthenticated.

### `current_user_can(string $capability): bool`
Checks if the current user possesses a specific capability. Automatically returns `true` for `super-admin`.

---

## 5. Logging & Diagnostics

### `cms_log(string $message, string $level = 'info', array $context = []): void`
Appends a formatted line to `storage/logs/favorite_cms.log`.
Levels: `'info'`, `'warning'`, `'error'`, `'debug'`.
