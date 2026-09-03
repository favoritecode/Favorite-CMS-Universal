# Themes Subsystem Overview

Favorite CMS Universal strictly separates presentation (HTML/CSS/JS) from business logic and database architecture. Visual styling and responsive layouts are controlled by **Themes** located in the `themes/` directory.

---

## 1. Role & Responsibility of Themes

- **Markup & Typography**: HTML structure, semantic landmarks (`<header>`, `<main>`, `<article>`, `<footer>`), font pairings, and heading hierarchies.
- **Responsive Layout**: CSS grid and flexbox rules adapting content across desktop, tablet, and mobile displays.
- **Theme Regions**: Declaring widget areas (sidebars, multi-column footers, header strips) where administrators can position widgets.
- **Section Composition**: Declaring modular homepage sections (hero banners, featured cards, latest posts).
- **Zero Business Logic**: Themes should not handle user registration logic, database migrations, or payment processing. Those belongs in Core and Plugins.

---

## 2. Directory Structure of a Theme

Every theme lives in its own subdirectory inside `themes/`:
```
themes/
└── default/
    ├── theme.json         # Required: Metadata, declared regions, and options
    ├── functions.php      # Optional: Region registration and theme hooks
    ├── header.php         # Global site header and navigation
    ├── footer.php         # Global site footer and copyright
    ├── index.php          # Main fallback archive template
    ├── single.php         # Single post article template
    ├── page.php           # Static page template
    ├── archive.php        # Category and tag archive template
    ├── search.php         # Search results template
    ├── 404.php            # Not found template
    ├── assets/            # CSS, JavaScript, and images
    │   ├── css/style.css
    │   └── js/main.js
    └── screenshot.png     # 1200x900px visual preview in /admin/themes
```

---

## 3. Template Hierarchy

When rendering a URL, the view engine inspects the active theme following a cascading hierarchy:

1. **Single Post**: `single-{slug}.php` &rarr; `single.php` &rarr; `index.php`
2. **Static Page**: `page-{slug}.php` &rarr; `page.php` &rarr; `index.php`
3. **Category Archive**: `category-{slug}.php` &rarr; `category.php` &rarr; `archive.php` &rarr; `index.php`
4. **Tag Archive**: `tag-{slug}.php` &rarr; `tag.php` &rarr; `archive.php` &rarr; `index.php`
5. **Search Results**: `search.php` &rarr; `index.php`
6. **404 Page**: `404.php` &rarr; system fallback view.

