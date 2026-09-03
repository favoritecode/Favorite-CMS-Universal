# Plugin Manifest (`plugin.json`)

Every plugin must include a `plugin.json` manifest at its root. This file is parsed by `PluginManager` during discovery, validation, and activation.

---

## 1. Complete Manifest Schema

```json
{
    "id": "sample-plugin",
    "name": "Sample Plugin",
    "version": "1.0.0",
    "description": "A concise description of the plugin's features.",
    "author": "Your Name / Organization",
    "author_url": "https://example.com",
    "requires_php": "8.1.0",
    "dependencies": [
        "core-framework-extension"
    ],
    "entry_point": "plugin.php"
}
```

---

## 2. Field Definitions

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `id` | string | **Yes** | Unique identifier matching directory name (lowercase alphanumeric and hyphens). |
| `name` | string | **Yes** | Human-readable name displayed in the Admin Plugins table. |
| `version` | string | **Yes** | Semantic version string (e.g. `1.0.0`). |
| `description` | string | No | Short explanation of what the plugin does. |
| `author` | string | No | Author or organization name. |
| `author_url` | string | No | Link to author or plugin documentation website. |
| `requires_php` | string | No | Minimum PHP version required (e.g. `8.1.0`). If the server PHP version is lower, activation is blocked. |
| `dependencies`| array | No | List of plugin IDs that **must** be active before this plugin can be activated. |
| `entry_point` | string | No | Bootstrap file path relative to plugin root (defaults to `plugin.php`). |
