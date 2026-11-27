# Favorite CMS Universal 2.0.0

**Official package:** `Favorite-CMS-Universal-v2.0.0.zip`. Use this named release asset for installation and updates; GitHub's automatically generated "Source code" archive is developer source, not the installer.

This is the one and only document shipped inside the package. Customers need only this CMS ZIP; plugins and themes are installed separately. No Owner server source, production credentials, customer database or publisher private key is included.

## Requirements
HTTPS, PHP 8.1 or newer with PDO MySQL, mbstring, sodium, curl and ZipArchive, and a MySQL/MariaDB database. Google mail additionally needs the configured official OAuth gateway; the Owner gateway requires PDO SQLite and OpenSSL. No Node.js, production Composer installation or separate terminal service is required.

## Install a new website
1. Create an empty MySQL/MariaDB database and database user on your hosting.
2. Upload the CMS ZIP to the website root, extract it, and visit the domain. Complete the normal installer and create your administrator account.
3. The site registers itself automatically with the official service and receives the **Favorite CMS Free License**. A congratulations message confirms it. There is no key, code or API address to enter.
4. Begin managing content. Install plugins and themes from **Plugins** and **Themes**.

## Update an existing website
Keep a hosting files/database backup. In **Admin → Updates**, upload the complete official ZIP, validate it and apply the update. Same-version repair is supported. The updater preserves `.env`, per-site `config/`, content, uploads, installed extensions, settings and registration credentials. Do not extract a full installer over a live site's configuration. A site on 2.0.0-beta.N updates to 2.0.0 the same way.

## Registration and licensing
- **Free site license.** Registration and the free license activate automatically on first run. Site access stays free.
- **Unverified sites.** If the site cannot prove its domain at once (for example a local or temporary address), it still works and appears at the platform owner as *unverified*. It keeps running for **3 days** while the owner verifies it or automatic verification succeeds; afterwards public access pauses. Install the latest official update and contact the platform owner to restore it.
- **Outages.** A registered site keeps its last authenticated owner decision during a temporary server outage: active stays active, suspended or banned stays restricted. A new installation runs pending during a temporary network outage and retries on later requests. Verification or authentication failures do not receive that fallback.
- **Plugins and themes with a license.** The **Plugins** and **Themes** pages show, for each paid extension, its state (*Free trial · N days left*, *License needed*, *Licensed until …*) and a **Buy** link to the **Site & add-ons** page, which lists its price and a **Buy** button. Buying opens a purchase page in your site's theme and pays through Favorite Pay; the license activates by itself once the payment is approved. Trial length, price and term are set by the platform owner.
- **The owner's own site** never needs a license.

## Times and dates
Every date and time shown in the admin and on the site uses the **site time zone** (Settings → General). Backup file names and listings and scheduled publishing follow the same setting.

## Backups, retention and restore
**Admin → Tools → Backups & System Health**
- **Download database (.sql)** downloads a real MySQL/MariaDB script. Select the destination database in phpMyAdmin and import it, or run `mysql database_name < backup.sql`.
- **Generate complete backup (.zip)** creates a checked archive. Keep all three include options checked for the complete site.
- **Automatic cleanup:** the newest **3** backups are always kept; older ones are deleted after **30 days**. You can also select several backups and delete them manually.

Archive structure: `files/` (website files, configuration and dotfiles, application, dependencies, uploads, all themes and plugins, installation identity), `database.sql` (structure and data of this installation's tables) and `manifest.json` (selections, counts, sizes and SHA-256 checksums). Backup archives, temporary files, caches, sessions, logs and update staging are excluded. Unreadable files or symbolic links stop a backup with an error instead of being silently skipped, and size/hash are verified before the ZIP becomes available. Pause publishing and uploads while a backup runs.

Restore: (1) keep a separate copy of the current files and database; (2) for complete hosting recovery copy the **contents** of `files/` into the website root, including hidden dotfiles, then import `database.sql` into the selected database; never serve the backup archive itself; (3) the admin ZIP restore restores data, uploads, themes and plugins and intentionally keeps the running core, configuration and connection files; use the signed official updater to repair core. Raw SQL uses the original table prefix; the admin restore can migrate the prefix. Routine, trigger and event imports need matching MySQL privileges.

## Recovery and safe repair
- Protected executable files carry a publisher-signed integrity inventory. Mutable per-site configuration is kept and is not treated as an immutable template.
- `/admin/login?safe_repair=1` and `/admin/connection-status` keep authenticated recovery access. Restore the signed official ZIP for a damaged core; re-verify a damaged connection with the current administrator password. Owner restrictions are preserved. When the status is *Active / Verified*, use **Open dashboard** to leave safe repair mode.
- Public ownership verification needs server-to-server HTTPS access; a browser-only challenge can prevent it. Local installations register with outbound installation-key proof and are listed separately; an offline machine cannot be discovered before it contacts the official service.
- A self-hosted PHP installation is not absolutely tamper-proof. The central directory holds installation and account metadata disclosed in the installer and recovery screen; passwords, tokens and private content are not synchronized.

## Email
Configure mail in **Settings → SMTP**: Continue with Google, manual SMTP, or PHP Mail where the host supports it.
