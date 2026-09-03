# Plugin Development Guide

This guide walks you through building a complete, production-ready plugin for **Favorite CMS Universal** from scratch.

---

## 1. Directory & File Structure

Every plugin resides in its own folder inside `plugins/`:
```
plugins/
└── my-custom-plugin/
    ├── plugin.json        # Required: Manifest declaring metadata
    ├── plugin.php         # Required: Main entrypoint file
    ├── src/               # Optional: PSR-4 or class files
    │   ├── Controller.php
    │   └── Model.php
    └── views/             # Optional: Plugin templates
        └── admin.php
```

---

## 2. Step 1: Create the Manifest (`plugin.json`)

Create `plugins/my-custom-plugin/plugin.json`:
```json
{
    "name": "My Custom Plugin",
    "slug": "my-custom-plugin",
    "version": "1.0.0",
    "description": "Adds specialized business logic and custom frontend endpoints.",
    "author": "Your Name",
    "author_url": "https://example.com",
    "main": "plugin.php",
    "requires_core": "^1.0.0",
    "capabilities": [
        "manage_custom_plugin"
    ]
}
```

---

## 3. Step 2: Implement the Entrypoint (`plugin.php`)

Create `plugins/my-custom-plugin/plugin.php`:
```php
<?php

declare(strict_types=1);

// Prevent direct file access
if (!defined('APP_ROOT')) {
    exit;
}

use FavoriteCMS\Core\Hook;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;

// 1. Hook into application initialization
Hook::addAction('init', function () {
    // Register a custom public frontend route
    add_route('/my-feature', function (Request $request): Response {
        $html = "<h1>Welcome to My Feature</h1><p>Powered by Favorite CMS Universal.</p>";
        return Response::make($html, 200);
    });
});

// 2. Register an Admin Menu Page
Hook::addAction('admin_menu', function () {
    add_admin_menu(
        title: 'My Plugin Settings',
        slug: 'my-plugin-settings',
        icon: '⚡',
        capability: 'manage_options',
        handler: function (Request $request): Response {
            return Response::make("<h2>My Plugin Settings Page</h2><p>Configure options here.</p>", 200);
        }
    );
});

// 3. Register a Content Filter
Hook::addFilter('the_content', function (string $content): string {
    // Append a custom notice to every published post
    return $content . '<p class="plugin-notice"><em>Thanks for reading!</em></p>';
});
```

---

## 4. Step 3: Activating the Plugin

1. Log into your site administrator panel (`/admin`).
2. Navigate to **Plugins** in the sidebar.
3. Locate **My Custom Plugin** in the list.
4. Click **Activate**.
5. Your custom route (`/my-feature`) and admin menu are now live!

