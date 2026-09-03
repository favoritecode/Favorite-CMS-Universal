# Layouts & Responsive Grid Systems

Designing content-first, responsive layouts for Favorite CMS themes.

---

## 1. Content Container & Max-Width

To ensure optimal readability across ultrawide desktop monitors and mobile devices:
- Main container: `max-width: 1200px` (centered with `margin: 0 auto;`).
- Article reading width: capped at `740px` for comfortable line lengths (65–75 characters per line).

---

## 2. CSS Grid Two-Column Architecture

A modern grid layout separating article content from sidebars:

```css
.site-content {
    max-width: 1200px;
    margin: 2rem auto;
    padding: 0 1.5rem;
    display: grid;
    grid-template-columns: minmax(0, 1fr) 300px;
    gap: 2.5rem;
    align-items: start;
}

@media (max-width: 960px) {
    .site-content {
        grid-template-columns: 1fr;
    }
}
```

---

## 3. Responsive Post Card Grid

```css
.posts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1.5rem;
}
```
Adaptive post cards format gracefully whether or not a featured image is attached.
