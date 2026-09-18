# Environment & Configuration Guide

Favorite CMS Universal uses standard `.env` configuration parsed on boot by `FavoriteCMS\Core\Config`.

---

## 1. Environment File (`.env`)

A default `.env.example` is provided in the repository root.

```env
# Application Identity & Mode
APP_NAME="Favorite CMS"
APP_ENV=local               # 'local' or 'production'
APP_URL=http://favorite-cms.local
APP_DEBUG=true              # Set to false in production
APP_KEY=                    # 32-character encryption key

# Database Connection (MySQL / MariaDB)
DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=favorite_cms
DB_USERNAME=root
DB_PASSWORD=

# Cache and Sessions
CACHE_DRIVER=file           # 'file' (stored in storage/cache)
SESSION_DRIVER=file         # 'file' (standard PHP session engine)
SESSION_LIFETIME=120        # Session timeout in minutes
```

---

## 2. Configuration Helper Functions

Within application code, plugins, or themes, you can read configuration values using standard helpers:

```php
// Retrieve configuration value with default fallback
$appName = config('app.name', 'Favorite CMS');
$isDebug = config('app.debug', false);

// Read raw environment variable
$dbHost = env('DB_HOST', '127.0.0.1');
```

---

## 3. Persistent Dynamic Settings (`settings` Table)

Application runtime settings (Site Name, Tagline, Posts Per Page, Active Theme, Active Plugins, SEO Meta) are stored in the database `settings` table and cached in memory.

### Reading Settings:
```php
use FavoriteCMS\Models\Setting;

$siteName = Setting::get('general', 'site_name', 'Favorite CMS');
$activeTheme = Setting::get('theme', 'active_theme', 'default');
$postsPerPage = Setting::get('reading', 'posts_per_page', 10);
```

### Writing Settings:
```php
Setting::set('general', 'site_name', 'My New Brand');
Setting::set('reading', 'posts_per_page', 15, 'integer');
```
