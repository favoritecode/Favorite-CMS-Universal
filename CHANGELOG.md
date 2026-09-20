# Changelog

All notable changes to **Favorite CMS Universal** are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-09-21

### Official Production Release
- **Official Production Core Baseline**:
  - Clean permanent official repository at `favoritecode/Favorite-CMS-Universal`.
  - Retaining 100% complete functionality and frozen public contracts across all Core modules (Application, Router, Database, Auth, Themes, Plugins, Roles, Posts, Pages, Media, Menus, Widgets, Settings, Tools).
  - Complete architectural separation of concerns: Core contains framework, security, user/roles management, posts, pages, comments, media library, widgets, and customizer engine.
  - Official default theme (`themes/default`) bundled out of the box with responsive layouts and Dark Mode support.
  - Global Dark Mode default across Installer, Admin Dashboard, Standalone Auth, and Default Theme with flicker-free rendering and instant toggle.
  - Standalone pure Core frontend fallback views (`resources/views/index.php` and `404.php`) enabling robust operation across all environments.
  - Generic extension update discovery (`ExtensionReleaseDiscovery`) connecting installed plugins and themes to the official Assets repository.
  - Production POSIX release packager ensuring normalized Unix paths, attributes, and complete integrity verification.

### UI / UX & Navigation
- **Universal Dark Mode**:
  - First-class Dark Mode support across Admin panel, Installer wizard, Standalone Auth screens, and Default frontend theme.
  - Semantic CSS design tokens and high-contrast styling for labels, tables, forms, and badges.
- **Sticky Admin Sidebar**:
  - Desktop and tablet sticky left navigation (`top: 42px; height: calc(100vh - 42px); overflow-y: auto; overscroll-behavior: contain;`).
  - Preserved mobile drawer navigation (`<= 782px`).
- **Frontend Enhancements**:
  - Theme-aware sticky sidebar and reusable Core Back-to-Top component (`FavoriteCMS\Rendering\BackToTop`).

## [1.0.15] - 2026-09-18

### Added
- **Authoritative Global Currency Architecture**:
  - Added `Currency::getSymbol(?string $code = null): string` and `Currency::format(float|int|string $amount, ?string $currency = null, bool $includeCode = false): string`.
  - Added global helper functions `currency_symbol(?string $code = null): string` and `format_currency(float|int|string $amount, ?string $currency = null, bool $includeCode = false): string`.
  - Allowed changing Primary Currency in `Settings → Primary Currency` at any time as a site-wide denomination change.
  - Removed the permanent lock preventing currency modification after financial activity.
  - Emitted `currency.primary_changed` action hook with `['old' => $oldCurrency, 'new' => $newCurrency]` for downstream ecosystem alignment.

## [1.0.14] - 2026-09-18

### Fixed
- **Generic Duplicate Parent Submenu Suppression in Admin Layout**:
  - Improved dynamic plugin admin menu rendering in `resources/views/admin/layout.php`.
  - Suppressed automatic duplicate parent submenu link when a registered plugin already provides an explicit landing submenu item matching the parent slug (`isset($dMenu['submenus'][$dMenu['slug']]) || in_array($dMenu['slug'], $subSlugs, true)`).
  - Preserved automatic parent submenu link generation for plugins with distinct submenu slugs (e.g., Favorite Pay).
  - Ensured complete independence without hardcoding plugin names or slugs into Core.

## [1.0.13] - 2026-09-15
## [1.0.13] - 2026-09-16

### Fixed & Security
- **Moderator Post Deletion Protection**:
  - Enforced strict authorization preventing Moderator from trashing, restoring, or permanently deleting other users' posts.
  - Added `canDeletePost(mixed $post): bool` and `canDeleteOtherPosts(): bool` methods to authoritative `User` model.
  - Explicitly decoupled `canModeratePosts()` and `canEditOtherPosts()` from post deletion permissions.
  - Secured `PostController` single-item and bulk operations (`trash`, `restore`, `delete`) to verify post deletion permissions.
  - Updated administrative posts list UI (`resources/views/admin/posts/index.php`) to conditionally hide destructive row actions ("Trash", "Restore", "Delete Permanently") and bulk action dropdown options for users without permission to delete other users' posts.
- **Author Admin Dashboard 500 Fix & Scoping**:
  - Fixed 500 Internal Server Error encountered when accessing `/admin` as an Author.
  - Scoped dashboard post metrics in `DashboardController` to the authenticated Author's own posts rather than site-wide counts.
  - Audited and capability-gated administrative queries (Pages, Comments, Users) so that unpermitted queries and metrics are not evaluated or exposed.
  - Passed resolved `$currentUser` to dashboard view and updated `resources/views/admin/dashboard.php` to render appropriate role-scoped cards ("My Posts", "Media", "My Account"), hide administrative action buttons (`+ Add an About Page`, `Customize Theme`), and safely link post titles to public URLs when editing is not authorized.
  - Added regression test scenarios 16–20 in `RolePermissionMatrixTest.php` covering Moderator deletion restrictions and Author dashboard rendering and access restrictions.
