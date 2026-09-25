# Plugins Guide

This guide covers how to discover, install, manage, and configure plugins in Favorite CMS Universal v1.0.0.

---

## Plugin Architecture & Ecosystem Separation

Favorite CMS Universal adheres to a strict architectural principle: **The Core CMS remains clean, lean, and universally applicable.**

Specialized domain logic—such as:
- **Audio & Video Streaming** (e.g. *Favorite Multimedia*)
- **Developer & Web Utilities** (e.g. *Favorite Web Tools*)
- **Ecommerce & Digital Stores** (e.g. *Favorite Shop*)
- **Membership & Subscriptions**
- **Payment Gateways**

are built as **Plugins**, decoupled from core. The core CMS provides the generic plugin runtime, hooks, database migration runner, and admin routing to empower third-party plugins.

---

## Plugin Directory & Discovery

Plugins reside in the `plugins/` directory:
```
plugins/
├── my-plugin/
│   ├── plugin.json       # Required manifest
│   ├── plugin.php        # Entry point
│   ├── database/
│   │   └── migrations/   # Automated schema migrations
│   └── templates/
```

Favorite CMS scans the `plugins/` directory and reads each plugin's `plugin.json` manifest. Valid plugins are listed in the admin panel under **Plugins** (`/admin/plugins`).

---

## Managing Plugins (`/admin/plugins`)

### 1. Activating a Plugin
When an administrator clicks **Activate**:
1. **Validation:** Checks PHP version compatibility (`requires_php`) and verifies that required dependency plugins are active.
2. **Table Prefix Registration:** Registers any declared custom tables (`tables` array in manifest) with the database layer so dynamic prefixing (`fc_`) applies.
3. **Database Migrations:** If `database/migrations/` exists inside the plugin folder, the core `Migrator` executes them automatically.
4. **Lifecycle Event:** Fires the `plugin.activated` action hook.
5. **Persistence:** Saves the plugin identifier to active plugins list in settings.

### 2. Deactivating a Plugin
When an administrator clicks **Deactivate**:
1. Fires the `plugin.deactivated` action hook.
2. Removes the plugin identifier from the active list.
3. The plugin's routes, admin menus, and hooks are no longer loaded on subsequent requests.
4. Plugin database tables and settings are preserved so the administrator can reactivate later without data loss.

---

## Fault-Tolerant Execution (Failure Isolation)

Third-party plugin code can occasionally contain runtime bugs or fatal errors.

Favorite CMS Universal wraps plugin booting in an exception isolation shield (`PluginManager::loadPlugin()`):
- If an active plugin throws an exception during startup, the error is recorded in `storage/logs/favorite_cms.log` and captured in `$bootErrors`.
- The broken plugin is temporarily suppressed for that request while the rest of the core CMS and admin panel continue executing normally.
- Administrators can safely log into `/admin/plugins` and deactivate the malfunctioning plugin without needing direct FTP or SSH access.
