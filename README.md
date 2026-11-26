# Favorite CMS Universal 1.2.1

**Official CMS package:** `Favorite-CMS-Universal-v1.2.1.zip`.
This is the final same-version correction published on 26 November 2026. Use this named release asset for installation and updates; GitHub's automatically generated Source code archive is developer source.

## Install a new website
1. Create an empty MySQL/MariaDB database and database user on your hosting.
2. Upload the CMS ZIP to the website root, extract it, and visit the domain. Complete the normal installer and create your administrator account.
3. Each installation keeps its own persistent identity. Free registration and ownership verification run automatically against the official service; ordinary administrators do not enter a central API URL or license key.
4. Begin managing content. Install plugins/themes separately; their license and purchase controls appear in the normal extension flow.

Use HTTPS and PHP 8.1+ with PDO MySQL, mbstring, sodium, curl and ZipArchive. Google mail additionally needs the configured official OAuth gateway; the Owner gateway requires PDO SQLite and OpenSSL. No Node.js, production Composer installation or separate terminal service is required.

## Update an existing website
Keep a hosting files/database backup. In Admin → Updates, upload the complete official ZIP, validate it, and apply the update. Same-version repair is supported. The updater preserves `.env`, per-site `config/`, content, uploads, installed extensions, settings and registration credentials. Do not extract a full installer over a live site's configuration.

## Site operation and recovery
- Protected executable files have a publisher-signed integrity inventory. Mutable per-site configuration is retained and is not treated as an immutable distribution template.
- A registered site keeps its last authenticated Owner decision during a temporary server outage. Active stays active; suspended/banned stays restricted. A new installation can run pending during a temporary service/network outage and retries registration on subsequent requests. Verification/authentication failures do not receive that fallback.
- Public ownership verification requires server-to-server HTTPS access. A browser-only challenge can prevent verification. Local installations register through outbound installation-key proof and are listed separately; an offline machine cannot be discovered before it contacts the official service.
- `/admin/login?safe_repair=1` and `/admin/connection-status` retain authenticated recovery access. Restore the signed official ZIP for damaged core; reverify a damaged connection using the current administrator password. Owner restrictions are preserved. When status is Active / Verified, use Open dashboard to leave safe repair mode.
- A self-hosted PHP installation is not absolutely tamper-proof. The central directory includes installation/account metadata disclosed in the installer and recovery screen; passwords, tokens and private content are not synchronized.

## Backups and email
Tools → Backups & System Health downloads a real MySQL `.sql` export. Complete backups contain `files/`, `database.sql` and a checksum inventory. Defaults include core/configuration, uploads and all themes/plugins; documented temporary/runtime folders and existing backups are excluded. Admin ZIP restore restores data and extensions while retaining running core/connection settings. See `docs/BACKUP-AND-MYSQL-RESTORE.md` for full hosting recovery.

Configure mail in Settings → SMTP: Continue with Google, manual SMTP, or PHP Mail as supported by the host. There is no separate customer central-connection administration screen.

## Final corrections
Complete ZIP/SQL backups and streamed downloads; legacy configuration compatibility; exact-file integrity diagnostics; post-update core verification; and removal of recursive Favorite Pay initialization during co-located site-status checks. The core version remains **1.2.1**.

Platform owners install the separate private Server 1.0.1 package. Customers need only this CMS ZIP. No Owner server source, production credentials, customer database or publisher private key is included here.