### Added & Security
- **Authoritative 6-Role Permission Matrix Alignment**:
  - Aligned core permission matrix across all 6 roles (Super Admin, Admin, Editor, Moderator, Author, Subscriber) via database migration `017_align_role_permission_matrix.php`.
  - Added granular permissions: `upload_media`, `view_dashboard`, and `edit_seo_meta` with proper capability mapping in `User` model.
- **Strict Ownership-Aware Content Deletion & Bulk Operations**:
  - Enforced per-item ownership validation for single and bulk actions (`trash`, `restore`, `delete`) in `PostController`.
  - Super Admin & Admin: Full trash, restore, and permanent deletion rights across all posts.
  - Editor, Moderator, & Author: Strictly restricted to trashing, restoring, and permanently deleting only their own authored posts (`canDeletePost($post)` check per item). Denied items are preserved and reported with explicit skipped-count feedback.
  - Subscriber: Zero deletion permissions.
- **Role Boundary Hardening**:
  - **Editor**: Full content management (Categories, Tags, Navigation Menus, Page Management, Post & Comment Moderation, Media Management). Author-level deletion boundary (can only delete/trash own posts).
  - **Moderator**: Post approval/rejection, post content editing (`edit_others_posts`), comment moderation, and own-content SEO meta editing. Restricted from deleting other users' posts, pages, menus, categories/tags, and general media management.
  - **Author**: Restricted strictly to authoring, editing, and deleting own posts. Can upload media (`upload_media`) for own content, but cannot access full media library management (`manage_media`).
  - **Subscriber**: Basic authenticated dashboard view (`view_dashboard`) and account management only. Completely restricted from post authoring, media upload, and administrative controls.
- **Server-Side Route Authorization & UI Guarding**:
  - Enforced server-side permission checks in `Kernel.php` (`edit_others_posts`, `delete_others_posts`, `manage_pages`, `manage_menus`, `upload_media`, `manage_media`).
  - Audited admin dashboard metrics and navigation layout to conditionally display features matching authoritative capabilities.
- **Integration Test Alignment**:
  - Added comprehensive 41-scenario test matrix in `RolePermissionMatrixTest.php` covering single and bulk operations, ownership boundaries, and route protections.
  - Aligned legacy integration tests to reflect authenticated session requirements and 6-role permission invariants.

## [1.0.12] - 2026-09-15

### Added & Security
- **Authoritative 6-Role Permission Architecture**:
  - Aligned server-side permissions across all six core roles: Super Admin, Admin, Editor, Moderator, Author, Subscriber.
  - Added `moderate_comments` and `edit_others_posts` permissions with upgrade migration `016_update_role_permissions.php`.
  - Enforced strict authorization separation: Moderator is granted "Edit Other Users' Posts" without inheriting trash, restore, or permanent deletion rights over other users' posts.
  - Granted Editor role full content management capabilities: Post Approval & Rejection, Comment Moderation, Pages, Categories/Tags, Media, and Navigation Menus.
  - Restricted Author strictly to own content creation and editing; blocked from editing other users' posts, post moderation, comment moderation, pages, and menus.
  - Restricted Subscriber to basic account management; blocked from post creation, media uploads, and administrative functions.
  - Updated Admin navigation layout with capability-level visibility controls for Posts, Pages, Media, Comments, and Menus.
  - Added comprehensive 15-scenario integration test suite (`RolePermissionMatrixTest.php`).

## [1.0.11] - 2026-09-15

### Fixed & Security
- **Super Admin Role Integrity & Safe Live-Site Recovery**:
  - Enforced zero Super Admin system invariant: prevented number of active Super Admin accounts from becoming zero through role demotion, self-demotion, deactivation, suspension, banning, or account deletion.
  - Implemented row-level locking (`SELECT id FROM roles WHERE slug = 'super-admin' FOR UPDATE`) across all user status, role, and deletion transactions to ensure strict concurrency protection.
  - Fixed runtime role resolution mismatch where database Super Admin failed to resolve capabilities due to stale session role values or unnormalized role slugs.
  - Hardened `hasRole()`, `isSuperAdmin()`, and `hasPermission()` with full slug normalization and bypass checks.
  - Added single-use, authenticated, rate-limited, password-verified Emergency Super Admin Recovery flow for the verified legitimate site administrator (User ID 1 matching site settings).

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
