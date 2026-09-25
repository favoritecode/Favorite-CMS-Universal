# Themes and Customization Guide

This guide explains how to manage, customize, and configure presentation themes in Favorite CMS Universal v1.0.0.

---

## Themes Overview (`/admin/themes`)

Themes control the visual appearance, typography, layout, and responsiveness of the public-facing website.
- **Installed Themes:** Located in the `themes/` directory. Each theme is self-contained with its own templates, assets, and `theme.json` manifest.
- **Active Theme:** The theme currently serving public requests (stored in `Setting::get('theme', 'active_theme', 'default')`).
- **Activation Safety:** When an administrator activates a new theme, `ThemeManager` verifies required templates (`index.php`), automatically seeds recommended default widget arrangements, and preserves previous configurations in case of rollback.

---

## The Visual Theme Customizer (`/admin/customize`)

The Theme Customizer gives administrators real-time controls over site styling without touching code:

### 1. Layout Orientation
- **Right Sidebar:** Traditional content column with right-hand widget sidebar.
- **Left Sidebar:** Left-hand navigation and widget sidebar.
- **Full Width:** Clean, modern full-width layout without sidebars.

### 2. Branding & Colors
- **Logo Upload:** Set the site branding logo displayed in the header.
- **Favicon:** Upload browser tab icon.
- **Brand Colors:** Choose primary theme accent color, background color, and header background.

### 3. Homepage Sections
Themes supporting modular homepages allow administrators to toggle and reorder front-page sections:
- **Hero Banner:** Introductory welcome message, call-to-action button, and headline.
- **Featured Stories Showcase:** Highlight sticky or selected featured articles.
- **Latest Articles Feed:** Dynamic blog article stream with pagination.

---

## Widgets Management (`/admin/widgets`)

Themes define widget regions (such as `sidebar-primary`, `footer-1`, `footer-2`, `footer-3`, `header-right`).

### The 10 Core Widgets
1. **Search:** Search input box allowing visitors to find articles.
2. **Recent Posts:** Displays the latest published articles with optional dates and thumbnails.
3. **Categories:** Lists post categories with optional post counts.
4. **Tags:** Popular tag cloud with configurable tag limits.
5. **Navigation Menu:** Renders any custom navigation menu inside a widget area.
6. **Pages:** Lists site pages in hierarchical or alphabetical order.
7. **Custom HTML:** Arbitrary HTML, embed codes, or custom markup.
8. **Image:** Displays a custom image linked to any destination URL.
9. **Featured Post:** Highlights a specific article with excerpt and call-to-action.
10. **Recent Comments:** Lists recent approved reader comments.

### 1-Click Reset to Theme Defaults
If you wish to restore the widget layout recommended by the theme author, click **Reset to Defaults**. The system re-applies the default widget configurations declared in the theme's `theme.json` manifest.
