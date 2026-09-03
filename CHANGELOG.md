# Changelog

All notable changes to **Favorite CMS Universal** are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.0-beta] - 2026-09-03

### Added
- **Core Architecture**:
  - Service container and lightweight dependency injection framework (`FavoriteCMS\Core\Application`).
  - Idempotent PDO database migration runner with 13 core migrations (`database/migrations/`).
  - Multi-tier persistent installation state with automatic self-healing lock mechanism (`storage/installed.lock`).
- **Extensibility Subsystem**:
  - Priority-based action and filter hook system (`FavoriteCMS\Core\Hook`) with global helpers: `add_action`, `do_action`, `add_filter`, `apply_filters`.
  - Dynamic plugin frontend route registration engine (`FavoriteCMS\Core\Router`) with `{param}` matching and JSON/HTML responses.
  - Dynamic admin menu and custom page registration engine (`FavoriteCMS\Core\AdminMenu`) with `/admin/page/{slug}` routing and sidebar injection.
  - Isolated plugin settings storage service (`FavoriteCMS\Models\PluginSetting`) with automatic JSON serialization and caching.
  - Standardized diagnostic logging service (`FavoriteCMS\Core\Logger`) writing to `storage/logs/favorite_cms.log`.
  - Failure-isolated plugin bootloader in `FavoriteCMS\Plugins\PluginManager`.
- **Presentation & Default Theme**:
  - Redesigned `default` theme featuring modern design tokens, centered reading container (740px), image-optional post cards, accessible mobile navigation drawer, and 404 error template.
  - Multi-tier template resolution in `FavoriteCMS\Rendering\Engine` (Theme Override &rarr; Plugin Default &rarr; Core View).
- **Security & Authorization**:
  - Role-Based Access Control (RBAC) with dynamic `hasPermission()` and automatic `super-admin` capability bypass.
  - Comprehensive CSRF protection, input sanitization (`clean_post_content()`), and Zip-Slip path-traversal prevention.
- **Reference Extensions**:
  - Added official reference plugin `hello-favorite` demonstrating routes, admin panels, settings, hooks, and views.
  - Enhanced reference theme `default` with full responsive components.
- **Documentation**:
  - Complete 42-document knowledge base under `docs/` covering getting started, architecture, plugin development, theme development, deployment, and API references.
- **Testing**:
  - 44 automated PHPUnit tests with 173 assertions covering Core, installer, models, media, and plugin readiness.
