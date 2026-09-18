# Manifest Reference: `plugin.json` and `theme.json`

Detailed schema specifications for extension manifests.

---

## 1. Plugin Manifest (`plugin.json`)

```json
{
    "id": "my-plugin",
    "name": "My Plugin Name",
    "version": "1.0.0",
    "description": "Short summary of plugin functionality.",
    "author": "Developer or Studio Name",
    "author_url": "https://example.com",
    "requires_php": "8.1.0",
    "dependencies": [
        "prerequisite-plugin-id"
    ],
    "entry_point": "plugin.php"
}
```

### Constraints:
- `id`: Must match the directory name exactly (`^[a-zA-Z0-9_\-]+$`).
- `entry_point`: Path relative to the plugin root (defaults to `plugin.php`).
- `requires_php`: Valid PHP version string (e.g. `8.1.0`, `8.2.0`).

---

## 2. Theme Manifest (`theme.json`)

```json
{
    "id": "my-theme",
    "name": "My Theme Name",
    "version": "1.0.0",
    "author": "Designer or Studio Name",
    "description": "Visual presentation description.",
    "license": "MIT",
    "menu_locations": {
        "primary": "Primary Header Navigation",
        "footer": "Footer Menu"
    }
}
```
