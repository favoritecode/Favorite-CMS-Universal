# Plugin Directory Structure

Favorite CMS supports clean, organized plugin structures.

---

## 1. Standard Directory Layout

```
plugins/my-plugin/
│
├── plugin.json               # Required manifest file
├── plugin.php                # Main entry / bootstrap file
│
├── src/                      # (Optional) PSR-4 class files
│   ├── Controllers/
│   ├── Models/
│   └── Services/
│
├── admin/                    # (Optional) Administrative templates and handlers
│   └── settings-page.php
│
├── templates/                # (Optional) Frontend templates and view overrides
│   └── my-view.php
│
├── assets/                   # (Optional) Static CSS, JS, and image assets
│   ├── css/
│   └── js/
│
└── tests/                    # (Optional) Automated tests for the plugin
    └── PluginTest.php
```

---

## 2. Directory Naming Rules

- The directory name must consist only of lowercase letters, numbers, and hyphens (e.g. `ecommerce-core`, `contact-form-7`).
- The directory name **must match** the `"id"` field in `plugin.json`.
