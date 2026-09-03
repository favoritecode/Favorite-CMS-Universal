# Routing Subsystem

Favorite CMS Universal employs a multi-stage request router configured in `app/Core/Kernel.php` combined with dynamic route registration through `app/Core/Router.php`.

---

## 1. Route Dispatch Priority

When an HTTP request enters `Kernel::dispatch($request)`, it is evaluated in the following order:

```
[ Incoming Request URL ]
           │
           ├─► 1. Admin Routes: /admin/*
           │      └── Dispatches to Dashboard, Posts, Pages, Media, Users, or /admin/page/{slug}
           │
           ├─► 2. Static Asset Routes: /themes/* or /plugins/*
           │      └── Kernel::serveStaticAsset() serves CSS, JS, Images with MIME and Caching
           │
           ├─► 3. Dynamic Plugin Routes: Router::dispatch()
           │      └── Matches any custom route registered by active plugins
           │
           └─► 4. Frontend Controller Routes:
                  ├── '/'                   ──► FrontendController::home()
                  ├── '/post/{slug}'        ──► FrontendController::post()
                  ├── '/category/{slug}'    ──► FrontendController::category()
                  ├── '/tag/{slug}'         ──► FrontendController::tag()
                  ├── '/search'             ──► FrontendController::search()
                  ├── '/comment/submit'     ──► FrontendController::submitComment()
                  ├── '/sitemap.xml'        ──► FrontendController::sitemap()
                  ├── '/robots.txt'         ──► FrontendController::robots()
                  ├── '/{slug}' (Page)      ──► FrontendController::page()
                  └── [ Fallback ]          ──► Kernel::notFound() (404 Template)
```

---

## 2. Dynamic Route Registration for Plugins

Plugins register public frontend endpoints without modifying Core files:

```php
// Exact path
add_route('GET', '/contact-us', function(Request $request) {
    return Response::make('Contact form content', 200);
});

// Path with parameters
add_route('GET', '/portfolio/{client}', function(Request $request, string $client) {
    return Response::json(['client' => $client]);
});

// Multiple HTTP methods
add_route(['GET', 'POST'], '/newsletter/subscribe', [NewsletterController::class, 'handle']);
```

---

## 3. Static Asset Routing

To ensure static assets (CSS, JS, SVG, WebP) in themes and plugins are delivered reliably even on servers with strict document-root rewrite configurations:

1. Assets are physically present in `themes/` and `plugins/`.
2. Web servers check for direct file delivery first.
3. If an asset request is routed through the PHP front controller, `Kernel::serveStaticAsset()` verifies path safety (rejecting directory traversal `..`), resolves the correct MIME type, adds `Cache-Control: public, max-age=86400`, and streams the file.
