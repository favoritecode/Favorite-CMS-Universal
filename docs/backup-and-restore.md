# Backup and Restore Subsystem

Favorite CMS Universal v1.0.0 features a native, format-aware **Site Backup and Portability Subsystem** implemented via `FavoriteCMS\Services\BackupService` and `RestoreService`.

---

## Overview

Unlike solutions that require external database dump utilities (`mysqldump`) or shell access, Favorite CMS generates and restores self-contained backup archives directly within standard PHP runtime limits.

### Backup Archive Contents
Every generated `.zip` backup archive contains:
- **`manifest.json`:** Structured metadata recording CMS version, schema version, creation timestamp, table names, file inventory, and SHA-256 database checksum.
- **`database.sql`:** Chunked streaming SQL dump containing all core and plugin table structures, primary keys, indexes, and records.
- **`uploads/`:** Complete copy of media library files and uploaded user avatars.
- **`themes/`:** Installed custom themes and configurations.
- **`plugins/`:** Installed custom plugins.

---

## Creating a Full Site Backup

1. In the admin panel, navigate to **Tools &rarr; Backups &amp; Health** (`/admin/tools`).
2. Click **Create Full Site Backup**.
3. The `BackupService` will:
   - Stream the database tables into `database.sql` without exhausting memory.
   - Calculate SHA-256 checksum of the generated SQL dump.
   - Assemble `uploads/`, `themes/`, and `plugins/` into the archive.
   - Save the finalized package to `storage/backups/`.
4. Once completed, you can download the `.zip` archive to your local computer for safekeeping.

---

## Restoring a Backup

Backups can be restored through two pathways:

### Method 1: 1-Click Restore During Fresh Installation
When moving a website to a brand new server:
1. Extract a fresh copy of Favorite CMS Universal onto the new server.
2. Open the domain in your browser to reach the installer welcome screen (`/install`).
3. Click **Restore from Backup**.
4. Upload your existing Favorite CMS `.zip` backup archive and enter the new database connection credentials.
5. The installer restores the complete site, imports the database, and rewrites URLs to the new domain automatically.

### Method 2: Restore via Admin Panel
1. Navigate to **Tools &rarr; Backups &amp; Health** (`/admin/tools`).
2. Locate the backup archive in the list or upload an archive file.
3. Click **Restore**.
4. The system validates the `manifest.json` and checks the SQL dump hash before restoration begins.

---

## Format-Aware Domain & URL Migration

When migrating a site from one domain to another (e.g. `http://localhost/site` &rarr; `https://production.com`), simple string find-and-replace corrupts JSON settings and serialized PHP arrays.

Favorite CMS Universal's `RestoreService` employs a **format-aware URL transformer**:
- Standard string columns in `posts`, `pages`, and `taxonomies` have URLs updated cleanly.
- JSON-encoded fields (such as widget configurations and theme layout options) are decoded, recursively transformed, and re-encoded.
- Site settings in the `settings` table are updated to reference the new domain without manual database editing.
