# Favorite CMS v1.2.1 — server-controlled settings save fix

- Retains native version 1.2.1 and the existing Favorite Server & License runtime/bootstrap and routes.
- Fixes redirects back to the selected tab for save/validation/test operations (prior patch retained).
- Splits Settings Save by `settings_tab` **on the server**, so Site, SMTP, Permalink, and Storage update only their own fields.
- Disables non-active form panel controls in the browser to prevent hidden HTML5 required fields blocking submission; server logic does not depend on JS.
- SMTP's existing `Setting::set('email', ...)` shared storage is preserved for Favorite Server License Console and OAuth gateway consumers.
- Does not change owner/license policies, server-side license tables, tokens, runtime, or any migration.

## Safety testing limits

Static PHP syntax and source contract checks can be performed on the ZIP. Actual site login, database migration, license activation and external OAuth/SMTP workflows require a staging copy with live services and cannot be guaranteed from source inspection.

## Follow-up recommended

- Staging regression: save every tab, verify untouched fields and empty checkboxes; test SMTP connection, Google OAuth connection and server console mail settings.
- Backup both files and database before core update. Deploy CMS ZIP through built-in core update flow. The server ZIP does not need updating for this scoped fix.
