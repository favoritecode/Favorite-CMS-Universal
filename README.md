# Favorite CMS Universal

<p align="center">
  <strong>"One CMS. Any Website."</strong><br>
  A lightweight, modular, standalone PHP CMS engineered for high speed, dependable reliability, and straightforward deployment on shared hosting and local development environments.
</p>

<p align="center">
  <a href="#-key-features">Features</a> &bull;
  <a href="#-core-philosophy">Core Philosophy</a> &bull;
  <a href="#-system-requirements">Requirements</a> &bull;
  <a href="#-quick-installation-overview">Installation</a> &bull;
  <a href="#-documentation-index">Documentation</a> &bull;
  <a href="#-automated-testing">Testing</a> &bull;
  <a href="#-license">License</a>
</p>

---

## 🌟 What is Favorite CMS Universal?

**Favorite CMS Universal** is a standalone, lightweight PHP Content Management System designed to run effortlessly on budget shared hosting (cPanel, DirectAdmin, LAMP) as well as local development environments (XAMPP, WampServer, Valet, Docker).

Unlike modern frameworks that require Node.js runtimes, continuous terminal daemons, Composer on production, or specialized VPS servers, Favorite CMS Universal is **100% PHP and MySQL/MariaDB**. Everything needed to run a production website is included out of the box—no command line or Git access required for end users.

---

## 🏛️ Core Philosophy

Favorite CMS Universal adheres to a strict four-pillar architectural separation of concerns:

```
┌─────────────────────────────────────────────────────────────┐
│                    Favorite CMS Universal                   │
├──────────────────────────────┬──────────────────────────────┤
│ 1. CORE                      │ Necessary universal CMS      │
│    (Framework, Security,     │ functionality common to all  │
│     Users, Posts, Media)     │ web projects.                │
├──────────────────────────────┼──────────────────────────────┤
│ 2. PLUGINS                   │ Specialized business and     │
│    (Ecommerce, Streaming,    │ domain logic added only when │
│     Bookings, Membership)    │ needed.                      │
├──────────────────────────────┼──────────────────────────────┤
│ 3. THEMES                    │ Visual presentation, design, │
│    (Templates, CSS, JS,      │ responsive styling, and      │
│     Typography, Regions)     │ markup semantics.            │
├──────────────────────────────┼──────────────────────────────┤
│ 4. WIDGETS & LAYOUT          │ Modular site composition and │
│    (Customizer, Sidebars,    │ visual placement of content  │
│     Footers, Reordering)     │ blocks across regions.       │
└──────────────────────────────┴──────────────────────────────┘
```

### Business Features Belong in Plugins
Core remains clean, lean, and universally useful. Specialized business systems—including:
- **Ecommerce & Digital Stores**
- **Video & Audio Streaming Systems**
- **Ticket & Hotel Booking Engines**
- **Paid Memberships & Subscriptions**
- **Third-Party Payment Gateways**
- **Custom Forum & Social Networks**

are deliberately designed to be implemented as **Plugins** rather than bloated into the Core codebase. Core provides robust public APIs, hooks, filters, custom routes, admin panels, and database capabilities to empower any plugin.

---

## 🚀 Key Features

### Core Features
- **Zero-Dependency Runtime**: No Node.js, Python, Ruby, or external worker processes required in production.
- **Fast Request Dispatch**: Minimal execution overhead with optimized PSR-4 autoloading and lightweight PDO wrapper.
- **Resilient Web Installer**: Automated browser setup with environment checks, URL auto-detection, optional automatic database creation, and manual configuration fallback.
- **Persistent Installation State**: Lockfile and database checks work together to maintain installation state across server restarts.
- **Clean Subdirectory & Subdomain Support**: Automatic base path normalization whether installed in domain root, a subdomain (`blog.example.com`), or a nested subdirectory (`example.com/subfolder/`).

