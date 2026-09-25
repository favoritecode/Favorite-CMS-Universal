# Settings Guide

This guide documents the global configuration options available in Favorite CMS Universal v1.0.0 via **Settings** (`/admin/settings`) and **SEO** (`/admin/seo`).

---

## 1. General Settings

- **Site Title:** Name of the website displayed in the browser tab and site header.
- **Site Tagline:** Brief summary or motto describing your website.
- **Administration Email:** Primary system email used for administrative alerts, moderation notices, and password recovery.
- **New User Default Role:** The default role assigned to visitors who register through public signup (`Subscriber` recommended).
- **Timezone & Date Formats:** Configures date and time display across posts, comments, and admin logs.

---

## 2. Writing Settings

- **Default Post Category:** The category assigned to new articles when an author does not specify one.
- **Default Post Status:** Initial status applied when clicking "Save Draft" or "Submit".

---

## 3. Reading Settings

- **Front Page Displays:**
  - **Your latest posts:** Displays the blog feed ordered by publication date with pagination.
  - **A static page:** Allows selecting a specific published page as the homepage (e.g. *Home*) and a separate page for blog posts (e.g. *Blog*).
- **Blog Pages Show at Most:** Number of published posts displayed per page before pagination occurs (default `10`).

---

## 4. Discussion & Comments Settings

- **Default Article Settings:** Allow or disallow visitor comments globally on newly created articles.
- **Comment Moderation:**
  - Hold all comments in pending queue until approved by an Editor or Moderator.
  - Automatically approve comments from previously approved authors.

---

## 5. Media Upload Settings

Configure role-specific upload ceilings in bytes:
- **Administrator Upload Limit:** Up to 7,168 MB (7 GB).
- **Moderator Upload Limit:** Up to 500 MB.
- **Standard User Upload Limit:** Up to 200 MB.

*Note: Effective upload capacities will be limited by the underlying PHP `upload_max_filesize` and `post_max_size`.*

---

## 6. SEO Configuration (`/admin/seo`)

- **Title Tag Format:** Template used for `<title>` tags across the site (e.g. `%post_title% - %site_title%`).
- **Default Meta Description:** Fallback summary rendered when an individual post or page lacks a custom description.
- **Open Graph Social Card:** Default social image URL used when sharing links on Facebook, X (Twitter), LinkedIn, and messaging apps.
- **XML Sitemap:** Dynamic sitemap generation enabled at `/sitemap.xml`.
- **Robots.txt:** Custom rules instructing search engine crawlers which sections to index.
