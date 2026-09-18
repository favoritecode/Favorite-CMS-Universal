# Theme Components

Reusable modular components keep theme templates clean and DRY (Don't Repeat Yourself).

---

## 1. Header Component (`header.php`)

Contains the opening HTML document structure, `<head>` metadata, stylesheet links, site branding, navigation menu, and mobile toggle:

```php
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle ?? $siteName ?? 'Favorite CMS', ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/themes/<?php echo htmlspecialchars($themeName ?? 'default'); ?>/assets/css/style.css">
</head>
<body>
<header class="site-header">
    <div class="header-container">
        <a href="/" class="site-branding"><?php echo htmlspecialchars($siteName ?? 'Favorite CMS'); ?></a>
        <nav class="main-nav">
            <!-- Navigation items -->
        </nav>
    </div>
</header>
<div class="site-content">
```

---

## 2. Footer Component (`footer.php`)

Closes the container tags, outputs site copyright, and includes deferred JavaScript:

```php
</div><!-- /.site-content -->
<footer class="site-footer">
    <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($siteName ?? 'Favorite CMS'); ?>. Powered by Favorite CMS.</p>
</footer>
<script src="/themes/<?php echo htmlspecialchars($themeName ?? 'default'); ?>/assets/js/main.js" defer></script>
</body>
</html>
```

---

## 3. Sidebar Component (`sidebar.php`)

Renders widgets like quick search, recent articles, category chips, and tag clouds.
Included inside templates via `require __DIR__ . '/sidebar.php';`.