### User & Account Features
- **Public User Signup**: Optional visitor registration (`/register`, `/signup`) with CSRF protection, password strength validation, and duplicate prevention.
- **Role-Based Access Control**: Standard roles (`Super Admin`, `Admin`, `Editor`, `Moderator`, `Author`, `Subscriber`) with granular permissions.
- **Post Moderation Queue**: Posts submitted by standard users are held in `pending` review until approved by an Admin or Moderator.
- **Moderator Direct Publishing**: Moderators bypass review queues with instant direct publishing capabilities.
- **Account Status Management**: Instant user suspension (`suspended`) or permanent bans (`banned`) with immediate active-session invalidation while preserving historical content.

### Editor & Media Features
- **Professional Dual-Mode Post Editor**:
  - **Visual Mode**: WYSIWYG editor with rich text formatting, headings (H1–H6), blockquotes, lists, links, images, code blocks, tables, and paste sanitization.
  - **Code Mode**: Direct HTML editing with monospace typography, line numbering, Tab indentation handling, and quick formatting tags.
  - **Bidirectional Sync**: Switch seamlessly between Visual and Code mode with zero content loss.
  - **Live Preview**: Inspect full draft rendering before publishing.
- **Role-Aware Large Media System**:
  - **Admin configured limit**: Up to 7 GB (7168 MB) for large video/audio/archive files.
  - **Moderator configured limit**: Up to 500 MB.
  - **Normal User configured limit**: Up to 200 MB.
  - **Server Limit Awareness**: Respects underlying server boundaries (`upload_max_filesize`, `post_max_size`, `memory_limit`) and informs users of effective capacities.
- **High-Capacity Storage**: Content stored in MySQL `LONGTEXT` fields supporting long-form posts, courses, transcripts, and rich media articles.

### Theme & Widget Features
- **Visual Theme Customizer**: Real-time layout controls, sidebar orientation (Right Sidebar, Left Sidebar, Full Width), brand colors, logo, and homepage section reordering.
- **Core Widget Engine**: 10 built-in widgets (Search, Recent Posts, Categories, Tags, Navigation Menu, Pages, Custom HTML, Image, Featured Post, Recent Comments).
- **Multi-Instance Widgets**: Add, reorder, configure, or duplicate multiple instances of any widget across theme-defined regions (Sidebar, Header, Footer columns).
- **1-Click Theme Defaults**: Reset widget configurations to theme-recommended defaults at any time.

### Security Features
- **Prepared Statements**: All database operations execute through parameterized PDO queries to protect against SQL injection.
- **Strict Content Sanitization**: Strips dangerous scripts, events, `javascript:` protocols, and unauthorized iframes.
- **Multi-Extension & Executable Shield**: Rejects PHP, executable, script, and hidden double extensions (`.php.jpg`, `.phtml`, etc.) in media uploads.
- **CSRF Token Protection**: Cryptographic token generation and strict timing-attack resistant `hash_equals()` validation on all administrative and authentication forms.
- **Secure Password Hashing**: Utilizes PHP's native `password_hash()` with `PASSWORD_DEFAULT` (bcrypt/argon2).

### Developer Features
- **Extensible Hook & Filter System**: Priority-based action hooks (`add_action`, `do_action`) and value filters (`add_filter`, `apply_filters`).
- **Dynamic Plugin Subsystem**: Self-contained plugin packaging (`plugin.json`), custom URL routing, admin menu registration, and settings schema.
- **Isolated Plugin Booting**: Fault-tolerant plugin execution helps prevent third-party exceptions from taking down the core application.
- **Complete Automated Test Suite**: 100+ unit and integration tests covering installer, database, editor, upload capabilities, widgets, moderation, and user workflows.

---

## 💻 System Requirements

