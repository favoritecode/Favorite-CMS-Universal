# Favorite CMS Universal — Documentation Index

Welcome to the official developer and administrator documentation for **Favorite CMS Universal**.

> **"One CMS. Any Website."**  
> Favorite CMS Universal is a lightweight, modular, standalone PHP CMS engineered for high speed, absolute stability, and effortless shared-hosting and local development deployment.

---

## 🧭 Navigation & Learning Paths

Depending on your goal, follow the suggested path below:

```
                  ┌───────────────────────────────┐
                  │    Getting Started / Setup    │
                  │ (Local XAMPP / Shared Hosting)│
                  └───────────────┬───────────────┘
                                  │
                                  ▼
                  ┌───────────────────────────────┐
                  │     Core Architecture Tour    │
                  │ (Lifecycle, Database, Routing)│
                  └───────────────┬───────────────┘
                                  │
                 ┌────────────────┴────────────────┐
                 ▼                                 ▼
   ┌───────────────────────────┐     ┌───────────────────────────┐
   │    Theme Development      │     │    Plugin Development     │
   │  (Layouts, Hierarchy,     │     │ (Routes, Admin Pages,     │
   │   Components & Assets)    │     │  Hooks, Settings & DB)    │
   └─────────────┬─────────────┘     └─────────────┬─────────────┘
                 │                                 │
                 └────────────────┬────────────────┘
                                  │
                                  ▼
                  ┌───────────────────────────────┐
                  │ Deployment, Testing & Security│
                  │ (Backup, Hardening, Packaging)│
                  └───────────────────────────────┘
```

---

## 📚 Documentation Directory

### 1. Getting Started
- [Local XAMPP Installation](getting-started/installation-local.md) — Step-by-step installation on Windows with XAMPP.
- [Shared Hosting Installation](getting-started/installation-shared-hosting.md) — Deploying to standard cPanel / LAMP shared hosting.
- [Environment Configuration](getting-started/configuration.md) — Setting up `.env` and database parameters.
- [Creating Your First Website](getting-started/first-website.md) — Site identity, first article, navigation, and settings.

### 2. Architecture & Core Concepts
- [Architecture Overview](architecture/overview.md) — System layers, design philosophy, and technology stack.
- [Application Lifecycle](architecture/application-lifecycle.md) — Bootstrap, front controller, middleware, and termination.
- [Core Foundation](architecture/core.md) — Application container, service registration, and error handling.
- [Database & Migrations](architecture/database.md) — PDO abstraction, migrations runner, and schema definitions.
- [Routing Subsystem](architecture/routing.md) — Clean URL dispatching, static assets, and route resolution.
- [Template Rendering](architecture/rendering.md) — Layout hierarchy and output evaluation.
- [Themes Architecture](architecture/themes.md) — Presentation layer contracts and boundaries.
- [Plugins Architecture](architecture/plugins.md) — Business extensibility, isolation, and lifecycle.
- [Hooks & Events Subsystem](architecture/hooks-events.md) — Priority-based actions and filters.
- [Security Architecture](architecture/security.md) — CSRF, input sanitization, output escaping, and session security.
- [Performance Architecture](architecture/performance.md) — Zero-overhead boot, static asset caching, and query efficiency.

### 3. Developer Standards
- [Development Environment](developer/development-environment.md) — Recommended local tooling, PHP CLI, and Composer.
- [Coding Guidelines](developer/coding-guidelines.md) — PSR-12 formatting, strict types, and naming conventions.
- [Public Core APIs](developer/public-apis.md) — Stable global helpers and contracts available to extensions.
- [Extension Lifecycle](developer/extension-lifecycle.md) — Discovery, activation, boot, and deactivation mechanics.
- [Debugging & Diagnostics](developer/debugging.md) — Log files, error traces, and CLI utilities.
- [Automated Testing](developer/testing.md) — Running PHPUnit suites and writing integration tests.

