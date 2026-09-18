# Upgrading Favorite CMS Universal

This guide outlines the safe procedure for upgrading **Favorite CMS Universal** to a newer release line.

---

## 1. Pre-Upgrade Safety Rule

> [!IMPORTANT]
> **Always take a full backup before applying any update.**  
> Export your database via phpMyAdmin and create an archive of your `public/uploads/`, `.env`, `plugins/`, and `themes/` directories.

---

## 2. Upgrading via Distribution Archive

1. **Download the Latest Release**:
   - Download the official `Favorite-CMS-Universal.zip` from GitHub Releases.
2. **Extract to a Temporary Folder**:
   - Extract the ZIP locally or into a temporary directory on your server.
3. **Files to NEVER Overwrite**:
   - **DO NOT overwrite** `.env` (contains your database credentials).
   - **DO NOT overwrite** `public/uploads/` (contains your user media).
   - **DO NOT overwrite** `storage/` (contains your installation lock, logs, and sessions).
   - **DO NOT overwrite** custom themes or custom plugins in `themes/` and `plugins/`.
4. **Files to Replace / Overwrite**:
   - Replace `app/` with the new version.
   - Replace `config/` with the new version.
   - Replace `database/migrations/` with the new version.
   - Replace `public/assets/` and `public/index.php` with the new version.
   - Replace `resources/` with the new version.
   - Replace `vendor/` with the new version.
   - Replace `bootstrap.php` with the new version.
5. **Run Pending Database Migrations**:
   - Log into `/admin`. If the new version includes database schema updates, visit `/admin/tools` or the migration prompt to execute pending migrations automatically.
6. **Verify System Health**:
   - Review `/admin` dashboard status.
   - Clear browser cache and test creating a post or uploading an image.

