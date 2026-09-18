# Production Deployment Security & Hardening

This checklist outlines the recommended security hardening practices when deploying **Favorite CMS Universal** to production shared hosting or dedicated servers.

---

## 1. Writable Directories & Permissions

Enforce strict least-privilege file permissions:

| Directory / File | Recommended Mode | Rationale |
|---|---|---|
| **Standard PHP files** (`.php`) | `0644` (rw-r--r--) | Non-writable by the web server; prevents automated file tampering. |
| **Standard Directories** | `0755` (rwxr-xr-x) | Standard read/executable directory access. |
| **`storage/`** | `0775` (rwxrwxr-x) | Writable by PHP process for logs, cache, sessions, and `installed.lock`. |
| **`public/uploads/`** | `0775` (rwxrwxr-x) | Writable by PHP process for user-uploaded media files. |
| **`.env` file** | `0600` (rw-------) or `0640` | Contains database passwords and application keys. Must not be readable by other users. |

---

## 2. HTTPS & SSL/TLS Configuration

1. **Enforce HTTPS**:
   - Obtain a free SSL certificate via Let's Encrypt / AutoSSL in your hosting control panel.
   - Redirect all incoming HTTP traffic to HTTPS via your root `.htaccess`:
     ```apache
     <IfModule mod_rewrite.c>
         RewriteEngine On
         RewriteCond %{HTTPS} off
         RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
     </IfModule>
     ```
2. **Security Headers**:
   - Add recommended HTTP security headers to your server configuration or root `.htaccess`:
     ```apache
     <IfModule mod_headers.c>
         Header always set X-Content-Type-Options "nosniff"
         Header always set X-Frame-Options "SAMEORIGIN"
         Header always set X-XSS-Protection "1; mode=block"
         Header always set Referrer-Policy "strict-origin-when-cross-origin"
     </IfModule>
     ```

---

## 3. Disabling Directory Indexing

Ensure that directory listing is disabled so visitors cannot browse folder contents directly:
```apache
Options -Indexes
```
Favorite CMS includes this directive in its root `.htaccess`.

---

## 4. Database Security

1. **Use a Dedicated Database User**:
   - Do not connect Favorite CMS using the MySQL `root` or `admin` superuser.
   - Create a dedicated user (e.g. `site_cms_user`) with permissions limited strictly to the Favorite CMS database.
2. **Table Prefix**:
   - Use a unique table prefix (e.g. `fcms_` or `mysite_`) rather than a generic prefix.

