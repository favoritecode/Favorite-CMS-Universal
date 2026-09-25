# System Requirements

Favorite CMS Universal v1.0.0 is intentionally designed to run smoothly on budget shared hosting environments as well as dedicated servers and local developer workstations.

---

## PHP Requirements

| Directive | Requirement | Notes |
|---|---|---|
| **PHP Version** | `PHP 8.1.0` or higher | Tested on PHP 8.1, 8.2, 8.3, and 8.5. |
| **Strict Types** | Supported | Core files declare `strict_types=1`. |

### Required PHP Extensions
These extensions are verified automatically by the installer (`EnvironmentChecker`):
- **`pdo` & `pdo_mysql`**: Required for secure database transactions and parameterized queries.
- **`mbstring`**: Required for multibyte string manipulation and UTF-8 content handling.
- **`json`**: Required for manifest parsing (`theme.json`, `plugin.json`), settings storage, and API responses.
- **`session`**: Required for user authentication, CSRF tokens, and flash messages.
- **`fileinfo`**: Required for reliable MIME-type detection during media uploads.
- **`gd`**: Required for dynamic image resizing and thumbnail generation in the Media Library.

### Recommended Extensions
- **`zip`**: Required for generating full-site backup archives, restoring backups, and installing zipped themes or plugins.
- **`curl`**: Recommended for remote update checks and external API integrations.
- **`xml`**: Recommended for sitemap generation and Blogger/WordPress content imports.

### Recommended `php.ini` Settings
```ini
upload_max_filesize = 64M     ; Or higher depending on intended video/media size
post_max_size = 64M           ; Must be >= upload_max_filesize
memory_limit = 128M           ; 256M recommended for large backup archives
max_execution_time = 120      ; 300 recommended for large database restores
```

---

## Database Requirements

- **Supported Engines:** MySQL 5.7+ or MariaDB 10.3+.
- **Storage Engine:** **InnoDB** is strictly required for ACID transactions and foreign key integrity.
- **Collation:** `utf8mb4_unicode_ci` or `utf8mb4_general_ci` (full emoji and international character support).
- **User Privileges Required:**
  - `SELECT`, `INSERT`, `UPDATE`, `DELETE`
  - `CREATE`, `ALTER`, `DROP`, `INDEX`
  - `LOCK TABLES` (recommended for database backups)

---

## Web Server Requirements

### Apache 2.4+ or LiteSpeed
- **`mod_rewrite`** must be enabled for clean URL rewriting (`/admin`, `/posts/slug`).
- **`AllowOverride All`** must be enabled so root and `/public` `.htaccess` rules take effect.

### Nginx (Alternative)
When hosting with Nginx without Apache, route all non-static requests through `index.php`:
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ /\. {
    deny all;
}

location ^~ /storage/ {
    deny all;
}
```

---

## Directory Permissions

The following directories must be writable by the web server process (`www-data`, `nobody`, `apache`):

| Directory | Purpose | Required Permission |
|---|---|---|
| `.` (CMS root) | Writing initial `.env` configuration during setup | Writable during install |
| `storage/` | System lockfiles and metadata | `755` or `775` |
| `storage/cache/` | Template and system cache | `755` or `775` |
| `storage/logs/` | Error and audit logs | `755` or `775` |
| `storage/sessions/` | Encrypted session files | `755` or `775` |
| `storage/backups/` | Generated backup archives | `755` or `775` |
| `storage/temp/` | Temporary file assembly during zip/updates | `755` or `775` |
| `public/uploads/` | Uploaded images, documents, and avatars | `755` or `775` |
| `plugins/` | Dynamic plugin installations | Writable for in-app installs |
| `themes/` | Dynamic theme installations | Writable for in-app installs |
