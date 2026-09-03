# Favorite CMS Universal

<p align="center">
  <strong>"One CMS. Any Website."</strong><br>
  A lightweight, modular, standalone PHP CMS engineered for speed, reliable stability, and straightforward shared-hosting and local development deployment.
</p>

---

## 🌟 Overview

**Favorite CMS Universal** provides a solid, production-oriented content management foundation. It is designed to make it simple to build any kind of website—from personal blogs and company sites to news magazines and portfolio showcases—from a single, stable Core.

The **Core** provides the general CMS foundation (users, roles, permissions, posts, pages, categories, tags, media, comments, settings, and routing).  
**Plugins** add specialized business functionality and dynamic endpoints.  
**Themes** control presentation and responsive layouts.

---

## 🚀 Key Benefits

- **Shared-Hosting Friendly**: Runs on standard cPanel/Apache/LAMP shared hosting without needing root access, VPS, Docker, or external queue workers.
- **Local-PC Friendly**: Tested to work out of the box with XAMPP and PHP's built-in web server.
- **Zero Mandatory Node.js / Python Runtime**: No background Node.js processes, Python daemons, or separate microservices required.
- **Persistent Installation**: Multi-tier persistence (lock file + database verification) designed so the CMS maintains persistent installation state across web server restarts.
- **Modern Default Theme**: Clean, responsive, content-first theme with zero external CSS/JS framework dependencies.
- **First-Class Plugin Subsystem**: Extend the CMS with dynamic routes, admin pages, permissions, settings, and hooks without modifying Core code.
- **Fault-Tolerant Plugin Booting**: Runtime errors during plugin boot are caught and logged to help prevent third-party plugins from crashing the main application.
- **Security Best Practices**: Parameterized PDO queries, CSRF validation, strict input cleaning, and secure session management.

---

---

## 📥 Download

The standalone, installable WordPress-style distribution archive is prepared as:
- **Package**: `Favorite-CMS-Universal.zip`
- **Current Version**: `1.0.0-beta`

When official GitHub Releases are published, end users can download the pre-packaged, zero-configuration distribution ZIP directly from the [GitHub Releases](https://github.com/favoritecode/Favorite-CMS-Universal/releases) page. The distribution package includes all required core classes, assets, themes, plugins, and bundled vendor dependencies—no git cloning, command line, or Composer installation is needed on shared hosting.

### Standard End-User Installation Workflow:
```
1. Download Favorite-CMS-Universal.zip
2. Extract directly onto your web hosting or XAMPP directory
3. Open your domain in any web browser
4. Complete the 2-minute web setup wizard (Database & Admin Account)
5. Your website is immediately online!
```

---

## 📦 Quick Start for Developers (Local XAMPP)

1. **Clone the Repository**:
   ```bash
   git clone https://github.com/favoritecode/Favorite-CMS-Universal.git
   cd Favorite-CMS-Universal
   ```

2. **Install Dependencies**:
   ```bash
   composer install
   ```

3. **Point Apache DocumentRoot**:
   Point your local virtual host to the `public/` folder:
   ```apache
   DocumentRoot "C:/xampp/htdocs/favorite-cms/public"
   ```

4. **Run the Browser Installer**:
   Navigate to `http://favorite-cms.local/` (or `http://localhost/favorite-cms/public`). The installer wizard detects the current URL, tests or creates the database when permissions allow, writes `.env`, and creates your initial administrator account.

---

## 📂 Project Structure

```
├── app/                  # Application Core classes (PSR-4 FavoriteCMS\)
│   ├── Core/             # Container, Kernel, Database, Router, Hook, Logger
│   ├── Http/             # Request, Response, Controllers (Admin & Frontend)
│   ├── Models/           # ActiveRecord Models (Post, Page, User, Setting, etc.)
│   ├── Plugins/          # PluginManager lifecycle engine
│   └── Rendering/        # Engine template resolution
├── bootstrap.php         # Application initialization & singleton binding
├── database/             # Schema migrations (001 - 013)
├── docs/                 # Complete developer & administrator documentation
├── plugins/              # Active & installed plugins
│   └── hello-favorite/   # Official reference plugin demonstrating all APIs
├── public/               # Web server DocumentRoot (index.php, uploads, assets)
├── resources/views/      # System views and admin panel templates
├── storage/              # Cache, logs, and installation lock files
├── tests/                # Automated PHPUnit test suite
└── themes/               # Visual presentation themes
    └── default/          # Reference modern, responsive theme
```

---

## 📖 Complete Documentation

Complete, self-contained documentation is included directly within the repository in the [`docs/`](docs/README.md) directory:

- [Documentation Index](docs/README.md)
- **Getting Started**:
  - [Local XAMPP Installation](docs/getting-started/installation-local.md)
  - [Shared Hosting Installation](docs/getting-started/installation-shared-hosting.md)
  - [Configuration Guide](docs/getting-started/configuration.md)
  - [Creating Your First Website](docs/getting-started/first-website.md)
- **Architecture**:
  - [Overview](docs/architecture/overview.md)
  - [Application Lifecycle](docs/architecture/application-lifecycle.md)
  - [Database & Migrations](docs/architecture/database.md)
  - [Routing](docs/architecture/routing.md)
  - [Rendering Engine](docs/architecture/rendering.md)
  - [Hooks & Events](docs/architecture/hooks-events.md)
- **Plugin Development**:
  - [Getting Started with Plugins](docs/plugins/getting-started.md)
  - [Dynamic Routes](docs/plugins/routes.md)
  - [Admin Pages](docs/plugins/admin-pages.md)
  - [Permissions](docs/plugins/permissions.md)
  - [Settings API](docs/plugins/settings.md)
  - [Reference Plugin (`hello-favorite`)](docs/plugins/complete-example.md)
- **Theme Development**:
  - [Getting Started with Themes](docs/themes/getting-started.md)
  - [Template Hierarchy](docs/themes/template-hierarchy.md)
  - [Components & Layouts](docs/themes/components.md)
  - [Reference Theme (`default`)](docs/themes/complete-example.md)
- **Operations & Deployment**:
  - [Backup & Migration](docs/deployment/backup-migration.md)
  - [Upgrading](docs/deployment/upgrade.md)
  - [Troubleshooting](docs/deployment/troubleshooting.md)
- **API Reference**:
  - [Core Public APIs](docs/reference/api-reference.md)
  - [Hooks Reference](docs/reference/hooks-reference.md)
  - [Manifest Reference](docs/reference/manifest-reference.md)

---

## 🧪 Automated Testing

Run the full PHPUnit test suite:

```bash
composer test
```
All tests must report **OK (100% Pass)**.

---

## 🤝 Contributing

Contributions are welcome! Please review [CONTRIBUTING.md](CONTRIBUTING.md) for coding conventions, pull request guidelines, and security reporting.

---

## 📄 License

See [LICENSE](LICENSE) for licensing details.
