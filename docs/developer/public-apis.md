# Public Core APIs

This document serves as the contract between the Favorite CMS Core and extension authors. These APIs are designed to remain backward-compatible within a major version release.

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

---

## 6. Frontend Account & Profile Menu APIs

Favorite CMS Core provides an extensible, theme-agnostic frontend account and profile menu registry (`FavoriteCMS\Core\AccountMenu`). Plugins can register custom navigation items into the authenticated user's dropdown menu.

### `register_account_menu_item(array $item): bool`
Registers a new item in the frontend account menu.

Supported array keys:
- `id` (string, required): Unique identifier (alphanumeric, dashes, underscores).
- `label` (string, required): Display text (HTML tags are automatically stripped).
- `url` (string, required): Root-relative path (e.g. `/account/orders`) or valid `http://`/`https://` URL. Dangerous schemes (`javascript:`, `data:`, `file:`, protocol-relative `//`) are strictly rejected.
- `icon` (string, optional): Icon identifier (e.g. `'user'`, `'settings'`, `'layout-dashboard'`, `'log-out'`) or safe inline SVG.
- `order` (int, optional): Priority position (default `50`; lower numbers appear higher).
- `capability` (string, optional): Required permission slug. If specified, the item is hidden from users lacking this capability.
- `plugin` (string, optional): Originating plugin ID (default `'core'`). Used for automatic lifecycle cleanup.
- `condition` (callable|bool|null, optional): Dynamic visibility condition `fn(?User $user): bool`.

#### Example Plugin Usage:
```php
// Inside plugin.php or during 'account_menu_init' hook:
register_account_menu_item([
    'id'         => 'my-custom-dashboard',
    'label'      => 'Member Portal',
    'url'        => '/portal/dashboard',
    'icon'       => 'layout-dashboard',
    'order'      => 25,
    'capability' => 'read',
    'plugin'     => 'my-portal-plugin',
    'condition'  => function($user) {
        return $user && $user->id > 0;
    },
]);
```

### `unregister_account_menu_item(string $id): bool`
Removes an account menu item by its ID.

### `get_account_menu_items(?User $user = null): array`
Returns all account menu items visible to the specified user (or `current_user()`), filtered by capabilities and custom conditions, sorted by order ascending. Returns an empty array `[]` for unauthenticated guests.

### `has_account_menu_items(?User $user = null): bool`
Returns `true` if the current or given user has at least one visible account menu item, `false` otherwise (and `false` for guests).

### `render_account_menu(array $options = []): string`
Renders the accessible HTML dropdown markup for the account menu. Returns an empty string `""` for unauthenticated guests.

Options:
- `user`: Custom user object (defaults to `current_user()`).
- `show_avatar`: Show/hide user avatar icon (default `true`).
- `show_name`: Show/hide display name (default `true`).
- `show_role`: Show/hide role label in dropdown header (default `true`).

### Lifecycle & Unregistration Behavior
- **Automatic Lifecycle Purging**: When a plugin is deactivated (`plugin.deactivated`) or uninstalled (`plugin.uninstalled`), Core automatically purges all account menu items registered with that plugin's ID from memory.
- **Boot Isolation**: Because active plugins are booted on each request, deactivating a plugin prevents its bootstrap code from running on subsequent requests, ensuring its items disappear automatically.

### Security & Permission Expectations
- **Guest Isolation**: Unauthenticated visitors receive **no** account menu items or authenticated actions. `get_account_menu_items()` returns `[]` and `render_account_menu()` returns `""`.
- **Capability Checking**: Items configured with a `capability` are automatically checked against `$user->hasPermission($capability)`. Unauthorized users never receive or see the item.
- **XSS & URL Sanitization**: All labels are stripped of HTML tags and escaped with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`. All URLs are validated to prevent `javascript:`, `data:`, or protocol-relative injection.

