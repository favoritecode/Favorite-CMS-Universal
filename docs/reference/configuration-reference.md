# Configuration Reference

Complete listing of all environment variables supported in `.env`.

---

## 1. Application Options

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `APP_NAME` | string | `"Favorite CMS"` | Title of the application instance. |
| `APP_ENV` | string | `"local"` | Environment mode (`"local"`, `"production"`, `"testing"`). |
| `APP_URL` | string | `"http://localhost"` | Root URL of the public website. |
| `APP_DEBUG` | boolean | `true` | Display detailed error stack traces. Must be `false` in production. |
| `APP_KEY` | string | `""` | 32-character key for encryption and HMAC signing. |

---

## 2. Database Options

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `DB_DRIVER` | string | `"mysql"` | PDO driver name (MySQL/MariaDB). |
| `DB_HOST` | string | `"127.0.0.1"` | Database server hostname or IP address. |
| `DB_PORT` | integer | `3306` | MySQL port number. |
| `DB_DATABASE` | string | `"favorite_cms"` | Database name. |
| `DB_USERNAME` | string | `"root"` | MySQL user account. |
| `DB_PASSWORD` | string | `""` | MySQL password. |

---

## 3. Session & Cache Options

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `CACHE_DRIVER` | string | `"file"` | Cache engine (`"file"` stores in `storage/cache`). |
| `SESSION_DRIVER` | string | `"file"` | PHP session storage handler. |
| `SESSION_LIFETIME` | integer | `120` | Inactivity timeout in minutes before expiring sessions. |
