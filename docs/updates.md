# Core Update System

Favorite CMS Universal v1.0.0 includes a native **Core Update Engine** located in `app/Services/Update/`, providing in-app update management without requiring command-line or Git terminal access.

---

## Update Engine Architecture

The update subsystem consists of five coordinated services:
- **`UpdateChecker`:** Queries the official GitHub repository releases API to identify new stable versions.
- **`MaintenanceMode`:** Activates an atomic maintenance screen preventing visitors from interacting with the site during file replacement.
- **`UpdateDownloader`:** Downloads official release ZIP packages and verifies file checksums.
- **`UpdateService`:** Orchestrates the upgrade lifecycle, extracts files, validates copied bytes, and executes schema migrations.
- **`UpdateRollback`:** Retains a complete pre-update file snapshot, allowing immediate recovery if file copy or database migrations fail.

---

## In-App Update Lifecycle

When an update is executed from **Tools &rarr; Core Updates** (`/admin/updates`):

```
┌────────────────────────────────────────────────────────┐
│ 1. Check for New Release                               │
├────────────────────────────────────────────────────────┤
│ 2. Create Pre-Update Snapshot Backup                   │
├────────────────────────────────────────────────────────┤
│ 3. Activate Maintenance Mode (With Admin Bypass Token) │
├────────────────────────────────────────────────────────┤
│ 4. Extract & Verify Copied Bytes                       │
├────────────────────────────────────────────────────────┤
│ 5. Execute Database Migrations (Migrator)              │
├────────────────────────────────────────────────────────┤
│ 6. Clear Caches & Deactivate Maintenance Mode          │
└────────────────────────────────────────────────────────┘
```

1. **Pre-Update Verification:** Downloads release asset and parses release checksums (plain text and Markdown formats).
2. **Snapshot Creation:** Creates an atomic backup snapshot in `storage/temp/snapshot_backup.zip`.
3. **Maintenance Shield:** Places the public site into maintenance mode with HTTP 503 response. The logged-in administrator is issued a session bypass token (`_maintenance_bypass_token`) so they can oversee the process.
4. **Byte Verification:** Extracted files are audited to confirm the destination byte count matches source files.
5. **Database Migration:** Executes any new schema migrations in `database/migrations/`.
6. **Recovery / Rollback:** If any step fails, the system immediately unpacks the snapshot backup, restoring files to their exact pre-update state.

---

## Safe Manual Core Updates

If you prefer to perform updates manually over FTP/cPanel:

> [!CAUTION]
> **Never perform a fresh installation to update an existing site.**  
> Do NOT drop your database, do NOT delete your entire site folder, and do NOT re-run the web installer.

### Manual Update Procedure
1. **Back up your site:** Create a full site backup via `/admin/tools` or export your database via phpMyAdmin and zip your files.
2. **Download Release:** Obtain the latest `Favorite-CMS-Universal-vX.X.X.zip`.
3. **Overwrite Core Files:** Upload and overwrite the following core directories and files:
   - `app/`
   - `config/` (verify no custom values overwritten)
   - `database/migrations/`
   - `public/` (do NOT overwrite `public/uploads/`)
   - `resources/`
   - `vendor/`
   - `bootstrap.php`
   - `index.php`
   - `migrate.php`
4. **Run Migrations:** Visit your website or run:
   ```bash
   php migrate.php
   ```
5. **Verify:** Log into `/admin` to verify that your active theme, plugins, uploads, and posts remain intact.
