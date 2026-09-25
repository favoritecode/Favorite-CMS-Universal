# Quick Start Guide

Get Favorite CMS Universal v1.0.0 up and running in under two minutes.

---

## 1. Extract Archive

Download `Favorite-CMS-Universal-v1.0.0.zip` from [Releases](https://github.com/favoritecode/Favorite-CMS-Universal/releases) and extract it into your web server directory:
- **Shared Hosting (cPanel/DirectAdmin):** Extract directly into `public_html/` (or a subdirectory like `public_html/site/`).
- **Local XAMPP:** Extract into `C:\xampp\htdocs\favorite-cms\` (Windows).
- **Local Laragon:** Extract into `C:\laragon\www\favorite-cms\`.
- **Local PHP Server:** Extract into any working directory and run `php -S localhost:8000`.

---

## 2. Prepare Database

Create an empty MySQL or MariaDB database:
- **Via phpMyAdmin:**
  1. Open phpMyAdmin.
  2. Click **Databases** &rarr; Enter database name (e.g. `favorite_cms`).
  3. Collation: `utf8mb4_unicode_ci` &rarr; Click **Create**.
- **Via MySQL CLI:**
  ```sql
  CREATE DATABASE `favorite_cms` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  ```

---

## 3. Run Web Setup Wizard

1. Open your browser and navigate to your website URL:
   - Shared hosting: `http://your-domain.com/`
   - Local XAMPP: `http://localhost/favorite-cms/`
   - Local CLI: `http://localhost:8000/`
2. You will automatically be redirected to the web installer (`/install`).
3. Follow the 5 wizard steps:
   - **Step 1: Welcome & Mode:** Choose a fresh installation or restore from an existing backup ZIP.
   - **Step 2: System Health:** Ensure all required PHP extensions and directories are green.
   - **Step 3: Database Settings:** Enter your database name, database username, and password. (Host defaults to `localhost` and prefix to `fc_`).
   - **Step 4: Site & Administrator:** Specify your site title, admin username, email, and strong password.
   - **Step 5: Finished:** The installer provisions tables, seeds initial data, generates `.env`, and sets `storage/installed.lock`.

---

## 4. Log into Admin Panel

Click **Go to Admin Dashboard** or navigate to:
```
http://your-domain.com/admin/login
```
Log in using your administrator credentials. You are now ready to publish content, customize themes, and manage your site!
