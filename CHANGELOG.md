# Favorite CMS Universal v1.1.0

**Release Date:** October 7, 2026  
**License:** MIT  
**Compatibility:** PHP 8.1.0 or higher, MySQL 5.7+ / MariaDB 10.3+

Favorite CMS Universal 1.1.0 delivers automatic search and social SEO metadata, comprehensive dark/light appearance across all screens, editor layout improvements, private autosaves, revision history, and package validation hardening.

## Highlights & Capabilities

### Appearance & Dark/Light System
- **Unified Theme Controller:** Shared appearance manager controlling installer, authentication, admin dashboard, customizer, default theme, and fallback templates.
- **Persistence & Synchronization:** Mode preferences persist across sessions via localStorage and fallback cookies, synchronizing across browser tabs and preview frames.
- **Semantic Color Architecture:** Semantic CSS tokens replace fixed color rules across cards, forms, badges, modals, editors, and widgets, passing WCAG 2.1 AA 4.5:1 contrast standards.
- **Responsive Stacking:** Mobile layout fixes for widget management columns, media attachment details, and editor viewports.

### Post & Page Editor Improvements
- **Sidebar & Header Layout:** Editor right sidebar correctly positions below header actions and adheres cleanly below the admin bar on desktop scroll, with a thin scrollbar for lower controls.
- **Header Actions Wrapping:** Add New, View, and Preview actions wrap gracefully on narrow viewports without clipping.

### Customizer Undo/Redo & Live Preview
- **History Integration:** Undo/Redo tracking for color inputs, typography, and section reordering.
- **Unsaved Preview Mode:** View modifications in a read-only preview frame without persisting to the database until explicit save.
- **Feedback & Navigation Guard:** AJAX save provides explicit success/error feedback, preserving edits in-flight; page exit guards prevent accidental loss.

### Private Workspace & Revision History
- **Server Autosave:** Private draft saved after an 8-second typing pause without altering published records.
- **Stale Write Protection:** Client baselines prevent overwriting concurrent updates.
- **Revision History:** Up to 30 revisions and 10 private drafts per content item with recovery into code mode.

### Media & Thumbnails
- **Original-Preserving Thumbnails:** Eligible JPEG, PNG, and WebP uploads generate 320/640/960/1280 responsive srcset variants while preserving untouched originals.
- **Safety Fallbacks:** Animated GIFs, SVGs, EXIF-oriented images, and memory-constrained files safely fall back to original assets.

### Hardened Theme & Plugin Uploads
- **Pre-Extraction Inspection:** Archive structure and manifest validation before writing to disk.
- **Type Segregation:** Automatic rejection of plugins uploaded under Themes or themes uploaded under Plugins with clear remediation messages.
- **Archive Hardening:** Blocks path traversal, symlinks, duplicate entries, and malformed manifest structures with atomic rollback on failure.

### Automatic SEO, Sitemaps & Redirects
- **Automatic Fallback:** Search title and description compute dynamically from content, excerpt, and site settings without overwriting custom inputs.
- **Social & Schema:** Open Graph, Twitter cards, and Schema.org JSON-LD (Article, WebPage, Breadcrumb, Organization) with social image fallback.
- **Slug Redirects (`019_create_seo_automation.php`):** Old post/page slugs automatically generate direct 301 redirects to current live destinations.
- **Chunked Sitemaps:** `/sitemap.xml` supports unlimited posts with 1,000-URL chunked indexes (`/sitemap-1.xml`, etc.), omitting drafts, noindex, and non-self canonicals.
- **SEO Audit & Batch Transfer:** Admin SEO audit panel, paginated CSV/JSON metadata export/import with formula neutralization and reviewed preview commit.
- **Migration Adapters:** WordPress WXR and JSON adapters import Yoast/Rank Math metadata into native SEO fields.

---

# Favorite CMS Universal v1.0.0

**Release Date:** September 25, 2026  
**License:** MIT  
**Compatibility:** PHP 8.1.0 or higher, MySQL 5.7+ / MariaDB 10.3+

Initial official public release of **Favorite CMS Universal**, a lightweight, modular, standalone PHP Content Management System engineered for high speed, dependable reliability, and straightforward deployment on shared hosting and local development environments.

## Highlights & Capabilities

