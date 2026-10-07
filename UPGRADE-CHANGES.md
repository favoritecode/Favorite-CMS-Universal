# Favorite CMS Universal 1.1.0

Delivered on 7 October 2026. This release includes the earlier dark/light and layout fixes plus the following improvements. The original v1.0.2 ZIP is unchanged.

## Installing or updating

Use `Favorite-CMS-Universal-v1.1.0-update.zip` in **Admin → Core Updates → Upload ZIP**. It is also suitable for a fresh installation. Files sit directly at the ZIP root, with portable `/` paths. `bootstrap.php`, `release.json`, the README and configuration all identify core version 1.1.0.

The earlier generated ZIP used Windows backslashes in entry names. Its files existed, but the old updater could not locate them. The replacement ZIP uses the correct format and was installed using the original v1.0.2 updater itself. The new validator and extraction code also handle Windows-style archives consistently.

The existing updater keeps its automatic backup, maintenance, recovery and rollback flow. It applies migration 018 for private workspaces and migration 019 for old-slug redirects and per-item social image URLs, with the configured database prefix. No existing migration or content table was removed. Manual file-copy upgrades need `php migrate.php` once; use the regular updater when available.

## Same-version editor sidebar correction (7 October 2026)

The 1.1.0 package was rebuilt after fixing the post/page editor sidebar. The right panel now stays in its grid column below Add New/View/Preview actions and becomes sticky only while scrolling, with clearance below the fixed admin bar. Its lower controls remain accessible through a visible thin scrollbar. Narrow screens and short windows use normal page scrolling; header actions wrap when needed. Routes, form fields and editor actions are unchanged. Core version remains 1.1.0; the manual Core ZIP upload supports installing this corrected package over 1.1.0.

PHP syntax, rendered screens, original form/action preservation and release validation were rerun. No browser surface was available for live screenshot or pixel-position verification; the browser suite includes a specific editor header/sidebar overlap check.

## Automatic SEO follow-up (7 October 2026)

The same-version release now includes shared automatic search/social metadata, live editor previews, a paginated SEO audit, reviewed CSV/JSON metadata import/export, JSON/WordPress SEO migration, a complete chunked sitemap and published-only old-slug redirects. Existing content, custom metadata and indexing choices are retained. See [SEO-CHANGES.md](SEO-CHANGES.md) for behavior, limits, examples and verification.

## Improvements and benefits

- **Customizer:** the generic form now connects to the existing Undo/Redo API; color inputs and section ordering participate in history. Unsaved changes are visible and navigation is guarded. Changes render in a read-only preview without saving settings. AJAX Save reports success/failure and preserves edits made during the request. Themes with their own customizer retain the existing builder contract. Relative logo/favicon paths work alongside absolute URLs.
- **Drafts and history:** post/page editors save private server drafts after an eight-second pause. Autosave never changes the published record. Different tabs use separate draft IDs. Changed baselines stop stale autosave/normal editor writes. Existing post browser drafts remain; pages gain a browser fallback. Up to ten server drafts per account/content type and thirty title/content/excerpt revisions per item are retained. Load a draft/revision into code mode, review it and use the existing Save/Publish controls. Images, slug, categories, status and SEO remain controlled by their existing fields. Protected HTML and role/CSRF checks are preserved. Server draft payloads are capped at 4 MB; normal save limits are unchanged.
- **Images:** eligible JPEG/PNG/WebP uploads get original-preserving 320/640/960/1280-width variants and responsive listing images. Pickers still insert the original URL. Small, animated/unsupported, EXIF-oriented, oversized and memory-constrained images use the original. GIF/SVG originals are preserved. Derived files are removed with their own media item, leaving neighboring files intact. GD is optional for this optimization; original URLs remain available without it.
- **Performance:** general setting groups load once per request; repeated post/page status counts use a bounded request cache. Successful writes and transaction boundaries invalidate cached reads. No persistent page/authentication cache was introduced. Tests measured three repeated count calls using one SQL query, and three general-setting lookups using one query.
- **Keyboard and feedback:** existing media cards support Enter/Space; dialogs receive names, focus handling, Tab containment and Escape close through their existing close buttons. Controls receive labels and asynchronous draft/customizer feedback uses live status regions.
- **Theme/plugin ZIPs:** package identity and required files are checked before extraction. A plugin uploaded under Themes, or a theme under Plugins, is rejected with the correct destination named. Flat and wrapped packages, manifest IDs differing from folder names, custom plugin entry points and legacy index-based themes work. Mixed/ambiguous packages, malformed manifests, unsafe paths, duplicate paths and symlinks are rejected. Installation is staged with rollback; existing extra extension files/data are retained. Dependency compatibility warnings keep the existing install-now/activate-later flow.
- **Regression tools:** repeatable PHP/MySQL, package, DOM and screenshot suites are supplied separately from the production/update ZIP, because the core validator excludes test directories. No Node dependencies are required on the CMS server.

## Verification

- 22 integration checks passed on an isolated local MariaDB database with GD enabled: actual installation, all 19 migrations, post/page save, private drafts, stale-write prevention, role/CSRF restrictions, protected-code recovery, history retention, settings cache invalidation, original-preserving uploads, subdirectory URLs and full backup/restore.
- 21 package checks passed, including wrong-type uploads, flat/wrapped packages, safe replacement, malformed/mixed ZIP rejection and Windows path handling.
- The original v1.0.2 updater installed this release successfully; 10 checks confirmed preserved posts, pages, accounts, config, media and custom themes/plugins, the automatic backup, correctly prefixed new tables and released locks.
- 7 HTTP checks passed after that upgrade: real login, both appearance modes, private autosave, CSRF rejection and both wrong-type upload errors.
- 17 DOM behavior checks passed, covering history, section/checkbox restoration, paired colors, unsaved preview, successful/failed/concurrent saves, guarded navigation, blocked storage, non-executing recovery and keyboard media selection.
- The existing 94 rendered screen variants, original-control comparisons, 14 appearance behavior checks and 68 semantic contrast checks are retained and rerun. Full PHP/JavaScript syntax reports and file-preservation reports are stored in the adjacent `_qa` directory.

No production database or live site was connected. Browser screenshot tests are implemented for mobile/tablet/desktop and both modes, but could not be executed because no browser surface is connected. DOM tests do not establish pixel accuracy. Third-party themes/plugins and author-selected/custom embedded content still need their own styling checks.