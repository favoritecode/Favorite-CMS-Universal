# Themes Architecture

The Theme layer in Favorite CMS is strictly presentation-driven. Themes determine how content is styled and arranged across devices.

---

## 1. Theme Responsibilities & Boundaries

### Themes MUST:
- Provide HTML5 layout structures.
- Define CSS design tokens (typography, colors, breakpoints).
- Render navigation menus, headers, footers, and sidebars.
- Format post archives, cards, reading typography, and search displays.
- Escape dynamic output using `htmlspecialchars()` to prevent XSS.

### Themes MUST NOT:
- Define database tables or run migrations.
- Process user authentication, passwords, or permission changes.
- Handle e-commerce orders, payment transactions, or business workflows.
- Modify Core source code or bypass Core APIs.

---

## 2. Directory Structure of a Theme

```
themes/my-theme/
├── theme.json            <-- Required manifest
├── header.php            <-- Site header & navigation component
├── footer.php            <-- Footer & asset inclusion component
├── sidebar.php           <-- Sidebar widgets component
├── index.php             <-- Homepage & post archive view
├── single.php            <-- Single post reading view
├── page.php              <-- Static page view
├── search.php            <-- Search results view
├── 404.php               <-- Friendly not-found view
└── assets/
    ├── css/style.css     <-- Theme stylesheet
    ├── js/main.js        <-- Accessible navigation scripting
    └── images/           <-- Logos and icons
```

---

## 3. Theme Activation

The active theme is stored in the database `settings` table (`group: theme`, `key: active_theme`).
When an administrator selects a theme in **Appearance &rarr; Themes**, the setting updates immediately. No server reboot or cache clearing is needed.
