# Favorite CMS Universal Documentation Portal

Welcome to the official documentation for **Favorite CMS Universal v1.0.0**. This documentation library provides comprehensive guides, technical specifications, and architectural references for site owners, administrators, theme designers, and plugin developers.

---

## Table of Contents

### 🚀 Getting Started
- [System Requirements](requirements.md) — Hardware, PHP version, PHP extensions, and database requirements.
- [Quick Start Guide](quick-start.md) — Getting up and running in under 2 minutes.
- [Installation Guide](installation.md) — Detailed walkthrough of web installer steps, shared hosting, local XAMPP setup, and subdirectory deployments.

### 🛡️ Administration & Operations
- [Administration Guide](administration.md) — Navigating the admin dashboard, system tools, and general management.
- [Settings Guide](settings.md) — General site options, writing/reading defaults, and SEO metadata configuration.
- [Backup & Restore](backup-and-restore.md) — Native full-site ZIP generation, SQL backup verification, and 1-click restore workflows.
- [Core Updates](updates.md) — Managing CMS updates, maintenance mode activation, snapshot backups, and atomic rollbacks.

### 👥 Users & Permissions
- [User Management](user-management.md) — User account creation, profile management, status controls (active, suspended, banned), and session handling.
- [Roles & Permissions](roles-and-permissions.md) — The 6-role permission matrix (Super Admin, Admin, Editor, Moderator, Author, Subscriber) and capability definitions.

### 📝 Content Management
- [Posts & Pages](posts-and-pages.md) — Authoring content with the dual-mode editor (Visual WYSIWYG & HTML Code), revisions, categories, and tags.
- [Media Management](media.md) — Role-aware upload boundaries, file types, MIME-type validation, and thumbnail processing.
- [Menus & Navigation](menus.md) — Creating and structuring menus across theme-registered navigation locations.

### 🎨 Themes & Presentation
- [Themes Guide](themes.md) — Theme activation, visual customizer, color schemes, and widget placement.
- [Theme Development Guide](theme-development.md) — Building custom themes from scratch: `theme.json` manifest, template hierarchy, widget regions, assets, and minimal theme example.

### 🔌 Plugins & Extensibility
- [Plugins Guide](plugins.md) — Installing, activating, configuring, and updating generic plugins.
- [Plugin Development Guide](plugin-development.md) — Building third-party plugins: `plugin.json` manifest, lifecycle hooks, custom routes, admin menus, database migrations, and minimal plugin example.

### 🏗️ Architecture & Internals
- [System Architecture](architecture.md) — Application lifecycle, front controller, Dependency Injection container, models, and rendering engine.
- [Database & Migrations](database.md) — InnoDB schema layout, table prefix handling, migration engine (`database/migrations`), and relationship structures.
- [Routing System](routing.md) — URL resolution, path normalization, route registration via `Router`, and controller dispatching.
- [Hooks & Events](hooks-and-events.md) — Priority-based actions (`add_action`, `do_action`) and filters (`add_filter`, `apply_filters`).

### 🔒 Security & Standards
- [Security Guide](security.md) — In-depth overview of authentication, session versioning, CSRF protection, HTMLPurifier sanitization, and upload hardening.
- [Coding Conventions](coding-conventions.md) — PSR-12 coding style, PSR-4 autoloading rules, strict typing, and security best practices.

### 🔧 Maintenance & Support
- [Troubleshooting](troubleshooting.md) — Diagnostic steps for common installation, permission, database, rewrite, and upload issues.
- [Disaster Recovery](disaster-recovery.md) — Complete procedure to restore the master development workspace from Git.
- [Release Process](release-process.md) — Build verification, SHA-256 checksum calculation, and release integrity guidelines.
- [Repository Governance](../REPOSITORY-RULES.md) — Official repository scope, core-only policy, and release boundaries.
- [AI Agent Rules](../AGENTS.md) — Permanent directives and constraints for automated AI agents.
