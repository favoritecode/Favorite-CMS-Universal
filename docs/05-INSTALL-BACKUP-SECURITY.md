# Favorite CMS Universal &mdash; Installer, Backup, Migration & Security

## 1. WordPress-Like Easy Installation

Favorite CMS Universal provides a streamlined, accessible 5-step web installation experience modeled after the simplicity of WordPress:

```
Step 1: Environment & Requirements Check
                  ↓
Step 2: Database Setup (Recommended / Minimal Input)
                  ↓
Step 3: Site Information (Name & Auto-Detected URL)
                  ↓
Step 4: Administrator Account (Username, Email, Password)
                  ↓
Step 5: Automatic Installation & Lock
                  ↓
Ready! Instant Redirect to Dashboard / Site
```

---

## 2. Automatic & Recommended Database Setup

### Automatic Detection & Defaulting
To remove technical friction for shared-hosting users, the installer automatically defaults or detects:
- **Database Host**: Automatically defaults to `localhost` (the standard on Hostinger, cPanel, DirectAdmin, and XAMPP).
- **Database Port**: Automatically defaults to `3306` (standard MySQL/MariaDB TCP port).
- **Table Prefix**: Automatically generates a unique, isolated table prefix (e.g., `fvcms_a7b3_`) to prevent table collision without requiring user configuration.
- **Environment Credentials**: Detects credentials if already provided via standard hosting environment variables (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DATABASE_URL`, or `MYSQL_DATABASE`).
- **Site URL & Base Path**: Intelligently computed from the active HTTP request, respecting HTTPS, custom ports, subdomains (`blog.example.com`), and subdirectories (`example.com/cms/`).

### Technical Boundaries of Automatic Discovery
> [!IMPORTANT]
> **No Magic Password Discovery**: When running on shared hosting (such as Hostinger or cPanel), a PHP web application cannot magically discover MySQL passwords that the hosting environment does not expose. 
> 
> Therefore, in **Recommended Setup Mode**, the user is asked only for the minimum required information:
> 1. **Database Name** (from hosting control panel, e.g. `u123456_mycms`)
> 2. **Database Username** (e.g. `u123456_admin`)
> 3. **Database Password**
>
> All other technical parameters (Host, Port, Table Prefix, Privileged Account) are pre-configured automatically and hidden from the normal path.

### Advanced / Manual Database Setup
For unusual hosting environments (remote database servers, non-standard ports, custom prefix requirements, or local development with privileged root accounts), an **Advanced Database Settings** toggle expands:
- Custom Database Host (IP address or hostname)
- Custom Database Port
- Custom Table Prefix
- Optional Privileged Database Account (`CREATE DATABASE`, `CREATE USER`, `GRANT`) for local environments or unmanaged VPS.

---

## 3. Humanized Database Error Handling

Database connection errors are categorized into plain-language, actionable instructions without ever exposing passwords or sensitive details in error messages:
- **Access Denied (1045 / 28000)**: *"Incorrect database username or password. Please verify the credentials provided by your hosting control panel."*
- **Unknown Database (1049 / 42000)**: *"The database '...' does not exist on host '...'. Please create the database first in your hosting control panel (e.g. cPanel MySQL Databases), or verify the spelling."*
- **Host Unreachable (2002 / HY000)**: *"Could not reach database host '...'. On shared hosting (such as Hostinger or cPanel), the host should almost always be 'localhost'. Ensure the database service is active."*
- **Server Timeout (2006)**: *"The connection to database host '...' timed out. Please verify that your hosting server allows local MySQL connections."*
- **Insufficient Privileges (1142 / 42000)**: *"The database user has insufficient privileges. Please ensure your database user has been granted ALL PRIVILEGES in your hosting control panel."*

---

## 4. Core CMS Backup & Restore Subsystem

Backup and Restore is built directly into Favorite CMS Core as a native portability engine (**not a plugin**).

### Backup Package Format
Backups are packaged as self-contained `.zip` archives saved under `storage/backups/`:
- `manifest.json`: Structured metadata containing:
  - `manifest_version`
  - `cms_name` and `cms_version`
  - `schema_version` (migration count)
  - `created_at` (ISO 8601 timestamp)
  - `site_name` and `site_url`
  - `table_prefix`
  - `tables` (dictionary of dumped tables and row counts)
  - `file_count` and `sql_checksum` (SHA-256)
- `database.sql`: Clean, portable SQL dump using chunked streaming (500 rows per query) to avoid high memory consumption on shared hosting.
- `public/uploads/`: All user-uploaded media files.
- `themes/`: Installed presentation themes.
- `plugins/`: Installed extension plugins.

### Production Backup Exclusions
Backups strictly exclude:
- `.git/` and `.github/`
- `tests/` and test artifacts
- `.env` and environment backups
- `storage/installed.lock`
- Runtime logs (`storage/logs/*.log`)
- User session data (`storage/sessions/sess_*`)
- Application caches (`storage/cache/*`)

---

## 5. Site Restoration & Domain Migration Workflow

Sites can be restored from:
1. **Admin Control Panel**: Under `/admin/tools` via the Restore Site from Backup interface.
2. **Fresh Installation Wizard**: Directly at `/install` using the **Restore Existing Site from Backup** tab, allowing 1-click site migrations to a new host!

### Format-Aware URL & Domain Migration
When restoring to a different domain or protocol (e.g. `http://oldsite.com` &rarr; `https://newsite.com`):
- Automatically updates `site_url` in the `settings` table.
- Replaces old domain references across `posts` (content and excerpt) and `pages`.
- Updates `media` file URLs.
- **Structured String Preservation**: For `theme_options` and widget configurations containing JSON or serialized data, uses recursive format-aware replacement to ensure JSON data is not corrupted.

---

## 6. Security Architecture

### Zip-Slip & Path Traversal Shield
The restore engine inspects all archive entries prior to extraction. Any entry containing directory traversal elements (`../`, `..\`, leading `/`, or absolute drive paths `C:`) is immediately rejected with a `SecurityException`.

### Uploads Protection
Whenever uploads are restored, an Apache `.htaccess` security barrier is written into `public/uploads/` disabling PHP script execution:
```apache
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
<IfModule mod_php8.c>
    php_flag engine off
</IfModule>
RemoveHandler .php .phtml .php3 .php4 .php5 .phar .cgi .pl .py .sh
```

### Installation Locking
Once installation or restoration completes, `storage/installed.lock` is written and installer routes are permanently locked. All subsequent visits to `/install` are redirected to the home page or admin login.
