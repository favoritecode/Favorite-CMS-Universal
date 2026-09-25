# Comprehensive Installation Guide

This guide documents the real installation procedure for **Favorite CMS Universal v1.0.0**, derived strictly from the core installer implementation (`FavoriteCMS\Installer\InstallerController` and `DatabaseProvisioner`).

---

## Technical Prerequisites

Before beginning installation, ensure your hosting environment satisfies the following requirements:

- **PHP Version:** PHP 8.1.0 or newer.
- **PHP Extensions:**
  - `pdo` & `pdo_mysql` (Database PDO drivers)
  - `mbstring` (Multibyte UTF-8 handling)
  - `json` (Manifest and setting serialization)
  - `session` (User auth and CSRF protection)
  - `fileinfo` (MIME-type verification)
  - `gd` (Media image thumbnail generation)
- **Database Engine:** MySQL 5.7+ or MariaDB 10.3+ with **InnoDB** storage engine support.
- **Web Server:** Apache 2.4+ with `mod_rewrite` enabled or LiteSpeed.
- **File System Permissions:** The web server must have write permissions to the application root (for writing `.env`), `storage/`, and `public/uploads/`.

---

## Standard Installation Flow

### Step 1: Upload and Extract Files
Download `Favorite-CMS-Universal-v1.0.0.zip` from the official release page.

#### Option A: Domain Root (e.g. `https://example.com/`)
Extract the contents directly into your public document root (typically `public_html/` on cPanel or `htdocs/` on Apache/XAMPP).
Ensure the following files and directories sit directly inside your document root:
```
public_html/
├── app/
├── config/
├── database/
├── plugins/
├── public/
├── resources/
├── storage/
├── themes/
├── vendor/
├── .htaccess
├── bootstrap.php
└── index.php
```

#### Option B: Subdirectory (e.g. `https://example.com/blog/`)
Extract into a subdirectory named `blog/` inside `public_html/`. Favorite CMS Universal's `UrlResolver` automatically detects the nested path and normalizes all asset and administrative routes without manual `.htaccess` editing.

---

### Step 2: Create MySQL Database
Using your hosting control panel (cPanel, Plesk, DirectAdmin, or phpMyAdmin):
1. Create a new MySQL database (e.g. `mysite_db`).
2. Create a new MySQL user (e.g. `mysite_user`) with a strong password.
3. Assign the user to the database and grant **ALL PRIVILEGES**.

---

### Step 3: Launch Web Setup Wizard
Open any browser and visit your site URL:
```
http://example.com/
```
Because no `.env` file exists and `storage/installed.lock` is absent, the CMS front controller (`Application.php`) automatically redirects you to:
```
http://example.com/install
```

The 5-step installer wizard guides you through setup:

#### Step 1: Welcome & Setup Mode
- Displays welcome screen and detected site URL.
- **Fresh Installation:** Click **Begin Installation** to start setting up a new site.
- **Restore Backup:** If you have an existing Favorite CMS backup ZIP archive from another server, click **Restore from Backup** to upload and restore the database and files in one step.

#### Step 2: Environment Compatibility Check
- The `EnvironmentChecker` automatically audits:
  - PHP version compatibility (8.1.0+)
  - Presence of required PHP extensions (`pdo`, `pdo_mysql`, `mbstring`, `json`, `session`, `fileinfo`, `gd`)
  - Write permissions for application root (for `.env`), `storage/`, and `public/uploads/`
- If any check fails, the installer displays precise diagnostics explaining how to resolve it.

#### Step 3: Database Connection Configuration
Two database modes are supported:
- **Recommended Database (Standard Shared Hosting):**
  - **Host:** Defaults to `127.0.0.1` (or `localhost`).
  - **Port:** Defaults to `3306`.
  - **Database Name:** Name of the empty MySQL database created in Step 2.
  - **Username:** MySQL user with full privileges.
  - **Password:** MySQL user password.
  - **Table Prefix:** Defaults to `fc_` (allows multiple installations in a single database).
- **Custom / Advanced Mode:**
  - Allows specifying alternative remote hosts, non-standard port numbers, or custom socket paths.
- The installer validates the connection immediately. If authentication fails, clear, friendly error messages identify whether the issue is wrong password, unknown database, or unreachable host.

