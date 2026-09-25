# Troubleshooting Guide

This guide provides practical solutions for common installation, hosting, and runtime issues in **Favorite CMS Universal v1.0.0**, based on the actual implementation.

---

## 1. Installer Issues

### "Installer Not Loading / Redirect Loop"
- **Symptom:** Navigating to `/` does not redirect to `/install`, or visiting `/install` redirects immediately back to `/`.
- **Cause:** The installer detects an existing installation lockfile at `storage/installed.lock` or an existing `.env` file with configured database settings.
- **Solution:**
  - If you intend to perform a fresh re-installation, remove `storage/installed.lock` and `.env`.
  - If the site is already installed, access the admin panel at `/admin/login`.

### "Application root is not writable for .env"
- **Symptom:** Step 2 of the installer shows a red checkmark on "application root for .env".
- **Cause:** PHP process user (`www-data`, `nobody`, `apache`) lacks write permissions to the application directory to create the `.env` file.
- **Solution:** Temporarily grant write permissions to the root directory (`chmod 775 .` or `chmod 777 .`), complete the installer, and then revert permissions to `755`.

---

## 2. Database Connection Errors

### "Access denied for user 'username'@'localhost'"
- **Cause:** Incorrect MySQL credentials or the user does not have permission to access the specified database.
- **Solution:**
  - Verify your database name, username, and password in phpMyAdmin or cPanel MySQL manager.
  - In cPanel, verify that you clicked **Add User to Database** and checked **ALL PRIVILEGES**.

### "Unknown database 'database_name'"
- **Cause:** The database specified in the installer has not been created yet.
- **Solution:** Create an empty MySQL database in your hosting panel before running the installer.

### "Can't connect to MySQL server / Connection timed out"
- **Cause:** Database server is running on a non-standard port or socket, or remote MySQL connections are blocked.
- **Solution:** Use `127.0.0.1` instead of `localhost` or switch to **Custom Database Mode** in the installer to specify the exact port (e.g. `3306`) or socket.

---

## 3. Blank Screen or HTTP 500 Internal Server Error

### Inspect the Error Log
Favorite CMS Universal logs runtime exceptions to:
```
storage/logs/favorite_cms.log
```
Check this file first to view the exact PHP stack trace.

### Common 500 Causes:
1. **Missing PHP Extensions:** Ensure `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `session`, and `json` are enabled.
2. **Missing `vendor/autoload.php`:** Verify that the `vendor/` directory was fully extracted from the release package.
3. **File Permission Issues:** Ensure the `storage/` directory and all its subdirectories (`cache/`, `logs/`, `sessions/`, `backups/`, `temp/`) are writable (`755` or `775`).

---

## 4. URL Routing & 404 Errors on Admin Links

### "404 Not Found When Clicking Links"
- **Symptom:** Homepage loads, but visiting `/admin`, `/posts`, or `/login` returns a server 404 error.
- **Cause:** Apache `mod_rewrite` is disabled, or Apache is ignoring the `.htaccess` file because `AllowOverride` is set to `None`.
- **Solution:**
  1. Confirm that `mod_rewrite` is active: `sudo a2enmod rewrite`.
  2. In your Apache configuration file (`httpd.conf` or virtual host configuration), set:
     ```apache
     <Directory "/path/to/favorite-cms">
         AllowOverride All
         Require all granted
     </Directory>
     ```
  3. Restart Apache.

---

## 5. Media Upload Failures

### "The uploaded file exceeds the upload_max_filesize directive in php.ini"
- **Cause:** The file exceeds the server's PHP upload limit.
- **Solution:** Increase `upload_max_filesize` and `post_max_size` in your `php.ini` or `.user.ini` file:
  ```ini
  upload_max_filesize = 64M
  post_max_size = 64M
  ```

### "HTTP 413 Payload Too Large"
- **Cause:** Form submission or upload payload exceeded `post_max_size`. Favorite CMS rejects oversized requests before PHP truncates input data to protect against silent data loss.
- **Solution:** Increase `post_max_size` to match or exceed your expected file payload.

### "Invalid File Type or Executable Shield Triggered"
- **Cause:** Favorite CMS blocks executable extensions (`.php`, `.exe`, `.phtml`) and disguised double extensions (`.php.jpg`).
- **Solution:** Upload only standard images, videos, audio files, PDFs, or archives.

---

## 6. Plugin & Theme Activation Issues

### "Cannot activate plugin: Requires PHP X.X"
- **Cause:** The plugin's `plugin.json` specifies a `requires_php` higher than your server's current PHP version.
- **Solution:** Upgrade your server's PHP version to match or exceed the requirement.

### "Theme is invalid: missing required index.php template"
- **Cause:** The activated theme folder is missing the mandatory `index.php` template file.
- **Solution:** Ensure the theme includes both `theme.json` and `index.php` in its root directory.

---

## 7. Session & CSRF Errors

### "CSRF Token Validation Failed (403)"
- **Cause:** Session expired while a form was open in the browser, or multiple browser tabs overwrote the active session token.
- **Solution:** Refresh the page to obtain a fresh CSRF token and resubmit the form.

### "Session Logged Out Immediately Upon Password Reset"
- **Note:** This is expected security behavior! Whenever an account's password or status changes, `auth_version` increments, terminating all existing active sessions to protect the account against hijacking.
