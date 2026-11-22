# Favorite CMS 1.1.4 — uploads, layout counts and 404 settings

## Install
Use the complete Favorite-CMS-Universal-v1.1.4-Uploads-Layouts-404.zip in Admin → Updates → manual Core ZIP upload. Back up the site and database before applying a Core update; use the CMS update workflow to preserve installed extensions/configuration. This is a Core ZIP, not a plugin ZIP. Version and PHP minimum remain 1.1.4 and PHP 8.1. Existing settings and media URLs are not migrated or rewritten. New settings default to the previous behavior; no database migration is required.

## Media
- Administrators can upload EXE, APK, MSI, BAT, CMD, DMG, DEB and RPM. Existing non-admin media permissions remain; publishing software/command files additionally requires admin or super-admin.
- EPUB, MOBI/AZW/AZW3, RTF, ODT/ODS/ODP, 7Z and RAR are supported as download-only formats. Existing PDF, DOC/DOCX, spreadsheet/presentation, image, audio, video, TXT, CSV and ZIP behavior remains.
- Added formats are validated by format signatures or archive markers, stored as random `.bin` files under `storage/media-downloads`, and delivered at `/media-download/{id}` as attachments with the original filename, binary content type and `nosniff`. They are not placed in public uploads or exposed as server-executable paths. No archive extraction is performed.
- Media Library → Other / Software locates executable packages. E-books/open documents appear under Documents. Existing media records and URLs are unchanged.
- HTTP HEAD and a single byte range support download tools and resuming. Download responses release the session lock before streaming bounded chunks. Missing files return 404 and invalid ranges 416. Deleting media also deletes its stored download.
- PHP scripts and dangerous embedded filename extensions remain blocked. File format checks are not malware scanning or APK signature verification. PHP/web-server/hosting upload caps still apply. ZIP support is needed for APK/EPUB/OpenDocument inspection.

## Independent post counts
Settings → Site Settings → Reading Settings → Post counts by layout:
- Homepage listing
- Category / tag archives
- Search results
Each accepts 1–100. Set 0 to inherit the existing Blog pages limit. Counts and pagination are computed together, so page 2 uses the corresponding offset.

Sidebar/footer Recent Posts widgets already had independent limits in the supplied Core; they do not read `posts_per_page`. Use Appearance → Widgets → the corresponding Recent Posts widget to adjust its number. Its label now explains this. Custom theme/plugin sections that query their own posts keep their own controls. No theme CSS/card dimensions are changed and no posts are sliced after pagination. The supplied ZIP includes the default theme; a separately installed Favorite Web theme's private query settings cannot be verified from this ZIP alone.

## 404 redirect
Settings → Site Settings → Reading Settings → Missing page (404) redirect. Choose a published page and 302 (default temporary) or 301 (permanent). Disabled is the default. Old-slug redirects retain priority. Deleted/unpublished targets fall back to the real theme 404. Self-redirects, admin/API/download/assets errors, JSON responses/requests, AJAX and non-GET/HEAD requests are excluded. Subdirectory installs use the existing URL prefix helper. This affects CMS-handled requests; a web server's own missing static-file responses may not pass through PHP.

This is optional visitor navigation, not an automatic SEO improvement. Google advises using relevant replacements rather than redirecting many missing URLs to an unrelated page, which can be treated as soft 404: https://developers.google.com/search/docs/crawling-indexing/site-move-with-url-changes

## Validation
- 190 feature/integration checks, including the actual Settings save handler and Kernel route entry, passed with both empty and `cms_` database prefixes.
- 56 existing Core regression checks, 13 other-flow checks, 8 preservation checks and 18 rendered-route checks passed.
- 10 HTTP checks passed, including a real multipart EXE upload through the authenticated/CSRF-checked CMS entry point, byte-identical download, HEAD, resume/416 and missing download.
- 5 browser layout/control checks passed at 1440 px and 390 px, light/dark; rendered views were visually reviewed.
- PHP syntax and final archive validation are recorded in the accompanying findings report.

All data tests used local SQLite fixtures. A live hosting deployment, MySQL server, Nginx/Apache configuration and third-party theme/plugin end-to-end run were not performed. Newly changed code uses PHP 8.1-compatible syntax; execution tests used the available PHP runtime. Your live site has not been modified.
