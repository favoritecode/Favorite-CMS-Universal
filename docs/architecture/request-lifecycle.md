# HTTP Request Lifecycle

This document describes the complete execution path of an incoming HTTP request in **Favorite CMS Universal**, from initial web server reception to final response delivery.

---

## 1. High-Level Flowchart

```
[ Incoming HTTP Request ]
           │
           ▼
[ Apache / LiteSpeed Rewrite (.htaccess) ]
           │
           ▼
[ public/index.php (Front Controller) ]
           │
           ▼
[ bootstrap.php (DI Container, Config, Error Handlers) ]
           │
           ▼
[ Kernel::handle(Request) ]
           │
    ┌──────┴──────┐
    ▼             ▼
Not Installed   Installed
    │             │
    │             ├─► Base Path & Session Start
    ▼             ├─► Boot Active Plugins (PluginManager)
[ Installer ]     ├─► Load Theme functions.php
                  ├─► Boot Widget Registry (WidgetRegistry)
                  ├─► Fire 'init' Hook
                  │
                  ▼
         [ Dispatching Routes ]
         ├── Public Auth: /register, /signup, /login
         ├── Admin: /admin/* (Auth check, Ban check, Role check)
         ├── Dynamic Plugin Routes (Router::dispatch)
         ├── Theme / Plugin Static Assets
         └── Frontend Routes (Home, Post, Page, Category, Tag, Search)
                  │
                  ▼
         [ Controller / View Engine ]
                  │
                  ▼
         [ Response::send() ]
                  │
                  ▼
[ HTTP 200 / 302 / 404 / 403 / 500 Output ]
```

---

## 2. Step-by-Step Lifecycle

### Step 1: Web Server Entrypoint (`public/index.php`)
Every request arriving at the domain is rewritten to `public/index.php` by `.htaccess` (unless the request matches a physical static asset file like CSS or an image in `public/`).

`public/index.php`:
1. Defines runtime constants (`APP_START_TIME`, `APP_ROOT`).
2. Requires `bootstrap.php` to initialize the DI container.
3. Captures the incoming request using `Request::capture()`.
4. Creates a `Kernel` instance and invokes `$kernel->handle($request)`.
5. Calls `$response->send()` to transmit HTTP headers and body content to the browser.

### Step 2: Bootstrapping (`bootstrap.php`)
1. **Autoloading**: Registers the Composer PSR-4 autoloader.
2. **Configuration**: Loads `.env` using a lightweight parser, establishing database credentials and application keys.
3. **Container**: Instantiates the `Container` and binds singletons:
   - `Database`: PDO database connection.
   - `Hook`: Global action and filter dispatcher.
   - `Router`: Dynamic route registry.
   - `WidgetRegistry`: Catalog of active widgets.
4. **Installation State**: Evaluates whether `storage/installed.lock` exists.

### Step 3: Kernel Dispatch (`Kernel::handle()`)
1. **URL & Base Path Resolution**: Calculates whether the site is running in domain root or a subdirectory (`/subfolder`), setting `$request->setBasePath()`.
2. **Session Initialization**: Starts a secure session (`fcms_*`) with `httponly=true` and `samesite=Lax`.
3. **Installation Check**:
   - If not installed, delegates immediately to `InstallerController`.
   - If installed and the user visits `/install`, redirects to `/`.
4. **Plugin Booting**: `PluginManager` boots all active plugins, allowing them to register routes, hooks, widgets, and admin menus.
5. **Theme Functions**: Loads the active theme's `functions.php` (if present) to register regions and theme options.
6. **Widget Booting**: `WidgetRegistry::ensureBooted()` triggers the `widgets_init` action hook.
7. **Init Hook**: Triggers `Hook::doAction('init', $app)`.

### Step 4: Route Matching & Authorization
The request path is evaluated in priority order:
1. **Public Authentication**:
   - `/register` / `/signup`: Serves registration form or processes new user creation.
   - `/login`: Redirects to `/admin/login`.
2. **Admin Routes (`/admin/*`)**:
   - `/admin/login`: Handles admin login credentials.
   - **Authentication Guard**: If not logged in, redirects to `/admin/login`.
   - **Ban Guard**: Verifies `!$currentUser->isBanned()`. If banned, destroys the session immediately and redirects with a ban notification.
   - **Permission Guard**: Verifies user has access to requested admin controller (e.g. non-admins are blocked from Settings, Themes, Plugins).
3. **Static Theme/Plugin Assets**: Serves CSS, JS, and font files with appropriate cache headers.
4. **Dynamic Plugin Routes**: Matches URLs registered via `add_route()`.
5. **Frontend Controller**:
   - `/` &rarr; Homepage (latest posts feed or static page).
   - `/post/{slug}` &rarr; Single post article view.
   - `/page/{slug}` &rarr; Static page.
   - `/category/{slug}` &rarr; Category archive.
   - `/tag/{slug}` &rarr; Tag archive.
   - `/?s=query` &rarr; Search results.

### Step 5: Rendering & Output
The controller extracts view data and compiles templates through `Engine` or views. The resulting HTML is wrapped in a `Response` object and sent with correct HTTP status codes.

