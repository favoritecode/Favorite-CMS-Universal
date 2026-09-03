# Post Editor Guide (Visual & Code Modes)

Favorite CMS Universal includes a built-in professional dual-mode editor designed for writers, journalists, and technical creators.

---

## 1. Dual Editing Modes

The editor features two synchronized modes accessible via the top-right toggle buttons: **[Visual Mode]** and **[Code Mode]**.

### Mode 1: Visual Mode (WYSIWYG)
Visual mode provides a rich typographical editing environment for non-technical authors:
- **Format Dropdown**: Switch between Paragraph (`<p>`), Heading 1 (`<h1>`), Heading 2 (`<h2>`), Heading 3 (`<h3>`), Heading 4 (`<h4>`), Heading 5 (`<h5>`), Heading 6 (`<h6>`), and Preformatted (`<pre>`).
- **Inline Styling**:
  - **Bold** (`Ctrl+B` / `Cmd+B`)
  - **Italic** (`Ctrl+I` / `Cmd+I`)
  - **Underline** (`Ctrl+U` / `Cmd+U`)
  - **Strikethrough**
- **Text Alignment**: Align Left, Center, Align Right, and Justify.
- **Lists & Indentation**:
  - Bulleted unordered list (`<ul>`)
  - Numbered ordered list (`<ol>`)
  - Increase Indent / Decrease Indent
- **Block Elements**:
  - **Blockquote**: Formats quotations with active theme quote styling.
  - **Horizontal Divider** (`<hr>`): Separates thematic content sections.
  - **Code Block**: Monospace container for programming snippets.
- **Interactive Tables**: Insert responsive HTML tables with customizable rows and columns.
- **Link Manager**: Insert hyperlinks with optional `target="_blank"` attribute.
- **Clear Formatting**: Quickly strips inline styling from selected text.
- **Undo / Redo**: Keyboard shortcuts (`Ctrl+Z`, `Ctrl+Y`) and toolbar buttons for instant revision.
- **Automatic Paste Sanitizer**: When content is pasted from desktop applications (such as Microsoft Word or Google Docs), proprietary XML tags (`<o:p>`, `mso-*`, junk inline styles) are automatically cleaned.

### Mode 2: Code Mode
Code mode gives developers and technical authors direct, raw control over the underlying HTML markup:
- **High-Contrast Monospace Editor**: Clean font styling optimized for readability.
- **Line Numbers Gutter**: Synchronized line numbering column that updates in real time as content is added or scrolled.
- **Tab Key Handling**: Pressing `Tab` inserts 2 spaces without losing editor focus or tabbing out of the field.
- **Quick Tag Buttons**: One-click tags for rapid markup insertion (`<h2>`, `<h3>`, `<p>`, `<strong>`, `<em>`, `<a>`, `<blockquote>`, `<ul>`, `<ol>`, `<code>`).
- **Large Document Support**: Handles multi-megabyte HTML code without browser lag.

### Bidirectional Synchronization
Both modes operate on the exact same underlying article data. You can draft in Visual mode, switch to Code mode to adjust specific markup or embed custom widgets, and switch back to Visual mode with zero content loss.

---

## 2. Media Integration

Clicking the **Add Media** button above the editor opens the integrated Media Library modal:
1. Select an existing image, video, audio file, or document.
2. Alternatively, drag and drop new files directly into the modal upload zone.
3. Configure alignment (None, Left, Center, Right) and enter an `alt` description.
4. Click **Insert into Post**:
   - Images are inserted as responsive `<img src="..." alt="...">` elements.
   - Videos are inserted as standard HTML5 `<video controls src="...">` players.
   - Audio files are inserted as `<audio controls src="...">` players.
   - Documents and archives are inserted as clickable download links.

---

## 3. High Capacity Storage

- Posts are saved to MySQL `LONGTEXT` columns, providing up to **4 GB** of text storage per article.
- Full support for multi-chapter books, series guides, long code documentation, and transcripts.

---

## 4. Disaster Recovery & Autosave

- The editor maintains a local snapshot in browser `localStorage` every 20 seconds.
- In the event of a browser crash, accidental window close, or network disconnection, opening the post editor displays a recovery notification banner allowing you to restore your unsaved content with one click.

---

## 5. Live Theme Preview

Clicking **Preview** in the sidebar opens a full preview window rendering the current post draft inside the active theme layout, allowing you to review responsiveness, typography, and image sizing prior to publishing.