### 4. Plugin Development Guide
- [Getting Started with Plugins](plugins/getting-started.md) — Your first plugin in 5 minutes.
- [Plugin Directory Structure](plugins/plugin-structure.md) — Directory conventions and required files.
- [Plugin Manifest (`plugin.json`)](plugins/plugin-manifest.md) — Metadata, dependencies, and versioning schema.
- [Dynamic Frontend Routes](plugins/routes.md) — Registering custom URLs with `add_route()`.
- [Admin Menus & Custom Pages](plugins/admin-pages.md) — Adding sidebar items and admin controllers with `add_admin_menu()`.
- [Permissions & Capabilities](plugins/permissions.md) — Securing plugin actions with `current_user_can()`.
- [Plugin Settings Storage](plugins/settings.md) — Isolated key-value storage with `plugin_setting()`.
- [Plugin Hooks & Events](plugins/hooks-events.md) — Listening to actions and filtering CMS data.
- [Database & Schema Access](plugins/database.md) — Creating custom tables and executing prepared queries.
- [File Storage & Media](plugins/storage.md) — Working with uploads and filesystem resources.
- [Custom Templates & Overrides](plugins/templates.md) — Serving plugin views and hooking `template_include`.
- [Plugin Security Guidelines](plugins/security.md) — Path traversal prevention, CSRF validation, and input sanitation.
- [Testing Plugins](plugins/testing.md) — Isolating tests and mocking requests.
- [Packaging & Distribution](plugins/packaging.md) — Creating production-ready ZIP archives.
- [Complete Working Example](plugins/complete-example.md) — Full walk-through of the `hello-favorite` plugin.

### 5. Theme Development Guide
- [Getting Started with Themes](themes/getting-started.md) — Creating a new visual theme.
- [Theme Directory Structure](themes/theme-structure.md) — Templates, assets, and metadata layout.
- [Theme Manifest (`theme.json`)](themes/theme-manifest.md) — Theme declaration and navigation locations.
- [Theme Templates](themes/templates.md) — Home, single post, page, search, and 404 views.
- [Template Resolution Hierarchy](themes/template-hierarchy.md) — Precedence from Theme to Plugin to Core.
- [Header, Sidebar & Footer Components](themes/components.md) — Reusable modular visual components.
- [Layouts & Grid Systems](themes/layouts.md) — Content-first responsive layout design.
- [Static Assets (CSS & JS)](themes/assets.md) — Performance tokens, caching, and delivery.
- [Theme Customization](themes/customization.md) — Site branding, typography, and color tokens.
- [Theme Security](themes/security.md) — Escaping attributes, sanitizing output, and XSS defense.
- [Testing Themes](themes/testing.md) — Verifying responsive viewports and empty states.
- [Complete Working Example](themes/complete-example.md) — Tour of the reference `default` theme.

### 6. Deployment & Operations
- [Shared Hosting Deployment](deployment/shared-hosting.md) — Upload, permissions, and Apache rewrite setup.
- [Local XAMPP Deployment](deployment/local-xampp.md) — Virtual hosts, MySQL persistence, and Apache controls.
- [Backup & Migration Guide](deployment/backup-migration.md) — Exporting database and files without re-triggering installer.
- [Upgrade Guide](deployment/upgrade.md) — Safe core updates and database migration execution.
- [Troubleshooting Common Issues](deployment/troubleshooting.md) — Resolving 404s, white screens, and permission blocks.

### 7. API & Technical Reference
- [Plugin & Theme Manifest Reference](reference/manifest-reference.md) — Full JSON schema definitions.
- [Hooks & Events Reference](reference/hooks-reference.md) — Exhaustive list of core actions and filters.
- [Public Core API Reference](reference/api-reference.md) — Classes, functions, arguments, and return types.
- [Permissions Reference](reference/permissions-reference.md) — Standard RBAC roles and capability slugs.
- [Template Hierarchy Reference](reference/template-reference.md) — Fallback decision matrix.
- [Configuration Reference](reference/configuration-reference.md) — All `.env` environment options.
