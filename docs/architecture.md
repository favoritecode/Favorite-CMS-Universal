# System Architecture

This document provides a comprehensive technical breakdown of the architecture, subsystems, and execution flow of **Favorite CMS Universal v1.0.0**.

---

## High-Level Architecture Overview

Favorite CMS Universal follows a modular, lightweight model-view-controller (MVC) architecture with strict separation between core functionality, presentation themes, and business plugins:

```
                  ┌──────────────────────────────┐
                  │      HTTP Request (Web)      │
                  └──────────────┬───────────────┘
                                 │
                                 ▼
                     ┌───────────────────────┐
                     │       index.php       │
                     └───────────┬───────────┘
                                 │
                                 ▼
                     ┌───────────────────────┐
                     │     bootstrap.php     │
                     │  (Container & Config) │
                     └───────────┬───────────┘
                                 │
                                 ▼
                     ┌───────────────────────┐
                     │       Kernel.php      │
                     │   (Session & Auth)    │
                     └───────────┬───────────┘
                                 │
       ┌─────────────────────────┴─────────────────────────┐
       ▼                                                   ▼
┌──────────────┐                                    ┌──────────────┐
│  Installer   │ (If not installed)                 │ Active Core  │
│  Controller  │                                    └──────┬───────┘
└──────────────┘                                           │
                                       ┌───────────────────┴───────────────────┐
                                       ▼                                       ▼
                             ┌───────────────────┐                   ┌───────────────────┐
                             │  Active Plugins   │                   │   Active Theme    │
                             │  (PluginManager)  │                   │  (ThemeManager)   │
                             └─────────┬─────────┘                   └─────────┬─────────┘
                                       │                                       │
                                       └───────────────────┬───────────────────┘
                                                           │
                                                           ▼
                                                ┌─────────────────────┐
                                                │   Request Router    │
                                                │      & Dispatch     │
                                                └──────────┬──────────┘
                                                           │
                                 ┌─────────────────────────┴─────────────────────────┐
                                 ▼                                                   ▼
                     ┌───────────────────────┐                           ┌───────────────────────┐
                     │   Admin Controllers   │                           │ Frontend Controllers  │
                     │ (Dashboard, Posts,    │                           │ (Posts, Pages, Feeds, │
                     │  Users, Themes, etc.) │                           │  Taxonomy Archives)   │
                     └───────────┬───────────┘                           └───────────┬───────────┘
                                 │                                                   │
                                 └─────────────────────────┬─────────────────────────┘
                                                           │
                                                           ▼
                                                ┌─────────────────────┐
                                                │  View Engine (PHP)  │
                                                │ & HTTP Response Out │
                                                └─────────────────────┘
```

---

## 1. Application Bootstrap & Initialization

### `index.php` (Public Webroot & Root Entry)
The application front controller initializes the environment and dispatches the incoming request:
```php
require_once __DIR__ . '/bootstrap.php';

$kernel = $app->make(\FavoriteCMS\Core\Kernel::class);
$response = $kernel->handle($request);
$response->send();
```

### `bootstrap.php`
- Declares `APP_VERSION` (`1.0.4` build line) and `CMS_NAME`.
- Loads PSR-4 autoloader (`vendor/autoload.php`).
- Reads `.env` configuration file if present and populates `$_ENV` and `putenv()`.
- Sets error reporting based on `APP_DEBUG`.
- Instantiates `Application` (which extends `Container`) and registers singleton service bindings for `Config`, `Database`, and `Kernel`.

---

## 2. Dependency Injection Container (`Container.php`)

The lightweight service container manages dependencies, service lifecycles, and singleton resolution:
- `bind(string $abstract, $concrete)`: Registers a transient service factory.
- `singleton(string $abstract, $concrete)`: Registers a shared instance resolved once.
- `make(string $abstract)`: Resolves an instance from the container.
- `has(string $abstract)`: Checks if a service binding exists.

---

## 3. Request Flow & Kernel Dispatch (`Kernel.php`)

`Kernel::handle(Request $request)` coordinates each step of request execution:
1. **URL Resolution:** Instantiates `UrlResolver` to detect the base path whether hosted in domain root, subdomain, or subdirectory.
2. **Session Initialization:** Starts the secure session via `InstallerSession`.
3. **Installation Check:** If `storage/installed.lock` is absent or database connection is unconfigured, routes execution to `InstallerController`.
4. **Maintenance Mode:** Checks if maintenance mode is active. If active and the request does not carry an admin bypass token, renders the HTTP 503 maintenance page.
5. **Plugin Booting:** `PluginManager::bootActivePlugins()` boots active plugins safely with failure isolation.
6. **Theme Functions:** Loads the active theme's `functions.php` file if it exists.
7. **Widget Booting:** Boots `WidgetRegistry` and fires `widgets_init` hook.
8. **Init Hook:** Dispatches the core `init` action hook (`Hook::doAction('init', $app)`).
9. **Payload Inspection:** Validates `post_max_size` boundaries to reject oversized uploads cleanly before PHP truncates input data.
10. **Dispatch:** Dispatches the request to the matching controller endpoint.

---

## 4. Routing System (`Router.php` & Kernel Dispatch)

- Static routes registered via `Router::get()`, `Router::post()`, `Router::put()`, `Router::delete()`, and `Router::match()`.
- Supports dynamic named URL parameters via regex conversion (e.g. `/posts/{slug}`).
- Administrative routes (`/admin/*`) are routed through dedicated admin controllers under `app/Http/Controllers/Admin/`.
- Frontend content routes (home, single post, page, category, tag, search, RSS feed) are dispatched through `FrontendController`.

---

## 5. Database Layer & Migrations

### `Database.php`
- Wrapper around PDO with automatic reconnection and query parameterization.
- Methods: `query()`, `select()`, `selectOne()`, `insert()`, `update()`, `delete()`, `beginTransaction()`, `commit()`, `rollback()`.
- **Dynamic Table Prefixing:** Automatically rewrites query placeholders using the configured `DB_PREFIX` (default `fc_`). Allows plugins to register prefixable table names via `registerPrefixableTables()`.

### `Migrator.php`
- Executes numbered migrations (`001` through `017`) from `database/migrations/`.
- Tracks executed migrations in the `cms_migrations` table to guarantee idempotent execution.

---

## 6. Models & Data Access (`app/Models/`)

The model layer extends `BaseModel`, providing an Active Record pattern over database tables:
- `Post`: Blog articles, statuses, revisions, taxonomy associations.
- `Page`: Hierarchical pages, parent/child relationships.
- `User`: Account data, password verification, `auth_version` session tracking.
- `Role` & `Permission`: Role definitions and capability assignments.
- `Media`: Uploaded asset records, MIME types, file sizes.
- `Comment`: Visitor comments, parent comment threading, moderation status.
- `Setting`: Key-value configuration pairs categorized by groups (`general`, `theme`, `plugins`, `media`, `seo`).

---

## 7. Rendering Engine & Views (`Engine.php`)

- Native PHP templating for maximum speed and simplicity (no template compilation overhead).
- System views reside in `resources/views/`.
- Theme templates reside in `themes/<active-theme>/`.
- Template inheritance and layout wrapping supported via `Engine::render()` and `Engine::layout()`.
