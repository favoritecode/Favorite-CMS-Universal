# Troubleshooting Common Issues

Quick solutions for common operational and development issues.

---

## 1. Blank Screen or White Page

- **Cause**: PHP fatal error with `display_errors` turned off.
- **Solution**:
  1. Open `storage/logs/favorite_cms.log` to read the exact PHP stack trace.
  2. In local development, ensure `APP_DEBUG=true` in `.env`.
  3. Ensure all files in `storage/` are writable (`0775`).

---

## 2. 404 Errors on Clean URLs (e.g. `/post/hello-world` returns Apache 404)

- **Cause**: Apache `mod_rewrite` is disabled or `.htaccess` is ignored.
- **Solution**:
  1. In `httpd.conf`, verify that `LoadModule rewrite_module modules/mod_rewrite.so` is uncommented.
  2. Verify that `AllowOverride All` is set on the DocumentRoot directory.
  3. Ensure `public/.htaccess` exists.

---

## 3. The Installer Appears Unexpectedly

- **Cause**: `storage/installed.lock` is missing AND the database cannot be contacted.
- **Solution**:
  1. Verify MySQL is running in XAMPP or cPanel.
  2. Check your `.env` credentials (`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).
  3. As long as your database contains the `users` and `settings` tables with a valid admin user, the CMS will auto-heal and regenerate `storage/installed.lock` automatically.

---

## 4. Class Not Found / Autoload Errors

- **Cause**: Composer autoload classmap is outdated after creating new classes.
- **Solution**:
  Run:
  ```bash
  composer dump-autoload -o
  ```

---

## 5. Plugin Activation Fails

- **Cause**: The plugin has an unhandled syntax error, requires a higher PHP version, or is missing a dependency.
- **Solution**:
  Check `storage/logs/favorite_cms.log` for the exact error message. Because of failure isolation, the rest of your CMS remains fully operational.