### Shared Hosting & Production
| Component | Minimum Requirement | Recommended |
|---|---|---|
| **Operating System** | Linux / FreeBSD / Windows Server | Linux (AlmaLinux, Ubuntu, Debian, CentOS) |
| **Web Server** | Apache 2.4+ (`mod_rewrite` enabled) | Apache 2.4+ or LiteSpeed with `.htaccess` support |
| **PHP Version** | PHP 8.1.0 or higher | PHP 8.2 or 8.3 |
| **PHP Extensions** | `pdo`, `pdo_mysql`, `mbstring`, `json`, `session`, `fileinfo`, `gd` | Above plus `curl`, `zip`, `xml` |
| **Database** | MySQL 5.7+ or MariaDB 10.3+ | MySQL 8.0+ or MariaDB 10.6+ (InnoDB support) |
| **Disk Space** | 25 MB for core files | 500 MB+ (depends on media uploads) |
| **Node.js / Python** | **Not Required** | **Not Required** |
| **Composer / Git** | **Not Required** (pre-bundled vendor) | **Not Required** |

### Local Development
- **PHP**: 8.1+ CLI with PDO MySQL extension.
- **Composer**: 2.x (for running test suites and developer linting).
- **Environment**: XAMPP, WampServer, Laragon, or PHP built-in server (`php -S localhost:8000`).

---

## ⚡ Quick Installation Overview

Installing Favorite CMS Universal takes less than 2 minutes:

```
Step 1: Upload
   Download Favorite-CMS-Universal.zip and upload it to your webroot (e.g. public_html/ or htdocs/).

Step 2: Extract
   Extract the archive so that index.php, app/, config/, etc., reside in your target directory.

Step 3: Open in Browser
   Navigate to your domain: http://example.com/ (or http://localhost/Favorite-CMS/).

Step 4: Web Wizard
   The installer automatically checks PHP extensions, directory permissions, and detects your URL.

Step 5: Database & Admin
   Enter your MySQL credentials and define your initial administrator account.

Step 6: Done!
   The installation lock is created, and your website is immediately ready to use.
```

For complete platform-specific guides, consult:
- [Local XAMPP Installation Guide](docs/getting-started/installation-local.md)
- [Shared Hosting Installation Guide](docs/getting-started/installation-shared-hosting.md)
- [Subdomain Installation Guide](docs/getting-started/installation-subdomain.md)
- [Subdirectory Installation Guide](docs/getting-started/installation-subdirectory.md)

---

## 📂 Project Directory Structure

```
├── app/                  # Application Core classes (PSR-4 FavoriteCMS\)
│   ├── Core/             # Container, Kernel, Database, Router, Hook, Logger
│   ├── Http/             # Request, Response, Controllers (Admin & Frontend)
│   ├── Models/           # Models (Post, Page, User, Role, Setting, Media, etc.)
│   ├── Plugins/          # PluginManager lifecycle engine
│   ├── Services/         # UploadCapabilityService, ContentSanitizer, MediaService
│   ├── Widgets/          # Core WidgetRegistry, AbstractWidget, ThemeLayoutService
│   └── Rendering/        # View Engine and template hierarchy
├── bootstrap.php         # Application initialization & singleton container binding
├── config/               # Configuration files (app, database, permissions)
├── database/             # Schema migrations (001 - 013)
├── docs/                 # Complete A-Z technical and user documentation
├── plugins/              # Active and installed plugins (e.g., hello-favorite)
├── public/               # Public webroot (index.php, uploads, static assets)
├── resources/views/      # System views, installer, and admin panel templates
├── storage/              # Cache, logs, sessions, and installed.lock
├── tests/                # Automated PHPUnit integration and unit test suite
└── themes/               # Visual presentation themes (e.g., default)
```

---

## 📖 Documentation Index

The complete documentation library is organized inside the [`docs/`](docs/README.md) directory:

