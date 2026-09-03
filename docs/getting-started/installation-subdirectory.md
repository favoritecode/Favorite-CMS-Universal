# Subdirectory Installation Guide

Favorite CMS Universal includes built-in base path resolution designed to work seamlessly in subdirectories (such as `example.com/blog/`, `example.com/news/`, or `localhost/favorite-cms/`).

---

## 1. Why Subdirectory Support Matters

Many websites host an existing marketing site, HTML landing page, or separate application on their root domain, while hosting their blog or publication inside a nested folder:
```
https://example.com/              <-- Main Website
https://example.com/blog/         <-- Favorite CMS Universal
```

Favorite CMS Universal automatically detects the nested directory from `$_SERVER['SCRIPT_NAME']` and `$_SERVER['REQUEST_URI']`, prefixing all admin routes, public post URLs, media links, and installer redirects without requiring hardcoded manual configuration.

---

## 2. Directory Placement

1. On your shared host or local server, create the target folder inside your document root:
   ```bash
   public_html/
   └── blog/                       # Target subdirectory
   ```
2. Upload and extract `Favorite-CMS-Universal.zip` directly inside `public_html/blog/`:
   ```
   public_html/blog/
   ├── app/
   ├── config/
   ├── database/
   ├── index.php
   ├── .htaccess
   ├── public/
   ├── resources/
   ├── storage/
   ├── themes/
   └── vendor/
   ```

---

## 3. Web Server & `.htaccess` Rules

The root `.htaccess` inside the subdirectory handles clean routing for the subdirectory. It detects requests and forwards them to `index.php` while preserving directory boundaries:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /blog/

    # Direct static assets from public/ folder if needed
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ index.php [L,QSA]
</IfModule>
```

> **Automatic RewriteBase**: In most standard Apache configurations, `RewriteBase` is not strictly needed because Apache computes it relative to the `.htaccess` file location. If your host requires an explicit base, ensure `RewriteBase /your-subdirectory/` is set.

---

## 4. Running the Web Wizard

1. Open your browser and navigate to:
   ```text
   https://example.com/blog/
   ```
2. **URL Auto-Detection**:
   - The installer inspects the request and sets:
     - **Base Path**: `/blog`
     - **Detected Site URL**: `https://example.com/blog/`
3. Complete the standard database and admin account steps.
4. When installation completes:
   - Frontend homepage: `https://example.com/blog/`
   - Posts: `https://example.com/blog/post/hello-world`
   - Admin panel: `https://example.com/blog/admin`
   - User registration: `https://example.com/blog/register`

---

## 5. Troubleshooting Subdirectory Setups

- **404 on Sub-pages (e.g. `/blog/post/slug`)**:
  - Ensure the parent directory's `.htaccess` (at `public_html/.htaccess`) does not interfere with the subdirectory. Adding `RewriteCond %{REQUEST_URI} !^/blog/` to the parent `.htaccess` resolves root conflicts.
- **Missing CSS / Images**:
  - Inspect the page source to confirm asset links start with `/blog/themes/...` or `/blog/uploads/...`.
  - Check that the `site_url` setting in **Admin &rarr; Settings &rarr; General** matches `https://example.com/blog`.

