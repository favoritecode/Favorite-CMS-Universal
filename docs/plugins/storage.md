# File Storage & Assets in Plugins

How plugins manage static assets (CSS/JS) and file storage.

---

## 1. Serving Plugin Static Assets

Place static assets in `plugins/{plugin-id}/assets/`:
```
plugins/my-plugin/assets/
├── css/style.css
└── js/widget.js
```

These assets are automatically deliverable by the web server or routed through `Kernel::serveStaticAsset()` at:
```html
<link rel="stylesheet" href="/plugins/my-plugin/assets/css/style.css">
<script src="/plugins/my-plugin/assets/js/widget.js" defer></script>
```

---

## 2. File Uploads

When your plugin accepts file uploads:
1. Always verify the MIME type with `finfo_file()`.
2. Generate an unpredictable filename (e.g. `bin2hex(random_bytes(16)) . '.png'`).
3. Store uploads inside `public/uploads/{plugin-id}/`.
4. Disallow executable PHP file extensions (`.php`, `.phtml`, `.phar`).
