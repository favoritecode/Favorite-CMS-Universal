# Safe Core Update Guide for Favorite CMS Universal
## How to Update Core Without Losing Existing Website Data

> [!IMPORTANT]
> **CORE UPDATE != FRESH INSTALLATION**  
> Updating Favorite CMS Universal to a newer Core release means updating the CMS software engine files while **strictly preserving your existing website database, uploaded media, plugins, themes, and configuration**.  
> **NEVER delete your website folder, NEVER drop your database, and NEVER run the web installer (`/install`) on an existing website.**

---

## 1. Simple Visual Model: Core Application vs. Website Data

When updating your live Favorite CMS Universal installation (for example, located in `/public_html/cms/` or `/public_html/`), visualize the site in three distinct categories:

```
EXISTING LIVE CMS

🔴 WEBSITE DATA / SITE CONFIGURATION
    KEEP IT

🟢 CORE APPLICATION
    UPDATE IT

🟡 MIXED / CUSTOM / SERVER-SPECIFIC
    INSPECT IT

Then:

BACKUP
  ↓
KEEP RED
  ↓
INSPECT YELLOW
  ↓
REPLACE GREEN
  ↓
RUN MIGRATIONS
  ↓
VERIFY
  ↓
DONE
```

### The Two Core Principles
1. **Core Application**: The engine code (`app/`, `resources/`, `vendor/`, `bootstrap.php`, `index.php`, `migrate.php`) can and should be updated to new releases.
2. **Website Data & Site-Specific Configuration**: Your database, passwords, customer files, uploaded images, plugins, and custom designs (`.env`, `public/uploads/`, `storage/`, `plugins/`, `themes/`, live MySQL database) must **NEVER** be deleted.

> [!WARNING]
> **"Replace" does NOT mean delete the old CMS directory, upload the new ZIP, and start again.**  
> That destructive procedure is strictly forbidden for an update. An update replaces only confirmed Core-managed files after backups are secured.

---

## 2. Quick Reference Safety Table

