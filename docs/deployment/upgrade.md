# Upgrading Favorite CMS Universal

How to safely upgrade Favorite CMS to new versions without losing custom themes, plugins, or database records.

---

## 1. Upgrade Safety Rules

1. **Always backup** your database and `.env` file before initiating an upgrade.
2. Never overwrite `public/uploads/`, `plugins/`, `themes/`, or `.env`.

---

## 2. Upgrade Steps

1. Replace core directories:
   - `app/`
   - `resources/`
   - `bootstrap.php`
   - `public/index.php`
2. Update Composer dependencies:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
3. Run database migrations:
   Database migrations in `database/migrations/` are idempotent. If a new version includes new tables or columns, log into the admin dashboard or run the migration runner to apply updates automatically.
