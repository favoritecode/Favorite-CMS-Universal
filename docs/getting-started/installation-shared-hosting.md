# Shared Hosting Installation

Favorite CMS Universal is designed for ordinary Apache/PHP shared hosting. A normal ZIP installation does not require Composer, Node.js, Python, Git, SSH, or command-line access on the hosting account.

## Requirements

- Apache with URL rewriting enabled.
- PHP 8.1 or newer.
- PHP extensions: `pdo_mysql`, `openssl`, `json`, and sessions. `mbstring`, `fileinfo`, `gd` or `imagick`, and `zip` are recommended for the full CMS feature set.
- MySQL or MariaDB.
- Writable `storage/` directory and writable application root for first-run `.env` creation.

## Public And Private Files

Keep the public document root pointed at `public/` when your host allows it. The private application directories `app/`, `config/`, `database/`, `resources/`, `storage/`, `themes/`, `plugins/`, and `vendor/` should not be directly web-accessible.

On hosts where the ZIP is extracted below `public_html`, the root `.htaccess` forwards web requests into `public/` and blocks sensitive directories. If your host lets you configure the subdomain document root, point it directly to the CMS `public/` directory.

## Browser Installer

1. Extract `Favorite-CMS-Universal.zip`.
2. Visit the actual domain where the CMS will run, for example `https://example.com/`, `https://cms.example.com/`, or `https://example.com/cms/`.
3. The CMS detects that no persistent installation lock exists and opens the installer.
4. Review the requirements screen.
5. Configure the database.
6. Enter the site name, detected site URL, and administrator account.
7. Complete installation.

The installer writes `.env`, runs migrations, creates the administrator, stores the site URL, and creates `storage/installed.lock` only after the installation has completed successfully.

## Database Setup

### Automatic Database Creation

The installer can try to create the database automatically when the database account you provide has the necessary MySQL privileges:

- `CREATE DATABASE`
- `CREATE USER`, when creating a separate runtime user
- `GRANT`, when assigning privileges to the runtime user

This commonly works on local XAMPP or VPS-style database accounts. Many shared hosts, including cPanel, DirectAdmin, and managed providers, do not allow PHP applications to create databases or database users directly. That is normal.

When automatic creation is denied, Favorite CMS shows a clean manual fallback instead of a raw SQL error. It does not require root access and does not weaken database security.

### Manual Database Setup

If automatic creation is unavailable, create a database and database user in your hosting control panel, then enter:

- Database host
- Database port
- Database name
- Database username
- Database password
- Table prefix

Use the host value provided by your hosting company. Do not assume `127.0.0.1`, `localhost`, or `root` on production shared hosting.

The table prefix defaults to `fvcms_` for new installations. It is validated and allows multiple Favorite CMS installations to share one database without touching unrelated tables.

## Subdomains And Subdirectories

Favorite CMS detects the URL used to access the installer. If you install from `https://cms.canbangla.net/`, the saved site URL and generated installer/admin routes stay on `https://cms.canbangla.net/`; they do not collapse to `https://canbangla.net/`.

Supported examples:

- `https://example.com/`
- `https://www.example.com/`
- `https://cms.example.com/`
- `https://example.com/cms/`

## HTTPS And Proxies

The installer detects HTTPS from direct Apache HTTPS variables and standard server values. Proxy headers are used only when proxy trust is enabled with `TRUST_PROXY_HEADERS=true` or when the request comes through a local/private proxy address.

Use HTTPS for production installs so session cookies receive the Secure flag.

## Interrupted Install Recovery

Installation is resumable. If a request stops midway:

- The persistent install lock is not written until the schema, admin account, settings, and configuration are valid.
- Existing Favorite CMS tables are detected.
- Partial Favorite CMS tables are treated as repair/resume candidates.
- Unrelated tables are never dropped.
- The installer never drops a database during normal setup.

After a successful install, `/install` is no longer a normal setup entry point and redirects safely to the site.
