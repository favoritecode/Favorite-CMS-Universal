# Dark/light appearance and layout fixes

**Current release: 1.1.0.** The earlier ZIP format issue has been fixed. Customizer history, drafts/revisions, thumbnails, safe extension uploads and real MySQL/update checks are documented in [UPGRADE-CHANGES.md](UPGRADE-CHANGES.md). The notes below describe the first UI delivery.

Delivered on 7 October 2026 (Asia/Dhaka). The original ZIP remains unchanged.

## Changes

- One shared theme controller for installation, recovery, authentication, admin, fullscreen customization, the default theme and core fallback pages.
- The selected mode applies before styles render, persists across pages, synchronizes between tabs and updates the same-origin customizer preview.
- Cookie persistence still works when localStorage is restricted. Admin account preference writes keep their click order and continue using the existing CSRF-protected endpoint.
- Semantic colors replace fixed light colors in cards, forms, status badges, upload feedback, editors, media pickers, widget management, SEO, settings, import, backup tools and updates.
- Installer/authentication side panels and default-theme footer/widget regions adapt to both modes. Native controls, placeholders, autofill and focus indicators follow the theme.
- Mobile widget management stacks into one column; media attachment details stack below the gallery; customizer toolbar wraps, the controls sidebar starts at the workspace top, and narrow form grids fit their container.
- Editor grids retain their desktop sidebar and allow the content column to shrink. Preview frames fit their available width and height.
- Existing customizer script had a missing closing brace; repaired it so the original preview/toolbar script runs. Its toolbar Save button now submits the existing form. Fixed an extra quote in a customizer color input.
- Customizer and widget preview links respect installation subdirectories. The public stylesheet gets a version query to refresh browser caches after CSS updates.
- Author-selected rich text colors and custom HTML are preserved; broad inline-style color overrides were removed.

No source files, routes, widget types, features or database migrations were removed. Content, roles, plugins, update/import/backup engines and business logic were retained.

## Verification

- 94 rendered screen variants passed: both modes across installer (environment setup, advanced database setup, restore, success), all authentication screens, admin lists, editors, profile, customizer, tools/import/update views, public home/post/page/search/category/tag/404 and all core fallback templates.
- All 10 built-in widgets rendered using sample data.
- 94 original-versus-updated form/action comparisons passed. Form fields, validation constraints, links, select options and editor commands were retained. Random generated widget instance IDs were normalized for comparison; the intentional customizer Save-button repair was accounted for.
- 14 theme behavior tests passed, including blocked storage, initial mode, button state, cross-tab synchronization, preview synchronization, unsaved input preservation and ordered CSRF preference writes.
- 212 rendered JavaScript blocks parsed successfully.
- 212 non-vendor PHP files passed syntax checks.
- 68 static semantic text/button contrast checks passed the 4.5:1 threshold.
- The actual uninstalled CMS returned HTTP 200 with the correct theme for both light and dark cookies.
- Every file from the original package is still present. Dependencies were kept intact.

Rendering tests used an in-memory SQLite fixture with an adapter for existing MySQL schema statements; no production database was connected. Update screens used local fixture data. Live browser screenshots, pixel positioning and full installation/write workflows on MySQL were not verified because no browser surface was available and the local PHP runtime has no GD extension. Custom third-party themes/plugins and user-supplied embedded content require their own theme-aware styling.

## Follow-up status

1. Browser screenshot regression suites have been added for mobile, tablet and desktop in both modes. Execution still needs a connected browser.
2. Generic customizer Undo/Redo, unsaved-change indicators and preview/save feedback are now active in version 1.1.0.

The later MySQL/GD, real update and extension-upload results are recorded in [UPGRADE-CHANGES.md](UPGRADE-CHANGES.md).

The `_qa` directory beside the extracted CMS contains the local verification scripts and reports. It is deliberately excluded from the installation ZIP.