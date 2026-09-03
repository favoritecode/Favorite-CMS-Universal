# Extension Lifecycle

Detailed overview of the lifecycle phases for both Plugins and Themes in Favorite CMS Universal.

---

## 1. Plugin Lifecycle States

```
[ Discovery ]
     │  Scans plugins/ directory for valid plugin.json manifests.
     ▼
[ Validation ]
     │  Verifies required manifest keys, PHP version, entry file existence.
     ▼
[ Dependency Resolution ]
     │  Verifies prerequisite active plugins listed in "dependencies" array.
     ▼
[ Installation ]
     │  Safely extracts ZIP archive to plugins/{id}.
     ▼
[ Activation ]
     │  Adds plugin id to settings table (plugins.active).
     │  Fires Hook::doAction('plugin.activated', $pluginId).
     ▼
[ Runtime Boot ]
     │  Requires entry file (e.g. plugin.php) on every request.
     │  Fires Hook::doAction('plugins.loaded').
     ▼
[ Deactivation ]
     │  Removes plugin id from settings table (plugins.active).
     │  Fires Hook::doAction('plugin.deactivated', $pluginId).
     ▼
[ Uninstallation ]
     │  Fires Hook::doAction('plugin.uninstalled', $pluginId).
     │  Purges all isolated settings from plugin_settings table.
     │  Deletes the plugin directory from disk.
```

---

## 2. Theme Lifecycle States

```
[ Discovery ]
     │  Scans themes/ directory for theme.json manifests.
     ▼
[ Activation ]
     │  Updates active_theme in settings table (theme.active_theme).
     ▼
[ Runtime Rendering ]
     │  Engine resolves templates from active theme directory first.
     │  Falls back to plugin templates or core views if absent.
```
