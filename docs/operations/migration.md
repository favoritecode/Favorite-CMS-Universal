# Site Migration Guide

This guide describes how to migrate a **Favorite CMS Universal** website between hosting providers, or move a local development site (e.g. XAMPP) to live production hosting.

---

## 1. Migration Checklist

Moving your site involves four steps:
1. Exporting the database and files from the source server.
2. Transferring files and importing the database to the destination server.
3. Updating database configuration in `.env`.
4. Updating the `site_url` in the database.

---

## 2. Step-by-Step Migration Process

### Step 1: Export from Source Server
1. Create a MySQL database dump (`site_backup.sql`).
2. Zip the entire application directory into an archive (`site_archive.zip`).

### Step 2: Upload to Destination Server
1. Upload `site_archive.zip` to the destination document root (e.g. `public_html/`).
2. Extract the archive.

### Step 3: Create Database on Destination Server
1. In your new hosting cPanel, create a new MySQL database (e.g. `newhost_favcms`).
2. Create a MySQL user and grant `ALL PRIVILEGES` to the new database.
3. Open phpMyAdmin, select `newhost_favcms`, and **Import** `site_backup.sql`.

### Step 4: Update `.env`
Edit the `.env` file in the root of your newly extracted site to match your new database credentials:
```ini
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=newhost_favcms
DB_USERNAME=newhost_dbuser
DB_PASSWORD=your_new_password
APP_URL=https://your-new-domain.com
```

### Step 5: Update Site URL in Database
If your domain name changed during migration (e.g. from `http://favorite-cms.local` to `https://myproductiondomain.com`):
1. In phpMyAdmin, open the `settings` table.
2. Locate the row with `setting_key = 'site_url'`.
3. Update the `value` to your new domain: `https://myproductiondomain.com`.

---

## 3. Post-Migration Verification

1. Visit your new domain homepage: `https://myproductiondomain.com/`.
2. Verify that images and styles load correctly.
3. Log into `/admin` and verify that posts, pages, and media files are present.
4. Test saving a post draft to verify database write permissions.

