# Universal Content Import & Migration Guide

Favorite CMS Universal Core provides an extensible, secure, and lightweight **Universal Content Import & Migration System** accessible from **Tools → Import / Migration** (`/admin/tools/import`).

The system imports content directly from official backup and export files provided by other platforms. It operates without live website scraping, external Node.js dependencies, or API credentials.

---

## 1. Supported Source Platforms

| Source Platform | Official Export Format | Core Status | Content Supported | Technical Notes |
|---|---|---|---|---|
| **Google Blogger (Blogspot)** | Atom XML (`feed.atom` or `blog-*.xml`) | `READY` | Posts, Pages, Comments, Tags/Labels, Media, Dates, Statuses | Export via Blogger Settings → Manage blog → Back up content. |
| **WordPress** | WXR XML (`v1.0`, `v1.1`, `v1.2`) | `READY` | Posts, Pages, Comments, Categories, Tags, Authors, Attachments/Media, Hierarchical Pages | Export via WordPress Tools → Export → All content. |
| **Generic RSS / Atom** | Standard Syndication XML | `READY` | Posts, Categories, Enclosures, Inline Images, Authors, Dates | Standard RSS 2.0 and Atom 1.0 feeds. |
| **Universal JSON Export** | JSON (Favorite CMS Standard Schema) | `READY` | Posts, Pages, Comments, Taxonomies, Media, Metadata | Clean structured schema without unsafe object deserialization. |
| **Ghost CMS** | JSON (Lexical / Mobiledoc) | `NOT_READY` | &mdash; | Ghost uses internal Lexical/Mobiledoc AST document formats; planned for a future release. |
| **Medium** | Multi-file HTML ZIP archive | `NOT_READY` | &mdash; | Medium exports individual unindexed HTML files without a central metadata index; planned for future ecosystem release. |
| **Drupal** | Custom modules only | `NOT_READY` | &mdash; | Drupal core does not provide a standard single-file content export format. |
| **Joomla** | Third-party extension packages | `NOT_READY` | &mdash; | Requires third-party extensions for content export; not yet supported in Core. |

---

## 2. Architecture & Pipeline

```
Source CMS Export File (.xml / .atom / .json)
                 │
                 ▼
     Universal Import Engine
                 │
                 ├── Format Auto-Detection (MIME & Structure signatures)
                 ▼
          Source Adapters
     (Blogger, WordPress, RSS/Atom, JSON)
                 │
                 ▼
       Normalized Content Model
     (Posts, Pages, Comments, Taxonomies, Authors, Media)
                 │
                 ▼
       Security & Migration Pipeline
     ├── SafeXmlParser: XXE & Entity Expansion Mitigation (LIBXML_NONET)
     ├── SsrfGuard: Blocks loopback, RFC-1918 private IPs, & cloud metadata
     ├── MediaMigrator: Streams remote images, checks MIME, rewrites HTML URLs
     ├── Deduplication Engine: Skip, update, or create-new modes
     └── Author Mapping: Non-privileged mapping (never creates admin accounts)
                 │
                 ▼
   Favorite CMS Database & Media Library
```

---

## 3. How to Migrate Content

1. **Obtain Official Backup / Export**:
   - **From Blogger**: Go to *Settings → Manage blog → Back up content*. Save `feed.atom` or `blog-*.xml`.
   - **From WordPress**: Go to *Tools → Export*. Choose *All content* and download the `.xml` file.
   - **From RSS/Atom**: Download your live RSS 2.0 or Atom 1.0 XML feed.
   - **From JSON**: Prepare a structured `.json` export matching the Favorite CMS universal JSON schema.
2. **Open Favorite CMS Dashboard**:
   - Navigate to **Tools → Import / Migration** (`/admin/tools/import`).
3. **Upload & Analyze**:
   - Select your export file.
   - Leave source on **Auto-Detect** (or select your source platform explicitly).
   - Click **Analyze & Preview Import**.
4. **Review Interactive Preview**:
   - Inspect detected counts: Posts (published & draft), Pages, Comments, Categories, Tags, and Media References.
   - Review sample posts and any format warnings.
5. **Configure Migration Preferences**:
   - **Deduplication Mode**:
     - *Skip Existing (Recommended)*: Prevents duplicate posts if re-importing.
     - *Update Matching Content*: Refreshes existing items with new export versions.
     - *Import All as New*: Appends all items with unique incremented slugs.
   - **Media Scope**: Check to download remote `<img src="...">` and featured images into local `public/uploads/imports/` and rewrite HTML URLs.
   - **Author Assignment**: Map to current administrator, map to existing user accounts, or create safe non-privileged author profiles.
   - **Publication Status**: Preserve source statuses or import all as draft for manual review.
6. **Confirm & Execute**:
   - Click **Confirm & Execute Import**.
   - Review the detailed post-migration report showing imported, updated, and skipped counts.

---

## 4. Security & Hardening

- **XXE Prevention**: All XML parsing enforces `LIBXML_NONET` and pre-parse regex checks rejecting `<!DOCTYPE ... SYSTEM` and `<!ENTITY` definitions.
- **SSRF Defense**: The `SsrfGuard` validates all remote media URLs before downloading. It resolves hostnames via DNS and blocks requests targeting loopback (`127.0.0.0/8`, `::1`), private ranges (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`), and cloud metadata (`169.254.169.254`).
- **Safe Media Ingestion**: Only valid image MIME types are accepted (`image/jpeg`, `image/png`, `image/gif`, `image/webp`, `image/svg+xml`). Dangerous extensions and scripts (`.php`, `.phtml`, `.exe`) are strictly blocked.
- **HTML Sanitization**: All post and page content is processed through `ContentSanitizer::clean()`.
- **Privilege Protection**: Importers never create administrator accounts and never import authentication passwords or tokens.
