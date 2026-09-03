# Theme Development Guide

This guide walks you through building a custom, standards-compliant presentation theme for **Favorite CMS Universal**.

---

## 1. Creating the Manifest (`theme.json`)

Create `themes/my-theme/theme.json`:
```json
{
    "name": "My Custom Theme",
    "slug": "my-theme",
    "version": "1.0.0",
    "description": "A clean, modern blog theme with sidebar and footer widget regions.",
    "author": "Your Name",
    "author_url": "https://example.com",
    "regions": [
        {
            "id": "primary-sidebar",
            "name": "Primary Sidebar",
            "description": "Appears alongside blog articles."
        },
        {
            "id": "footer-1",
            "name": "Footer Column 1",
            "description": "Appears in the first footer column."
        },
        {
            "id": "footer-2",
            "name": "Footer Column 2",
            "description": "Appears in the second footer column."
        }
    ],
    "supports": [
        "widgets",
        "custom-logo",
        "post-thumbnails",
        "customizer-sidebar-layout",
        "customizer-colors"
    ]
}
```

---

## 2. Setting Up Theme Functions (`functions.php`)

`functions.php` is loaded automatically during application bootstrap when your theme is active:
```php
<?php

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    exit;
}

// 1. Register recommended default widgets for this theme
add_filter('theme_default_widgets', function (array $defaults): array {
    return [
        'primary-sidebar' => [
            ['widget' => 'search', 'title' => 'Search Website'],
            ['widget' => 'recent-posts', 'title' => 'Latest Stories', 'limit' => 5],
            ['widget' => 'categories', 'title' => 'Browse Categories'],
        ],
        'footer-1' => [
            ['widget' => 'pages', 'title' => 'Quick Links'],
        ],
        'footer-2' => [
            ['widget' => 'html', 'title' => 'About Us', 'content' => '<p>Built with Favorite CMS Universal.</p>'],
        ],
    ];
});
```

---

## 3. Rendering Header & Footer

### `header.php`
```php
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle ?? get_site_name(), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="<?php echo theme_asset('css/style.css'); ?>">
</head>
<body>
    <header class="site-header">
        <div class="container">
            <h1 class="logo"><a href="/"><?php echo htmlspecialchars(get_site_name(), ENT_QUOTES, 'UTF-8'); ?></a></h1>
            <nav class="main-nav">
                <ul>
                    <li><a href="/">Home</a></li>
                    <?php if (!empty($_SESSION['auth_user_id'])): ?>
                        <li><a href="/admin/posts/new">+ Create Post</a></li>
                        <li><a href="/admin">Dashboard</a></li>
                    <?php else: ?>
                        <li><a href="/admin/login">Log In</a></li>
                        <?php if ((int)\FavoriteCMS\Models\Setting::get('general', 'allow_registration', 1)): ?>
                            <li><a href="/register">Sign Up</a></li>
                        <?php endif; ?>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </header>
```

### `footer.php`
```php
    <footer class="site-footer">
        <div class="container footer-widgets">
            <?php if (has_region_widgets('footer-1')): ?>
                <div class="footer-col"><?php echo render_region('footer-1'); ?></div>
            <?php endif; ?>
            <?php if (has_region_widgets('footer-2')): ?>
                <div class="footer-col"><?php echo render_region('footer-2'); ?></div>
            <?php endif; ?>
        </div>
        <div class="footer-bottom">
            <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars(get_site_name(), ENT_QUOTES, 'UTF-8'); ?>. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>
```

---

## 4. Rendering Single Articles (`single.php`)

```php
<?php include 'header.php'; ?>

<div class="container main-content-layout">
    <main class="article-content">
        <article class="single-post">
            <h1 class="post-title"><?php echo htmlspecialchars($post->title, ENT_QUOTES, 'UTF-8'); ?></h1>
            <div class="post-meta">
                <span>By <?php echo htmlspecialchars($post->getAuthorName(), ENT_QUOTES, 'UTF-8'); ?></span> &bull;
                <time><?php echo date('F j, Y', strtotime($post->created_at)); ?></time>
            </div>

            <?php if ($post->featured_image_id && ($img = $post->getFeaturedImage())): ?>
                <div class="post-featured-image">
                    <img src="<?php echo htmlspecialchars($img->getUrl(), ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($post->title, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            <?php endif; ?>

            <div class="post-body">
                <?php echo apply_filters('the_content', $post->content); ?>
            </div>
        </article>
    </main>

    <?php if (get_theme_mod('sidebar_position', 'right') !== 'none' && has_region_widgets('primary-sidebar')): ?>
        <aside class="sidebar">
            <?php echo render_region('primary-sidebar'); ?>
        </aside>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
```

