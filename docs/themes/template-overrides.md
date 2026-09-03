# Template Overrides Guide

Themes in Favorite CMS Universal can override template outputs supplied by the Core system or active Plugins without modifying plugin source code.

---

## 1. Plugin Template Overrides

When a plugin renders a public user-facing view (such as an ecommerce product card, booking form, or user profile block), it queries the template resolution engine.

### Override Resolution Order
The engine checks locations in the following sequence:

```
1. Active Theme Override:
   themes/{active_theme}/plugins/{plugin-slug}/{template-name}.php

2. Plugin Default Fallback:
   plugins/{plugin-slug}/views/{template-name}.php
```

### Example: Customizing an Ecommerce Product Card
If an installed plugin named `simple-store` renders a product listing template at:
`plugins/simple-store/views/product-card.php`

To customize this card in your theme:
1. Inside your theme directory, create the subfolder:
   `themes/my-theme/plugins/simple-store/`
2. Copy `product-card.php` into that directory.
3. Edit the HTML markup and CSS classes in your theme copy.
4. The CMS will automatically load your theme's version instead of the plugin's default view.

---

## 2. Widget Template Overrides

Themes can also customize how core or plugin widgets are rendered on the frontend:

```
1. Theme Widget Override:
   themes/{active_theme}/widgets/{widget-slug}.php

2. Widget Default Rendering:
   app/Widgets/{WidgetClass}::render()
```

This allows a theme to style recent post thumbnails, category lists, or search bars to match the theme's unique aesthetic while preserving all underlying query logic and administrative options.

