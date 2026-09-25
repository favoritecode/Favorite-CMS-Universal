# Favorite CMS Universal

<p align="center">
  <strong>"One CMS. Any Website."</strong><br>
  A lightweight, modular, standalone PHP Content Management System engineered for high speed, dependable reliability, and straightforward deployment on shared hosting and modern web servers.
</p>

<p align="center">
  <a href="#-current-version">Version</a> &bull;
  <a href="#-key-features">Features</a> &bull;
  <a href="#-system-requirements">Requirements</a> &bull;
  <a href="#-quick-installation">Installation</a> &bull;
  <a href="#-core-modules-overview">Modules</a> &bull;
  <a href="#-extensibility-themes--plugins">Extensibility</a> &bull;
  <a href="#-security-architecture">Security</a> &bull;
  <a href="#-documentation-portal">Documentation</a> &bull;
  <a href="#-license">License</a>
</p>

---

## 📌 Current Version

- **Current Version:** `v1.0.0` (Initial Official Core Release)
- **Status:** Production Stable
- **Official Repository:** [https://github.com/favoritecode/Favorite-CMS-Universal](https://github.com/favoritecode/Favorite-CMS-Universal)
- **Official Package:** `Favorite-CMS-Universal-v1.0.0.zip`

---

## 🛡️ Repository Scope & Governance

> [!IMPORTANT]
> **Favorite-CMS-Universal is the official Favorite CMS CORE master repository.**
>
> This repository contains the complete CMS core development source, architecture specifications, and official core documentation.
>
> **Standalone plugins, themes, external products, and asset releases are maintained elsewhere:**
> - Non-core releases, standalone plugins, themes, and asset bundles belong in:  
>   👉 **[Favorite-CMS-Assets](https://github.com/favoritecode/Favorite-CMS-Assets)**
> - Independent standalone products (such as *Favorite Multimedia*, *Favorite Web Tools*, *Favorite Shop*) are maintained in their respective dedicated repositories.
>
> For full repository policies, see [REPOSITORY-RULES.md](REPOSITORY-RULES.md) and [AGENTS.md](AGENTS.md).

---

## 🌟 What is Favorite CMS Universal?

**Favorite CMS Universal** is a standalone, lightweight PHP Content Management System built from the ground up to eliminate dependency bloat, terminal requirements, and complex hosting overhead.

Unlike modern frameworks that require Node.js background workers, continuous terminal daemons, production Composer installation, or dedicated VPS environments, Favorite CMS Universal is **100% PHP and MySQL/MariaDB**. Everything needed to run a production website is included out of the box—no command-line access or Git tooling is required for end users.

### Architectural Separation of Concerns
1. **Core:** Fundamental CMS capabilities (database access, routing, security, user authentication, posts, pages, categories, tags, menus, media, widgets, settings, updates).
2. **Themes:** Visual presentation, layout orientation, typography, templates, and responsive styling (`themes/`).
3. **Plugins:** Modular business logic, ecommerce, multimedia streaming, and custom domain features installed independently (`plugins/`).
4. **Widgets & Layout:** Flexible region-based layout composition across sidebars, headers, and footer columns.

---

## 🚀 Key Features

- **Zero-Dependency Production Runtime:** Runs seamlessly on shared hosting (cPanel, DirectAdmin, Apache, LiteSpeed) and local development stacks (XAMPP, WAMP, Laragon, Docker).
- **Intuitive Web Installer:** 5-step setup wizard with automated PHP extension checks, directory permission verification, and Recommended Database connection mode.
- **Dual-Mode Post & Page Editor:** Seamless switching between WYSIWYG Visual editing and raw HTML Code editing with line numbering, bidirectional synchronization, and live previews.
- **Trusted Code Preservation & HTMLPurifier:** Untrusted content submissions are sanitized with bundled HTMLPurifier 4.19.0 while preserving custom HTML/CSS/JavaScript blocks for authorized administrators.
- **Granular 6-Role Permission Matrix:** Super Admin, Admin, Editor, Moderator, Author, and Subscriber roles with distinct capabilities.
- **Role-Aware Media Library:** Configurable file upload boundaries (Admin up to 7 GB, Moderator up to 500 MB, normal user up to 200 MB), bounded by server runtime capacities.
- **Built-in Theme & Widget Engine:** 10 core widgets, multi-region widget placement, and visual customizer controls (sidebar orientation, color schemes, brand assets).
- **Native Full-Site Backup & Restore:** Generate complete site backups (.zip archives containing SQL dump, uploads, themes, plugins) with checksum verification and 1-click installer restore.
- **In-App Core Update Engine:** Automated release update checks, maintenance mode protection, backup snapshots, and safe rollback support.
- **Clean Subdirectory & Subdomain Support:** Automatic base path normalization whether installed in domain root, subdomain, or nested subdirectories.

---

## 💻 System Requirements

### Shared Hosting & Production Servers
| Component | Minimum Requirement | Recommended |
|---|---|---|
| **PHP Version** | PHP 8.1.0 or higher | PHP 8.2 or 8.3 |
| **PHP Extensions** | `pdo`, `pdo_mysql`, `mbstring`, `json`, `session`, `fileinfo`, `gd` | Above plus `curl`, `zip`, `xml` |
| **Database** | MySQL 5.7+ or MariaDB 10.3+ | MySQL 8.0+ or MariaDB 10.6+ (InnoDB support) |
| **Web Server** | Apache 2.4+ (`mod_rewrite` enabled) or LiteSpeed | Apache 2.4+ / LiteSpeed / Nginx |
| **Node.js / Python** | **Not Required** | **Not Required** |
| **Composer on Server** | **Not Required** (pre-bundled vendor) | **Not Required** |

---

## ⚡ Quick Installation

Installing Favorite CMS Universal takes less than two minutes:

1. **Download:** Get `Favorite-CMS-Universal-v1.0.0.zip` from the [Releases](https://github.com/favoritecode/Favorite-CMS-Universal/releases) page.
2. **Upload & Extract:** Upload the ZIP archive to your web root (`public_html/` or `htdocs/`) or any subdirectory, then extract it.
3. **Open in Browser:** Navigate to your domain (e.g. `http://example.com/` or `http://localhost/site/`). If the CMS is not yet installed, it will automatically redirect to the web setup wizard (`/install`).
4. **Environment Check:** The wizard validates PHP version, extensions, and directory write permissions.
5. **Database Setup:** Select **Recommended Database** (auto-configured for `localhost:3306` with prefix `fc_`) and provide your database name, username, and password.
6. **Administrator Account:** Define your site title, admin username, email, and password.
7. **Complete:** The installer locks further installation attempts (`storage/installed.lock`) and directs you to the admin login page (`/admin/login`).

For comprehensive installation guides, see:
- [Installation Guide](docs/installation.md)
- [System Requirements](docs/requirements.md)
- [Quick Start Guide](docs/quick-start.md)

---

## 🧭 Core Modules Overview

### 1. Posts & Pages
- **Posts:** Categorized and tagged blog articles supporting statuses: `draft`, `pending` (moderation review), `scheduled`, and `published`.
- **Pages:** Static hierarchical site pages with custom slugs, parent page selection, and page template assignment.
- Detailed Guide: [Posts & Pages](docs/posts-and-pages.md)

### 2. Media Management
- Centralized media repository for images, videos, audio, and documents.
- Automatic image thumbnail creation and metadata extraction.
- Strict upload hardening with MIME inspection and extension filtering.
- Detailed Guide: [Media Management](docs/media.md)

### 3. Categories & Tags
- Multi-level hierarchical categories with slug management.
- Free-form keyword tags with post assignment and archive filtering.

### 4. Menus & Navigation
- Drag-and-drop menu manager supporting custom links, internal pages, post links, and category archives.
- Multiple menu theme locations (e.g. Primary Header, Footer Navigation).
- Detailed Guide: [Menus](docs/menus.md)

### 5. Comments & Moderation
- Visitor commenting system with spam protection, author approval queues, and moderator review workflows.

### 6. Users & Role-Based Access Control
- Six standard roles: `Super Admin`, `Admin`, `Editor`, `Moderator`, `Author`, and `Subscriber`.
- Account status controls (`active`, `suspended`, `banned`).
- Immediate session termination upon account suspension, ban, or password reset via `auth_version` tracking.
- Detailed Guide: [User Management](docs/user-management.md) &bull; [Roles and Permissions](docs/roles-and-permissions.md)

### 7. Settings & SEO
- **General Settings:** Site title, tagline, admin email, default user role, site language, date formats.
- **Writing & Reading Settings:** Default post categories, posts per page, homepage display (latest posts vs. static page).
- **SEO Tools:** Meta titles, canonical URLs, Open Graph social cards, XML sitemaps, and robots.txt generation.
- Detailed Guide: [Settings](docs/settings.md)

### 8. Backup & Restore
- Native full-site ZIP generator creating atomic database dumps and asset backups.
- Integrity verification using embedded `manifest.json` and SQL dump checksums.
- 1-click restore during fresh installation or via the Admin Tools menu.
- Detailed Guide: [Backup & Restore](docs/backup-and-restore.md)

### 9. Core Updates
- Automatic GitHub release discovery and in-app update execution.
- Safe maintenance mode activation during updates, pre-update snapshots, and atomic rollbacks.
- Detailed Guide: [Updates](docs/updates.md)

---

## 🧩 Extensibility: Themes & Plugins

Favorite CMS Universal provides clean, robust extension interfaces without requiring external build steps:

### Theme System
Themes live in `themes/<theme-id>/` and are declared via a standard `theme.json` manifest:
- Template files: `index.php`, `header.php`, `footer.php`, `sidebar.php`, `single.php`, `page.php`, `archive.php`, `search.php`, `404.php`, and `functions.php`.
- Support for customizable regions, layout options (sidebar left/right/none), brand colors, and dynamic widgets.
- Complete Guide: [Theme Development Guide](docs/theme-development.md)

### Plugin System
Plugins live in `plugins/<plugin-id>/` and are declared via a standard `plugin.json` manifest:
- Dynamic URL routing via `FavoriteCMS\Core\Router` (`Router::get()`, `Router::post()`).
- Custom administrative menus via `FavoriteCMS\Core\AdminMenu` (`AdminMenu::addMenu()`, `AdminMenu::addSubMenu()`).
- Automated database migrations via `FavoriteCMS\Core\Migrator`.
- Action hooks and filters via `FavoriteCMS\Core\Hook` (`add_action()`, `do_action()`, `add_filter()`, `apply_filters()`).
- Complete Guide: [Plugin Development Guide](docs/plugin-development.md)

---

## 🔒 Security Architecture

Favorite CMS Universal implements multiple defense layers:
- **SQL Injection Prevention:** 100% PDO prepared statements across all queries.
- **CSRF Defense:** Cryptographic tokens verified with `hash_equals()` on all POST/PUT/DELETE actions.
- **XSS & Content Sanitization:** Contextual escaping (`esc_html`, `esc_attr`, `esc_url`) and bundled HTMLPurifier 4.19.0.
- **Secure Password Hashing:** PHP native `password_hash()` (bcrypt / Argon2).
- **Session Hardening:** `HttpOnly`, `SameSite=Lax`, automatic HTTPS `Secure` cookies, and versioned session revocation (`auth_version`).
- Detailed Security Documentation: [SECURITY.md](SECURITY.md) &bull; [Security Guide](docs/security.md)

---

## 📚 Documentation Portal

All documentation is located in the [`docs/`](docs/README.md) directory:

| Topic | Documentation Link | Description |
|---|---|---|
| **Documentation Portal** | [docs/README.md](docs/README.md) | Full table of contents and documentation index |
| **Installation** | [docs/installation.md](docs/installation.md) | Step-by-step setup on shared hosting, local XAMPP, and subdirectories |
| **System Requirements** | [docs/requirements.md](docs/requirements.md) | Detailed PHP, MySQL, web server, and extension requirements |
| **Quick Start** | [docs/quick-start.md](docs/quick-start.md) | Up and running in 2 minutes |
| **Administration** | [docs/administration.md](docs/administration.md) | Admin dashboard walkthrough, navigation, and site management |
| **User Management** | [docs/user-management.md](docs/user-management.md) | Account registration, profile settings, and status actions |
| **Roles & Permissions** | [docs/roles-and-permissions.md](docs/roles-and-permissions.md) | Detailed 6-role permission matrix and access rules |
| **Posts & Pages** | [docs/posts-and-pages.md](docs/posts-and-pages.md) | Content creation, dual-mode editor, revisions, and publishing |
| **Media Library** | [docs/media.md](docs/media.md) | File uploads, limits, storage formats, and MIME protections |
| **Menus** | [docs/menus.md](docs/menus.md) | Navigation menu builder and theme locations |
| **Themes** | [docs/themes.md](docs/themes.md) | Theme activation, visual customizer, and widget regions |
| **Plugins** | [docs/plugins.md](docs/plugins.md) | Plugin management, activation, settings, and updates |
| **Settings** | [docs/settings.md](docs/settings.md) | Global CMS configuration, reading, writing, and SEO |
| **Backup & Restore** | [docs/backup-and-restore.md](docs/backup-and-restore.md) | Site portability, backup creation, and safe restoration |
| **Updates** | [docs/updates.md](docs/updates.md) | Core update procedure, maintenance mode, and rollback safety |
| **Security** | [docs/security.md](docs/security.md) | Architecture defenses, file upload hardening, and hardening tips |
| **Architecture** | [docs/architecture.md](docs/architecture.md) | Application lifecycle, request dispatch, container, and models |
| **Database** | [docs/database.md](docs/database.md) | Schema design, migrations, table relationships, and prefixing |
| **Routing** | [docs/routing.md](docs/routing.md) | Request routing, route parameters, and controller dispatch |
| **Hooks & Events** | [docs/hooks-and-events.md](docs/hooks-and-events.md) | Action hooks, value filters, and lifecycle events |
| **Theme Development** | [docs/theme-development.md](docs/theme-development.md) | Comprehensive theme developer guide and minimal theme example |
| **Plugin Development** | [docs/plugin-development.md](docs/plugin-development.md) | Comprehensive plugin developer guide and minimal working plugin |
| **Coding Conventions** | [docs/coding-conventions.md](docs/coding-conventions.md) | Coding guidelines, PSR standards, and naming rules |
| **Troubleshooting** | [docs/troubleshooting.md](docs/troubleshooting.md) | Solutions to common installation, server, and runtime issues |
| **Release Process** | [docs/release-process.md](docs/release-process.md) | Official release packaging, verification, and checksum validation |

---

## 📦 Releases & Downloads

Official pre-built production releases are published on the GitHub Releases page:
- **Download Latest Release:** [Favorite CMS Universal Releases](https://github.com/favoritecode/Favorite-CMS-Universal/releases)
- **Official Package:** `Favorite-CMS-Universal-v1.0.0.zip`

---

## 📄 License

Favorite CMS Universal is open-source software licensed under the [MIT License](LICENSE).
Feel free to use, modify, and distribute it for personal and commercial projects.
