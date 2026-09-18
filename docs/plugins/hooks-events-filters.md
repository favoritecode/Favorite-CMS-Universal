# Hooks, Events & Filters Guide

Favorite CMS Universal includes an event-driven architecture powered by `FavoriteCMS\Core\Hook`. Hooks allow plugins to listen to system events, execute custom logic, or transform data in-flight without modifying Core files.

---

## 1. Action Hooks vs. Filter Hooks

| Feature | Action Hooks (`add_action`) | Filter Hooks (`add_filter`) |
|---|---|---|
| **Purpose** | Execute logic at a specific lifecycle event (e.g. on boot, after post save). | Modify and return a value (e.g. sanitize title, format content). |
| **Return Value** | Return value is ignored. | **Must** return the modified value. |
| **Invocation** | `Hook::doAction($tag, ...$args)` or `do_action(...)` | `Hook::applyFilters($tag, $value, ...$args)` or `apply_filters(...)` |

---

## 2. Working with Actions

### Registering an Action
```php
use FavoriteCMS\Core\Hook;

// Basic syntax: Hook::addAction(string $tag, callable $callback, int $priority = 10);
Hook::addAction('init', function () {
    // Code runs during application initialization
});

// Priority determines execution order (lower numbers run earlier, default: 10)
Hook::addAction('init', function () {
    // Runs before standard priority
}, priority: 5);
```

### Common Core Actions
- `init`: Fired after bootstrap, active plugins, and themes are loaded. Ideal for route and service registration.
- `widgets_init`: Fired when widget registry boots. Register custom widget classes here.
- `admin_menu`: Fired when administrative navigation menus are constructed. Register admin pages here.
- `post_published`: Fired when an article changes status to `published`. Receives `($postId, $post)`.
- `user_registered`: Fired when a new user completes public signup. Receives `($userId, $user)`.

---

## 3. Working with Filters

### Registering a Filter
```php
use FavoriteCMS\Core\Hook;

// Basic syntax: Hook::addFilter(string $tag, callable $callback, int $priority = 10);
Hook::addFilter('the_content', function (string $content): string {
    // Must return the modified content string
    return str_replace('CMS', '<strong>CMS</strong>', $content);
});
```

### Common Core Filters
- `the_content`: Filters raw post HTML before frontend rendering.
- `the_title`: Filters post or page titles.
- `upload_file_name`: Filters uploaded file names during media ingestion.
- `max_upload_size`: Filters maximum upload size for a user or session.

