# Post & Page Editor: Dual Editing Modes

Favorite CMS includes a professional, built-in dual-mode editor designed for both content writers and technical creators.

---

## 🎨 Dual Editing Modes

### 1. Visual Mode (Default)
Visual Mode provides an intuitive WYSIWYG editing experience similar to modern office documents or blogging suites (Word, Google Docs, Blogger):
- **Paragraph & Headings**: Quick dropdown for Paragraph, H1, H2, H3, H4, and `<pre>`.
- **Text Styling**: Bold (`Ctrl+B`), Italic (`Ctrl+I`), Underline (`Ctrl+U`), Strikethrough.
- **Alignment**: Align left, center, right, and justify.
- **Lists & Indentation**: Bulleted lists, numbered lists, increase and decrease indent.
- **Blocks & Dividers**: Blockquote styling and horizontal rules (`<hr>`).
- **Interactive Tables**: Insert table with configurable rows and columns.
- **Link Manager**: Insert, edit, and remove hyperlinks.
- **Paste Cleaner**: Automatically strips Microsoft Word junk XML, `mso-*` styles, and `<o:p>` tags when pasting content from desktop word processors.

### 2. Code Mode
Code Mode provides technical authors and developers complete control over the underlying HTML:
- **Monospace Code Container**: High-contrast, syntax-friendly code editor.
- **Line Numbers Gutter**: Synchronized line-number column on typing and scrolling.
- **Indentation Handling**: Tab key inserts 2 spaces without losing focus.
- **Quick HTML Inserts**: Convenient one-click insertion of semantic tags (`<h2>`, `<h3>`, `<p>`, `<strong>`, `<em>`, `<a>`, `<blockquote>`, `<ul>`, `<ol>`, `<pre><code>`).

### Bidirectional Synchronization
Switching between **Visual Mode** and **Code Mode** is completely seamless and instantaneous. The editor ensures no data loss or silent tag divergence when toggling modes or submitting posts.

---

## 💾 Post Storage & Capacity

- **Database Column**: Content is stored in MySQL `LONGTEXT`, providing up to 4 GB of text storage capacity per post or page.
- **Large Content Support**: Easily handles long articles, full book chapters, multi-part movie or web-series episode guides, and comprehensive code snippets.
- **Local Autosave & Disaster Recovery**: Content is periodically snapshot in browser `localStorage` every 20 seconds. If a power outage or accidental tab closure occurs, a restore notification banner prompts the user to restore the unsaved draft with one click.

---

## 👁️ Live Theme Preview

Clicking **Preview Post** opens a modal window rendering the active theme's styling, so authors can verify typography, images, and layout before publishing to the public.

