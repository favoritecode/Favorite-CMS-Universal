# Automatic database setup

Fresh installation defaults to Welcome → Requirements → Site Information → Admin Account → Install. Database fields are not rendered in this flow. On Install, Core checks configuration supplied by the hosting operator. If it is missing, invalid, unreachable, or cannot be used, the installer opens Advanced Database Setup and keeps the existing manual installation options. Site/account details are retained; passwords must be entered again.

## What the hosting environment must provide

Supply an existing MySQL/MariaDB account, its password, the database host, and the intended database name through either:

- `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, with optional `DB_PORT` and `DB_PREFIX`.
- `MYSQL_HOST`, `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`, with optional `MYSQL_PORT`. The corresponding `DB_*` value takes precedence when explicitly present.
- A complete `DATABASE_URL`, such as `mysql://USER:PASSWORD@HOST:3306/DATABASE`, with URL-encoded components. This replaces individual connection settings rather than mixing credentials from different sources. `DB_PREFIX` may still be supplied.

Core reads process environment variables, then PHP `$_ENV`/`$_SERVER`. The existing bootstrap also loads an operator-provided `.env`. No default MySQL account or password is assumed. A passwordless account is accepted only when its password is explicitly supplied as an empty value. The standard port 3306 is used when omitted. A safe table prefix is generated server-side if no prefix is supplied, and remains stable across retries in the installer session.

An existing reachable database is used directly. If MySQL explicitly reports that the database does not exist, Core checks `SHOW GRANTS FOR CURRENT_USER` before attempting creation. It accepts direct `CREATE`/`ALL PRIVILEGES` grants for the exact database or globally. It fails closed for partial revokes, indirect role grants, wildcard-only grants, or permissions it cannot verify. The server still enforces permission on creation, and the resulting connection is verified. The automatic flow does not create users, generate passwords, or grant additional privileges. The supplied account must also have the privileges needed to create and operate the CMS tables.

Database configuration stays server-side during automatic setup. It is saved through the existing `.env` writer when installation proceeds. Automatic failures return a generic fallback message without connection details; passwords are never repopulated in forms.

## Generic shared hosting

Uploading a ZIP alone cannot provision a database without credentials or a hosting integration. A hosting provider must either create the database/account and inject the configuration above, or offer an authenticated control-panel API integration that provisions them and supplies the resulting configuration. Core currently has no cPanel, Plesk, or other hosting API adapter. Where neither mechanism exists, create the database and assigned user in the hosting panel, then use the installer's manual fallback.

Backup Restore remains a separate explicit database workflow, reached through Restore backup. Its backup handling, validation, consent, and restoration processing are unchanged.
