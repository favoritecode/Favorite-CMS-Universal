# Menus and Navigation Guide

This guide describes how to manage navigation menus and bind them to theme locations in Favorite CMS Universal v1.0.0.

---

## Menu System Overview

Favorite CMS Universal features a flexible menu builder allowing administrators to construct multi-level navigation trees and assign them to theme-registered regions.

### Menu Item Types
- **Pages:** Direct links to published static pages (e.g. Home, About Us, Contact).
- **Custom Links:** External or arbitrary URLs (e.g. `https://github.com/favoritecode`, `/client-portal`).
- **Categories:** Links to category archive listings (e.g. `/category/news`).
- **Posts:** Links directly to featured articles.

---

## Managing Menus (`/admin/menus`)

### 1. Creating a New Menu
1. In the admin panel, navigate to **Appearance &rarr; Menus**.
2. Click **Create Menu**.
3. Enter a descriptive **Menu Name** (e.g. *Main Header Navigation*).
4. Click **Create Menu**.

### 2. Adding & Arranging Menu Items
1. In the left panel, select items from **Pages**, **Categories**, or **Custom Links**.
2. Click **Add to Menu**.
3. Drag items vertically to adjust display order.
4. Drag items slightly to the right to create **Sub-items (Dropdowns)**.
5. Expand any item to edit its **Navigation Label** or **Target** (`_blank` for opening in a new tab).
6. Click **Save Menu**.

---

## Theme Menu Locations

Themes declare supported navigation slots in their `theme.json` manifest:
```json
{
    "menu_locations": {
        "primary": "Primary Header Navigation",
        "footer": "Footer Menu"
    }
}
```

Under **Menu Settings** &rarr; **Display Location**, check the location where the active menu should appear and click **Save Menu**.

---

## Frontend Theme Integration

Theme templates render registered menus using standard core helper functions:

```php
<?php if (has_nav_menu('primary')): ?>
    <nav class="site-nav">
        <?php wp_nav_menu([
            'location'        => 'primary',
            'container_class' => 'main-menu-container',
            'menu_class'      => 'nav-list',
            'depth'           => 2,
        ]); ?>
    </nav>
<?php endif; ?>
```
