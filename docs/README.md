# Favorite CMS Universal — Documentation Index

Welcome to the official developer, administrator, and user documentation library for **Favorite CMS Universal**.

> **"One CMS. Any Website."**  
> Favorite CMS Universal is a lightweight, modular, standalone PHP CMS engineered for high speed, dependable reliability, and straightforward shared-hosting and local development deployment.

---

## 🧭 Complete Documentation Structure

```
docs/
├── getting-started/      # System requirements and platform installations
├── user-guide/           # Site administration, post editor, media, moderation
├── architecture/         # System design, lifecycle, and security model
├── plugins/              # Plugin development, manifests, and extension APIs
├── themes/               # Theme development, customizer, and widget layout
├── security/             # Multi-tier security defenses and hardening
├── operations/           # Backup, migration, upgrades, and troubleshooting
└── development/          # Local setup, automated testing, and release process
```

---

## 📚 Documentation Directory

### 1. Getting Started
- [System Requirements](getting-started/requirements.md) — Production shared hosting and development environment specifications.
- [Local XAMPP Installation](getting-started/installation-local.md) — Step-by-step installation on Windows with XAMPP.
- [Shared Hosting Installation](getting-started/installation-shared-hosting.md) — Deploying to standard cPanel / LAMP shared hosting.
- [Subdomain Installation](getting-started/installation-subdomain.md) — Running on subdomains (`blog.example.com`).
- [Subdirectory Installation](getting-started/installation-subdirectory.md) — Running in nested folders (`example.com/blog/`).
- [Environment Configuration](getting-started/configuration.md) — Setting up `.env` and database parameters.
- [Creating Your First Website](getting-started/first-website.md) — Site identity, first article, navigation, and settings.

### 2. User & Administration Guide
- [Admin Dashboard](user-guide/dashboard.md) — Overview cards, quick draft, and recent activities.
- [Posts Management](user-guide/posts.md) — Life cycle, post statuses, categories, tags, and trash.
- [Dual-Mode Post Editor](user-guide/post-editor.md) — Visual WYSIWYG mode, Code mode, line numbers, table tools, and paste cleaning.
- [Media Library & Uploads](user-guide/media.md) — Managing multimedia, formats, progress bars, and role-based limits (7 GB Admin, 500 MB Mod, 200 MB User).
- [Users, Roles & Statuses](user-guide/users-and-roles.md) — RBAC permissions, public signup, Active, Suspended, and Banned states.
- [Content Moderation Workflow](user-guide/moderation.md) — Contributor submissions, pending queue, approvals, rejections, and moderator direct publishing.
- [Widgets & Layout](user-guide/widgets.md) — 10 built-in widgets, theme regions, multi-instance configuration, and 1-click theme defaults.
- [Themes & Customizer](user-guide/themes.md) — Switching themes, sidebar layouts (Right, Left, Full Width), colors, and section reordering.
- [Site Settings](user-guide/settings.md) — General site information, public registration toggle, reading options, and upload limits.

### 3. Architecture & Core Concepts
- [Architecture Overview](architecture/overview.md) — System layers, technology stack, and separation of concerns.
- [Folder Structure](architecture/folder-structure.md) — Detailed directory breakdown and code visibility.
- [Core Foundation](architecture/core.md) — Application container, service registration, and error handling.
- [Database & Migrations](architecture/database.md) — PDO abstraction, migrations runner, and schema definitions.
- [HTTP Request Lifecycle](architecture/request-lifecycle.md) — From incoming request to controller dispatch and output.
- [Security Model](architecture/security-model.md) — Threat model, defense-in-depth principles, and mitigations.

### 4. Plugin Development
- [Plugin Subsystem Overview](plugins/overview.md) — Plugin philosophy, lifecycle, and public extension points.
- [Plugin Development Guide](plugins/plugin-development.md) — Building your first plugin step by step.
- [Plugin Manifest Specification](plugins/plugin-manifest.md) — `plugin.json` schema, dependencies, and capabilities.
- [Hooks, Events & Filters](plugins/hooks-events-filters.md) — Using `add_action()`, `do_action()`, `add_filter()`, and `apply_filters()`.
- [Dynamic Routes & Admin Menus](plugins/routes-and-admin-panels.md) — Registering custom frontend URLs and admin panels.
- [Plugin Best Practices](plugins/plugin-best-practices.md) — Do's and Don'ts, database prefixing, and theme overrides.

### 5. Theme Development
- [Themes Subsystem Overview](themes/overview.md) — Presentation layer contracts and boundaries.
- [Theme Development Guide](themes/theme-development.md) — Building custom themes, templates, headers, and footers.
- [Theme Manifest Specification](themes/theme-manifest.md) — `theme.json` schema, declared regions, and feature flags.
- [Widgets & Layout Architecture](themes/widgets-and-layout.md) — Region integration and customizer hooks.
- [Template Overrides](themes/template-overrides.md) — Overriding plugin views and widget markup in themes.

### 6. Security & Hardening
- [Security Overview](security/overview.md) — Multi-tier security architecture and realistic expectations.
- [Authentication & Sessions](security/authentication.md) — Password hashing, session storage, and instant ban termination.
- [Authorization & RBAC](security/authorization.md) — Capability enforcement and server-side tamper-proofing.
- [Upload Hardening](security/uploads.md) — Extension whitelists, MIME detection, and `.htaccess` execution blocks.
- [Content Sanitization](security/content-sanitization.md) — XSS defense and HTML tag whitelisting.
- [Deployment Security](security/deployment-security.md) — File permissions, HTTPS enforcement, and server headers.

### 7. Operations & Maintenance
- [Backup & Disaster Recovery](operations/backup-restore.md) — Database exports and media file preservation.
- [Site Migration](operations/migration.md) — Moving sites between hosting providers or local XAMPP to production.
- [Upgrading Favorite CMS](operations/upgrade.md) — Safely applying newer releases without data loss.
- [Troubleshooting Guide](operations/troubleshooting.md) — Solutions to common installation, routing, upload, and database errors.
- [Performance & Optimization](operations/performance.md) — Browser caching, OPcache, and query efficiency.

### 8. Development & Contributing
- [Development Setup](development/development-setup.md) — Setting up a local development environment.
- [Automated Testing](development/testing.md) — Running PHPUnit suites and quality assurance.
- [Contributing Guidelines](development/contributing.md) — Coding standards (PSR-12, strict types) and pull request workflow.
- [Release Process](development/release-process.md) — Packaging and verifying production distribution archives.
