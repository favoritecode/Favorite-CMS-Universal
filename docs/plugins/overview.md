# Plugin Subsystem Overview

Favorite CMS Universal features a lightweight, first-class Plugin Subsystem allowing developers to add specialized business functionality without modifying a single line of Core code.

---

## 1. Architectural Role of Plugins

In Favorite CMS Universal, **Core** provides universal publishing foundations (posts, pages, media, users, roles, settings, widgets).

All specialized, domain-specific features belong in **Plugins**:
- **Ecommerce & Digital Stores**
- **Video & Audio Streaming Portals**
- **Ticket & Event Booking Engines**
- **Paid Membership & Paywall Subscriptions**
- **Custom Payment Gateways (Stripe, PayPal, bKash)**
- **Community Forums & User Profiles**

By keeping these systems in plugins, Core remains fast, lean, and universally usable for any website.

---

## 2. Plugin Lifecycle

Every plugin proceeds through an isolated, stateful lifecycle managed by `FavoriteCMS\Plugins\PluginManager`:

```
1. Discover
   └── Scans the plugins/ directory for folders containing a valid plugin.json manifest.

2. Validate
   └── Confirms manifest syntax, required keys (name, version, main), and file existence.

3. Compatibility & Dependency Check
   └── Validates core version compatibility (e.g. ^1.0.0) and required dependencies.

4. Register
   └── Adds the plugin to the in-memory catalog with state (active or inactive).

5. Boot (Runtime)
   └── For each active plugin, includes its entrypoint (e.g. plugin.php) inside a 
       try-catch block to prevent a third-party error from crashing the main site.

6. Deactivate / Uninstall
   └── Deactivation halts booting. Uninstallation optionally removes plugin data tables.
```

---

## 3. Public Extension Mechanisms

Plugins extend Favorite CMS Universal using official public APIs:
- **Action Hooks (`add_action`)**: Execute code at specific lifecycle moments (`init`, `admin_menu`, `widgets_init`, `after_post_published`).
- **Filter Hooks (`add_filter`)**: Modify content or parameters in-flight (`the_content`, `the_title`, `upload_file_name`).
- **Dynamic Routes (`add_route`)**: Register custom frontend URLs (`/store`, `/tickets/{id}`) handled by plugin closures or controllers.
- **Admin Menus (`add_admin_menu`)**: Add dedicated sidebar items and custom administrative screens.
- **Custom Widgets**: Register custom widget classes extending `AbstractWidget`.
- **Database Tables**: Create and manage custom schema tables with standard prefixing (`$db->prefix()`).
- **Theme Overrides**: Provide fallback view templates that themes can optionally override.

