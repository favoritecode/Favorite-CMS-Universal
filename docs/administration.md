# Administration Guide

This guide covers the day-to-day administration of **Favorite CMS Universal v1.0.0** through the unified administrative control panel.

---

## Accessing the Administration Panel

1. Navigate to your site's admin URL:
   ```
   http://example.com/admin
   # or
   http://example.com/admin/login
   ```
2. Log in using your registered username/email and password.
3. Upon successful authentication, you will be directed to the main **Admin Dashboard**.

---

## 1. Dashboard Overview (`/admin`)

The Dashboard provides a real-time high-level view of your website:
- **At a Glance Statistics:** Quick count cards for Published Posts, Pages, Media Assets, Users, and Comments.
- **Pending Moderation Alert:** Highlights submissions requiring attention (e.g. pending posts from Authors or comments awaiting moderation).
- **Recent Activity:** Feed of recent posts and user signups.
- **System Information:** PHP version, database driver, active theme name, and active plugin count.

---

## 2. Posts & Content Management (`/admin/posts`)

- **All Posts:** Search, filter by status (`published`, `draft`, `pending`, `trash`), filter by category, or filter by author.
- **Add New Post (`/admin/posts/new`):**
  - **Dual-Mode Editor:** Toggle seamlessly between **Visual Mode** (rich WYSIWYG formatting) and **Code Mode** (monospaced raw HTML with line numbers).
  - **Post Settings Sidebar:** Set URL slug, publish date/scheduling, excerpt, featured image, post status, categories, and tags.
  - **Live Preview:** Inspect how the rendered post appears on the frontend before publishing.
  - **Revisions & Auto-Recovery:** Automatic browser draft retention guards against accidental data loss if a browser tab is closed.
- **Categories & Tags (`/admin/taxonomies`):** Create and organize taxonomy structures for content organization.

---

## 3. Pages Management (`/admin/pages`)

- **All Pages:** Manage hierarchical content (About Us, Services, Contact, Terms of Service).
- **Add New Page (`/admin/pages/new`):** Same dual-mode editor capabilities as posts, with page-specific controls such as parent page hierarchy and template selection.

---

## 4. Media Library (`/admin/media`)

- **File Browser:** Grid and list views of uploaded images, videos, audio, and documents.
- **Upload Assets:** Drag-and-drop or file picker uploading.
- **Media Information:** Inspect file dimensions, file size, MIME-type, upload date, and direct asset URL.
- **Security Protections:** Executable scripts and double extensions (`.php.jpg`) are automatically rejected.

---

## 5. Comments & Moderation (`/admin/comments`)

- **Review Queue:** Approve, mark as spam, or move comments to trash.
- **Batch Moderation:** Process multiple comments simultaneously.

---

## 6. Appearance & Customization (`/admin/themes`)

- **Themes:** View all installed themes in `themes/`. Click **Activate** to switch active themes. The system seeds default widget regions automatically.
- **Visual Customizer (`/admin/customize`):**
  - **Layout Configuration:** Set global layout (Right Sidebar, Left Sidebar, or Full Width).
  - **Colors & Branding:** Upload site logo, favicon, and define primary brand color schemes.
  - **Homepage Sections:** Enable, disable, or reorder homepage sections (Hero Banner, Featured Articles, Recent Posts Feed).
- **Widgets (`/admin/widgets`):**
  - Add, arrange, and configure widgets across theme-defined regions (Primary Sidebar, Footer 1, Footer 2, Footer 3, Header Right).
  - 10 core widgets available: Search, Recent Posts, Categories, Tags, Navigation Menu, Pages, Custom HTML, Image, Featured Post, Recent Comments.
- **Menus (`/admin/menus`):**
  - Create and manage navigation menus.
  - Add custom links, internal pages, categories, or posts.
  - Assign menus to theme display locations (e.g. Primary Header Navigation, Footer Menu).

---

## 7. Plugins Management (`/admin/plugins`)

- **Plugin List:** Shows installed plugins detected in `plugins/`.
- **Plugin Lifecycle:**
  - **Activate:** Loads plugin, registers prefixable database tables, runs database migrations, and fires `plugin.activated` hook.
  - **Deactivate:** Unregisters plugin and fires `plugin.deactivated` hook. Active data is preserved.
- **Safety Isolation:** Fault-tolerant boot mechanism ensures an error in a third-party plugin does not disable core CMS operation.

---

## 8. User Management (`/admin/users`)

- **User Accounts:** View all registered accounts, roles, and status flags.
- **Add New User (`/admin/users/new`):** Provision new staff or client accounts with specific roles.
- **Status Controls:** Set status to `active`, `suspended`, or `banned`. Suspended or banned accounts immediately lose access to all authenticated sessions.
- **Profile (`/admin/users/profile`):** Update personal details, bio, avatar, and password.

---

## 9. Settings (`/admin/settings`)

- **General Settings:** Site title, site tagline, administration email, new user default role, timezone, date/time format.
- **Writing Settings:** Default post category, post status defaults.
- **Reading Settings:** Front page display (latest posts vs. a specific static page), posts per page pagination limit.
- **Discussion Settings:** Enable/disable public comments, comment moderation rules.
- **Media Settings:** Configure upload size limits for Administrators, Moderators, and standard Users.

---

## 10. SEO Tools (`/admin/seo`)

- **Meta Information:** Global meta title format, default meta description, and keywords.
- **Social Cards:** Open Graph image and social metadata for Facebook and X (Twitter) sharing.
- **Sitemap & Robots:** Built-in XML sitemap generator (`/sitemap.xml`) and customizable `robots.txt` output.

---

## 11. System Tools & Backups (`/admin/tools`)

- **Backups & Health:**
  - Generate full site backups (complete SQL dump + uploads + themes + plugins).
  - Inspect server PHP environment, disk space, and MySQL status.
  - Download or delete stored backup archives.
- **Import / Migration (`/admin/tools/import`):**
  - Import posts and pages from standard formats (Blogger XML, WordPress exports).
- **Core Updates (`/admin/updates`):**
  - Check for official GitHub core updates.
  - Execute 1-click in-app updates with automated pre-update snapshot creation and rollback capability.
