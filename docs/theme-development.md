# Theme Development Guide

This guide is the complete reference for designing and developing themes for **Favorite CMS Universal v1.0.0**.

---

## 1. Theme Folder Location & Structure

All themes reside in the `themes/` directory under a unique, slugified folder name matching the theme's identifier:

```
themes/
└── <theme-id>/
    ├── theme.json            # [Required] Theme manifest
    ├── index.php             # [Required] Fallback template & blog feed
    ├── header.php            # Site header & opening markup
    ├── footer.php            # Site footer & closing markup
    ├── sidebar.php           # Primary sidebar widget region
    ├── single.php            # Single post template
    ├── page.php              # Static page template
    ├── archive.php           # Category, tag, and date archives
    ├── search.php            # Search results template
    ├── 404.php               # Not found error template
    ├── functions.php         # Optional theme bootstrap & custom helpers
    └── assets/               # Static assets
        ├── css/
        │   └── style.css
        ├── js/
        │   └── main.js
        └── images/
```

### The Two Strictly Required Files
To be recognized as a valid theme by `FavoriteCMS\Themes\ThemeManager`:
1. **`theme.json`**: Theme manifest.
2. **`index.php`**: Primary template entry point.

---

## 2. The Theme Manifest (`theme.json`)

The `theme.json` file declares theme metadata, navigation menu locations, widget regions, homepage sections, and recommended default widgets.

### Complete `theme.json` Example
```json
{
    "id": "my-theme",
    "name": "My Custom Theme",
    "version": "1.0.0",
    "author": "Your Name or Studio",
    "description": "A high-performance modern presentation theme for Favorite CMS Universal.",
    "license": "MIT",
    "menu_locations": {
        "primary": "Primary Header Navigation",
        "footer": "Footer Navigation Menu"
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
            "description": "First column in the multi-column footer."
        },
        {
            "id": "footer-2",
            "name": "Footer Column 2",
            "description": "Second column in the multi-column footer."
        }
    ],
    "sections": [
        {
            "id": "hero",
            "name": "Welcome Hero Banner",
            "description": "Top introductory headline and call to action.",
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
            {
                "widget": "search",
                "settings": {
                    "title": "Search Site",
                    "placeholder": "Type keywords..."
                }
            },
            {
                "widget": "recent_posts",
                "settings": {
                    "title": "Recent Stories",
                    "number": 5,
                    "show_date": true
                }
            }
        ],
        "footer-1": [
            {
                "widget": "custom_html",
                "settings": {
                    "title": "About Us",
                    "content": "<p>Welcome to our modern website powered by Favorite CMS.</p>"
                }
            }
        ]
    }
}
```

---

## 3. Template Hierarchy & Resolution

When a visitor requests a URL, `FavoriteCMS\Http\Controllers\FrontendController` resolves the template according to the following precedence hierarchy:

```
┌─────────────────┬────────────────────────────────────────────────────────┐
│ Request Type    │ Template Resolution Hierarchy                          │
├─────────────────┼────────────────────────────────────────────────────────┤
│ Single Post     │ single-{slug}.php  ──>  single.php  ──>  index.php     │
│ Static Page     │ page-{slug}.php    ──>  page.php    ──>  index.php     │
│ Category        │ category-{slug}.php ─>  category.php ─> archive.php ─> index.php │
│ Tag             │ tag-{slug}.php     ──>  tag.php      ─> archive.php ─> index.php │
│ Search Results  │ search.php         ──>  index.php                      │
│ 404 Not Found   │ 404.php            ──>  index.php                      │
│ Homepage / Feed │ index.php                                              │
└─────────────────┴────────────────────────────────────────────────────────┘
```

---

## 4. Theme Bootstrap (`functions.php`)

If `functions.php` exists in the active theme directory, `Kernel.php` includes it automatically during bootstrap before routes are dispatched:
- Register custom helper functions.
- Enqueue custom theme assets.
- Hook into core lifecycle events (`init`, `wp_head`, `wp_footer`).
- Register custom widgets via the `widgets_init` action hook.

```php
<?php
// themes/my-theme/functions.php

add_action('init', function($app) {
    // Custom theme initialization
});

add_action('wp_head', function() {
    echo '<meta name="theme-color" content="#1e293b">' . PHP_EOL;
});
```

---

## 5. Header & Footer Ownership

### `header.php`
Includes `<!DOCTYPE html>`, `<head>`, asset link tags, opening `<body>`, header navigation, and the `wp_head` hook:

```php
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html($meta_title ?? Setting::get('general', 'site_name', 'Favorite CMS')); ?></title>

    <!-- Theme Stylesheet (Automatic Cache Busting) -->
    <link rel="stylesheet" href="<?php echo esc_url(theme_asset_url('assets/css/style.css')); ?>">

    <!-- Core Head Hook (Required for SEO, Plugins, and Customizer) -->
    <?php do_action('wp_head'); ?>
</head>
<body class="<?php echo esc_attr($body_class ?? ''); ?>">

<header class="site-header">
    <div class="container header-inner">
        <a href="<?php echo esc_url(site_path('/')); ?>" class="brand-logo">
            <?php echo esc_html(Setting::get('general', 'site_name', 'Favorite CMS')); ?>
        </a>

        <?php if (has_nav_menu('primary')): ?>
            <nav class="main-navigation">
                <?php wp_nav_menu([
                    'location'   => 'primary',
                    'menu_class' => 'nav-links'
                ]); ?>
            </nav>
        <?php endif; ?>
    </div>
</header>
```

