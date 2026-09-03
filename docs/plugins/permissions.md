# Permissions & Capabilities in Plugins

Favorite CMS Universal implements Role-Based Access Control (RBAC). Plugins can guard sensitive actions, endpoints, and admin panels using capability checks.

---

## 1. Checking Permissions

Use the global helper `current_user_can()`:

```php
if (!current_user_can('manage_options')) {
    return \FavoriteCMS\Core\Response::make('403 Access Denied', 403);
}
```

---

## 2. Super-Admin Automatic Bypass

Users assigned the `super-admin` role automatically bypass capability restrictions and return `true` for all `current_user_can()` checks.

This allows plugins to introduce novel capabilities (e.g. `manage_tickets`, `export_invoices`) without requiring manual database seeding for site administrators.

---

## 3. Standard System Capabilities

| Capability Slug | Typical Role | Description |
|-----------------|--------------|-------------|
| `manage_options`| Administrator | Manage site settings, themes, and plugins. |
| `manage_users`  | Administrator | Create, edit, and delete user accounts. |
| `publish_posts` | Editor / Author | Publish articles directly to the live site. |
| `edit_posts`    | Contributor+ | Create and edit draft posts. |
| `upload_files`  | Author+ | Upload images and documents to media library. |
| `read`          | All users | Access authenticated member areas. |
