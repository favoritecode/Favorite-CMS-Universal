# Safe Core Update Guide for Favorite CMS Universal
## How to Update Core Without Losing Existing Website Data

> [!IMPORTANT]
> **CORE UPDATE != FRESH INSTALLATION**  
> Updating Favorite CMS Universal to a newer Core release means updating the CMS engine files while **strictly preserving your existing website database, uploaded media, plugins, themes, and configuration**.  
> **NEVER delete your website folder, NEVER drop your database, and NEVER run the web installer (/install) on an existing website.**

---

## Table of Contents

1. [Beginner Mental Model: Core Application vs. Website Data](#1-beginner-mental-model-core-application-vs-website-data)
2. [Complete Root-Level Data Safety Classification](#2-complete-root-level-data-safety-classification)
3. [Understanding the Release Package Structure](#3-understanding-the-release-package-structure)
4. [Critical Rule: Separate Core, Plugin, and Theme Updates](#4-critical-rule-separate-core-plugin-and-theme-updates)
5. [Data That Must Survive a Normal Core Update](#5-data-that-must-survive-a-normal-core-update)
6. [Pre-Update Requirements: Backups First](#6-pre-update-requirements-backups-first)
   - [File Backup (Hostinger / cPanel)](#file-backup-procedure)
   - [Database Backup (phpMyAdmin)](#database-backup-procedure-phpmyadmin)
7. [Step-by-Step Update Procedure (Hostinger / cPanel File Manager)](#7-step-by-step-update-procedure-hostinger--cpanel-file-manager)
8. [Step-by-Step Update Procedure for Local / XAMPP Environments](#8-step-by-step-update-procedure-for-local--xampp-environments)
9. [Running Database Migrations](#9-running-database-migrations)
10. [Safe Rollback Procedure](#10-safe-rollback-procedure)
11. [Post-Update Verification Checklist](#11-post-update-verification-checklist)
12. [Common Dangerous Mistakes (DO NOT DO THIS)](#12-common-dangerous-mistakes-do-not-do-this)

---

## 1. Beginner Mental Model: Core Application vs. Website Data

When updating Favorite CMS Universal, visualize your installation as two distinct categories:

`
+-----------------------------------------------------------------------------+
|                       FAVORITE CMS UNIVERSAL INSTALLATION                   |
+--------------------------------------+--------------------------------------+
|       1. CORE APPLICATION CODE       |     2. WEBSITE DATA & CUSTOM ASSETS   |
|         [SAFE TO UPDATE]             |         [MUST BE PRESERVED]          |
+--------------------------------------+--------------------------------------+
| * app/ (controllers, models, logic)  | * .env (database credentials, keys)  |
| * resources/ (views, admin templates)| * public/uploads/ (all media files)  |
| * vendor/ (bundled PHP libraries)    | * storage/ (install lock, data, logs)|
| * bootstrap.php (core bootstrap)     | * plugins/ (favorite-digital, etc.)  |
| * index.php (request forwarder)      | * themes/ (default & custom themes)  |
| * migrate.php (migration runner)     | * MySQL Database (posts, users, etc.)|
+--------------------------------------+--------------------------------------+
`

Some directories contain **mixed content** (for example, public/ contains core routing scripts alongside your persistent media library in public/uploads/).  
**Never delete or overwrite whole directories blindly.** Always follow the explicit safety classification below.

---

## 2. Complete Root-Level Data Safety Classification

Each directory and file in your Favorite CMS Universal installation falls into one of three safety tiers:

- :red_circle: **NEVER DELETE DURING A NORMAL CORE UPDATE**: Contains persistent site data, credentials, uploads, custom themes, or plugins.
- :yellow_circle: **INSPECT / PRESERVE**: Contains mixed content or configuration templates. Inspect or merge changes without overwriting user data.
- :green_circle: **CORE-MANAGED (SAFE TO REPLACE)**: Core software engine files that are replaced with the new release files.

| Directory / File | Status | Classification | Purpose & Why It Must Be Handled This Way |
| :--- | :---: | :--- | :--- |
| pp/ | :green_circle: | **REPLACE** | **Core-Managed**: Contains core application classes, controllers, models, and middleware. Safe to replace completely with the new release. |
| config/ | :yellow_circle: | **INSPECT / MERGE** | **Core Defaults**: Contains default config templates (pp.php, cache.php, database.php, storage.php). These pull credentials from .env via env(). If you customized upload limits or MIME types directly in storage.php, merge those edits into the new files rather than blindly overwriting. |
| database/ | :yellow_circle: | **INSPECT / MERGE** | **Migrations Only**: Contains database/migrations/*.php. Your actual website data lives in MySQL/MariaDB, NOT in this directory. Copy new migration files from the release into database/migrations/. Inspect to ensure no custom .sqlite or SQL backup files were placed inside. |
| dfre/ | :red_circle: | **NEVER DELETE** | **External / Hosting**: dfre/ is **not part of Favorite CMS Universal Core** and does not exist in the official Git repository or release ZIP. If present on your server, it is an external hosting tool, cache, or deployment artifact. Leave it completely untouched. |
| plugins/ | :red_circle: | **NEVER DELETE** | **Separate Ecosystem**: Contains installed plugins (e.g., avorite-digital, avorite-pay, hello-favorite, or custom plugins). A Core update must **never** touch, overwrite, or delete plugin directories. Plugin updates are separate releases. |
| public/ | :yellow_circle: | **MIXED CONTENT** | **Inspect Subdirectories**: Contains both core web entrypoints and persistent user media:<br>&bull; public/uploads/: :red_circle: **NEVER DELETE**. Stores your media library images and attachments.<br>&bull; public/plugins/, public/themes/: :red_circle: **PRESERVE**. Plugin & theme public web assets.<br>&bull; public/index.php: :green_circle: **REPLACE**. Core HTTP entrypoint.<br>&bull; public/assets/: :green_circle: **REPLACE**. Core admin CSS, JS, and UI icons.<br>&bull; public/.htaccess: :yellow_circle: **INSPECT / PRESERVE**. Core rewrite rules; preserve if host added custom directives. |
| 
esources/ | :green_circle: | **REPLACE** | **Core-Managed**: Contains admin view templates, layout templates, and core mail templates. Safe to replace completely with the new release. |
| storage/ | :red_circle: | **NEVER DELETE** | **Persistent Site State**: Stores critical runtime files:<br>&bull; storage/installed.lock: Proves the site is installed. Deleting it triggers re-installation!<br>&bull; storage/plugins/favorite-digital/: Stores protected digital download files (iles/), product images (images/), and customer payment proofs (proofs/).<br>&bull; storage/backups/: Stores database backups created from admin tools.<br>&bull; storage/cache/, storage/logs/, storage/sessions/: Safe to empty old log or cache files, but **never delete the directory structure**. |
| 	hemes/ | :red_circle: | **NEVER DELETE** | **Theme Templates**: Contains active and custom themes (e.g., 	hemes/default/). Preserves your frontend design, layouts, and custom modifications. |
| endor/ | :green_circle: | **REPLACE** | **Core-Managed**: Contains third-party PHP dependencies. Bundled pre-packaged inside official Core release ZIPs (Favorite-CMS-Universal.zip). Replace with the new endor/ from the release archive. |
| .env | :red_circle: | **NEVER DELETE OR OVERWRITE** | **Site Secrets & Credentials**: Contains your MySQL database name, database user, password, APP_KEY, APP_URL, and ADMIN_PREFIX. Blindly overwriting or deleting .env disconnects your site from the database. |
| .htaccess (root) | :yellow_circle: | **INSPECT / PRESERVE** | **Server Security Rules**: Contains Apache/LiteSpeed directives blocking access to .env, storage/, pp/, database/, and forwarding requests to public/. Safe to update to the new version unless your host added custom SSL, PHP handler, or proxy directives. |
| ootstrap.php | :green_circle: | **REPLACE** | **Core-Managed**: Initializes autoloader, constants, and dependency injection container. Safe to replace. |
| index.php (root) | :green_circle: | **REPLACE** | **Core-Managed**: Root forwarder routing traffic into public/. Safe to replace. |
| migrate.php | :green_circle: | **REPLACE** | **Core-Managed**: Official command-line migration runner. Safe to replace. |

---

## 3. Understanding the Release Package Structure

Official Favorite CMS Universal release archives (Favorite-CMS-Universal.zip) are generated with pre-optimized dependencies so shared hosting users do not need Composer:

### Files Included in the Official Core Release ZIP:
- pp/ (core application classes and controllers)
- config/ (default configuration templates)
- database/migrations/ (core schema migrations)
- public/ (contains index.php, ssets/, .htaccess, and an empty uploads/.gitkeep)
- 
esources/ (core admin view templates and emails)
- endor/ (pre-bundled production Composer dependencies with optimized classmap autoloader)
- ootstrap.php, index.php, migrate.php, .htaccess, LICENSE, README.md

### Files Excluded from the Release ZIP (Preserved on Your Server):
- **.env is NOT included**: Your live database credentials and secret APP_KEY exist only on your server.
- **storage/installed.lock is NOT included**: Created only upon installation on your live server.
- **storage/plugins/ is NOT included**: User downloads, product files, and payment receipts are created at runtime.
- **public/uploads/* files are NOT included**: Your uploaded media files remain intact.
- **plugins/favorite-digital and plugins/favorite-pay are NOT included**: Domain plugins are packaged and released as independent plugins, never bundled into Core releases.
- **dfre/ is NOT included**: It is not part of the CMS codebase.

---

## 4. Critical Rule: Separate Core, Plugin, and Theme Updates

Favorite CMS Universal strictly separates its architecture into distinct layers. Understand the boundary between each:

`
+------------------------------------------------------------------------+
|                        INDEPENDENT UPDATE CYCLES                       |
+------------------+-----------------------+-----------------------------+
| 1. CORE UPDATE   | 2. PLUGIN UPDATE      | 3. THEME UPDATE             |
+------------------+-----------------------+-----------------------------+
| Updates Core CMS | Updates ONE specific  | Updates ONE specific        |
| engine & admin.  | plugin (e.g. Digital).| theme (e.g. default).       |
|                  |                       |                             |
| * Update:        | * Update:             | * Update:                   |
|   app/, vendor/, |   plugins/<plugin>/   |   themes/<theme>/           |
|   resources/,    |                       |                             |
|   bootstrap.php  | * Preserve:           | * Preserve:                 |
|                  |   Core, other plugins,|   Core, plugins, other      |
| * Preserve:      |   all website data    |   themes, database          |
|   plugins/,      |                       |                             |
|   themes/,       |                       |                             |
|   database,      |                       |                             |
|   uploads/       |                       |                             |
+------------------+-----------------------+-----------------------------+
`

- A **Core update** modifies universal CMS functionality (e.g., admin navigation, security hardening, core routing). It must never modify or replace your plugins or active theme.
- A **Plugin update** (such as Favorite Digital or Favorite Pay) uses that plugin's own dedicated ZIP release and updater. Do not mix plugin files into a Core update.

---

## 5. Data That Must Survive a Normal Core Update

A successful Core update leaves all website content 100% intact. The following categories must survive:

1. **User Accounts & Roles**: Administrator, editor, author, and subscriber accounts, passwords, and permissions.
2. **Content & Taxonomy**: All blog posts, static pages, categories, tags, and comments.
3. **Site Settings & Menus**: Site title, tagline, active theme selection, custom navigation menus, and widgets.
4. **Media Library Files**: All user images, PDFs, and media stored in public/uploads/.
5. **Installed Plugins & Plugin Settings**: Plugins remain in plugins/ and active in the database.
6. **Digital Products & Orders** *(if Favorite Digital is installed)*: Product listings, prices, secure download files in storage/plugins/favorite-digital/files/, orders, and customer entitlements.
7. **Payment Records & Wallets** *(if Favorite Pay is installed)*: Payment gateway configurations, transaction history, customer proof uploads, and wallet balances.

---

## 6. Pre-Update Requirements: Backups First

> [!CAUTION]
> **DO NOT START A CORE UPDATE UNTIL BOTH A COMPLETE FILE BACKUP AND DATABASE BACKUP EXIST.**  
> A file backup alone is NOT enough if database migrations are applied. Take both backups now.

### File Backup Procedure

#### On Hostinger (hPanel):
1. Log into your **Hostinger Control Panel** (hPanel).
2. Go to **Files** -> **File Manager** (access files of your website domain).
3. Navigate to the folder containing your CMS (usually public_html).
4. Select all files and folders.
5. Click **Compress** (ZIP), name it ackup-files-pre-update.zip.
6. Download ackup-files-pre-update.zip to your local computer or move it to a safe directory outside public_html.

#### On cPanel:
1. Log into **cPanel** -> **File Manager**.
2. Navigate to your CMS root folder (e.g., public_html).
3. Click **Select All** -> **Compress** -> Select **Zip Archive**.
4. Name it ackup-files-pre-update.zip and click **Compress Files**.
5. Download the archive to your computer for safekeeping.

---

### Database Backup Procedure (phpMyAdmin)

1. In Hostinger hPanel or cPanel, open **Databases** -> **phpMyAdmin**.
2. In the left sidebar, click your **Favorite CMS database name** (check .env for DB_NAME if unsure).
3. Click the **Export** tab in the top navigation bar.
4. Select **Quick** export method and format **SQL**.
5. Click **Export** (or **Go**).
6. Save the downloaded .sql file on your computer.

---

## 7. Step-by-Step Update Procedure (Hostinger / cPanel File Manager)

Follow this exact 12-step procedure to update Favorite CMS Universal safely without risking data loss:

### Step 1: Download the New Core Release
Download the latest Favorite-CMS-Universal.zip from the official repository releases page.

### Step 2: (Optional but Recommended) Enable Maintenance Mode
Favorite CMS Universal does not require taking the site offline, but to prevent customers from placing orders or creating content while files are being copied:
- Create a simple maintenance.html in your root or use your hosting provider's maintenance toggle.

### Step 3: Upload the Release ZIP to a Temporary Folder
1. In your hosting File Manager, open the root directory of your CMS.
2. Create a new temporary folder named core-update-temp/.
3. Open core-update-temp/ and upload Favorite-CMS-Universal.zip.

### Step 4: Extract into the Temporary Folder
1. Inside core-update-temp/, right-click Favorite-CMS-Universal.zip and select **Extract**.
2. Verify that the files extracted cleanly into core-update-temp/.

### Step 5: Replace Core-Managed Directories
Using File Manager, move/copy the following directories from core-update-temp/ into your live CMS root, replacing the old versions:
- pp/ -> Overwrite/replace live pp/
- 
esources/ -> Overwrite/replace live 
esources/
- endor/ -> Overwrite/replace live endor/

### Step 6: Update Database Migrations
Copy any new migration files from core-update-temp/database/migrations/ into your live database/migrations/ folder:
- Do NOT delete existing migration files.
- Simply copy over the new .php migration files so migrate.php can find them.

### Step 7: Update Public Assets
1. From core-update-temp/public/assets/, copy all files into live public/assets/ (replaces admin CSS, JS, icons).
2. Copy core-update-temp/public/index.php over live public/index.php.
3. **DO NOT TOUCH public/uploads/**. Leave your live public/uploads/ completely untouched.

### Step 8: Update Root Core Files
From core-update-temp/, copy the following individual files over your live CMS root:
- ootstrap.php -> Replace
- index.php -> Replace
- migrate.php -> Replace

### Step 9: Inspect Configuration and .htaccess
- **config/**: Check if the release notes mention any new configuration options. If so, copy the new file or merge the new options. Never overwrite custom storage/MIME settings you previously added.
- **.htaccess**: If the release notes include security updates to .htaccess, review and apply them, keeping any custom SSL redirects or host rules intact.
- **.env**: **NEVER OVERWRITE .env**. If a new release introduces a new optional setting, open your live .env in File Manager Code Editor and append the new variable manually.

### Step 10: Delete the Temporary Folder
Once files are in place, delete core-update-temp/ to keep your server clean.

### Step 11: Run Database Migrations
Run pending schema migrations to bring your database up to date with the new Core release:
- **If you have SSH / Terminal Access (hPanel / cPanel Terminal)**:
  `ash
  cd public_html
  php migrate.php
  `
  *(See [Section 9: Running Database Migrations](#9-running-database-migrations) for alternatives if you do not have SSH access).*

### Step 12: Clear Temporary Cache & Verify
1. In File Manager, open storage/cache/.
2. Delete all files inside storage/cache/ (temporary cache files will regenerate automatically). **Keep the storage/cache/ directory itself.**
3. Remove maintenance.html (if created in Step 2).
4. Run through the [Post-Update Verification Checklist](#11-post-update-verification-checklist).

---

## 8. Step-by-Step Update Procedure for Local / XAMPP Environments

For developers updating a local installation (XAMPP, WampServer, Laragon):

1. **Backup Database**:
   - Open http://localhost/phpmyadmin, select your CMS database, and click **Export**.
2. **Backup Files**:
   - Copy your CMS folder (e.g., C:\xampp\htdocs\my-site) to my-site-backup.
3. **Extract Release**:
   - Extract Favorite-CMS-Universal.zip into a temporary folder.
4. **Copy Core Files**:
   - Copy pp/, 
esources/, endor/ into your project, replacing old folders.
   - Copy new migration files from database/migrations/ into your project's database/migrations/.
   - Copy public/assets/ and public/index.php into public/ (leave public/uploads/ intact).
   - Copy ootstrap.php, index.php, and migrate.php into your project root.
5. **Run Migrations**:
   - Open Command Prompt or PowerShell in your project directory:
     `ash
     php migrate.php --status
     php migrate.php
     `
6. **Verify**:
   - Open http://localhost/my-site/admin and verify login and dashboard.

---

## 9. Running Database Migrations

### How Favorite CMS Universal Migrations Work
- Core migrations reside in database/migrations/*.php.
- The database records applied migrations in the cms_migrations table with batch numbers.
- Migrations are **incremental and idempotent**: running the migration runner applies only newly added migrations and leaves previously run tables and existing data completely untouched.

### Method A: Via Command Line (Recommended)
If your hosting plan provides SSH access or a web Terminal (cPanel Terminal / Hostinger SSH):
`ash
# 1. View migration status (shows which migrations are pending)
php migrate.php --status

# 2. Run pending migrations safely
php migrate.php
`

> [!CAUTION]
> **NEVER USE php migrate.php --fresh ON A LIVE SITE!**  
> The --fresh flag drops all database tables and completely destroys all website data. Only use php migrate.php without --fresh.

### Method B: Via One-Time Scheduled Task / Cron Job (No SSH Access)
If your shared hosting plan does not provide SSH or Terminal access:
1. In cPanel or Hostinger hPanel, go to **Cron Jobs** / **Scheduled Tasks**.
2. Create a new cron job set to run once (e.g., in 2 minutes):
   - Command: /usr/bin/php /home/u123456789/domains/yourdomain.com/public_html/migrate.php
   *(Replace with your hosting account's actual PHP and file path)*.
3. Configure the cron job to email output to your email address or check the output log file.
4. Once you receive confirmation that migrations applied ([OK] ... migration(s) applied successfully), **immediately delete the cron job**.

---

## 10. Safe Rollback Procedure

If you encounter an issue during or immediately after updating, follow this safe rollback process:

1. **Keep Calm and Keep Backups**: Do NOT delete your backups or attempt random fixes.
2. **Determine if Migrations Ran**:
   - Check if php migrate.php executed during the update.
   - Because database schema changes may have been introduced, rolling back code without rolling back schema can cause SQL errors.
3. **Restore Database (If Migrations Ran)**:
   - Open **phpMyAdmin**.
   - Select your CMS database.
   - Go to the **Import** tab.
   - Choose your ackup-database-pre-update.sql file created before the update and click **Import**.
   - This restores your exact pre-update database tables and records.
4. **Restore Core Files**:
   - From your ackup-files-pre-update.zip, restore the previous versions of pp/, 
esources/, endor/, ootstrap.php, and public/index.php.
   - Your .env, public/uploads/, storage/, plugins/, and 	hemes/ were never modified, so your content remains intact.
5. **Clear Application Cache**:
   - Delete cached files in storage/cache/.
6. **Verify Site**:
   - Test frontend and admin login to ensure the site operates normally on the previous release.
7. **Inspect Error Logs**:
   - Check storage/logs/ or server error logs to identify why the update failed before attempting again.

---

## 11. Post-Update Verification Checklist

After completing an update, run through this verification checklist to ensure all systems are functioning properly:

- [ ] **Homepage**: Frontend loads cleanly with HTTP 200 (no 500 error, no fatal PHP errors).
- [ ] **Admin Login**: Access /admin and log in successfully.
- [ ] **Admin Navigation**: Admin sidebar navigation and submenus expand cleanly on click.
- [ ] **Dashboard**: Admin dashboard widgets load correct statistics.
- [ ] **Posts & Pages**: Existing posts and pages viewable on frontend and editable in editor.
- [ ] **Media Library**: Existing uploaded images display correctly in /admin/media.
- [ ] **New Upload Test**: Uploading a new test image works without storage errors.
- [ ] **Comments**: Existing comments remain present; test submission works.
- [ ] **Users & Roles**: User list displays all administrators and authors.
- [ ] **Settings**: Site settings and permalinks remain preserved.
- [ ] **Menus**: Frontend navigation menus display correctly.
- [ ] **Themes**: Active theme renders properly without missing template errors.
- [ ] **Plugins Page**: Access /admin/plugins - all installed plugins remain listed and active.
- [ ] **No PHP Fatal Errors**: Check storage/logs/ for unexpected exceptions.
- [ ] **Maintenance Mode**: Ensure any temporary maintenance.html is removed.

### If Favorite Digital / Favorite Pay are Installed:
- [ ] **Digital Products**: All existing digital products remain listed with correct prices.
- [ ] **Orders & Entitlements**: Existing customer orders, purchase history, and download links remain intact.
- [ ] **Secure Files**: Digital product file downloads in storage/plugins/favorite-digital/ work for authorized users.
- [ ] **Payment Settings**: Gateway credentials and payment methods remain configured.
- [ ] **Wallets & Proofs**: Customer wallet balances and payment proofs remain preserved.

---

## 12. Common Dangerous Mistakes (DO NOT DO THIS)

Avoid these common mistakes that lead to accidental data loss:

- :x: **DO NOT delete the entire website directory before uploading the new release.**  
  Deleting the root folder deletes your .env credentials, all uploaded media in public/uploads/, digital downloads in storage/, and custom plugins/themes.
- :x: **DO NOT delete or drop the existing website database.**  
  A Core update does not require creating a new database. It updates your existing database schema via migrations.
- :x: **DO NOT run the web installer (/install) on an existing site.**  
  The web installer is only for fresh, uninstalled websites. Running the installer on an existing site risks overwriting your database tables.
- :x: **DO NOT delete or overwrite .env.**  
  Your .env file contains your database password and encryption key. Overwriting it will disconnect your site.
- :x: **DO NOT delete storage/ blindly.**  
  storage/ contains installed.lock and protected plugin assets (like digital download files and payment receipts).
- :x: **DO NOT delete plugins/ or 	hemes/.**  
  Core updates do not touch plugins or themes. Replacing them can break your custom design or disable domain features.
- :x: **DO NOT run php migrate.php --fresh.**  
  The --fresh flag drops all tables and wipes all site data. Always run standard php migrate.php.
- :x: **DO NOT delete dfre/ without knowing what it is.**  
  dfre/ is not a CMS directory; if present on your server, it was created by your host or another tool. Leave it alone.
- :x: **DO NOT replace plugin folders during a Core update.**  
  Favorite Digital and Favorite Pay have their own release packages. Never copy Core files over plugin folders.
