# Architecture Overview

Favorite CMS Universal is designed around a modular, decoupled architecture prioritizing simplicity, performance, security, and developer clarity.

---

## 1. High-Level Architecture Diagram

```
[ Browser / HTTP Client ]
           │
           ▼
[ Front Controller (public/index.php) ]
           │
           ▼
[ Application Bootstrap & Service Container ]
           │
           ▼
[ HTTP Kernel (FavoriteCMS\Core\Kernel) ]
           │
 ┌─────────┴─────────┐
 │                   ▼
 │        [ Active Plugins Boot ]
 │        (FavoriteCMS\Plugins\PluginManager)
 │                   │
 │                   ▼
 │        [ Core Init Hook: do_action('init') ]
 │                   │
 │                   ▼
 ├─► [ Static Assets Router (/themes/*, /plugins/*) ]
 ├─► [ Dynamic Plugin Routes (Router::dispatch) ]
 ├─► [ Admin Module (/admin/* & /admin/page/*) ]
 └─► [ Frontend Controller (Home, Single, Page, Search, 404) ]
           │
           ▼
[ Template Rendering Engine (Engine.php) ]
   (Theme Override ──► Plugin Template ──► Core View)
           │
           ▼
[ HTTP Response with Security Headers ]
```

---

## 2. Core Architectural Pillars

### Pillar 1: Zero Mandatory Dependencies
The core requires only PHP 8.1+ and MySQL/MariaDB with standard extensions (`pdo_mysql`, `mbstring`, `json`, `fileinfo`, `gd`, `zip`). It has no runtime reliance on Node.js, Python, or external queue brokers.

### Pillar 2: Two-Tier Extensibility
- **Themes**: Responsible exclusively for presentation, layout, HTML structure, typography, and visual assets. Themes must not contain business logic or direct database mutations.
- **Plugins**: Responsible for business functionality, dynamic routes, administrative pages, external integrations, and domain models.

### Pillar 3: Safe Boundaries & Error Isolation
Third-party plugins boot inside failure-isolated wrappers. If a plugin throws an unhandled exception during initialization, the CMS logs the diagnostic error, bypasses the broken plugin, and serves the rest of the website normally.

### Pillar 4: Persistent Installation State
Installation state is permanently recorded via a multi-tiered check (`storage/installed.lock` and database table verification). Once installed, restarting web servers or database daemons never triggers a re-installation.
