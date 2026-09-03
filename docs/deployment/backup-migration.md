# Backup and Migration Guide

Moving a Favorite CMS Universal installation between local environments or from local development to production shared hosting.

---

## 1. Backing Up the CMS

A full backup consists of three elements:

### Element 1: Database Export
Export the database using `mysqldump` or phpMyAdmin:
```bash
mysqldump -u root -p favorite_cms > backup_favorite_cms.sql
```

### Element 2: File System
Archive the project directory, ensuring `storage/installed.lock`, `themes/`, `plugins/`, and `public/uploads/` are included:
```bash
zip -r site_backup.zip . -x "storage/logs/*.log"
```

### Element 3: Configuration
Safely record your `.env` settings.

---

## 2. Restoring or Migrating to a New Server

1. **Extract Files**: Extract the backup archive to your destination server.
2. **Import Database**:
   ```bash
   mysql -u db_user -p db_name < backup_favorite_cms.sql
   ```
3. **Configure `.env`**: Update `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, and `APP_URL` to match the new destination.
4. **Permissions**: Ensure `storage/` and `public/uploads/` are writable (`0775`).
5. **Verify Installation State**: Because `storage/installed.lock` and the populated database exist, Favorite CMS immediately launches in normal operational mode without ever asking you to reinstall!
