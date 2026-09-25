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
