# Performance & Caching Optimization

Favorite CMS Universal is engineered for lightweight, high-performance execution on resource-constrained shared hosting environments.

---

## 1. Zero-Overhead Architectural Design

- **No Heavy Framework Abstractions**: Avoids bloated ORMs, thousands of reflection calls, or heavyweight service container bindings on simple requests.
- **Microsecond Request Boot**: A standard frontend page hit consumes minimal RAM (< 10 MB) and executes within milliseconds on PHP 8.1+.
- **Selective Loading**: Administrative classes, widgets, and post editor scripts are loaded strictly within `/admin` routes and are never loaded during public visitor page requests.

---

## 2. Server-Level Caching & Optimization

### Browser Asset Caching
The root `.htaccess` configures long-lived cache headers for static files (CSS, JS, WebP, JPEG, PNG, SVG, fonts):
```apache
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/webp "access plus 1 year"
    ExpiresByType image/jpeg "access plus 1 year"
    ExpiresByType image/png "access plus 1 year"
    ExpiresByType text/css "access plus 1 month"
    ExpiresByType application/javascript "access plus 1 month"
</IfModule>
```

### PHP OPcache
Ensure OPcache is enabled in your PHP configuration:
```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
```
OPcache caches precompiled PHP bytecode in shared memory, reducing file I/O overhead on every request.

---

## 3. Database Query Optimization

- **Indexed Queries**: Critical columns (`slug`, `status`, `published_at`, `author_id`, `created_at`) are indexed in MySQL.
- **Cached Settings**: Core site settings are queried in a single batch on application boot rather than issuing individual database lookups for each setting key.

