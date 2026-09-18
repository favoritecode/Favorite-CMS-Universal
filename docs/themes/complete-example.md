# Complete Working Theme Example: `default`

Favorite CMS Universal includes an official reference theme located at:
```
themes/default/
```

This theme is clean, professional, responsive, and lightweight.

---

## 1. Directory Structure

```
themes/default/
├── theme.json            <-- Theme manifest
├── header.php            <-- Semantic header with branding & mobile navigation
├── footer.php            <-- Semantic footer with copyright & scripts
├── sidebar.php           <-- Sidebar with search, recent posts, categories, tags
├── index.php             <-- Homepage post feed with card grid & pagination
├── single.php            <-- Single article reading template with comments
├── page.php              <-- Static page template
├── search.php            <-- Dedicated search results template
├── 404.php               <-- Friendly 404 error template
└── assets/
    ├── css/style.css     <-- Modular CSS design tokens and responsive rules
    └── js/main.js        <-- Accessible mobile drawer script
```

---

## 2. Key Features

- **Typography & Reading Container**: Base font size of `1.03125rem` (16.5px) and line-height of `1.72` with line width constrained to `740px`.
- **Image Optional Post Cards**: Post cards gracefully transition to typography-first layout when no thumbnail is attached.
- **Accessible Mobile Drawer**: Built with vanilla JavaScript, ARIA attributes, and keyboard Escape key listeners.
- **Integrated Search**: Search input with embedded SVG icon in the header and dedicated search result feed.