| PATH | ACTION DURING CORE UPDATE | DELETE? | WHY |
| :--- | :--- | :--- | :--- |
| `app/` | 🟢 REPLACE — CORE-MANAGED | **NO BLIND DELETE — REPLACE WITH NEW CORE VERSION** | Contains core application logic, controllers, models, and services. Overwrite/replace with the new `app/` from the release ZIP. |
| `config/` | 🟡 INSPECT — NEVER BLINDLY DELETE | **NO — INSPECT FIRST (DO NOT DELETE BLINDLY)** | Contains default config templates (`app.php`, `cache.php`, `database.php`, `storage.php`). These pull credentials from `.env` via `env()`. Inspect before replacing; if you customized upload limits or MIME types directly in `storage.php`, merge those edits into the new files rather than blindly overwriting. |
| `database/` | 🟡 INSPECT — NEVER BLINDLY DELETE | **NO — INSPECT FIRST (DO NOT DELETE BLINDLY)** | Contains `database/migrations/*.php`. Your actual website data lives in MySQL/MariaDB, NOT in this directory. Copy new migration files from the release ZIP into `database/migrations/`. Inspect to ensure no custom `.sqlite` or SQL backup files were placed inside. |
| `dfre/` | 🔴 KEEP — NEVER DELETE | **NO — NEVER DELETE DURING NORMAL CORE UPDATE** | `dfre/` is **not part of Favorite CMS Universal Core** and does not exist in the official Git repository or release ZIP. If present in `/public_html/cms/`, it is an external hosting tool, cache, or deployment artifact. Leave it completely untouched. |
| `plugins/` | 🔴 KEEP — NEVER DELETE | **NO — NEVER DELETE DURING NORMAL CORE UPDATE** | Contains installed plugins (e.g., `plugins/favorite-digital/`, `plugins/favorite-pay/`, `plugins/favorite-quick-notes/`, `hello-favorite/`, or custom plugins). Core update != Plugin update. Plugins are never bundled in Core release ZIPs. Deleting this directory destroys plugin functionality. |
| `public/` | 🟡 INSPECT — NEVER BLINDLY DELETE | **NO — INSPECT FIRST (DO NOT DELETE BLINDLY)** | Mixed content directory. Contains both core web entrypoints and persistent user data. Never delete or replace `public/` as a whole. Follow the sub-path classifications below. |
| `public/uploads/` | 🔴 KEEP — NEVER DELETE | **NO — NEVER DELETE DURING NORMAL CORE UPDATE** | Stores all user-uploaded media library images, blog attachments, and document uploads. Deleting this directory permanently erases all media from your website. |
| `public/assets/` | 🟢 REPLACE — CORE-MANAGED | **NO BLIND DELETE — REPLACE WITH NEW CORE VERSION** | Core-managed admin UI assets (CSS, JS, dashboard icons). Replace with the new files from the release ZIP. |
| `public/index.php` | 🟢 REPLACE — CORE-MANAGED | **NO BLIND DELETE — REPLACE WITH NEW CORE VERSION** | Core-managed HTTP entrypoint that boots the CMS and handles incoming web requests. Safe to replace. |
| `public/.htaccess` | 🟡 INSPECT — NEVER BLINDLY DELETE | **NO — INSPECT FIRST (DO NOT DELETE BLINDLY)** | Apache/LiteSpeed web rewrite rules forwarding requests to `index.php`. Safe to update unless your web hosting provider or security plugin added custom SSL, caching, or proxy rules. |
| `resources/` | 🟢 REPLACE — CORE-MANAGED | **NO BLIND DELETE — REPLACE WITH NEW CORE VERSION** | Core-managed admin panel views, layout templates, and core mail templates. Safe to replace completely with the new release. |
| `storage/` | 🔴 KEEP — NEVER DELETE | **NO — NEVER DELETE DURING NORMAL CORE UPDATE** | Stores critical persistent runtime state: `installed.lock` (proves active installation), `storage/plugins/favorite-digital/` (`files/`, `images/`, `proofs/`), and `storage/backups/`. Deleting `storage/` triggers reinstallation and deletes digital assets. |
| `themes/` | 🔴 KEEP — NEVER DELETE | **NO — NEVER DELETE DURING NORMAL CORE UPDATE** | Contains installed and custom frontend themes (e.g., `themes/default/`). Core update != Theme update. Preserves your custom site design, templates, and branding. |
| `themes/default/` | 🟢 UPDATE BUNDLED THEME | **UPDATE/MERGE WITH RELEASE** | The bundled Favorite CMS Default Theme. Part of the distributed Core package. When a Core release includes Default Theme improvements (e.g., frontend account menu integration), update `themes/default/` (and `public/themes/default/`). |
| `themes/<custom>/` | 🔴 KEEP USER/CUSTOM THEMES | **NO — NEVER OVERWRITE USER THEMES** | User-created or custom themes. Preserves your custom designs, child themes, templates, and branding. Core updates must never overwrite arbitrary custom user themes. |
| `vendor/` | 🟢 REPLACE — CORE-MANAGED | **NO BLIND DELETE — REPLACE WITH NEW CORE VERSION** | Contains third-party PHP libraries. Bundled pre-packaged with an optimized autoloader inside official Core release ZIPs (`Favorite-CMS-Universal.zip`). Replace with the new `vendor/` folder from the release archive. |
| `.env` | 🔴 KEEP — NEVER DELETE | **NO — NEVER DELETE DURING NORMAL CORE UPDATE** | Contains active MySQL database credentials (`DB_NAME`, `DB_USER`, `DB_PASS`), `APP_KEY`, and site URL. Blindly deleting or overwriting `.env` disconnects your website from its database. |
| `.htaccess` (root) | 🟡 INSPECT — NEVER BLINDLY DELETE | **NO — INSPECT FIRST (DO NOT DELETE BLINDLY)** | Protects sensitive directories (`app`, `storage`, `config`, `.env`, `migrate.php`) and forwards requests to `public/`. Safe to update to the new version unless your host added custom SSL, PHP handler, or redirection rules. |
| `bootstrap.php` | 🟢 REPLACE — CORE-MANAGED | **NO BLIND DELETE — REPLACE WITH NEW CORE VERSION** | Core-managed system bootstrap and autoloader initializer. Safe to replace with the new release version. |
| `index.php` (root) | 🟢 REPLACE — CORE-MANAGED | **NO BLIND DELETE — REPLACE WITH NEW CORE VERSION** | Core-managed root forwarder routing web traffic into `public/`. Safe to replace with the new release version. |
| `migrate.php` | 🟢 REPLACE — CORE-MANAGED | **NO BLIND DELETE — REPLACE WITH NEW CORE VERSION** | Core-managed command-line database migration runner. Safe to replace with the new release version. |

