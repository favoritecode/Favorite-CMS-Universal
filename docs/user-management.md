# User Management Guide

This guide details how user accounts, profile settings, and lifecycle statuses operate in **Favorite CMS Universal v1.0.0**.

---

## User Accounts Overview

Favorite CMS Universal includes an integrated account system supporting both staff administrators and front-end community members.

### Account Fields
- **Username:** Unique, alphanumeric account handle.
- **Email:** Unique, validated email address used for notifications and password recovery.
- **Display Name:** Publicly rendered author name.
- **Password:** Salted cryptographic hash (`password_hash` with `PASSWORD_DEFAULT`).
- **Role:** Assigned role determining system capabilities.
- **Status:** Account state (`active`, `suspended`, `banned`).
- **Auth Version (`auth_version`):** Integer counter incremented whenever security-sensitive credentials change.

---

## Account Statuses & Security Enforcement

Favorite CMS Universal enforces three distinct account states:

| Status | Behavior & Access Level |
|---|---|
| **`active`** | Normal account status with full permissions appropriate to its assigned role. |
| **`suspended`** | Account is temporarily frozen. Cannot log in or perform actions. Existing active sessions are instantly terminated. |
| **`banned`** | Account is permanently blocked. Cannot log in. All active sessions are immediately terminated upon the next HTTP request. Historical published content remains attributed to the author. |

### Immediate Session Invalidation (`auth_version`)
Favorite CMS Universal implements **session versioning** to protect against compromised credentials:
1. Every user record contains an `auth_version` column in the database (default `1`).
2. When a user logs in, their current `auth_version` is stored in `$_SESSION['auth_version']`.
3. Whenever an administrator suspends, bans, or changes the role of a user, or when the user changes their password, `auth_version` is incremented:
   ```sql
   UPDATE users SET auth_version = auth_version + 1 WHERE id = ?
   ```
4. On every subsequent HTTP request, the core Kernel checks if the session's version matches the database version. If they differ, the session is destroyed immediately, and the user is redirected to `/admin/login`.

---

## Managing Users via Admin Panel

Administrators can navigate to **Users** (`/admin/users`) to perform management tasks:

### 1. Adding a New User (`/admin/users/new`)
1. Click **Add New User**.
2. Enter **Username**, **Email Address**, and choose a strong **Password**.
3. Select an assigned **Role** (`Admin`, `Editor`, `Moderator`, `Author`, `Subscriber`).
4. Set initial status (`active`).
5. Click **Create User**.

### 2. Editing User Accounts
- Administrators can update a user's role, email, display name, and bio.
- **Super Administrator Protection:** The system prevents deleting or revoking the role of the final active Super Administrator to protect against accidental lockout (`User::getActiveSuperAdminCount()`).

### 3. Suspending or Banning a User
1. From the User list, click **Edit** next to the user.
2. Under **Account Status**, select **Suspended** or **Banned**.
3. Click **Save Changes**. The user's active login session will terminate immediately.

---

## User Profile Management (`/admin/users/profile`)

Every logged-in user can access their personal profile page to:
- Update their **Display Name** and biographical summary (**Bio**).
- Upload a custom **Avatar** (stored securely in `public/uploads/avatars/`).
- Change their **Password** by supplying their current password and confirming a new one.

---

## Password Recovery Workflow

If a user forgets their password:
1. Visit `/forgot-password`.
2. Enter their registered account email address.
3. The system generates a cryptographic reset token with an expiration window and dispatches a recovery link via `MailService`.
4. The user clicks the link to reach `/reset-password?token=...`, where they set a new password.
5. Upon saving, `auth_version` is incremented, and existing sessions are invalidated across all devices.
