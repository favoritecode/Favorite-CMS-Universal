# Local XAMPP Installation Guide

This guide walks you through setting up Favorite CMS Universal locally on Windows using XAMPP.

---

## 1. Prerequisites

- **Windows 10/11**
- **XAMPP for Windows** (Apache 2.4+ and MySQL/MariaDB 10.4+)
- **PHP 8.1+** (XAMPP PHP 8.2 or higher is recommended)
- **Composer 2.x** (optional for standard zip releases, required for development repository clones)

---

## 2. Directory Placement

Clone or extract the repository directly into your web directory or a dedicated workspace.

For example:
```
C:\xampp\htdocs\favorite-cms
```

---

## 3. Web Server & Document Root Configuration

Favorite CMS Universal strictly separates public web assets from application core code.
The public entry point is located at:
```
<project-root>/public/index.php
```

### Option A: Apache Virtual Host (Recommended)
Edit `C:\xampp\apache\conf\extra\httpd-vhosts.conf` and append:

```apache
<VirtualHost *:80>
    ServerAdmin admin@favorite-cms.local
    DocumentRoot "C:/xampp/htdocs/favorite-cms/public"
    ServerName favorite-cms.local
    <Directory "C:/xampp/htdocs/favorite-cms/public">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Add the domain to your `C:\Windows\System32\drivers\etc\hosts` file:
```
127.0.0.1  favorite-cms.local
```

### Option B: Direct XAMPP `htdocs` Subfolder
If placing the project inside `C:\xampp\htdocs\cms`, browse to:
```
http://localhost/cms/public
```

---

## 4. Database Setup

1. Open your browser and go to `http://localhost/phpmyadmin`.
2. Click **New** in the sidebar.
3. Database name: `favorite_cms`
4. Collation: `utf8mb4_unicode_ci`
5. Click **Create**.

---

## 5. Environment Configuration

Copy the sample environment file to `.env`:

```powershell
Copy-Item .env.example .env
```

Ensure the database credentials match your local MySQL server in `.env`:

```env
APP_NAME="Favorite CMS"
APP_ENV=local
APP_URL=http://favorite-cms.local
APP_DEBUG=true

DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=favorite_cms
DB_USERNAME=root
DB_PASSWORD=
```

Install Composer dependencies if cloning from git:
```powershell
composer install
```

---

## 6. Running the Browser Installer

1. Start Apache and MySQL in the XAMPP Control Panel.
2. Open your web browser and navigate to:
   ```
   http://favorite-cms.local/
   ```
3. Because the CMS is not yet installed, the web setup wizard will automatically open at `/install`.
4. Enter your site information:
   - **Site Title**: `My Awesome Site`
   - **Admin Username**: `admin`
   - **Admin Email**: `admin@example.com`
   - **Admin Password**: Choose a secure password (e.g. `admin123`)
5. Click **Install Favorite CMS**.
6. The installer runs all database migrations idempotently, creates the administrator account, and creates the persistent installation lock file at `storage/installed.lock`.

---

## 7. Verifying Persistent Installation State

A critical requirement of Favorite CMS is **permanent installation persistence**.

1. In the XAMPP Control Panel, click **Stop** on Apache and MySQL.
2. Wait a few seconds.
3. Click **Start** on Apache and MySQL.
4. Refresh `http://favorite-cms.local/`.

**Result**: The CMS immediately loads your homepage or dashboard. It **never** prompts you to reinstall, preserving all your posts, pages, media, settings, and users intact.
