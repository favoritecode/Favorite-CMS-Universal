# Routing System

This document details the URL resolution, route registration, and dispatch mechanics of **Favorite CMS Universal v1.0.0**.

---

## 1. URL Normalization & Resolution (`UrlResolver.php`)

Favorite CMS Universal runs effortlessly in root domains (`https://example.com/`), subdomains (`https://blog.example.com/`), or nested subdirectories (`https://example.com/site/`).

`UrlResolver::basePath(Request $request)` automatically calculates the current execution path relative to the document root:
- Strips script filenames (e.g. `/index.php`) from `SCRIPT_NAME`.
- Assigns `$request->setBasePath(...)` and populates the global helper `$GLOBALS['favorite_cms_base_path']`.
- Helper function `site_path($path)` prepends the base path dynamically so all internal links, admin navigation, and asset URLs remain portable across hosting setups.

---

## 2. Core Route Registration (`Router.php`)

The `Router` class provides static methods for registering HTTP route handlers:

```php
use FavoriteCMS\Core\Router;

// Standard HTTP methods
Router::get('/api/status', [$controller, 'status']);
Router::post('/contact/submit', [$controller, 'submitContact']);
Router::put('/api/resource/{id}', [$controller, 'updateResource']);
Router::delete('/api/resource/{id}', [$controller, 'deleteResource']);

// Matching multiple methods
Router::match(['GET', 'POST'], '/webhook', [$controller, 'handleWebhook']);

// Catch-all method matching
Router::any('/fallback', [$controller, 'fallbackHandler']);
```

---

## 3. Dynamic Route Parameters

Route paths can include `{parameter}` placeholders:
```php
Router::get('/products/{category}/{slug}', function($request, $params) {
    $category = $params['category'];
    $slug     = $params['slug'];
    // Process request...
});
```

### Pattern Compilation
Internally, `Router::match()` converts `{parameter}` tokens into named regular expression capture groups:
```php
$pattern = preg_replace('#\{([a-zA-Z0-9_]+)\}#', '(?P<$1>[^/]+)', $path);
$pattern = '#^' . $pattern . '$#';
```
When an incoming request matches the pattern, extracted named groups are passed to the handler closure or controller method.

---

## 4. Administrative vs. Frontend Route Dispatch

Request dispatch occurs inside `Kernel::dispatch(Request $request)`:

### Administrative Routes (`/admin/*`)
- Guarded by session checks and authentication barriers.
- Dispatched to dedicated controllers located in `app/Http/Controllers/Admin/`:
  - `DashboardController`: `/admin`
  - `PostController`: `/admin/posts`, `/admin/posts/new`, `/admin/posts/edit/{id}`
  - `PageController`: `/admin/pages`
  - `MediaController`: `/admin/media`
  - `TaxonomyController`: `/admin/taxonomies/*`
  - `UserController`: `/admin/users`
  - `ThemeController`: `/admin/themes`
  - `CustomizeController`: `/admin/customize`
  - `WidgetController`: `/admin/widgets`
  - `MenuController`: `/admin/menus`
  - `PluginController`: `/admin/plugins`
  - `SettingController`: `/admin/settings`
  - `SeoController`: `/admin/seo`
  - `ToolController`: `/admin/tools`
  - `UpdateController`: `/admin/updates`

### Frontend Public Routes
Dispatched through `FrontendController` based on database slugs:
- Homepage: `/`
- Single Post: `/posts/{slug}`
- Static Page: `/{slug}` or `/{parent}/{slug}`
- Category Archive: `/category/{slug}`
- Tag Archive: `/tag/{slug}`
- Site Search: `/search?q={query}`
- RSS Feed: `/feed`
