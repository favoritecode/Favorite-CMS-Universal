# Admin Dashboard Guide

The **Favorite CMS Universal** administrative dashboard (`/admin`) serves as the central control room for your website. It provides an immediate overview of your content, site activity, quick publishing tools, and system health.

---

## 1. Accessing the Dashboard

1. Navigate to `/admin` or `/admin/login` on your website domain.
2. Enter your administrator or staff credentials.
3. Upon successful authentication, you are presented with the main Dashboard interface.

---

## 2. Dashboard Interface Overview

### Top Summary Cards ("At a Glance")
The top statistics row displays real-time counts:
- **Published Posts**: Total active posts visible to the public.
- **Published Pages**: Total static pages live on the site.
- **Media Files**: Total images, videos, audio tracks, and documents stored in your Media Library.
- **Comments**: Total visitor feedback requiring moderation or published on articles.

### Pending Moderation Alert
If visitors or normal users have submitted articles that are awaiting review, a high-visibility amber alert appears:
> **Pending Posts Awaiting Review**: Displays the count of pending posts with a direct link to the moderation queue (`/admin/posts?status=pending`).

### Quick Draft Widget
Authors and administrators can quickly capture ideas without opening the full post editor:
1. Enter a **Title**.
2. Type brief thoughts or an outline into the **Content** field.
3. Click **Save Draft**.
4. The draft is saved instantly with `status = 'draft'` and is listed in your Posts list for full editing later.

### Recent Activity & Latest Content
- Displays the 5 most recently created or updated posts, their author, status badge, and update timestamp.
- Quick action links allow 1-click navigation to edit or preview any recent post.

### System & Hosting Status
Displays critical runtime parameters:
- **Favorite CMS Version**: Current release line (e.g. `1.0.0-beta`).
- **Active Theme**: Currently activated presentation theme (e.g. `default`).
- **PHP Version**: Server PHP runtime (e.g. `PHP 8.2.12`).
- **Database Engine**: MySQL/MariaDB version and connection status.
- **Server Upload Limits**: Maximum file size accepted by the server (`upload_max_filesize` and `post_max_size`).

---

## 3. Sidebar Navigation

The left administrative sidebar provides role-aware navigation:
- **📊 Dashboard**: Return to the overview screen.
- **📝 Posts**: All Posts, Add New Post, Categories, Tags, and Pending Review (for moderators/admins).
- **📄 Pages**: Static page creation and hierarchy management.
- **🖼️ Media**: Multimedia library, uploads, and file details.
- **💬 Comments**: Comment moderation, approvals, and spam filtering.
- **🎨 Appearance**: Themes, Theme Customizer, Widgets, and Navigation Menus *(Admin only)*.
- **🔌 Plugins**: Manage installed plugins and activate extensions *(Admin only)*.
- **👥 Users**: User management, role assignments, suspension, and ban controls *(Admin only; normal users see Profile only)*.
- **⚙️ Settings**: General site settings, public registration toggle, reading, writing, and upload limits *(Admin only)*.
- **🔍 SEO**: Global meta descriptions, canonical URLs, and sitemap settings *(Admin only)*.
- **🛠️ Tools**: System checks, import/export, and diagnostics *(Admin only)*.

