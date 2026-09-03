# Codebase Folder Structure

This document outlines the directory structure of **Favorite CMS Universal**, describing the purpose, responsibilities, and visibility of each directory.

---

## High-Level Tree

```
Favorite-CMS-Universal/
├── app/                  # Application Core classes (PSR-4 FavoriteCMS\)
│   ├── Core/             # Foundational runtime services
│   ├── Http/             # Request, Response, and Controllers
│   ├── Installer/        # Multi-step web installation subsystem
│   ├── Models/           # Database models and business logic
│   ├── Plugins/          # Plugin lifecycle management
│   ├── Rendering/        # View engine and template resolver
│   ├── Services/         # Dedicated domain services
│   └── Widgets/          # Core Widget engine and layout manager
├── bootstrap.php         # Dependency injection container & bootstrap
├── config/               # Configuration files (app, database, permissions)
├── database/             # Schema migrations (001 - 013)
│   └── migrations/       # Linear, numbered migration files
├── docs/                 # A-Z technical and user documentation
├── plugins/              # Installed and active plugins
│   └── hello-favorite/   # Official reference plugin
├── public/               # Public webroot (accessible via HTTP)
│   ├── assets/           # Built-in CSS and JavaScript
│   ├── uploads/          # User-uploaded media by YYYY/MM
│   ├── .htaccess         # URL rewrite rules for Apache
│   └── index.php         # Front Controller entrypoint
├── resources/            # Server-rendered view templates
│   └── views/            # Admin panel, auth, and installer views
├── storage/              # Private runtime storage
│   ├── cache/            # Temporary template and data cache
│   ├── logs/             # Daily rotation application logs
│   ├── sessions/         # Encrypted server-side session files
│   └── installed.lock    # Cryptographic installation lockfile
├── tests/                # Automated PHPUnit test suites
│   ├── Integration/      # Full-stack integration tests
│   └── Unit/             # Isolated unit tests
├── themes/               # Presentation themes
│   └── default/          # Modern, responsive default theme
├── composer.json         # Autoloading definitions & dev dependencies
└── README.md             # Project overview and quick start
```

---

## Detailed Directory Breakdown

### `app/Core/`
Foundational services that constitute the minimal runtime framework:
- `Application.php`: Application container and service locator.
- `Container.php`: Dependency injection container resolving constructors and singletons.
- `Database.php`: Robust PDO database wrapper supporting prepared statements, transactions, and prefixing.
- `Kernel.php`: Master HTTP request dispatcher and middleware pipeline.
- `Request.php`: Immutable HTTP request representation (GET, POST, headers, cookies, server).
- `Response.php`: HTTP response builder (HTML, JSON, Redirects, headers, status codes).
- `Hook.php`: WordPress-style action and filter hook system.
- `Router.php`: Dynamic route registry for custom plugin and core endpoints.
- `helpers.php`: Global helper functions (`config()`, `env()`, `__autoload()`).

### `app/Http/`
HTTP request handlers divided by context:
- `Controllers/Admin/`: Administrative controllers (`DashboardController`, `PostController`, `UserController`, `MediaController`, `SettingController`, `WidgetController`, `CustomizeController`, `ThemeController`, `PluginController`).
- `Controllers/FrontendController.php`: Public content rendering (home, single post, page, category, tag, search).

### `app/Models/`
Model layer providing database access and business logic:
- `User.php`: User authentication, role verification, suspension/ban checks, and post counts.
- `Role.php` & `Permission.php`: Role-based access control mapping.
- `Post.php`: Content management, slug generation, statuses (`draft`, `pending`, `published`, `rejected`), approve/reject workflows.
- `Page.php`: Hierarchical static pages.
- `Media.php`: Upload metadata and file URLs.
- `Setting.php`: Cached key-value site configurations.
- `Taxonomy.php`: Categories and tags.
- `Comment.php`: Visitor feedback and comment threads.

### `app/Widgets/`
The Core widget engine:
- `WidgetInterface.php`: Contract defining widget metadata, forms, and rendering methods.
- `AbstractWidget.php`: Base class providing default option parsing and sanitization.
- `WidgetRegistry.php`: Singleton catalog of all active core and plugin widgets.
- `WidgetInstanceManager.php`: Persistence manager saving widget configurations in database settings.
- `ThemeLayoutService.php`: Theme customizer service handling regions, sidebar layouts, colors, and section reordering.

### `public/`
The only directory that should be directly exposed to the public internet:
- `index.php`: The front controller that initializes `bootstrap.php` and executes `Kernel::handle()`.
- `uploads/`: Media files organized as `/uploads/YYYY/MM/filename.ext`. Protected by `.htaccess` preventing direct PHP execution.