| Section | Topic | Documentation Links |
|---|---|---|
| **Getting Started** | Setup & Requirements | [Requirements](docs/getting-started/requirements.md) &bull; [Local XAMPP](docs/getting-started/installation-local.md) &bull; [Shared Hosting](docs/getting-started/installation-shared-hosting.md) &bull; [Subdomain](docs/getting-started/installation-subdomain.md) &bull; [Subdirectory](docs/getting-started/installation-subdirectory.md) |
| **User Guide** | Managing the Site | [Dashboard](docs/user-guide/dashboard.md) &bull; [Posts](docs/user-guide/posts.md) &bull; [Post Editor](docs/user-guide/post-editor.md) &bull; [Media Library](docs/user-guide/media.md) &bull; [Users & Roles](docs/user-guide/users-and-roles.md) &bull; [Moderation](docs/user-guide/moderation.md) &bull; [Widgets & Layout](docs/user-guide/widgets.md) &bull; [Themes](docs/user-guide/themes.md) &bull; [Settings](docs/user-guide/settings.md) |
| **Architecture** | System Design | [Overview](docs/architecture/overview.md) &bull; [Folder Structure](docs/architecture/folder-structure.md) &bull; [Core Engine](docs/architecture/core.md) &bull; [Database & Schema](docs/architecture/database.md) &bull; [Request Lifecycle](docs/architecture/request-lifecycle.md) &bull; [Security Model](docs/architecture/security-model.md) |
| **Plugin Development** | Extending Core | [Plugin Overview](docs/plugins/overview.md) &bull; [Development Guide](docs/plugins/plugin-development.md) &bull; [Manifest Spec](docs/plugins/plugin-manifest.md) &bull; [Hooks & Filters](docs/plugins/hooks-events-filters.md) &bull; [Routes & Admin Panels](docs/plugins/routes-and-admin-panels.md) &bull; [Best Practices](docs/plugins/plugin-best-practices.md) |
| **Theme Development** | Presentation & Design | [Theme Overview](docs/themes/overview.md) &bull; [Theme Development](docs/themes/theme-development.md) &bull; [Theme Manifest](docs/themes/theme-manifest.md) &bull; [Widgets & Layout](docs/themes/widgets-and-layout.md) &bull; [Template Overrides](docs/themes/template-overrides.md) |
| **Security** | Hardening & Defenses | [Security Overview](docs/security/overview.md) &bull; [Authentication](docs/security/authentication.md) &bull; [Authorization](docs/security/authorization.md) &bull; [Upload Hardening](docs/security/uploads.md) &bull; [Sanitization](docs/security/content-sanitization.md) &bull; [Deployment Security](docs/security/deployment-security.md) |
| **Operations** | Maintenance & Ops | [Backup & Restore](docs/operations/backup-restore.md) &bull; [Site Migration](docs/operations/migration.md) &bull; [Upgrades](docs/operations/upgrade.md) &bull; [Troubleshooting](docs/operations/troubleshooting.md) &bull; [Performance](docs/operations/performance.md) |
| **Development** | Contributing & Tests | [Dev Setup](docs/development/development-setup.md) &bull; [Automated Testing](docs/development/testing.md) &bull; [Contributing](docs/development/contributing.md) &bull; [Release Process](docs/development/release-process.md) |

---

## 🧪 Automated Testing

Favorite CMS Universal includes a comprehensive PHPUnit test suite validating critical paths including database migrations, installer persistence, upload limits, sanitization, user moderation, and widget management.

To execute the test suite:

```bash
composer test
```
*(or run `php vendor/bin/phpunit`)*

Expected Result:
```
OK (109 tests, 511 assertions)
```

---

## 📦 Distribution Packages

Pre-built production ZIP packages are released via the official [GitHub Releases](https://github.com/favoritecode/Favorite-CMS-Universal/releases) page. Production packages contain all application classes, vendor libraries, default themes, and starter plugins, ready for immediate unzipping and browser setup.

- **Current Release Line**: `1.0.0-beta`
- **Release Package**: `Favorite-CMS-Universal.zip`

---

## 📄 License

Favorite CMS Universal is open-source software licensed under the [MIT License](LICENSE).
You are free to use, modify, and distribute it for personal and commercial projects.
