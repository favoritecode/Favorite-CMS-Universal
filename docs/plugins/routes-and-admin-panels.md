# Dynamic Routes & Admin Panels Guide

Plugins in Favorite CMS Universal can register public frontend URLs as well as administrative dashboard screens using clean, high-level helper functions.

---

## 1. Registering Frontend Routes (`add_route`)

Plugins register custom URLs inside the `init` action hook using `add_route()`:

```php
use FavoriteCMS\Core\Hook;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;

Hook::addAction('init', function () {
    // 1. Static Route
    add_route('/contact-us', function (Request $request): Response {
        if ($request->method() === 'POST') {
            // Process form submission
            return Response::redirect('/contact-us?success=1');
        }
        return Response::make("<h1>Contact Us</h1>", 200);
    });

    // 2. Dynamic Parameter Route (Regex matching)
    add_route('#^/products/([a-zA-Z0-9_\-]+)$#', function (Request $request, string $slug): Response {
        return Response::make("<h1>Viewing Product: {$slug}</h1>", 200);
    });

    // 3. JSON API Route
    add_route('/api/v1/status', function (Request $request): Response {
        return Response::json([
            'status' => 'ok',
            'time' => time(),
        ], 200);
    });
});
```

---

## 2. Registering Administrative Menus (`add_admin_menu`)

To provide an administration page within `/admin`, hook into `admin_menu` and use `add_admin_menu()`:

```php
use FavoriteCMS\Core\Hook;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;

Hook::addAction('admin_menu', function () {
    add_admin_menu(
        title: 'Ecommerce Store',         // Menu display title
        slug: 'ecommerce-store',          // Admin URL: /admin/page/ecommerce-store
        icon: '🛍️',                       // Sidebar emoji or icon
        capability: 'manage_options',     // Required permission
        handler: function (Request $request): Response {
            // Rendered inside the standard Admin layout automatically
            ob_start();
            ?>
            <div class="wrap">
                <h1 class="page-title">Store Management</h1>
                <p>Welcome to your plugin administration area.</p>
            </div>
            <?php
            return Response::make((string)ob_get_clean(), 200);
        }
    );
});
```

### Security Considerations for Admin Menus
- **Automatic Permission Checks**: The admin kernel verifies that `current_user_can($capability)` passes before executing your handler. If an unauthorized user attempts to open the URL, HTTP 403 Forbidden is returned automatically.
- **Admin Wrapper**: Content returned by your handler is automatically wrapped in the standard Favorite CMS admin navigation bar, header, and styling.