---

## 3. The Required RED LIST: Files and Folders You MUST NEVER Delete

These directories and files contain existing website data, user files, installed extensions, or vital configuration. They must **NEVER** be deleted during a normal Core update:

### 🔴 1. DO NOT DELETE `storage/`
- **What it contains**: 
  - `storage/installed.lock`: The critical lockfile that proves the CMS is installed. If deleted, the CMS thinks it is uninstalled and prompts for a fresh install!
  - `storage/plugins/favorite-digital/`: Stores protected digital download files (`files/`), product images (`images/`), and customer payment receipts (`proofs/`).
  - `storage/backups/`: Stores database backup SQL files.
  - `storage/cache/`, `storage/logs/`, `storage/sessions/`: Runtime temporary files.
- **Why deleting it causes problems**: Deleting `storage/` deletes your customer digital products, customer payment proofs, and resets the CMS installation status.
- **Action**: Leave the existing `storage/` directory untouched. Only disposable cache files inside `storage/cache/` may be emptied after updating.

### 🔴 2. DO NOT DELETE `plugins/`
- **What it contains**: Installed plugins such as:
  - `plugins/favorite-digital/` (e-commerce & digital store)
  - `plugins/favorite-pay/` (payment gateway integration)
  - `plugins/favorite-quick-notes/` (admin productivity)
  - `plugins/hello-favorite/` (demo plugin)
  - Any custom plugins built for your site
- **Why deleting it causes problems**: Core updates do not include plugins. Deleting `plugins/` removes all your extensions and breaks plugin-dependent pages.
- **Action**: Leave the existing `plugins/` directory completely untouched during a Core update. Core update != Plugin update.

### 🔴 3. PROTECT USER/CUSTOM THEMES (VS. BUNDLED DEFAULT THEME)
- **What it contains**: Visual frontend presentation themes:
  - `themes/default/` (and `public/themes/default/`): The bundled Favorite CMS Universal Default Theme, which is maintained as part of the Core release.
  - `themes/<custom-theme>/`: Any custom themes, child themes, or community themes created for your site.
- **Why deleting it causes problems**: Deleting your custom themes wipes out your website layout, customized CSS, and frontend templates.
- **Action**: 
  - **Custom / User themes**: Leave untouched. Never overwrite or delete user-created themes during a Core update.
  - **Bundled Default Theme (`themes/default/`)**: If your site uses the bundled Default Theme, update `themes/default/` and `public/themes/default/` from the new Core release package to receive official Core theme improvements (e.g., the integrated frontend account/profile menu). If you made modifications to `themes/default/`, merge the template changes rather than blindly overwriting.

### 🔴 4. DO NOT DELETE OR OVERWRITE `.env`
- **What it contains**: Database hostname, database name, username, password, `APP_KEY`, `APP_URL`, and `ADMIN_PREFIX`.
- **Why deleting or overwriting it causes problems**: Overwriting `.env` disconnects the CMS from MySQL and breaks all password and session encryption.
- **Action**: Keep your existing `.env` file untouched. If a future release introduces a new optional setting, open `.env` and add only that single line manually.

### 🔴 5. DO NOT DELETE `public/uploads/`
- **What it contains**: All images, PDFs, videos, and media files uploaded by administrators, authors, and users through the Media Library and post editor.
- **Why deleting it causes problems**: Deleting `public/uploads/` permanently deletes every picture and document on your live site, causing broken image links across all articles and pages.
- **Action**: Keep `public/uploads/` untouched.

### 🔴 6. DO NOT DELETE `dfre/`
- **What it contains**: `dfre/` is **not part of Favorite CMS Universal Core**. It does not exist in the official Git repository or release ZIP. It may be a server cache, staging directory, or hosting management artifact created by Hostinger or server tools.
- **Why deleting it causes problems**: Because it is not a CMS directory, deleting it may break server-side hosting tools or delete external files.
- **Action**: Leave `dfre/` untouched. Never delete a directory merely because it is absent from the new Core ZIP.

### 🔴 7. DO NOT DELETE `config/`
- **What it contains**: Core configuration templates (`app.php`, `cache.php`, `database.php`, `storage.php`). These pull dynamic values from `.env` via `env()`.
- **Why deleting it causes problems**: Deleting `config/` removes essential system configuration. Blindly overwriting it can destroy custom upload limits or MIME types previously configured in `storage.php`.
- **Action**: Do not delete `config/`. If a new release adds new configuration keys, merge the changes or inspect them before updating.

