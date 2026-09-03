# Users & Roles Guide

Favorite CMS Universal provides a comprehensive, role-based user management system (`/admin/users`) featuring granular permission control, account status lifecycle management, and public signup integration.

---

## 1. System Roles & Capabilities

Favorite CMS provides 6 predefined system roles:

| Role Name | Slug | Primary Responsibilities | Default Upload Limit |
|---|---|---|---|
| **Super Admin** | `super-admin` | Full, unrestricted access to all core, server, and system settings. | **7 GB** |
| **Administrator** | `admin` | Full site management: settings, themes, plugins, users, posts, media. | **7 GB** |
| **Editor** | `editor` | Full content management: publish, edit, and delete any post, page, or category. | **500 MB** |
| **Moderator** | `moderator` | Content review: direct post publishing, reviewing pending submissions, approving/rejecting user posts, comment moderation. *(Cannot modify settings, themes, or plugins).* | **500 MB** |
| **Author** | `author` | Create and publish own articles, upload media. | **200 MB** |
| **Subscriber / Normal User** | `subscriber` | Default registered user role: submit posts for review, manage personal profile. *(Posts require moderator approval).* | **200 MB** |

---

## 2. Account Statuses

Each user account exists in one of three functional operational states:

```
                  ┌──────────────┐
                  │    Active    │
                  └──────┬───────┘
                         │
             ┌───────────┴───────────┐
             ▼                       ▼
      ┌──────────────┐        ┌──────────────┐
      │  Suspended   │        │    Banned    │
      └──────────────┘        └──────────────┘
```

### 1. Active (`active`)
- Full standard account operation matching assigned role permissions.
- Can log in, access the admin panel, draft or publish posts (according to role), and upload media.

### 2. Suspended (`suspended`)
- **Blocked Actions**:
  - Cannot create new posts (blocked in `store`, `create`, and `quickDraft`).
  - Cannot update existing posts.
  - Cannot upload media files via form or AJAX uploaders.
- **Permitted Actions**:
  - Can log in to view their profile.
- **Content Preservation**:
  - All existing historical articles, pages, comments, and media files remain safely stored and visible to the public.
- **Reactivation**:
  - Administrators can unsuspend the user at any time with one click (**Activate** action).

### 3. Banned (`banned`)
- **Blocked Actions**:
  - Cannot log in to the website. Login attempts fail with:  
    *"Your account has been permanently banned."*
  - Cannot access any administrative endpoints.
- **Immediate Session Invalidation**:
  - If a user is active on the site at the moment an administrator bans them, their active session is immediately destroyed on their next HTTP request, redirecting them to the login screen with a ban notification.
- **Content Preservation**:
  - All existing historical content is preserved to maintain site integrity and reference history.
- **Restoration**:
  - Administrators can restore banned users back to `active` status at any time (**Restore** action).

---

## 3. Managing Users (`/admin/users`)

The Users administration table displays:
- **Username**: Click to edit profile details. Shows a `"You"` tag next to your own account.
- **Name & Email**: User contact information.
- **Role Selector**: Instant role-change dropdown. Select a new role (e.g. promote a Subscriber to Moderator), and the change applies immediately.
- **Status Badge**: Visual color-coded badge:
  - `ACTIVE` in green.
  - `SUSPENDED` in amber.
  - `BANNED` in red.
- **Posts Count**: Number of submitted posts, linked directly to filter the post list for that author (`/admin/posts?s=username`).
- **Actions**:
  - **Edit Profile**: Modify display name, email, biography, or reset password.
  - **Suspend**: Temporarily halt posting and upload privileges.
  - **Activate**: Restore a suspended user to active standing.
  - **Ban**: Permanently lock account access.
  - **Restore**: Unban and reactivate an account.
  - **Delete**: Permanently remove user account *(Protected: Admins cannot delete or ban their own active account)*.

---

## 4. Public Registration

When enabled, visitors can register accounts directly from `/register` or `/signup`:
- New accounts automatically receive the `subscriber` role and `active` status.
- Passwords are encrypted with PHP's native `password_hash()`.
- Public registration can be enabled or disabled at any time from **Admin &rarr; Settings &rarr; General &rarr; Membership**.

