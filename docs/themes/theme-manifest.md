# Theme Manifest (`theme.json`)

The `theme.json` file declares your theme metadata and available navigation menu locations.

---

## 1. Schema Example

```json
{
    "id": "default",
    "name": "Favorite Default",
    "version": "1.0.0",
    "author": "Favorite CMS Team",
    "description": "The clean, fast, mobile-ready default presentation theme for Favorite CMS.",
    "license": "MIT",
    "menu_locations": {
        "primary": "Primary Header Navigation",
        "footer": "Footer Menu"
    }
}
```

---

## 2. Manifest Properties

- **`id`**: Unique theme slug matching its directory name.
- **`name`**: Display name shown in the Themes management table.
- **`version`**: Semantic version string (e.g. `1.0.0`).
- **`author`**: Name of author or studio.
- **`description`**: Summary of the theme's visual style.
- **`license`**: Software license (e.g. `MIT`, `GPL-2.0`).
- **`menu_locations`**: Associative map of menu locations supported by the theme.
