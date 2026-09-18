# Plugins Architecture

The Plugin subsystem in Favorite CMS empowers developers to extend the CMS with rich business capabilities, custom endpoints, administration panels, and third-party integrations without altering Core source files.

---

## 1. Plugin Manager (`FavoriteCMS\Plugins\PluginManager`)

The `PluginManager` handles the full plugin lifecycle:
- **Discovery**: Scans `plugins/*` directories for valid `plugin.json` manifests.
- **Validation**: Checks manifest syntax, minimum PHP version, and prerequisite dependencies.
- **ZIP Installation**: Safely extracts uploaded archives with Zip-Slip path-traversal safeguards.
- **Activation & Deactivation**: Persists the list of active plugin IDs in the `settings` table (`group: plugins`, `key: active`).
- **Booting with Failure Isolation**: Loads active plugins inside a `try/catch` sandbox so a runtime error in one plugin never crashes the entire website.
- **Uninstallation**: Fires uninstallation lifecycle hooks, removes isolated plugin settings, and purges the plugin directory from disk.

---

## 2. Plugin Directory Conventions

```
plugins/my-plugin/
├── plugin.json           <-- Manifest declaring metadata and entry point
├── plugin.php            <-- Main bootstrap file
├── templates/            <-- Plugin views and template overrides
│   └── custom-view.php
├── assets/               <-- Plugin static files
│   ├── css/
│   └── js/
└── tests/                <-- Plugin integration tests
```

---

## 3. Failure Isolation & Diagnostics

During application bootstrap, `PluginManager::bootActivePlugins()` executes:

```php
foreach ($this->activePlugins as $pluginId) {
    try {
        require_once $entryFile;
        $this->loadedPlugins[$pluginId] = true;
    } catch (\Throwable $e) {
        $this->bootErrors[$pluginId] = $e->getMessage();
        Logger::error("Failed to boot plugin '{$pluginId}': " . $e->getMessage());
    }
}
```

If a third-party plugin encounters a fatal PHP error during initialization:
1. The exception is caught and recorded in `storage/logs/favorite_cms.log`.
2. The failing plugin is skipped.
3. The rest of the site (and admin dashboard) remains completely online, allowing administrators to diagnose or deactivate the offending plugin.