#### Step 4: Administrator Account & Site Info
- **Site Title:** Human-readable name of your website.
- **Admin Username:** The login username for the Super Administrator.
- **Admin Email:** Primary contact email (used for notifications and password recovery).
- **Admin Password:** Strong administrator password (minimum 8 characters).

#### Step 5: Execution & Installation Lock
When you click **Install Favorite CMS**:
1. The `DatabaseProvisioner` creates all 17 core database tables via schema migrations.
2. Initial default settings, taxonomies, and the 6 core roles are seeded.
3. The Super Administrator account is created and bound to the `super-admin` role.
4. The `.env` configuration file is written to the application root.
5. The `storage/installed.lock` lockfile is written to prevent any future access to the setup wizard.
6. A success screen appears with a direct link to **Log in to Admin Panel** (`/admin/login`).

---

## Local Development Installations

### XAMPP on Windows
1. Ensure Apache and MySQL modules are started in the XAMPP Control Panel.
2. Extract the CMS ZIP to `C:\xampp\htdocs\favorite-cms\`.
3. Open `http://localhost/phpmyadmin` and create a database named `favorite_cms`.
4. Navigate to `http://localhost/favorite-cms/` in your browser.
5. In Step 3 of the installer, enter:
   - **Host:** `localhost` or `127.0.0.1`
   - **Database Name:** `favorite_cms`
   - **Username:** `root`
   - **Password:** *(leave blank if default XAMPP)*
   - **Table Prefix:** `fc_`

### Laragon on Windows
1. Extract into `C:\laragon\www\favorite-cms\`.
2. Laragon will auto-create the local host virtual domain (e.g. `http://favorite-cms.test`).
3. Open the URL in your browser and complete the wizard.

### Built-in PHP CLI Server
For quick local testing without Apache:
```bash
cd D:\path\to\favorite-cms
php -S localhost:8000
```
Navigate to `http://localhost:8000/` and complete setup.

---

## Shared Hosting (cPanel / DirectAdmin)

1. Log into your hosting cPanel.
2. Open **File Manager** and enter `public_html`.
3. Upload `Favorite-CMS-Universal-v1.0.0.zip` and click **Extract**.
4. In cPanel, navigate to **MySQL® Databases**:
   - Create a database (e.g. `cpaneluser_favcms`).
   - Create a user (e.g. `cpaneluser_dbuser`) with password.
   - Add user to database with **ALL PRIVILEGES**.
5. Navigate to your domain in your browser and complete the wizard.
6. Ensure PHP 8.1 or higher is selected via cPanel's **Select PHP Version** or **MultiPHP Manager**.

---

## Post-Installation Notes & Best Practices

1. **Verify Installation Lock:**
   Confirm that `storage/installed.lock` exists. Attempting to visit `/install` while this file exists will automatically redirect to the homepage (`/`).
2. **File Permissions Hardening:**
   After installation, you may change permissions on the application root directory to read-only (`755`), keeping `storage/` and `public/uploads/` writable (`775` or `755`).
3. **Environment Security:**
   The generated `.env` file contains database connection credentials. Confirm that visiting `http://example.com/.env` in your browser returns a **403 Forbidden** error. The root `.htaccess` file provides this protection by default.

---

## Common Installation Issues & Solutions

### 1. "Application root is not writable for .env"
- **Cause:** The web server user cannot write the `.env` configuration file into the project root directory.
- **Fix:** Temporarily grant write permission to the root folder (`chmod 775 .` or `chmod 777 .`), complete installation, and restore to `755`.

### 2. "Database connection failed: Access denied for user"
- **Cause:** Incorrect MySQL username or password, or user lacks permissions for that database.
- **Fix:** In cPanel/phpMyAdmin, verify that the MySQL user was added to the database with ALL PRIVILEGES.

### 3. Blank Page or 500 Internal Server Error
- **Cause:** Missing required PHP extension or Apache `mod_rewrite` is disabled.
- **Fix:** Check `storage/logs/favorite_cms.log` or your web server error log (`error_log`). Verify that PHP 8.1+ and the `pdo_mysql` extension are active.

### 4. 404 Errors When Clicking Admin Links
- **Cause:** Apache `AllowOverride All` is not enabled, preventing `.htaccess` rewrite rules from taking effect.
- **Fix:** In your Apache virtual host configuration (`httpd.conf`), set `AllowOverride All` for the site directory and restart Apache.
