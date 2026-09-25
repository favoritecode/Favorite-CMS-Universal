# Posts and Pages Guide

This guide describes how to author, edit, format, and organize content using the **Posts and Pages** subsystem in Favorite CMS Universal v1.0.0.

---

## Posts vs. Pages

| Feature | Posts (`/admin/posts`) | Pages (`/admin/pages`) |
|---|---|---|
| **Content Type** | Time-sensitive blog articles, news, essays | Evergreen static content (About, Privacy, Contact) |
| **Taxonomies** | Categories and Tags supported | None (structured via hierarchy) |
| **Hierarchy** | Flat (ordered by publication date) | Hierarchical (Parent/Child relationships) |
| **Templates** | Rendered via `single.php` | Rendered via `page.php` (or custom template) |
| **Feeds & Archives**| Appear in latest post feeds and category archives | Not listed in blog feeds |

---

## The Dual-Mode Content Editor

Favorite CMS Universal features an advanced dual-mode editor allowing writers and developers to work efficiently:

```
┌────────────────────────────────────────────────────────┐
│ [👁️ Visual Mode]   [💻 Code Mode]   [🔍 Live Preview] │
├────────────────────────────────────────────────────────┤
│                                                        │
│   • Visual Mode: Full WYSIWYG rich text formatting     │
│   • Code Mode: Monospaced raw HTML with line numbers   │
│   • Bidirectional Sync: Instant lossless switching     │
│                                                        │
└────────────────────────────────────────────────────────┘
```

### Visual Mode (WYSIWYG)
- Rich text toolbar: Headings (H1 to H6), Bold, Italic, Strikethrough, Underline.
- Structured elements: Blockquotes, Ordered and Unordered lists, Code blocks, and Tables.
- Media embedding: Insert images and media directly from the Media Library.
- Clean paste filtering: Sanitizes external Microsoft Word or Google Docs formatting on paste.

### Code Mode (Direct HTML)
- Full control over markup, CSS classes, attributes, and embeds.
- Monospace font styling with line numbers and indentation handling.
- Seamlessly synchronize edits back to Visual Mode without losing attributes or structure.

### Trusted Code Preservation & HTMLPurifier
- Submissions from users without `unfiltered_html` permission are parsed and cleaned with bundled **HTMLPurifier 4.19.0**.
- Untrusted script tags, `javascript:` event attributes, and invalid iframes are stripped out.
- Existing trusted custom-code blocks inserted by administrators are preserved while non-admin editors modify surrounding text.

### Browser Draft Recovery
- The editor automatically preserves drafts in local browser storage on each keystroke.
- If a browser tab is accidentally closed or a session expires, draft recovery prompts the author to restore unsaved changes upon returning.

---

## Publishing Workflow & Statuses

Posts support five lifecycle states:

| Status | Meaning |
|---|---|
| **`draft`** | Work-in-progress visible only to the author and editorial staff in the admin panel. |
| **`pending`** | Awaiting editorial review. Authors submit drafts here; Moderators and Admins review and approve them. |
| **`scheduled`** | Configured with a future publication timestamp. Automatically becomes public when the date arrives. |
| **`published`** | Publicly accessible on the frontend website. |
| **`trash`** | Soft-deleted post. Can be restored or permanently emptied. |

### Moderation Workflow
1. An **Author** writes a draft and clicks **Submit for Review**. The post status becomes `pending`.
2. A badge appears in the Admin sidebar showing the count of pending posts.
3. An **Admin** or **Moderator** visits `/admin/posts?status=pending`, reviews the article, makes editorial edits if necessary, and clicks **Publish**.

---

## Categorization & Taxonomies

Posts can be classified using two taxonomy systems:
- **Categories (`/admin/taxonomies/categories`):** Hierarchical taxonomy for broad thematic grouping (e.g. Technology &rarr; Software &rarr; PHP).
- **Tags (`/admin/taxonomies/tags`):** Non-hierarchical keywords identifying specific topics covered across different articles (e.g. `tutorial`, `security`, `release`).

---

## Pages Hierarchy & Templates

When creating or editing a page (`/admin/pages/new`):
- **Parent Page:** Build URL structures like `/services/web-development` by choosing `/services` as the parent.
- **Page Template:** Select a custom template layout provided by the active theme (e.g. `page-fullwidth.php`, `page-contact.php`).
- **Menu Order:** Set integer sorting orders for pages rendered in navigation trees.