---

## 4. Understanding the Official Release ZIP Package

When you download `Favorite-CMS-Universal.zip` from GitHub Releases, understand what is inside and what is intentionally excluded:

### Files INCLUDED in the Official Core Release ZIP:
- `app/` (Core PHP classes, controllers, models, services)
- `config/` (Default configuration templates)
- `database/migrations/` (Core database schema migrations)
- `public/` (Contains `index.php`, `assets/`, `themes/default/`, `.htaccess`, and empty `uploads/.gitkeep`)
- `resources/` (Admin views, layouts, mail templates)
- `themes/default/` (Bundled Default Theme with latest core integrations)
- `vendor/` (Pre-packaged production third-party libraries with optimized autoloader)
- `bootstrap.php`, `index.php`, `migrate.php`, `.htaccess`, `LICENSE`, `README.md`

### Files INTENTIONALLY EXCLUDED from the Core Release ZIP:
- **`.env` is NOT included**: Live database credentials exist only on your server.
- **`storage/installed.lock` is NOT included**: Generated only during installation on your server.
- **`storage/plugins/` is NOT included**: Customer downloads and receipts exist only on your server.
- **`public/uploads/*` media files are NOT included**: Your media library is unique to your site.
- **Custom / User themes (`themes/<custom>/`) are NOT included**: Custom themes exist only on your server.
- **Domain plugins (`favorite-digital`, `favorite-pay`) are NOT included**: Domain plugins are standalone products with their own release cycles.
- **`dfre/` is NOT included**: It is not part of the CMS.

> [!IMPORTANT]
> **"The fact that a directory is absent from the new Core ZIP does NOT mean you should delete that directory from the existing website."**  
> Plugins, custom themes, user uploads, `.env`, and hosting artifacts are intentionally not in the Core ZIP because they belong to your specific website!

---

## 5. Critical Separation: Core Update vs. Plugin Update vs. Theme Update

Favorite CMS Universal strictly separates its architecture into independent layers:

```
+-----------------------------------------------------------------------------+
|                          INDEPENDENT UPDATE CYCLES                          |
+---------------------+-------------------------+-----------------------------+
| 1. CORE UPDATE      | 2. PLUGIN UPDATE        | 3. THEME UPDATE             |
+---------------------+-------------------------+-----------------------------+
| Updates Core CMS    | Updates ONE specific    | Updates ONE specific        |
| engine & admin.     | plugin (e.g. Digital).  | theme (e.g. default).       |
|                     |                         |                             |
| * Update:           | * Update:               | * Update:                   |
|   app/, vendor/,    |   plugins/<plugin>/     |   themes/<theme>/           |
|   resources/,       |                         |                             |
|   bootstrap.php     | * Preserve:             | * Preserve:                 |
|                     |   Core, other plugins,  |   Core, plugins, other      |
| * Preserve:         |   all website data      |   themes, database          |
|   plugins/, themes/,|                         |                             |
|   database, uploads/|                         |                             |
+---------------------+-------------------------+-----------------------------+
```

- A **Core update** modifies universal CMS functionality (e.g., admin navigation, security hardening, core routing). It must never modify or replace your plugins or active theme.
- A **Plugin update** (such as Favorite Digital or Favorite Pay) uses that plugin's own dedicated ZIP release and updater. Do not mix plugin files into a Core update.

---

## 6. Website Database vs. Database Migration Files

It is crucial not to confuse the `database/` folder with your live website database:
- **`database/migrations/`**: Contains PHP migration scripts that define table structures (schema). These are Core-managed files.
- **Live Website Database**: Resides in MySQL/MariaDB on your hosting server. It stores your actual posts, pages, user accounts, comments, orders, and configuration.

During a normal Core update:
- 🔴 **NEVER delete the live database.**
- 🔴 **NEVER drop existing database tables.**
- 🔴 **NEVER create a new database for an existing site.**
- 🔴 **NEVER run `php migrate.php --fresh` (which wipes all tables).**

The Core update process merely runs pending migrations to add new columns or tables while **preserving all existing records**.

---

## 7. Data That Must Survive a Normal Core Update

