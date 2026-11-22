# URL Redirect Manager — Favorite CMS 1.1.4

## Location
Admin → Settings → Redirects → Add Redirect. The existing Reading Settings → Missing page (404) redirect stays available, with a link to the new manager.

## Use
Enter a source path, such as `/old-guide`, and a destination, such as `/new-guide`. Alternatively choose a published destination page. Select 301 permanent or 302 temporary and save. Create another rule with Add Redirect. List controls provide Edit, Enable/Disable and Delete; search and pagination keep the list manageable.

Source matches an exact, case-sensitive public site path, ignoring trailing slash and query string; it is not a prefix or wildcard. The destination may include its own query and fragment. Same-origin full URLs are accepted and converted to site-relative paths; no external domains, schemes or protected system routes. For subdirectory installs, the configured site URL must include the installation path.

Keep incoming query is optional and applies only if the final destination has no query. Destination query parameters take precedence. Known rule chains resolve directly to their final target. Rules can redirect existing public pages as well as missing pages; they do not modify/delete content. Specific rules take priority over automatic old-slug routing when explicitly configured for that source. Without an explicit rule, existing native redirects continue normally.

## Safeguards
- Admin settings permission, POST-only mutations and CSRF checks.
- Duplicate sources, self-redirects, rule/canonical alias cycles and overly long chains are rejected.
- Admin/API/auth/system/download paths, AJAX, JSON requests and Range requests are excluded.
- Concurrent edits use a per-rule revision check. MySQL mutations lock a dedicated settings row; SQLite uses its transaction behavior. Failed writes preserve previous rules.
- Rules are separate settings records rather than one potentially oversized JSON TEXT value. Up to 1,000 rules, with 20 per list page; no schema migration is needed.
- The original 404 fallback still runs only after a normal 404. Its target follows specific rules without bouncing indefinitely back to the same missing URL.
- Browser-cached permanent redirects can outlive a change; new responses use no-store, but older cached redirects are outside the CMS's control.

## Install
This ZIP is a cumulative Core update on top of the prior Uploads / Layout Counts / 404 update, not a plugin. Use Admin → Updates → manual Core ZIP upload, with a site/database backup. Version stays 1.1.4, PHP minimum stays 8.1. No rules are created or enabled automatically. Existing post counts, uploaded files, themes and plugins are retained.

## Validation
156 redirect checks passed with empty and cms_ database prefixes, including add/edit/delete/toggle handlers, Kernel dispatch, permission and CSRF rejection, loop detection, automatic alias interactions, query/fragment behavior, subdirectory paths, independent record storage and 1,000-rule capacity. Existing 190 feature checks, 56 Core, 13 other-flow, 8 preservation and 18 rendered-route checks passed. Browser checks covered list/form at desktop/mobile in light/dark, including the page picker. Prior multipart EXE upload and attachment/range download HTTP checks passed again. Additional final archive/syntax results are in the accompanying report.

Execution used local SQLite fixtures and the available PHP runtime. MySQL locking syntax was implemented for the existing driver but not exercised against a live MySQL host. The live website and separately installed third-party themes/plugins were not modified or end-to-end tested.
