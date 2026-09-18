# Shared Hosting Production Checklist

Follow this checklist when deploying Favorite CMS Universal to shared web hosting.

---

## 1. Pre-Deployment Checks

1. **PHP Version**: Verify that the hosting account runs PHP 8.1, 8.2, or 8.3 via cPanel "Select PHP Version".
2. **Extensions Enabled**: Ensure `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `zip`, and `json` are checked.
3. **Environment**: Ensure `APP_ENV=production` and `APP_DEBUG=false` in `.env`.
4. **Encryption Key**: Set a random 32-character string in `APP_KEY=`.

---

## 2. File Placement & Permissions

- Place application core files outside `public_html/`.
- Set `storage/` permissions to `0775` or `0755`.
- Ensure `public_html/uploads/` is writable by the web server.

---

## 3. HTTPS Configuration

Ensure SSL is active via Let's Encrypt / AutoSSL, and enforce HTTPS in `public_html/.htaccess`:

```apache
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```
