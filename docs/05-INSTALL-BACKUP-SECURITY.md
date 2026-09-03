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

## Passwords
Use modern proven password hashing/password verification APIs.

Never store plaintext passwords.

## CSRF
Protect browser-based state-changing operations.

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
