# Core Widget Architecture & Layout Guide

Favorite CMS Universal features a modern, modular Widget and Layout system (`/admin/widgets`) allowing site administrators to customize their website's layout and content composition without editing PHP code.

---

## 1. Core Concepts: Widgets & Regions

- **Widget**: A reusable content block with its own configurable settings and frontend rendering template (e.g. Recent Posts, Search bar, Navigation Menu, Custom HTML).
- **Region**: A designated visual area declared by the active theme where widgets can be placed (e.g. Primary Sidebar, Header Right, Footer Column 1, Footer Column 2, Footer Column 3).
- **Instance**: A specific configured copy of a widget with its own unique title and options. Multiple instances of any widget can be added across the same or different regions.

---

## 2. Built-In Widgets

Favorite CMS Universal includes 10 built-in widgets ready for immediate use:

| Widget Name | Slug | Purpose & Features |
|---|---|---|
| **Search** | `search` | Instant search form with custom placeholder and optional label. |
| **Recent Posts** | `recent-posts` | Lists recent articles with post counts (1–20), thumbnail toggles, and publication dates. |
| **Categories** | `categories` | Categorized post listing with hierarchy, post counts, and empty category filtering. |
| **Tags Cloud** | `tags` | Visual keyword tag cloud with configurable maximum item counts. |
| **Navigation Menu** | `navigation-menu` | Displays any CMS-created navigation menu in sidebars or footers. |
| **Pages** | `pages` | Clean list of published static pages with page hierarchy support. |
| **Custom HTML** | `html` | Raw HTML/JavaScript container for embed codes, maps, ads, and analytics widgets. |
| **Image** | `image` | Standalone graphic banner or profile photo with link URL and caption. |
| **Featured Post** | `featured-post` | Spotlights a specific chosen article with its excerpt and hero thumbnail. |
| **Recent Comments** | `recent-comments` | Shows community discussion with author avatars and date stamps. |

---

## 3. Managing Widgets (`/admin/widgets`)

1. Navigate to **Appearance** &rarr; **Widgets**.
2. **Available Widgets Panel**: Displays all core and plugin-registered widgets.
3. **Region Panels**: The right area displays all regions registered by the active theme (e.g. `Primary Sidebar`, `Footer Column 1`, `Footer Column 2`).
4. **Adding a Widget**:
   - Drag a widget from the left column into your chosen region, OR
   - Click the widget, select your target region from the dropdown, and click **Add Widget**.
5. **Configuring Options**:
   - Click a widget card to expand its settings form.
   - Enter a custom title and widget-specific options (e.g. Number of posts to show).
   - Click **Save**.
6. **Reordering & Moving**:
   - Drag widget cards up or down within a region to change their vertical display order.
   - Drag widget cards across different regions to relocate them.
7. **Duplicating**:
   - Click **Duplicate** on any widget to create an exact copy with identical settings.
8. **Removing**:
   - Click **Delete** to remove the widget instance from the region.

---

## 4. One-Click Theme Defaults

Every compliant Favorite CMS theme provides a recommended default widget configuration for its regions.

If you ever wish to restore the theme's original sidebar and footer presentation:
1. Navigate to **Appearance** &rarr; **Widgets**.
2. Click the **Reset to Theme Defaults** button in the top toolbar.
3. Confirm the dialog. The CMS will automatically re-seed the theme's recommended widget configuration.

