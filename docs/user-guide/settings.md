# Site Settings Guide

Administrative settings in Favorite CMS Universal (`/admin/settings`) control global site behavior, reading preferences, writing defaults, and media upload capacity policies.

---

## 1. General Settings

- **Site Title**: The primary name of your website (e.g. `Favorite CMS News`). Displayed in browser title bars, header brand logos, and RSS feeds.
- **Tagline / Description**: A concise explanation of what your site is about (e.g. `"One CMS. Any Website."`).
- **Site URL**: The full public web address (e.g. `https://example.com` or `http://localhost/favorite-cms/public`). Used to construct canonical URLs, asset paths, and email links.
- **Administration Email**: The primary contact email address for administrative notices and system alerts.
- **Timezone**: The operational timezone for scheduling posts and formatting timestamps (e.g. `UTC`, `America/New_York`, `Asia/Dhaka`).
- **Membership (Allow Registration)**:
  - **Checkbox**: *"Anyone can register for a normal user account"*.
  - When checked, public registration endpoints (`/register`, `/signup`) are enabled.
  - When unchecked, public registration is rejected, restricting user creation strictly to administrators.

---

## 2. Reading Settings

- **Homepage Displays**:
  - **Your Latest Posts**: The front page renders the default chronological stream of published articles.
  - **A Static Page**: Choose a specific published page (e.g. `Home` or `Landing Page`) to serve as the site homepage.
- **Posts Per Page**: The number of articles displayed on index, category, and tag archive pages before pagination begins (default: `10`).

---

## 3. Writing Settings

- **Default Post Category**: Automatically assigns a default taxonomy term (e.g. `Uncategorized`) if an author publishes an article without selecting a category.

---

## 4. Media & Upload Capabilities

Administrators can customize the software limit allowances for each role category:
- **Admin Max Upload Allowance (MB)**: Default: `7168 MB` (7 GB).
- **Moderator Max Upload Allowance (MB)**: Default: `500 MB`.
- **Standard User Max Upload Allowance (MB)**: Default: `200 MB`.

The interface clearly distinguishes between the **CMS Configured Policy** and the **Server Technical Bottlenecks** (`upload_max_filesize`, `post_max_size`, `memory_limit`, disk space), ensuring administrators know whether their hosting environment can satisfy their configured allowances.

