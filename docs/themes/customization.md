# Theme Customization & Site Branding

How themes adapt to dynamic administrator settings.

---

## 1. Dynamic Site Identity

Read site identity using `FavoriteCMS\Models\Setting`:

```php
$siteName = \FavoriteCMS\Models\Setting::get('general', 'site_name', 'Favorite CMS');
$siteTagline = \FavoriteCMS\Models\Setting::get('general', 'site_description', '');
```

---

## 2. Dynamic Navigation Menus

Themes can query active navigation items:
```php
$menuItems = \FavoriteCMS\Models\Setting::get('theme', 'primary_nav_items', []);
```

---

## 3. SEO Meta Injection

Themes should include basic SEO meta tags in `header.php`:
```html
<meta name="description" content="<?php echo htmlspecialchars($metaDescription ?? $siteTagline ?? '', ENT_QUOTES, 'UTF-8'); ?>">
<meta property="og:title" content="<?php echo htmlspecialchars($pageTitle ?? $siteName, ENT_QUOTES, 'UTF-8'); ?>">
<meta property="og:type" content="<?php echo isset($post) ? 'article' : 'website'; ?>">
```
