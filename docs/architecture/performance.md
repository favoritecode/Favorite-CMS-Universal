# Performance Architecture

Favorite CMS Universal is engineered for sub-50ms server response times on standard shared hosting environments.

---

## 1. Zero-Weight Bootstrap

Unlike massive framework runtimes that boot hundreds of configuration files and service providers on every request, Favorite CMS initializes in under 5 milliseconds:
- Lightweight service container.
- On-demand singleton resolution.
- Native PHP session management.
- Static in-memory caching of settings.

---

## 2. In-Memory Static Caching

Common database reads (e.g. system settings, active plugin manifests, active theme identity) are cached in static class variables for the duration of the request:

```php
// First call executes SQL SELECT
$siteTitle = Setting::get('general', 'site_name');

// Subsequent calls in the same request return instantly from memory
$cachedTitle = Setting::get('general', 'site_name');
```

---

## 3. Static Asset Caching

When static assets are routed through PHP, `Kernel::serveStaticAsset()` emits standard HTTP cache headers:
```http
Cache-Control: public, max-age=86400
```
This instructs web browsers and edge CDNs to cache stylesheets, JavaScript files, SVGs, and WebP images locally for 24 hours, reducing repeated server hits to zero.

---

## 4. Query Efficiency & Pagination

- Post feeds (homepage, category, search) strictly enforce `LIMIT` and `OFFSET` clauses.
- Queries select only necessary columns.
- Relationship queries use indexed foreign keys (`user_id`, `post_id`, `category_id`).
