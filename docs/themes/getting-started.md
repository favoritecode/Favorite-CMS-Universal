# Getting Started with Theme Development

Themes control the presentation, styling, and responsive layout of your Favorite CMS website.

---

## 1. Creating a New Theme Directory

Navigate to `themes/` and create your new theme folder:
```
themes/minimalist/
```

---

## 2. Declare the Theme Manifest (`theme.json`)

Create `themes/minimalist/theme.json`:

```json
{
    "id": "minimalist",
    "name": "Minimalist Clean",
    "version": "1.0.0",
    "author": "Your Name",
    "description": "An ultra-clean, typography-first theme.",
    "license": "MIT"
}
```

---

## 3. Create Core Templates

At minimum, a theme provides:
1. `header.php` — Document head, branding, and navigation.
2. `footer.php` — Footer credits and script closing tags.
3. `index.php` — Post archive and homepage feed.
4. `single.php` — Article reading view.
5. `assets/css/style.css` — Visual styling.

---

## 4. Activate Your Theme

1. Log into `/admin`.
2. Go to **Appearance &rarr; Themes**.
3. Locate **Minimalist Clean** and click **Activate**.
4. Visit your site homepage to admire your new design!