A properly executed Core update leaves all existing website data completely intact:
1. **User Accounts & Roles**: Administrator, editor, author, and subscriber accounts, passwords, and permissions.
2. **Content & Taxonomy**: All blog posts, static pages, categories, tags, and comments.
3. **Site Settings & Menus**: Site title, tagline, active theme selection, custom navigation menus, and widgets.
4. **Media Library Files**: All user images, PDFs, and media stored in `public/uploads/`.
5. **Installed Plugins & Plugin Settings**: Plugins remain in `plugins/` and active in the database.
6. **Digital Products & Orders** *(if Favorite Digital is installed)*: Product listings, prices, secure download files in `storage/plugins/favorite-digital/files/`, orders, and customer entitlements.
7. **Payment Records & Wallets** *(if Favorite Pay is installed)*: Payment gateway configurations, transaction history, customer proof uploads, and wallet balances.

---

## 8. Pre-Update Requirements: Backups First

> [!CAUTION]
> **DO NOT START THE UPDATE UNTIL BOTH BACKUPS EXIST.**  
> A file backup alone is NOT enough if database migrations are applied. Take both backups before touching any files.

### A. Full File Backup (Hostinger File Manager / cPanel)
1. Open **Hostinger hPanel** -> **Files** -> **File Manager** (or cPanel File Manager).
2. Navigate to your CMS root folder (for example: `/public_html/cms/` or `/public_html/`).
3. Select all files and folders.
4. Click **Compress** (ZIP archive) and name it `backup-files-pre-update.zip`.
5. Download `backup-files-pre-update.zip` to your computer or move it outside the webroot for safekeeping.

### B. Full Database Backup (phpMyAdmin)
1. In Hostinger hPanel or cPanel, open **Databases** -> **phpMyAdmin**.
2. In the left panel, select your CMS database (check `.env` for `DB_NAME` if you are unsure).
3. Click the **Export** tab in the top navigation bar.
4. Select **Quick** export method and format **SQL**.
5. Click **Export** (or **Go**).
6. Save the downloaded `.sql` file on your computer.

---

## 9. Updating from Hostinger File Manager: Exact Step-by-Step Procedure

Follow this exact 26-step procedure to update Favorite CMS Universal safely in Hostinger File Manager (e.g. under `/public_html/cms/`):

1. **Open the existing CMS directory**: In Hostinger File Manager, open the folder where your CMS is installed (e.g., `/public_html/cms/`).
2. **Confirm the installation**: Verify you see `.env`, `bootstrap.php`, `index.php`, and `app/`.
3. **Create a full file backup**: Select all files and folders, compress to `backup-files-pre-update.zip`, and download it.
4. **Create a full database backup**: In phpMyAdmin, export your database to a `.sql` file.
5. **Enable maintenance mode (recommended)**: Create a temporary `maintenance.html` in your CMS root or enable your hosting provider's maintenance mode to prevent incoming orders during the update.
6. **Upload the new Core release ZIP**: Upload `Favorite-CMS-Universal.zip` into your CMS directory (e.g., `/public_html/cms/`).
7. **Extract into a temporary directory**: Create a temporary folder named `core-update-temp/`. Move `Favorite-CMS-Universal.zip` inside `core-update-temp/` and extract it there.
8. **DO NOT delete the existing CMS directory**: Never delete `/public_html/cms/` or any other parent folder!
9. **Compare extracted Core package with the existing installation**: Look at `core-update-temp/` side-by-side with your existing files.
10. **Preserve every RED directory/file**: Confirm that `.env`, `storage/`, `plugins/`, custom user themes in `themes/`, and `public/uploads/` will remain untouched.
11. **Do not blindly replace YELLOW directories/files**: Do not replace `.htaccess` or `config/` without checking for custom rules.
12. **Replace confirmed GREEN Core directories**:
    - Move `core-update-temp/app/` to replace live `app/`.
    - Move `core-update-temp/resources/` to replace live `resources/`.
    - Move `core-update-temp/vendor/` to replace live `vendor/`.
13. **Copy new migration files into `database/migrations/`**:
    - Open `core-update-temp/database/migrations/`.
    - Copy any newly added `.php` migration files into your live `database/migrations/` directory.
    - Do NOT delete existing migration files.
