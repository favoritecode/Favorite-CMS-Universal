# Troubleshooting Guide

This guide provides practical solutions to the most common setup, hosting, upload, and database issues encountered when running **Favorite CMS Universal**.

---

## 1. Installation & Environment Issues

### Issue 1: "The installer redirects to the homepage immediately"
- **Cause**: The CMS detects an existing `storage/installed.lock` file.
- **Solution**:
  - If you intend to perform a fresh clean install, delete `storage/installed.lock` and drop any existing tables in your database.
  - If you are already installed, access `/admin/login` directly instead of `/install`.

### Issue 2: "Database connection failed (SQLSTATE[HY000] [2002])"
- **Cause**: MySQL server is offline or host/port configuration is incorrect.
- **Solution**:
  - On local XAMPP: Ensure MySQL is running in the XAMPP Control Panel.
  - On shared hosting: Confirm your database host. Many hosts use `localhost`, but some (e.g. DreamHost, SiteGround) use specific hostnames like `mysql.example.com`.
  - Verify that the database user has been granted permissions to the database.

### Issue 3: "Automatic database creation failed"
- **Cause**: Standard shared hosting MySQL accounts do not possess `CREATE DATABASE` or `GRANT` superuser privileges.
- **Solution**:
  - Switch to **Manual Database Setup** in Step 2 of the installer.
  - Create the database in your cPanel **MySQL Databases** interface first, then provide those credentials to the installer.

---

## 2. Web Server & Routing Issues

### Issue 4: "404 Not Found on internal pages (e.g. `/post/slug` or `/admin`)"
- **Cause**: Apache `mod_rewrite` is disabled or `.htaccess` is missing / ignored.
- **Solution**:
  - Ensure `.htaccess` exists in your webroot.
  - On local Apache (`httpd.conf`): Enable `LoadModule rewrite_module modules/mod_rewrite.so` and verify `AllowOverride All` is set on your document root directory.

### Issue 5: "CSS, JS, or images fail to load (Broken Styles)"
- **Cause**: Base path mismatch or incorrect `site_url`.
- **Solution**:
  - Check your browser developer console (F12) to see the failed asset URLs.
  - If running in a subdirectory (`example.com/blog/`), ensure you access the site via the full path and that `site_url` in **Admin &rarr; Settings &rarr; General** includes the subdirectory.

---

## 3. Upload Issues

### Issue 6: "Upload failed: File exceeds upload_max_filesize"
- **Cause**: The file size exceeds your host's PHP settings, regardless of CMS role settings.
- **Solution**:
  - Favorite CMS allows configuring limits up to 7 GB for Admins, but PHP must be configured to permit it.
  - In cPanel, navigate to **Select PHP Version** &rarr; **Options** (or edit `php.ini`):
    ```ini
    upload_max_filesize = 128M
    post_max_size = 128M
    memory_limit = 256M
    ```

### Issue 7: "413 Payload Too Large"
- **Cause**: The submitted POST payload exceeds the server's `post_max_size`.
- **Solution**:
  - Favorite CMS detects `post_max_size` overflow early and returns a 413 error rather than silently losing content. Increase `post_max_size` in your hosting PHP settings.

---

## 4. Authentication & User Issues

### Issue 8: "Invalid security token. Please try again."
- **Cause**: CSRF token mismatch due to an expired session or cookie blocked by browser privacy settings.
- **Solution**:
  - Refresh the login page and re-submit.
  - Ensure browser accepts first-party cookies.
  - Verify that `storage/sessions/` is writable by PHP.

### Issue 9: "Your account has been permanently banned."
- **Cause**: An administrator updated your user account status to `banned`.
- **Solution**:
  - Contact the website administrator to review and restore your account status in **Admin &rarr; Users**.

