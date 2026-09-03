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
    },
    "regions": [
        {
            "id": "sidebar-primary",
            "name": "Primary Sidebar",
            "description": "Main sidebar displayed beside post and page content."
        },
        {
            "id": "footer-1",
            "name": "Footer Column 1",
            "description": "First column in site footer."
        }
    ],
    "sections": [
        {
            "id": "hero",
            "name": "Welcome Hero Banner",
            "description": "Top introductory welcome headline and tagline.",
            "enabled": true
        },
        {
            "id": "latest-posts",
            "name": "Latest Articles Feed",
            "description": "Standard blog article stream with pagination.",
            "enabled": true
        }
    ],
    "default_widgets": {
        "sidebar-primary": [
            { "widget": "search", "settings": { "title": "Search Articles" } },
            { "widget": "recent_posts", "settings": { "title": "Recent Articles", "number": 5 } }
        ]
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
- **`regions`**: Array of layout regions where administrators can place widgets.
- **`sections`**: Array of structural homepage sections that can be reordered or toggled.
- **`default_widgets`**: Initial widget placements seeded when the theme is first activated.