14. **Preserve existing user uploads**: Leave `public/uploads/` completely untouched.
15. **Preserve installed plugins**: Leave `plugins/` completely untouched.
16. **Handle themes correctly**:
    - **User/custom themes**: Leave any custom themes in `themes/<your-theme>/` completely untouched.
    - **Bundled default theme**: If your site uses the bundled Default Theme, copy `core-update-temp/themes/default/` over live `themes/default/` and `core-update-temp/public/themes/default/` over live `public/themes/default/` to apply Core theme improvements (such as the account/profile menu). If you customized files inside `themes/default/`, merge the template changes instead of blindly overwriting.
17. **Preserve `.env`**: Never overwrite or delete `.env`.
18. **Handle `.htaccess` carefully**: If the release notes describe new security rules, update `.htaccess` while preserving any custom SSL or host rules.
19. **Leave `dfre/` untouched**: Do not modify or delete `dfre/`.
20. **Replace individual root Core files**:
    - Copy `core-update-temp/bootstrap.php` over live `bootstrap.php`.
    - Copy `core-update-temp/index.php` over live `index.php`.
    - Copy `core-update-temp/migrate.php` over live `migrate.php`.
    - Copy `core-update-temp/public/index.php` over live `public/index.php`.
    - Copy `core-update-temp/public/assets/` over live `public/assets/`.
21. **Delete the temporary directory**: Delete `core-update-temp/` and the uploaded ZIP file to keep your server clean.
22. **Check migration status**:
    - Open SSH / Terminal in Hostinger hPanel or cPanel:
      ```bash
      cd public_html/cms
      php migrate.php --status
      ```
