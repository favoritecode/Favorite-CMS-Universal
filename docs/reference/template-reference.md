# Template Hierarchy Reference

How the rendering engine resolves every view request.

---

## 1. Route to Template Mapping Matrix

| Route | Primary Template | Secondary Fallback | Final Fallback |
|-------|------------------|--------------------|----------------|
| `/` (Homepage) | `themes/{theme}/index.php` | `themes/{theme}/templates/index.php` | `resources/views/index.php` |
| `/post/{slug}` | `themes/{theme}/single.php` | `themes/{theme}/templates/single.php` | `themes/{theme}/index.php` |
| `/{slug}` (Static Page) | `themes/{theme}/page.php` | `themes/{theme}/templates/page.php` | `themes/{theme}/single.php` |
| `/category/{slug}` | `themes/{theme}/category.php` | `themes/{theme}/templates/category.php` | `themes/{theme}/index.php` |
| `/tag/{slug}` | `themes/{theme}/tag.php` | `themes/{theme}/templates/tag.php` | `themes/{theme}/index.php` |
| `/search` | `themes/{theme}/search.php` | `themes/{theme}/templates/search.php` | `themes/{theme}/index.php` |
| 404 (Not Found) | `themes/{theme}/404.php` | `themes/{theme}/templates/404.php` | `resources/views/404.php` |
| Plugin View | `themes/{theme}/templates/{view}.php` | `plugins/{plugin}/templates/{view}.php` | `resources/views/{view}.php` |
