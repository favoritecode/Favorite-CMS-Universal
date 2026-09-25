# Roles and Permissions Guide

Favorite CMS Universal v1.0.0 implements a granular **Role-Based Access Control (RBAC)** architecture designed for editorial teams, community portals, and multi-author publications.

---

## The 6 Core Roles

The system defines six core roles out of the box:

```
┌────────────────────────────────────────────────────────┐
│                   Super Admin (Root)                   │
├────────────────────────────────────────────────────────┤
│                 Admin (Site Management)                │
├────────────────────────────────────────────────────────┤
│                Editor (Content Director)               │
├────────────────────────────────────────────────────────┤
│           Moderator (Review & Direct Publish)          │
├────────────────────────────────────────────────────────┤
│            Author (Standard Post Creator)              │
├────────────────────────────────────────────────────────┤
│              Subscriber (Reader / Member)              │
└────────────────────────────────────────────────────────┘
```

### 1. Super Admin (`super-admin`)
- **Purpose:** Full system owner and master administrator.
- **Capabilities:** Bypasses all capability checks via `isSuperAdmin()`. Holds complete authority over core updates, full-site backups, restore operations, user role assignments, plugins, themes, database tools, and raw custom HTML/JavaScript execution.
- **Lockout Prevention:** The CMS enforces a database constraint ensuring that the final active Super Admin account cannot be deleted or demoted.

### 2. Admin (`admin`)
- **Purpose:** Site administrator responsible for ongoing site operations.
- **Capabilities:** Full access to all content, media, taxonomies, navigation menus, user accounts, themes, widgets, plugins, site settings, SEO metadata, and core updates.
- **Restrictions:** Cannot delete or demote the primary Super Administrator.

### 3. Editor (`editor`)
- **Purpose:** Content director managing overall editorial operations.
- **Capabilities:**
  - Create, edit, and publish any post or page (including content written by other authors).
  - Manage categories, tags, and navigation menus.
  - Moderate public visitor comments.
  - Upload media assets (up to the moderator threshold, e.g. 500 MB).
  - Manage SEO meta descriptions and social cards.
- **Restrictions:** Cannot delete other authors' posts, manage site settings, install or activate plugins, switch themes, or manage user accounts.

### 4. Moderator (`moderator`)
- **Purpose:** Community and content reviewer.
- **Capabilities:**
  - Create and edit own posts; edit other authors' posts.
  - **Approve Pending Submissions:** Review and approve pending submissions from Authors directly into published status.
  - Moderate and manage comments (approve, mark spam, trash).
  - Upload media files up to the moderator capacity (default 500 MB).
- **Restrictions:** Cannot delete posts written by other authors, cannot manage pages, navigation menus, taxonomies, themes, plugins, or site settings.

### 5. Author (`author`)
- **Purpose:** Standard staff writer or community contributor.
- **Capabilities:**
  - Create, edit, and delete their own posts.
  - Upload standard media assets (up to user capacity, default 200 MB).
  - Manage their personal user profile and biography.
- **Restrictions:** Cannot edit or delete other authors' posts, cannot directly publish posts if moderation review is enforced, cannot moderate comments, cannot manage pages, menus, or taxonomies, and has no access to administration settings.

### 6. Subscriber (`subscriber`)
- **Purpose:** Registered site member or reader.
- **Capabilities:**
  - Access personal profile page to update display name, avatar, and password.
  - Leave verified comments on published articles.
- **Restrictions:** Read-only access to published public content. No publishing, media upload, or site configuration permissions.

---

## Detailed Role Permission Matrix

The table below reflects the exact permission matrix enforced in database migration `017_align_role_permission_matrix.php`:

