# System Requirements

This document defines the server, PHP, database, and environmental requirements for running **Favorite CMS Universal**.

---

## 1. Production / Shared Hosting Environment

Favorite CMS Universal is designed specifically to run reliably on budget and standard shared hosting (cPanel, DirectAdmin, Plesk, LAMP, LiteSpeed) without requiring root shell access, Docker, or external queue workers.

### Server Requirements
- **Operating System**: Linux (Ubuntu, Debian, AlmaLinux, Rocky Linux, CloudLinux, CentOS) or FreeBSD / Windows Server.
- **Web Server**: Apache 2.4+ with `mod_rewrite` enabled, or LiteSpeed / OpenLiteSpeed with `.htaccess` rewrite support.
- **Root Shell Access**: **Not Required**.
- **Cron Jobs**: Optional (used only if scheduled maintenance or background publication is configured).
- **Node.js / Python / Ruby**: **Not Required** in any capacity.
- **Composer / Git**: **Not Required** on production hosting (the production ZIP distribution includes all pre-compiled vendor autoloaders and dependencies).

### PHP Requirements
- **PHP Version**: PHP 8.1.0 or higher (PHP 8.2 or 8.3 recommended).
- **PHP SAPI**: Compatible with FPM, FastCGI, LSAPI, and Apache mod_php.
- **Required PHP Extensions**:
  - `pdo`: Core database abstraction.
  - `pdo_mysql`: MySQL / MariaDB database driver.
  - `mbstring`: Multibyte string processing for international characters and titles.
  - `json`: JSON encoding and decoding for configuration, widgets, and plugins.
  - `session`: Authentication state and CSRF token persistence.
  - `fileinfo`: MIME-type detection for secure file uploads.
  - `gd`: Image thumbnail generation and verification.
- **Recommended Extensions**:
  - `curl`: External HTTP requests for update checks and webhooks.
  - `zip`: Extraction and installation of plugin/theme packages.
  - `xml` / `dom`: Structured feed and sitemap generation.

### Recommended PHP Configuration
```ini
; Recommended php.ini settings for standard sites
memory_limit = 256M
upload_max_filesize = 64M
post_max_size = 64M
max_execution_time = 120
max_input_time = 120
date.timezone = UTC
session.cookie_httponly = 1
session.cookie_samesite = "Lax"
```

> **Note on Large Media Uploads**:  
> Favorite CMS supports role-configured upload limits up to 7 GB for Administrators, 500 MB for Moderators, and 200 MB for Normal Users. However, the effective limit is strictly bounded by your host's PHP settings (`upload_max_filesize`, `post_max_size`, `memory_limit`) and web server timeout directives (`max_execution_time`, `FcgidMaxRequestLen`, `client_max_body_size`).

### Database Requirements
- **Database Engine**: MySQL 5.7+ or MariaDB 10.3+.
- **Recommended Engine**: MySQL 8.0+ or MariaDB 10.6+.
- **Table Engine**: InnoDB (required for ACID compliance and foreign key/indexing support).
- **Collation**: `utf8mb4_unicode_ci` (full emoji and international multilingual character support).
- **User Privileges Required**:
  - `SELECT`, `INSERT`, `UPDATE`, `DELETE`
  - `CREATE`, `ALTER`, `DROP`, `INDEX` (for installer schema migrations and plugin table additions).

---

## 2. Local Development Environment

For local software development, running unit tests, or creating custom plugins and themes:

- **PHP**: PHP 8.1+ CLI with PDO MySQL enabled.
- **Local Server Stack**: XAMPP, WampServer, Laragon, or PHP's built-in server (`php -S localhost:8000`).
- **Composer**: Composer 2.x (required for running `composer install` and `composer test` on raw source checkouts).
- **Database**: Local MySQL or MariaDB instance (standard XAMPP default on port 3306).
- **Testing Framework**: PHPUnit 10+ (installed via Composer dev dependencies).

