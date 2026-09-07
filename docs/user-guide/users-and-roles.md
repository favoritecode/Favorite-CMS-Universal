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
  - Cannot update, trash, restore, or delete existing posts.
  - Cannot upload, update, or delete media files via form or AJAX uploaders.
  - Cannot submit comments on posts (both via authenticated session and registered email address).
  - Cannot access restricted administrative routes; mutations redirect directly to profile with a warning banner.
- **Permitted Actions**:
  - Can log in to view their profile, where a prominent suspension alert explains account restrictions.
  - Can update basic profile details (name, email, bio, password, avatar).
- **Content Preservation**:
  - All existing historical articles, pages, comments, and media files remain safely stored and visible to the public.
- **Reactivation**:
  - Administrators can unsuspend the user at any time with one click (**Activate** action in `/admin/users`).

### 3. Banned (`banned`)
- **Blocked Actions**:
  - Cannot log in to the website. Login attempts fail with:  
    *"Your account has been permanently banned."*
  - Cannot access any administrative endpoints.
  - Cannot submit comments on any post.
- **Immediate Session Invalidation**:
  - If a user is active on the site at the moment an administrator bans them, their active session is immediately destroyed on their next HTTP request across all routes, redirecting them to the login screen with a ban notification.
- **Content Preservation**:
  - All existing historical content is preserved to maintain site integrity and reference history.
- **Restoration**:
  - Administrators can restore banned users back to `active` status at any time (**Restore** action in `/admin/users`).

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
  - **Suspend**: Temporarily halt posting, comment, and upload privileges.
  - **Activate**: Restore a suspended user to active standing.
  - **Ban**: Permanently lock account access and terminate active sessions.
  - **Restore**: Unban and reactivate an account.
  - **Delete**: Permanently remove user account *(Protected: Admins cannot delete or ban their own active account)*.

---

## 4. Public Registration

When enabled, visitors can register accounts directly from `/register` or `/signup`:
- New accounts automatically receive the `subscriber` role and `active` status.
- Passwords are encrypted with PHP's native `password_hash()`.
- Public registration can be enabled or disabled at any time from **Admin &rarr; Settings &rarr; General &rarr; Membership**.

---

## 5. Profile & Avatar Management (`/admin/users/profile`)

Favorite CMS Universal includes a modern, card-based Profile and Account Settings interface accessible from the frontend account dropdown or `/admin/users/profile`.

### Avatar Management
Users can customize their avatar using either local file uploads or external image URLs:
- **Local File Upload**:
  - Supported formats: **JPEG**, **PNG**, and **WebP**.
  - File size limit: **2 MB**.
  - Server-side validation: verifies file size, extension, MIME type (`finfo`), binary image headers/dimensions (`getimagesize`), and strictly rejects SVG or executable files.
  - Safe storage: files are stored with random hex names in `public/uploads/avatars/` with path traversal protections.
  - Automated cleanup: when an avatar is replaced or removed, the previous uploaded file is safely deleted from the disk.
- **External Image URL**:
  - Users can provide a direct link starting with `http://` or `https://`.
  - Strict URL sanitation rejects `javascript:`, `data:`, `file:`, protocol-relative `//`, and control characters.
- **Avatar Removal & Default Fallback**:
  - Users can remove custom avatars with one click.
  - When no avatar is set, the Core automatically renders an accessible initial badge with the user's first initial on both the profile page and the frontend navigation bar.

### Account Security & Immutability
- **Immutable Username**: Usernames cannot be edited after account creation.
- **Self-Promotion Protection**: Users cannot modify their own role or account status via the profile endpoint; attempts to inject role or status updates are strictly ignored server-side.
- **Account Status Banner**: If an account is suspended, the profile page prominently displays a yellow alert explaining that publishing and upload privileges are paused.

---

## 6. Email Verification System

Favorite CMS Universal provides an integrated, cryptographically secure email verification lifecycle:

### Verification Workflow
1. **New Registrations**: When `require_email_verification` is enabled (default), newly registered users are created with `email_verified_at = NULL`. A secure verification token is generated, stored as a SHA-256 hash in `email_verifications`, and emailed to the user.
2. **Login Protection**: Unverified users cannot log in. Attempts to sign in redirect to the login screen with an explanatory alert and a link to request a fresh verification email (`/resend-verification`).
3. **Verification Link**: Users click the link (`/verify-email?token=...`) to activate their account. The token is verified against the SHA-256 hash, checked for 24-hour expiration, and marked verified (`email_verified_at = NOW()`). The token is immediately invalidated to prevent reuse.
4. **Resend Cooldown & Anti-Enumeration**: Resending verification tokens enforces a 60-second rate-limiting cooldown. The resend form always returns a neutral success message regardless of whether the email exists or is already verified, preventing email address enumeration attacks.

### Changing Email in Profile
- When an active user changes their email address in `/admin/users/profile`, the user's primary email address is **not** immediately updated.
- A verification token is issued to the *new* address, and a pending email record is stored.
- The user's account continues using the original email address until the new address is verified by clicking the confirmation link.

---

## 7. Account Self-Deletion & Content Preservation

Users can delete their own accounts from the Danger Zone section on `/admin/users/profile`:

### Security Safeguards
- **Authentication & Confirmation**: Requires CSRF token validation, entering the user's current password, and ticking a confirmation checkbox.
- **Active Account Eligibility**: Only `active` accounts can self-delete. Suspended or banned accounts are strictly prohibited from deleting their account to preserve disciplinary records.
- **Last Administrator Protection**: An administrator cannot delete their account if they are the last remaining active administrator on the website.

### Referential Integrity & Content Preservation
- When an account is self-deleted:
  - Authored posts and pages are automatically reassigned to an active site administrator (`fallbackAdminId`), ensuring no 404 broken links or lost content.
  - Uploaded media records are reassigned to the administrator.
  - The user's custom avatar file on disk is securely removed.
  - Assigned user roles, active sessions, and email verification tokens are deleted.
  - The session is terminated immediately and the visitor is redirected to the home page.

---

## 8. Anti-Ban Registration Bypass Protection

To prevent suspended or banned users from circumventing account restrictions:
- The public registration endpoint (`/register`) checks existing accounts in the database.
- If a registration attempt uses an email address belonging to an existing user with status `suspended` or `banned`, registration is rejected server-side with an error message.
- This prevents bad actors from re-registering or cycling through new accounts with the same email.