### `footer.php`
Closes main containers, renders footer widget columns, copyright notices, and the `wp_footer` hook:

```php
<footer class="site-footer">
    <div class="container footer-grid">
        <?php if (is_active_sidebar('footer-1')): ?>
            <div class="footer-col">
                <?php dynamic_sidebar('footer-1'); ?>
            </div>
        <?php endif; ?>

        <?php if (is_active_sidebar('footer-2')): ?>
            <div class="footer-col">
                <?php dynamic_sidebar('footer-2'); ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="container footer-bottom">
        <p>&copy; <?php echo date('Y'); ?> <?php echo esc_html(Setting::get('general', 'site_name', 'Favorite CMS')); ?>. All rights reserved.</p>
    </div>
</footer>

<!-- Core Footer Hook (Required for Scripts and Analytics) -->
<?php do_action('wp_footer'); ?>
</body>
</html>
```

---

## 6. Rendering Widgets & Sidebars

Themes render widget areas using standard helper functions:
- `is_active_sidebar(string $regionId): bool`: Verifies whether widgets are assigned to the region.
- `dynamic_sidebar(string $regionId): void`: Renders all active widgets in that region.

### `sidebar.php` Example
```php
<?php if (is_active_sidebar('sidebar-primary')): ?>
    <aside class="sidebar" role="complementary">
        <?php dynamic_sidebar('sidebar-primary'); ?>
    </aside>
<?php endif; ?>
```

---

## 7. Assets & URL Handling

Never hardcode relative paths like `href="assets/style.css"`, as they break in subdirectories or nested URLs like `/posts/my-article`.

Always use core asset helpers:
- **`theme_asset_url(string $assetPath): string`**: Returns the absolute URL to a theme asset with an automatic `?v={filemtime}` cache-busting timestamp appended.
  ```php
  <link rel="stylesheet" href="<?php echo esc_url(theme_asset_url('assets/css/style.css')); ?>">
  <script src="<?php echo esc_url(theme_asset_url('assets/js/main.js')); ?>" defer></script>
  ```
- **`site_path(string $path): string`**: Generates a portable, base-path aware internal URL.
  ```php
  <a href="<?php echo esc_url(site_path('/posts')); ?>">All Posts</a>
  ```

---

## 8. Escaping & Output Security Rules

Always escape dynamic output in theme templates to eliminate Cross-Site Scripting (XSS) risks:

| Function | Usage Context | Example |
|---|---|---|
| **`esc_html($string)`** | Text content inside HTML tags | `<p><?php echo esc_html($post->title); ?></p>` |
| **`esc_attr($string)`** | Inside HTML attribute values | `<img alt="<?php echo esc_attr($post->title); ?>">` |
| **`esc_url($url)`** | URLs in `href` or `src` attributes | `<a href="<?php echo esc_url($postUrl); ?>">` |

For post content (`$post->content`), use `the_content` filter:
```php
<div class="entry-content">
    <?php echo apply_filters('the_content', $post->content); ?>
</div>
```

---

## 9. Minimal Working Theme Example

Here is a complete, minimal, functional theme you can drop into `themes/minimal/`:

### File 1: `themes/minimal/theme.json`
```json
{
    "id": "minimal",
    "name": "Minimalist",
    "version": "1.0.0",
    "author": "Developer",
    "description": "Minimal single-file theme example.",
    "menu_locations": {
        "primary": "Main Navigation"
    },
    "regions": [
        {
            "id": "sidebar",
            "name": "Sidebar"
        }
    ]
}
```

### File 2: `themes/minimal/index.php`
```php
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo esc_html(Setting::get('general', 'site_name', 'Favorite CMS')); ?></title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 800px; margin: 40px auto; padding: 0 20px; line-height: 1.6; }
        header, footer { border-bottom: 1px solid #e2e8f0; padding-bottom: 20px; margin-bottom: 30px; }
        footer { border-top: 1px solid #e2e8f0; border-bottom: none; margin-top: 40px; padding-top: 20px; font-size: 0.9em; color: #64748b; }
        article { margin-bottom: 40px; }
        h1, h2 { color: #0f172a; }
        a { color: #2563eb; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
    <?php do_action('wp_head'); ?>
</head>
<body>

<header>
    <h1><a href="<?php echo esc_url(site_path('/')); ?>"><?php echo esc_html(Setting::get('general', 'site_name', 'Favorite CMS')); ?></a></h1>
    <?php if (has_nav_menu('primary')): ?>
        <nav><?php wp_nav_menu(['location' => 'primary']); ?></nav>
    <?php endif; ?>
</header>

<main>
    <?php if (!empty($posts)): ?>
        <?php foreach ($posts as $post): ?>
            <article>
                <h2><a href="<?php echo esc_url(site_path('/posts/' . $post->slug)); ?>"><?php echo esc_html($post->title); ?></a></h2>
                <div class="content"><?php echo apply_filters('the_content', $post->content); ?></div>
            </article>
        <?php endforeach; ?>
    <?php else: ?>
        <p>No published articles found.</p>
    <?php endif; ?>
</main>

<footer>
    <p>&copy; <?php echo date('Y'); ?> <?php echo esc_html(Setting::get('general', 'site_name', 'Favorite CMS')); ?></p>
</footer>

<?php do_action('wp_footer'); ?>
</body>
</html>
```
