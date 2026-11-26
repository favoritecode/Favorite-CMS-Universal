# Backup and MySQL restore — CMS 1.2.1 repair

The software version remains 1.2.1. This correction changes backup/export, not Central Server configuration or licensing.

## Download

Admin → Tools, Backups & System Health:

- **Download database (.sql)** downloads an actual MySQL/MariaDB script, not JSON. Select the destination database in phpMyAdmin and import this SQL, or use `mysql database_name < backup.sql`. The script does not create/select a database. It contains this installation's literal table prefix and visible associated objects; unrelated table prefixes in a shared database are not exported.
- **Generate complete backup (.zip)** creates a checked archive. Download it from Existing Site Backups after creation succeeds. Keep all three include options checked for the complete site.

## Archive structure

- `files/`: website files, including root routing, configuration/dotfiles, application, dependencies, assets, uploads, all themes/plugins, installation identity, and a co-located server if present.
- `database.sql`: MySQL structure and data for the installation's tables, with visible prefixed views, procedures, functions and events, and triggers attached to those tables.
- `manifest.json`: selections, excluded runtime paths, database/table counts, file sizes and SHA-256 checksums.

Website-owned files named database.sql or manifest.json are preserved inside files/. Empty folders are retained. Backup archives, temporary files, application caches, sessions, logs, transient locks and pending server-update staging are excluded. The installed.lock marker and plugin/theme folders named cache are retained. The integrated OAuth SQLite database is snapshotted with committed WAL data.

Unreadable files or symbolic links stop backup with an error rather than silently omitting content. File size/hash and archive consistency are checked before the final ZIP becomes available. Pause publishing/uploads/plugin changes for a coordinated database/file snapshot. Active concurrent writes and hosting process termination cannot provide an atomic whole-site snapshot.

## Restore

1. Before replacing a site, preserve a separate copy of its files and database.
2. For complete hosting recovery, copy the **contents** of files/ into the original website root, including hidden dotfiles, then import database.sql into its selected MySQL database. Do not serve the enclosing backup archive or expose its manifest/configuration to visitors. Adjust database credentials only if the hosting account changed. A moved domain still requires the normal ownership verification.
3. The admin ZIP restore restores data, uploads, themes and plugins, and supports old root-layout backups. It intentionally retains running core/configuration/connection files. Use the signed official updater for core repair. A full archive includes those files for hosting-level recovery.
4. Raw SQL uses the original prefix. The admin ZIP restore can migrate object prefixes while retaining literal text values. Existing target tables require overwrite confirmation.

SQL includes exact binary bytes, NULL/empty values, large decimal values, BIT values and generated-column definitions. Routine/trigger/event imports need corresponding MySQL privileges. Definers are omitted for portability; the importing account becomes the definer, so review permissions for custom database programs. Objects hidden by the database account's privileges cannot be discovered. Non-InnoDB tables require read locks; insufficient lock privileges produce an error rather than an inconsistent successful backup. Non-prefixed plugin tables, external databases/files, and content outside the application root require their own explicit backup plan.

Both download routes require the existing authenticated administrator access. Downloads stream from private files instead of loading whole ZIPs into PHP memory. SQL temporary files are removed after response completion. Keep downloaded complete archives private because they contain credentials and connection state.

## Deployment and recovery

Use the normal authenticated official CMS ZIP updater for this same-version repair. Publisher validation remains enabled; do not extract the distribution ZIP over live configuration. The pre-update backup is created by the currently installed version, so also keep the hosting backup made before this repair. After installation, generate and download a fresh ZIP and SQL, and verify them against a separate staging database. No Server package update is required for this correction.

If a site update fails, retain maintenance mode, the pre-update backup and update logs, and use the existing authenticated recovery entry. Restore a database backup before retrying if a migration was interrupted. This correction does not promise that a hosting hard kill or exhausted disk space can complete a backup.
