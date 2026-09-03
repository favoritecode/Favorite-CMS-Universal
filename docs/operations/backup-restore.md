# Backup & Disaster Recovery Guide

Performing regular backups ensures you can restore your website immediately in the event of hardware failure, hosting issues, or accidental content deletion.

---

## 1. What Needs to Be Backed Up?

A complete backup of **Favorite CMS Universal** consists of two components:

1. **The Database (MySQL / MariaDB)**:
   - Contains all posts, pages, categories, tags, comments, user accounts, settings, widget configurations, and permissions.
2. **The Media & Configuration Files**:
   - `public/uploads/`: All uploaded images, videos, audio files, and documents.
   - `.env`: Database credentials and encryption keys.
   - `plugins/`: Any custom or third-party installed plugins.
   - `themes/`: Any custom or modified themes.

---

## 2. Backing Up the Database

### Method A: Using phpMyAdmin (cPanel / Shared Hosting)
1. Log into your hosting control panel and open **phpMyAdmin**.
2. Select your Favorite CMS database from the left navigation tree.
3. Click the **Export** tab in the top menu.
4. Choose **Quick** export method and format **SQL**.
5. Click **Export** to download the `.sql` backup file to your computer.

### Method B: Using MySQL Command Line (`mysqldump`)
```bash
mysqldump -u your_db_user -p your_db_name > backup_favorite_cms_$(date +%Y%m%d).sql
```

---

## 3. Backing Up Application Files

### Method A: Using Hosting File Manager
1. In cPanel File Manager, navigate to your site's root directory.
2. Select all folders or specifically `public/uploads/`, `.env`, `plugins/`, and `themes/`.
3. Click **Compress** &rarr; **Zip Archive**.
4. Download the generated `.zip` file to your computer.

### Method B: Using SSH / Terminal
```bash
tar -czf site_files_backup_$(date +%Y%m%d).tar.gz public/uploads .env plugins themes
```

---

## 4. Restoring Your Website

To restore your site from a backup:
1. **Restore Files**:
   - Extract your files backup into your webroot directory.
2. **Restore Database**:
   - Open phpMyAdmin, select your database (or create a clean empty database).
   - Click the **Import** tab.
   - Choose your `.sql` backup file and click **Import**.
3. **Verify Configuration**:
   - Ensure the `.env` file contains the correct database name, username, and password.
4. **Test Live Site**:
   - Open your site in a browser and verify that articles, images, and admin login operate normally.