| Permission Slug | Description | Super Admin | Admin | Editor | Moderator | Author | Subscriber |
|---|---|:---:|:---:|:---:|:---:|:---:|:---:|
| `view_admin` | Access the admin control panel | :white_check_mark: | :white_check_mark: | :white_check_mark: | :white_check_mark: | :white_check_mark: | :white_check_mark: |
| `manage_posts` | Access post listing and editor | :white_check_mark: | :white_check_mark: | :white_check_mark: | :white_check_mark: | :white_check_mark: | :x: |
| `create_posts` | Create new posts | :white_check_mark: | :white_check_mark: | :white_check_mark: | :white_check_mark: | :white_check_mark: | :x: |
| `edit_posts` | Edit authored posts | :white_check_mark: | :white_check_mark: | :white_check_mark: | :white_check_mark: | :white_check_mark: | :x: |
| `edit_others_posts` | Edit posts by other authors | :white_check_mark: | :white_check_mark: | :white_check_mark: | :white_check_mark: | :x: | :x: |
| `delete_posts` | Delete authored posts | :white_check_mark: | :white_check_mark: | :white_check_mark: | :white_check_mark: | :white_check_mark: | :x: |
| `delete_others_posts` | Delete posts authored by others | :white_check_mark: | :white_check_mark: | :x: | :x: | :x: | :x: |
| `publish_posts` | Publish posts directly | :white_check_mark: | :white_check_mark: | :white_check_mark: | :x: | :x: | :x: |
| `approve_posts` | Review & approve pending posts | :white_check_mark: | :white_check_mark: | :x: | :white_check_mark: | :x: | :x: |
| `moderate_comments` | Moderate visitor comments | :white_check_mark: | :white_check_mark: | :white_check_mark: | :white_check_mark: | :x: | :x: |
| `manage_pages` | Create and edit static pages | :white_check_mark: | :white_check_mark: | :white_check_mark: | :x: | :x: | :x: |
| `publish_pages` | Publish static pages | :white_check_mark: | :white_check_mark: | :white_check_mark: | :x: | :x: | :x: |
| `manage_media` | Manage all media items | :white_check_mark: | :white_check_mark: | :white_check_mark: | :x: | :x: | :x: |
| `upload_media` | Upload media files | :white_check_mark: | :white_check_mark: | :white_check_mark: | :white_check_mark: | :white_check_mark: | :x: |
| `upload_moderator_media` | High-capacity upload (500 MB) | :white_check_mark: | :white_check_mark: | :white_check_mark: | :white_check_mark: | :x: | :x: |
| `upload_large_media` | Admin-level upload (up to 7 GB) | :white_check_mark: | :white_check_mark: | :x: | :x: | :x: | :x: |
| `manage_menus` | Create and assign menus | :white_check_mark: | :white_check_mark: | :white_check_mark: | :x: | :x: | :x: |
| `manage_taxonomy` | Manage categories and tags | :white_check_mark: | :white_check_mark: | :white_check_mark: | :x: | :x: | :x: |
| `manage_users` | Add, edit, ban, or delete users | :white_check_mark: | :white_check_mark: | :x: | :x: | :x: | :x: |
| `manage_roles` | Configure roles & permissions | :white_check_mark: | :white_check_mark: | :x: | :x: | :x: | :x: |
| `manage_themes` | Activate themes and customizer | :white_check_mark: | :white_check_mark: | :x: | :x: | :x: | :x: |
| `manage_plugins` | Install, activate plugins | :white_check_mark: | :white_check_mark: | :x: | :x: | :x: | :x: |
| `manage_settings` | Modify site configuration | :white_check_mark: | :white_check_mark: | :x: | :x: | :x: | :x: |
| `unfiltered_html` | Post raw script/HTML blocks | :white_check_mark: | :white_check_mark: | :x: | :x: | :x: | :x: |
| `manage_seo` | Configure SEO metadata & cards | :white_check_mark: | :white_check_mark: | :white_check_mark: | :x: | :x: | :x: |

---

## Programmatic Permission Checks

When developing themes or plugins, you can verify user permissions via the core helper functions or the `User` model:

### 1. Using `current_user_can()`
```php
if (current_user_can('manage_options')) {
    // Current user has permission
}

if (current_user_can('edit_posts')) {
    // Current user can edit posts
}
```

### 2. Using the `User` Model
```php
$user = \FavoriteCMS\Models\User::current();

if ($user && $user->can('publish_posts')) {
    // User can publish posts
}

if ($user && $user->isSuperAdmin()) {
    // User is Super Administrator
}

if ($user && $user->hasRole('editor')) {
    // User belongs to the editor role
}
```
