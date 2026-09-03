# Content Moderation Workflow Guide

Favorite CMS Universal includes an integrated, role-based post moderation workflow designed for multi-author publications, community portals, news sites, and contributor networks.

---

## 1. Overview of the Moderation Flow

```
Visitor / Contributor (Subscriber)
               │
               ▼
       Registers Account (/register)
               │
               ▼
       Writes & Submits Post
               │
               ▼
    FORCED SERVER-SIDE OVERRIDE
       Status: "pending"
               │
               ▼
    ┌─────────────────────────────┐
    │     MODERATION QUEUE        │
    │  (/admin/posts?status=pending)│
    └──────────────┬──────────────┘
                   │
         ┌─────────┴─────────┐
         │ Review by Admin   │
         │ or Moderator      │
         └─────────┬─────────┘
                   │
         ┌─────────┴─────────┐
         ▼                   ▼
     APPROVE               REJECT
  Status: "published"   Status: "rejected"
  Live on Website       Sent back for edits
```

---

## 2. Normal User (Contributor) Experience

1. A registered user logs into `/admin`.
2. They click **Posts** &rarr; **Add New Post** or **+ Create Post** from the public website header.
3. The post editor recognizes that the user does not possess direct-publishing permissions:
   - The primary action button displays: **"Submit for Review"**.
   - The status selector is constrained to **Draft** and **Submit for Review (Pending)**.
4. When the user clicks **Submit for Review**:
   - The server validates the content.
   - Even if an attacker attempts HTTP POST manipulation by injecting `status=published` or `action_type=publish`, the server's `canDirectPublish()` security check enforces `status = 'pending'`.
   - A success banner informs the author:  
     *"Post submitted successfully and is awaiting review by a moderator."*

---

## 3. Moderator & Administrator Review

### Finding Pending Submissions
- **Admin Dashboard Alert**: The dashboard displays an amber warning card showing the total count of pending submissions.
- **Sidebar Badge**: The **📝 Posts** menu item displays a dynamic yellow badge indicating pending posts (e.g. `Posts [3]`).
- **Pending Review Tab**: In `/admin/posts`, click the **Pending Review** tab to filter the table.

### Review Actions in the Post Table
For each pending post, hover actions provide:
- **Edit / Review**: Open the full post editor to inspect typography, formatting, links, and media.
- **Approve**: Instantly changes post status to `published`, sets `published_at = NOW()`, and makes the article visible on the public website.
- **Reject**: Changes post status to `rejected`. The article is removed from the pending queue but retained in the database so the author can address feedback.

### In-Editor Moderation Box
When an Admin or Moderator opens a pending post inside `/admin/posts/edit`:
- A yellow notice banner alerts the reviewer:  
  *"This post was submitted by [username] and is currently pending review."*
- The **Publish** box in the sidebar provides two prominent quick-action buttons:
  - **Approve Post** (Green button): Publishes the post immediately.
  - **Reject** (Red button): Rejects the submission.

---

## 4. Moderator Direct Publishing

Users assigned the **Moderator** role:
- Have the `publish_direct` capability: when a Moderator writes and submits an article, it publishes directly as `published` without entering the pending review queue.
- Have a **500 MB** upload limit allowance for rich media.
- Can moderate, edit, approve, and reject posts submitted by others.
- Are strictly prevented from accessing administrative system areas (Settings, Themes, Plugins, Users, Widgets, and Tools).