23. **Run supported migrations**:
    - Execute pending migrations safely:
      ```bash
      php migrate.php
      ```
    *(If you do not have SSH access, see [Section 10](#10-running-database-migrations) for the one-time Cron Job method).*
24. **Clear temporary cache and flush OPcache**:
    - In File Manager, open `storage/cache/` and delete all temporary cache files. **Keep the `storage/cache/` directory itself.**
    - **Flush LiteSpeed / PHP OPcache**: If your hosting server uses LiteSpeed Web Server (`lsphp`, standard on Hostinger) or standard PHP OPcache, PHP bytecode may be cached in memory. Touch/resave your CMS root `.htaccess` in File Manager or restart PHP via Hostinger hPanel (**Advanced** -> **PHP Configuration** -> **Restart PHP**) so updated theme templates and Core scripts load immediately.
25. **Disable maintenance mode**: Delete `maintenance.html` (if created in Step 5).
26. **Verify and keep backup**: Perform the [Post-Update Verification Checklist](#12-post-update-verification-checklist). Keep your backup files safe until you are 100% sure everything works.

---

## 10. Running Database Migrations

### How Favorite CMS Universal Migrations Work
- Core migrations reside in `database/migrations/*.php`.
- Applied migrations are recorded in the `cms_migrations` table with batch numbers.
- Migrations are **incremental and idempotent**: running the migration runner applies only newly added migrations and leaves existing tables and data completely untouched.

### The Real Commands Confirmed in `migrate.php`:
```bash
# 1. Check which migrations have run and which are pending
php migrate.php --status

# 2. Safely run all pending migrations
php migrate.php
```

> [!CAUTION]
> **🔴 NEVER RUN `php migrate.php --fresh` ON A LIVE WEBSITE!**  
> The `--fresh` command disables foreign key checks, drops all database tables, and destroys all website data. It requires typing 'yes' to proceed. Never use `--fresh` for an update. Always use standard `php migrate.php`.

### Alternative for Shared Hosting Without SSH Access (One-Time Cron Job)
If your shared hosting plan does not include SSH / Terminal:
1. In Hostinger hPanel or cPanel, navigate to **Cron Jobs** / **Scheduled Tasks**.
2. Add a new cron job scheduled to run once in the next 2 minutes:
   ```bash
   /usr/bin/php /home/u123456789/domains/yourdomain.com/public_html/cms/migrate.php
   ```
   *(Replace with the actual absolute path to your `migrate.php`)*.
3. Check the cron email output or log file. Once you see:
   `migration(s) applied successfully` or `Nothing to migrate. All migrations are up to date.`
4. **Immediately delete the cron job.**

---

## 11. Safe Rollback Procedure

If you encounter an issue during or immediately after an update, follow this safe rollback process:

1. **Keep Maintenance Mode Enabled**: Keep the site in maintenance mode while rolling back.
2. **Do Not Delete Your Backups**: Your pre-update backups are your safety net.
3. **Determine Whether Migrations Ran**:
   - Check if `php migrate.php` was executed during the update.
   - If migrations were NOT executed: Simply restore the previous Core files from your `backup-files-pre-update.zip`.
   - If migrations WERE executed: You must restore your database backup first because the database schema was modified.
4. **Database Rollback (If Migrations Ran)**:
   - Open **phpMyAdmin**.
   - Select your CMS database.
   - Go to the **Import** tab.
   - Select `backup-database-pre-update.sql` and click **Import**.
   - This restores your exact pre-update database tables and records.
5. **Restore Previous Core Files**:
   - From your `backup-files-pre-update.zip`, restore the previous versions of `app/`, `resources/`, `vendor/`, `bootstrap.php`, and `public/index.php`.
   - Because `.env`, `public/uploads/`, `storage/`, `plugins/`, and `themes/` were never deleted, all your content remains intact.
6. **Clear Application Cache**:
   - Delete temporary files inside `storage/cache/`.
7. **Verify Site and Disable Maintenance Mode**:
   - Verify frontend and admin login. Once verified, disable maintenance mode.

---

## 12. Post-Update Verification Checklist

After completing the update, check off each item to ensure your site is running perfectly:

- [ ] **Homepage**: Loads cleanly with HTTP 200 (no 500 error, no fatal PHP errors).
- [ ] **Admin Login**: Access `/admin` and log in successfully.
- [ ] **Admin Navigation**: Admin sidebar navigation and submenus expand cleanly on click.
- [ ] **Dashboard**: Admin dashboard displays correct site statistics.
- [ ] **Posts & Pages**: Existing posts and pages viewable on frontend and editable in editor.
- [ ] **Media Library**: Existing uploaded images display correctly in `/admin/media`.
- [ ] **New Upload Test**: Uploading a new test image works without storage errors.
- [ ] **Comments**: Existing comments remain present; test submission works.
- [ ] **Users & Roles**: User list displays all administrators and authors.
- [ ] **Settings**: Site settings and permalinks remain preserved.
- [ ] **Menus**: Frontend navigation menus display correctly.
- [ ] **Themes**: Active theme renders properly without missing template errors.
- [ ] **Plugins Page**: Access `/admin/plugins` — all installed plugins remain listed and active.
- [ ] **No PHP Fatal Errors**: Check `storage/logs/` for unexpected exceptions.
- [ ] **Maintenance Mode Disabled**: Ensure `maintenance.html` is removed.

### If Favorite Digital and Favorite Pay are Installed:
- [ ] **Digital Products**: All existing digital products remain listed with correct prices.
- [ ] **Orders & Entitlements**: Existing customer orders, purchase history, and download links remain intact.
- [ ] **Secure Files**: Digital product file downloads in `storage/plugins/favorite-digital/` work for authorized users.
- [ ] **Payment Settings**: Gateway credentials and payment methods remain configured.
- [ ] **Wallets & Proofs**: Customer wallet balances and payment proofs remain preserved.

---

## 13. Common Dangerous Mistakes (DO NOT DO THIS)

Avoid these dangerous mistakes that lead to accidental data loss:

- ❌ **DO NOT delete `/public_html/cms/` or the entire CMS directory before uploading the new release.**
- ❌ **DO NOT delete or drop the existing website database.**
- ❌ **DO NOT create a new database for an existing site.**
- ❌ **DO NOT run the web installer (`/install`) on an existing site.**
- ❌ **DO NOT delete `storage/` blindly.**
- ❌ **DO NOT delete `plugins/` blindly.**
- ❌ **DO NOT delete `themes/` blindly.** (Custom user themes must be protected; only update `themes/default/` and `public/themes/default/` if using the bundled default theme).
- ❌ **DO NOT delete `.env`.**
- ❌ **DO NOT blindly overwrite `.env`.**
- ❌ **DO NOT delete `public/uploads/`.**
- ❌ **DO NOT delete `dfre/` without confirming what it is.**
- ❌ **DO NOT delete `.htaccess` without understanding its role.**
- ❌ **DO NOT delete `database/` without inspecting its contents.**
- ❌ **DO NOT manually delete database tables.**
- ❌ **DO NOT run `php migrate.php --fresh`.**
- ❌ **DO NOT replace plugin directories during a Core-only update.**
- ❌ **DO NOT assume that every directory in the new ZIP should overwrite the same directory in the live site.**
