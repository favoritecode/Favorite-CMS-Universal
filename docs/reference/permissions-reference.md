# Permissions & Capabilities Reference

The default Role-Based Access Control matrix in Favorite CMS.

---

## 1. Standard Roles

1. **`super-admin`**: Full site owner with unrestricted capability bypass across all CMS actions.
2. **`administrator`**: Site administrator managing posts, pages, themes, plugins, and settings.
3. **`editor`**: Manages all posts, pages, categories, tags, comments, and media.
4. **`author`**: Creates, edits, and publishes their own posts; uploads media.
5. **`contributor`**: Writes and edits their own posts, but cannot publish directly.
6. **`subscriber`**: Authenticated member with read-only profile access.

---

## 2. Standard Capabilities

| Slug | Description | Roles |
|------|-------------|-------|
| `manage_options` | Modify site settings, themes, and plugins | Super Admin, Administrator |
| `manage_users` | Add, edit, or delete users | Super Admin, Administrator |
| `manage_plugins` | Install, activate, deactivate, or delete plugins | Super Admin, Administrator |
| `manage_themes` | Install, switch, or customize themes | Super Admin, Administrator |
| `publish_posts` | Publish posts to live site | Super Admin, Administrator, Editor, Author |
| `edit_posts` | Create and edit draft posts | Super Admin, Administrator, Editor, Author, Contributor |
| `delete_posts` | Trash or delete posts | Super Admin, Administrator, Editor, Author |
| `upload_files` | Upload media files | Super Admin, Administrator, Editor, Author |
| `moderate_comments`| Approve, unapprove, or trash comments | Super Admin, Administrator, Editor |
| `read` | Access internal dashboard area | All authenticated roles |
