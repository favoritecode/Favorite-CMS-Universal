# Favorite CMS — Installer, Backup, Migration and Security

## Installer
The first visit to an uninstalled site should enter the installer.

Installer stages:
1. environment check
2. filesystem check
3. database check
4. configuration setup
5. schema migrations
6. administrator creation
7. initial settings
8. installation verification
9. installer lock/disable

Do not reinstall over an existing installation.

Fresh release archives must not include `storage/installed.lock`. The lock is created only after the schema, configuration, initial administrator, and required settings have been verified.

## Environment checks
Give actionable messages for:
- unsupported PHP version
- missing required extensions
- insufficient permissions
- unavailable database
- unwritable required directories

Do not make optional features hard requirements.

## Installation safety
Use a lock/state mechanism so concurrent requests cannot create duplicate installations.

Protect secrets and generated configuration files.

Installation state must be persistent. A PHP session can protect the in-progress browser workflow, but it is not installation state. After a completed install, the CMS uses `storage/installed.lock`; if the lock is missing but the configured database clearly contains a valid Favorite CMS installation, the application may safely recreate the lock instead of reopening the normal installer.

Normal installation must never drop a database or unrelated tables. Existing Favorite CMS tables are detected, partial Favorite CMS tables are treated as repair/resume state, and unrelated application tables are left untouched.

## Database Setup
The installer supports manual database credentials and best-effort automatic database creation.

Automatic creation depends on the database privileges granted by the host. If `CREATE DATABASE`, `CREATE USER`, or `GRANT` are denied, the installer must fall back to manual setup with a plain-language message.

The table prefix is configurable and validated. It must be used consistently for core CMS tables so multiple installations can share one database safely.

## URL Detection
The installer detects the runtime scheme, host, and base path from the active request. It must preserve subdomains and subdirectories, for example `https://cms.example.com/` and `https://example.com/cms/`.

Redirects and form actions must be generated from the detected base path or trusted configured site URL. Do not hard-code production domains, local domains, `localhost`, or `127.0.0.1`.

## Updates
Use:
- version checks
- compatibility checks
- database migrations
- atomic/rollback-safe file operations where practical
- maintenance/recovery strategy

Never assume an update cannot fail.

## Backup
The platform should support a practical backup strategy and make it clear what must be backed up:
- application files
- themes
- plugins
- user media/storage
- database
- necessary configuration/state

## Migration
Avoid machine-specific assumptions.

After restoring to a new compatible hosting account, the CMS should be able to:
- detect the environment
- connect to the restored database
- run required migrations
- repair/rebuild caches
- verify paths/configuration
- continue serving the site

## Security boundary
Never rely on:
- hidden UI buttons
- JavaScript checks
- filenames
- client MIME types
- obscurity

as the sole security mechanism.

Authorization must be enforced server-side.

## Uploaded extensions
Never blindly trust extension code or archive paths.

Validate package metadata and filesystem targets before changing the installation.

## Sessions
Use secure session settings appropriate to HTTPS and hosting environment.

Prevent session fixation and unauthorized privilege escalation.

Session cookies should use an application-specific name and the detected base path. Avoid hard-coded cookie domains so a Favorite CMS subdomain does not collide with a WordPress site or another application on the parent domain.

## Passwords
Use modern proven password hashing/password verification APIs.

Never store plaintext passwords.

## CSRF
Protect browser-based state-changing operations.

Installer pages must send no-cache headers so browsers and proxies do not reuse stale CSRF tokens. Expired installer tokens should rotate safely and show a clear retry message; token validation must not be disabled or bypassed.

## Output
Escape untrusted data according to output context.

## Errors
Production error responses must not expose:
- stack traces
- database credentials
- secrets
- private paths
- internal tokens

Provide a safe user-facing error and an actionable server-side diagnostic.

## Recovery
A failed migration/update/theme/plugin activation should leave the site in the safest recoverable state available.
