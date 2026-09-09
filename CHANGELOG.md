# Changelog

All notable changes to **Favorite CMS Universal** are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.8] - Favorite Pay - 2026-09-09

### Added
- **Withdrawal Engine & Administrative Control**:
  - Administrative master toggle to enable or disable customer withdrawals anytime.
  - Configurable minimum withdrawal amount.
  - Configurable monthly withdrawal count limit (request-count based per calendar month).
  - Explicit business rules: there is **no maximum withdrawal amount** and **no monthly monetary withdrawal cap**.
  - Customers may withdraw up to their entire available balance.
  - Pending, approved, processing, and paid withdrawals consume a monthly slot; rejected, failed, and cancelled withdrawals release the slot back to the customer.
  - Fixed and percentage-based withdrawal fee calculation with transparent net payout computation.
- **Withdrawal Processing & Lifecycle Audit Trail**:
  - Full state-machine transitions: `pending` -> `approved` / `rejected` -> `processing` -> `paid` / `failed` / `cancelled`.
  - Transaction reference tracking for manual or automated payout settlement (`favorite_pay_withdrawals.transaction_reference`).
  - Strict audit trail columns: `admin_id`, `admin_notes`, `failure_reason`, and state timestamps (`approved_at`, `processed_at`, `paid_at`, `rejected_at`, `cancelled_at`).
  - Dynamic filtering by status, date range, customer ID, search, and streaming CSV export for administrative accounting.
- **Customer Notifications System**:
  - Dedicated persistent notifications table (`favorite_pay_notifications`) with customer inbox, unread counts, and mark-as-read endpoints.
  - Automated transactional notifications dispatched on withdrawal submission, approval, payout completion, rejection, and cancellation.
- **Customer Wallet & Transaction Experience**:
  - Modern, responsive customer wallet view (`/account/wallet`) displaying available balance, held funds, currency-formatted balances, and quick actions.
  - Dedicated transaction history (`/account/transactions`) and detailed transaction receipt page (`/account/transaction/{id}`).
  - Payout history view with real-time status badges, breakdown of gross amount, fee, net payout, and admin notes.
- **Admin Financial Dashboard & Operational Activity Center**:
  - Executive financial dashboard (`/admin/favorite-pay/dashboard`) presenting 24-hour, 7-day, 30-day, and all-time revenue, gross volume, fees, net payout, and pending approval metrics.
  - Dedicated Audit Log Center (`/admin/favorite-pay/audit-logs`) tracking critical operational events (withdrawal state transitions, settings updates, gateway modifications, manual adjustments).
  - Searchable, filterable audit log table with granular metadata inspection modal/detail view.
- **Failure-Scenario & Concurrency Hardening**:
  - Atomic database transactions wrapping all balance changes and withdrawal state transitions.
  - Strict double-spend and race-condition defenses via pessimistic locking (`FOR UPDATE`) and balance validation.
  - Comprehensive rollbacks ensuring zero orphaned balance deductions or stranded funds on provider or database failures.
- **Idempotent Database Migrations & Upgrade Integrity**:
  - Migration sequence 001 through 007 (`001_create_favorite_pay_tables.php` through `007_create_favorite_pay_audit_logs_table.php`).
  - Zero-downtime column and table checks (`IF NOT EXISTS` / `SHOW COLUMNS LIKE`) ensuring idempotent execution across SQLite, MySQL, and MariaDB.
  - Full lifecycle test suite covering clean installation, sequential upgrade from v1.0.7, and disaster recovery restore without data loss.
- **Security Hardening & Safe Logging**:
  - Strict permission hierarchy: `view_payments`, `manage_payments`, `view_withdrawals`, `manage_withdrawals`, `manage_gateways`, `manage_rates`, `view_audit_logs`.
  - CSRF validation on all state-changing POST requests.
  - Strict context sanitization via `SafeLogger`, masking API secrets, keys, and tokens to `[REDACTED]`.
  - Direct execution guards (`defined('ABSPATH') || exit;`) across all template views.

### Verified Testing Notes
- Full automated test suite passing with 0 failures, 0 errors, and 0 skipped tests across SQLite 3.44.2 and MariaDB 10.4.32.
- Verified through automated unit/integration tests and mock provider transports.
- *Note*: Real Hostinger deployment, live Binance production network transactions, live bKash production network transactions, and sandbox network calls were not performed during automated release verification.

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
