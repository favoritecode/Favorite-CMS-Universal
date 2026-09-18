# Posts Management Guide

Articles, news stories, tutorials, and long-form publications in **Favorite CMS Universal** are managed via the Posts system (`/admin/posts`).

---

## 1. Post Statuses & Life Cycle

Posts move through clearly defined statuses:

| Status | Code | Description | Who Can Set It |
|---|---|---|---|
| **Draft** | `draft` | Work in progress. Only visible to author and administrators. | All Authors, Normal Users, Admins |
| **Pending Review** | `pending` | Submitted for moderation. Awaiting approval by an Admin or Moderator. | Automatically forced for Normal Users / Subscribers |
| **Published** | `published` | Live on the public website. Appears on homepage, archives, and feeds. | Admins, Editors, Moderators |
| **Scheduled** | `scheduled` | Configured with a future `published_at` timestamp. | Admins, Editors |
| **Rejected** | `rejected` | Reviewed by a moderator and not accepted. Retained for author revision. | Admins, Moderators |
| **Archived** | `archived` | Withdrawn from public listings but preserved in the database. | Admins, Editors |
| **Trash** | `trash` | Soft-deleted post. Can be restored or permanently emptied. | Post Author, Admins |

---

## 2. Managing Posts (`/admin/posts`)

The main Posts list provides a comprehensive, filtered table:
- **Status Tabs**: Click **All**, **Published**, **Drafts**, **Pending Review**, **Rejected**, or **Trash** to filter the list instantly.
- **Pending Review Counter**: Highlights how many posts are waiting for moderator evaluation.
- **Search**: Search posts by title keywords or author username (`/admin/posts?s=query`).
- **Multi-Select & Bulk Actions**: Select multiple articles via row checkboxes or the master checkbox. The selection counter indicates the exact number of selected rows, and confirmation alerts safeguard against accidental deletion or trashing. Supported actions include **Move to Trash**, **Restore**, **Delete Permanently**, **Approve**, and **Reject**.
- **Post Columns**:
  - **Title**: Post title, with hover actions: **Edit**, **Quick View**, **Approve** (for pending), **Reject** (for pending), and **Trash**.
  - **Author**: Name and username of the creator.
  - **Categories**: Assigned taxonomy terms.
  - **Tags**: Keyword tags.
  - **Comments**: Approved and pending comment counts.
  - **Status Badge**: Visual color-coded badge (`Published` in green, `Pending Review` in amber, `Draft` in grey, `Rejected` in red).
  - **Date**: Publication or last modified date.

---

## 3. Creating a Post (`/admin/posts/new`)

1. Navigate to **Posts** &rarr; **Add New Post**.
2. Enter the **Post Title** in the top field.
3. Edit the body content using the **Dual-Mode Editor** (see [Post Editor Guide](post-editor.md)).
4. Configure the sidebar meta boxes:
   - **Publish Box**:
     - Status: Choose Draft, Pending, or Published (for authorized roles).
     - Save Draft or Publish / Submit for Review.
     - Move to Trash.
   - **Category**: Select the primary category or click **+ Add New Category**.
   - **Tags**: Enter comma-separated keyword tags.
   - **Featured Image**: Click **Set Featured Image** to select or upload a hero image from the Media Library.
   - **Excerpt**: Enter an optional summary text used in RSS feeds, search engine cards, and theme archive listings.
   - **Slug (URL)**: Automatically generated from your title (e.g. `my-first-post`), or customize it manually.
5. Click **Publish** (or **Submit for Review** if you are a normal user).

