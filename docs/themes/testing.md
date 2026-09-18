# Testing Themes

How to thoroughly verify a theme before release.

---

## 1. Testing Viewport Responsiveness

Verify all responsive breakpoints:
- **Mobile (360px–480px)**: Check hamburger menu toggle, readable font sizes, full-width post cards, and touch-friendly button targets.
- **Tablet (768px–960px)**: Check navigation collapse and sidebar stacking.
- **Desktop (1024px–1440px)**: Ensure maximum container width remains visually centered with comfortable line lengths.

---

## 2. Empty States

Verify that templates handle zero-data scenarios gracefully:
- Homepage with zero posts: Friendly empty prompt (no PHP warnings).
- Search query with zero matches: Clear empty state with retry search form.
- Post with no comments: Clean "No responses yet" indicator.
- Post with no featured image: Elegant typography fallback without broken image icons.

---

## 3. 404 Template

Test an invalid URL (e.g. `http://favorite-cms.local/non-existent-page`):
- Ensure HTTP 404 header is sent.
- Verify friendly 404 message and return-home action.
