# Changelog

All notable changes to **Favorite CMS Universal** are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.0-beta] - 2026-09-04

### Added
- **Dual-Mode Professional Content Editor**:
  - **Visual Mode**: Rich text WYSIWYG editor with format dropdowns (H1–H6, P, Pre), bold/italic/underline/strikethrough styling, alignment, lists, blockquotes, horizontal rules, interactive table builder, link manager, and automatic paste sanitization (stripping MS Word XML junk).
  - **Code Mode**: Syntax-friendly monospace editor with synchronized line-number gutter, Tab indentation handling, quick HTML insert tags, and large content capacity.
  - Seamless bidirectional synchronization between Visual and Code modes.
  - Local browser autosave snapshot every 20 seconds for disaster recovery.
  - Live Theme Preview rendering drafts directly within active theme styling.
- **Role-Aware Large Media System**:
  - Configured role allowances: **7 GB** for Administrators, **500 MB** for Moderators, and **200 MB** for Normal Users / Subscribers.
  - Server technical ceiling detection evaluating `upload_max_filesize`, `post_max_size`, `memory_limit`, and available disk space.
  - Early HTTP 413 error reporting on `post_max_size` overflows to protect against silent POST truncation.
  - Drag-and-drop file upload zone with real-time percentage and byte upload progress reporting.
  - Direct executable file rejection and double-extension attack defense (`.php.jpg`, etc.).
- **Core Widget Architecture & Theme Layout Customizer**:
  - Modular widget engine with `WidgetInterface`, `AbstractWidget`, `WidgetRegistry`, and `WidgetInstanceManager`.
  - 10 built-in widgets: Search, Recent Posts, Categories, Tags, Navigation Menu, Pages, Custom HTML, Image, Featured Post, Recent Comments.
  - Multi-instance widget support across theme-declared regions (sidebars, multi-column footers, header strips).
  - One-click **Reset to Theme Defaults** restoration.
  - Visual Theme Customizer (`/admin/customize`) with sidebar position toggles (Right, Left, Full Width), custom logo, brand accent color, and homepage section reordering.
- **Public User Signup & Account System**:
  - Dedicated registration endpoints (`/register`, `/signup`, `/admin/register`).
  - Automatic `subscriber` role assignment with `active` status and `password_hash()` encryption.
  - Administrative toggle in **Settings &rarr; General &rarr; Membership** (`allow_registration`).
  - Dynamic theme header navigation displaying **Sign Up** / **Log In** for visitors and **+ Create Post** / **Dashboard** for authenticated users.
- **Content Moderation Workflow**:
  - Normal user post submissions are strictly overridden on the server side to `pending` review.
  - Prevention of client-side privilege escalation (tampering `status=published` is overridden to `pending`).
  - Dedicated **Pending Review** and **Rejected** tabs in `/admin/posts` with live post counters.
  - One-click **Approve** and **Reject** actions in table row actions and inside the post editor sidebar.
  - Moderator role direct publishing capability (`publish_direct`) allowing moderators to publish immediately without review.
- **User Account Lifecycle (Suspension & Bans)**:
  - Account operational statuses: `active`, `suspended`, `banned`.
  - Suspended users are prevented from creating new posts, updating existing posts, or uploading media files.
  - Banned users cannot log in. Active sessions of banned accounts are immediately terminated upon their very next request.
  - Historical posts, media, and comments of suspended or banned accounts remain intact.
  - Administrative user table (`/admin/users`) with status badges, post counts, quick role changes, and suspend/ban/restore actions.
- **Core Architecture & Extensibility**:
  - Service container and lightweight dependency injection framework (`FavoriteCMS\Core\Application`).
  - Idempotent PDO database migration runner maintaining 13 core migrations (`database/migrations/`).
  - Multi-tier persistent installation state with automatic self-healing lock mechanism (`storage/installed.lock`).
  - Priority-based action and filter hook system (`FavoriteCMS\Core\Hook`).
  - Dynamic plugin frontend route registration engine (`FavoriteCMS\Core\Router`).
  - Dynamic admin menu registration engine (`FavoriteCMS\Core\AdminMenu`).
  - Isolated plugin settings storage service (`FavoriteCMS\Models\PluginSetting`).
- **Comprehensive Quality Assurance**:
  - Complete automated test suite expanded to **109 tests and 511 assertions** with 100% pass rate.
  - Full PHP syntax validation (`php -l`) across all production and test files.
