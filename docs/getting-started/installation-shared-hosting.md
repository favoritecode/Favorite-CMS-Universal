# Shared Hosting Installation Guide (cPanel / Apache / LAMP)

Favorite CMS Universal is purpose-built to run effortlessly on ordinary shared hosting. It does **not** require root access, VPS, Docker, Node.js background processes, Redis, or Python.

---

## 1. Shared Hosting Requirements

- **Web Server**: Apache 2.4+ with `mod_rewrite` enabled
- **PHP Version**: PHP 8.1 or higher (PHP 8.2+ recommended)
- **Required PHP Extensions**:
  - `pdo_mysql`
  - `mbstring`
  - `openssl`
  - `json`
  - `fileinfo`
  - `gd` or `imagick` (for media thumbnail generation)
  - `zip` (for plugin/theme zip installations)
- **Database**: MySQL 5.7+ or MariaDB 10.3+

---

## 2. Directory Architecture for Shared Hosting

For maximum security on cPanel and Apache shared hosting, keep application core files **outside** the public web root:

```
/home/username/
├── favorite_cms_core/        <-- Application core, app/, storage/, themes/, plugins/
│   ├── app/
│   ├── database/
│   ├── storage/
│   ├── themes/
│   ├── plugins/
│   ├── vendor/
│   ├── bootstrap.php
│   └── .env
│
└── public_html/              <-- Public web root (contents of public/)
    ├── assets/
    ├── index.php
    ├── .htaccess
    └── robots.txt
```

### Adjusting `public_html/index.php`
Open `public_html/index.php` and update the bootstrap path:

```php
define('APP_ROOT', dirname(__DIR__) . '/favorite_cms_core');
require_once APP_ROOT . '/bootstrap.php';
```

---

## 3. File Upload & Permissions

1. Upload the project files using cPanel File Manager or FTP (SFTP).
2. Set directory permissions:
   - `storage/` &rarr; `775` or `755` (writable by web server)
   - `storage/logs/` &rarr; `775` or `755`
   - `storage/cache/` &rarr; `775` or `755`
   - `public_html/uploads/` &rarr; `775` or `755`

---

## 4. Database Creation in cPanel

1. Log into your cPanel dashboard.
2. Under **Databases**, open **MySQL Database Wizard**.
3. Create a database: `username_favcms`.
4. Create a database user: `username_cmsuser` with a strong password.
5. Grant **All Privileges** to the user on this database.

---

## 5. Configure `.env`

Create or edit `.env` in your application core directory:

```env
APP_NAME="Favorite CMS"
APP_ENV=production
APP_URL=https://yourdomain.com
APP_DEBUG=false

DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=username_favcms
DB_USERNAME=username_cmsuser
DB_PASSWORD=your_secure_password

CACHE_DRIVER=file
SESSION_DRIVER=file
SESSION_LIFETIME=120
```

---

## 6. Apache URL Rewriting (`.htaccess`)

Ensure your `public_html/.htaccess` contains:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On

    # Prevent directory browsing
    Options -Indexes

    # Handle Authorization Header
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    # Redirect Trailing Slashes If Not A Folder...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} (.+)/$
    RewriteRule ^ %1 [L,R=301]

    # Send Requests To Front Controller...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>
```

---

## 7. Web Installation

1. Browse to your domain: `https://yourdomain.com/`
2. Follow the web installer wizard.
3. Configure your initial Site Title and Admin Account.
4. Once completed, your site is live and the installation is permanently secured with `storage/installed.lock`.
