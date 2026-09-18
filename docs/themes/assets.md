# Static Assets in Themes (CSS & JS)

Managing stylesheets, scripts, and visual assets in Favorite CMS themes.

---

## 1. Asset Placement

Store theme assets inside `themes/{theme-name}/assets/`:
```
themes/default/assets/
├── css/style.css
├── js/main.js
└── images/logo.svg
```

---

## 2. Linking Assets in Templates

Always reference assets with absolute web paths starting with `/themes/{theme-name}/`:

```html
<link rel="stylesheet" href="/themes/default/assets/css/style.css">
<script src="/themes/default/assets/js/main.js" defer></script>
```

---

## 3. CSS Custom Properties (Design Tokens)

Using CSS variables makes theming and dark-mode adaptation straightforward:

```css
:root {
    --color-primary: #1d4ed8;
    --color-ink: #0f172a;
    --color-body: #334155;
    --color-bg: #f8fafc;
    --color-surface: #ffffff;
    --color-border: #e2e8f0;
    --radius-sm: 4px;
    --radius-md: 8px;
}
```
