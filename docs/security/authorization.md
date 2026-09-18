# Authorization & Access Control

This document details the Role-Based Access Control (RBAC) architecture, capability checks, and tamper-proofing guards implemented in **Favorite CMS Universal**.

---

## 1. Role-Based Access Control (RBAC)

The authorization subsystem maps users to roles, and roles to specific permission capabilities in the database:

- `users`: Core account table (`id`, `username`, `email`, `status`).
- `roles`: Role definitions (`super-admin`, `admin`, `editor`, `moderator`, `author`, `subscriber`).
- `permissions`: Capability definitions (`manage_posts`, `publish_posts`, `publish_direct`, `approve_posts`, `manage_users`, `manage_settings`, `manage_themes`, `manage_plugins`, `upload_large_media`, `upload_moderator_media`).
- `role_permissions`: Join table linking roles to permissions.
- `user_roles`: Join table linking users to roles.

---

## 2. Server-Side Enforcement (Tamper-Proofing)

Authorization is never evaluated solely on the client. Even if a malicious actor manipulates HTML forms, inspects source code, or sends synthetic cURL/Postman requests, server-side guards strictly enforce policy:

### 1. Post Submission & Forced Moderation
- **Normal Users (`subscriber`)**:
  - In `PostController::store()` and `update()`, the user's direct publishing privilege is checked:
    ```php
    $canDirectPublish = $currentUser->canDirectPublish();
    if (!$canDirectPublish && ($status === 'published' || $actionType === 'publish')) {
        $status = 'pending';
    }
    ```
  - Even if `status=published` or `action_type=publish` is manually submitted by a normal user, the status is overridden to `pending`.

### 2. Moderation Endpoint Protection
- `/admin/posts/approve` and `/admin/posts/reject` explicitly verify `$currentUser->canModeratePosts()`.
- If an unauthorized user or subscriber attempts to call these endpoints, execution halts with HTTP 403 Forbidden.

### 3. Administrative Protection
- Non-admin roles (including Moderators and Subscribers) are strictly blocked from accessing `/admin/settings`, `/admin/themes`, `/admin/plugins`, `/admin/users`, `/admin/widgets`, and `/admin/customize`.
- The `Kernel` dispatch layer returns HTTP 403 Forbidden for any unauthorized access.

