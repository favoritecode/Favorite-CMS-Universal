# Public Core API Reference

Standard functions and classes provided by Favorite CMS Universal.

---

## 1. Global Helper Functions

### `app(?string $abstract = null): mixed`
Returns the application container instance, or resolves a singleton from the container.

### `config(string $key, mixed $default = null): mixed`
Reads configuration values from `app/Config` or `.env`.

### `env(string $key, mixed $default = null): mixed`
Reads environment variables directly with boolean casting.

### `add_action(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): void`
Attaches an event callback to `$tag`.

### `do_action(string $tag, mixed ...$args): void`
Triggers all callbacks attached to `$tag`.

### `add_filter(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): void`
Attaches a filter callback to `$tag`.

### `apply_filters(string $tag, mixed $value, mixed ...$args): mixed`
Applies all filters to `$value` and returns the filtered result.

### `add_route(string|array $methods, string $path, callable|array $handler): void`
Registers a dynamic HTTP route.

### `add_admin_menu(string $slug, string $title, ?string $icon = '🔌', ?callable $handler = null, string $capability = 'manage_options', int $position = 50): void`
Registers an administrative panel and sidebar link.

### `add_admin_submenu(string $parentSlug, string $slug, string $title, ?callable $handler = null, string $capability = 'manage_options'): void`
Registers a submenu under an existing admin menu.

### `plugin_setting(string $pluginId, string $key, mixed $default = null): mixed`
Retrieves an isolated setting value.

### `set_plugin_setting(string $pluginId, string $key, mixed $value): void`
Persists an isolated setting value.

### `current_user(): ?FavoriteCMS\Models\User`
Returns the logged-in user model.

### `current_user_can(string $capability): bool`
Checks user capability. Returns true for `super-admin`.

### `cms_log(string $message, string $level = 'info', array $context = []): void`
Writes a formatted entry to `storage/logs/favorite_cms.log`.