### Core Architecture & Runtime
- **Standalone Zero-Dependency Runtime:** Requires only PHP and MySQL/MariaDB. No Node.js daemons, Python scripts, or terminal Composer execution needed on production hosting.
- **Fast Request Dispatch:** PSR-4 autoloading engine, singleton service container (`Container`), and clean front controller request pipeline.
- **Automatic Path Normalization:** Automatic URL detection across root domains, subdomains (`blog.example.com`), and subdirectories (`example.com/site/`) via `UrlResolver`.
- **Pre-bundled Production Vendor:** Includes HTMLPurifier 4.19.0 and PSR-4 loader pre-packaged for zero-build deployments.

### Installation & System Setup
- **5-Step Web Setup Wizard:** Guided web-based setup with automatic environment diagnostics for PHP extensions (`pdo`, `pdo_mysql`, `mbstring`, `json`, `session`, `fileinfo`, `gd`) and folder permissions.
- **Dual Database Provisioning:** "Recommended" mode with automatic `localhost:3306` defaults and custom table prefixes (`fc_`), alongside advanced custom configuration.
- **1-Click Site Migration/Restore:** Direct restoration from backup archives directly on the installer welcome screen.
- **Installation Locking:** Cryptographic `installed.lock` barrier preventing unauthorized re-installation.

### Content & Publishing
- **Posts & Pages Engine:** Hierarchical pages and categorized/tagged blog posts with customizable URL slugs, drafts, pending reviews, scheduled publishing, and published states.
- **Dual-Mode Editor:** WYSIWYG visual content editor and monospace raw HTML code editor with bidirectional synchronisation, live draft recovery, and real-time previews.
- **Sanitization & Code Protection:** Integrated HTMLPurifier filtering for untrusted submissions while preserving trusted custom code blocks and administrator raw scripts.
- **Categorization & Taxonomies:** Multi-level categories and keyword tags with post relationship tracking.

### Media Management
- **Role-Aware Storage Limits:** Tiered upload limits (Admin up to 7 GB, Moderator up to 500 MB, standard user up to 200 MB), bounded by server runtime capacities (`upload_max_filesize`, `post_max_size`).
- **File Validation & Hardening:** Multi-extension shielding, MIME validation, and executable blocking against script uploads.
- **Media Library:** Image, video, audio, and document browsing with thumbnail previews and post insertion.

### User Management & Access Control
- **6 Core Role System:** Super Admin, Admin, Editor, Moderator, Author, and Subscriber.
- **Granular Role Matrix:** Dedicated permission controls covering content publishing, editing, media management, user management, and appearance customisation.
- **Account Security:** Native `password_hash()` (bcrypt/argon2), user suspension and banning with instant active-session termination, and email verification workflows.

### Themes & Customization
- **Theme Engine:** Clean template hierarchy (`index.php`, `header.php`, `footer.php`, `sidebar.php`, `single.php`, `page.php`, `archive.php`, `search.php`, `404.php`, `functions.php`).
- **Visual Customizer:** Live appearance controls, layout orientation (Right Sidebar, Left Sidebar, Full Width), brand colors, and homepage section toggles.
- **Widget Subsystem:** 10 core widgets (Search, Recent Posts, Categories, Tags, Navigation Menu, Pages, Custom HTML, Image, Featured Post, Recent Comments) with multi-instance support across theme regions.
- **Bundled Default Theme:** Responsive, fast "Favorite Default" presentation theme.

### Generic Plugin Architecture
- **Ecosystem Decoupling:** Standalone plugin system driven by `plugin.json` manifests, isolated class bootstrapping, and lifecycle hooks (`plugin.activated`, `plugin.deactivated`).
- **Plugin Capabilities:** Dynamic URL routing (`Router`), custom admin menus and submenus (`AdminMenu`), automated database migrations (`Migrator`), and table prefix registration.
- **Fault-Tolerant Execution:** Exception isolation preventing faulty plugins from terminating core CMS execution.

### Security Defenses
- **Prepared Statements:** 100% PDO parameterized query execution protecting against SQL injection.
- **CSRF Token Protection:** Timing-safe `hash_equals()` validation on all mutating POST requests.
- **Session Protections:** Strict cookie flags (`HttpOnly`, `SameSite=Lax`, configurable `Secure`), session regeneration on privilege escalation, and versioned session invalidation.

### Operations & Maintenance
- **Native Backup & Restore:** Complete site backup creation (SQL database dump + media uploads + themes + plugins) into structured ZIP archives with SHA-256 verification and restore checkpoints.
- **Format-Aware Domain Migration:** Format-safe string replacement during restore without breaking serialized data or JSON structures.
- **Core Update Engine:** In-app update verification, maintenance mode activation, checksum validation, and atomic snapshot rollbacks.
