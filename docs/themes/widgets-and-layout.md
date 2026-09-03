# Widgets & Theme Layout System

Favorite CMS features an extensible, lightweight Widget and Theme Layout customization architecture. Administrators can customize site layouts, add widgets, reorder sections, and adjust theme settings directly in the admin panel without touching PHP, HTML, or CSS.

---

## 1. Core Architecture Overview

```
                  Core System
  ┌──────────────────────────────────────────────┐
  │  WidgetRegistry   <── Core & Plugin Widgets  │
  │  WidgetInstanceManager (Isolated Settings)   │
  │  ThemeLayoutService (Manifest & Theme Mods)  │
  └──────────────────────┬───────────────────────┘
                         │
         ┌───────────────┴───────────────┐
         ▼                               ▼
   Admin Dashboard                 Theme Templates
  - Appearance → Widgets          - render_region('sidebar-primary')
  - Appearance → Customize        - get_theme_mod('site_layout')
  - Drag & Drop / Reorder         - Dynamic Homepage Sections
```

- **Widgets**: Reusable, configurable UI content blocks (Search, Recent Posts, Categories, Tags, Menus, Custom HTML, Images, Featured Story, Recent Comments).
- **Regions**: Structural layout zones declared by themes in `theme.json` (e.g. `sidebar-primary`, `footer-1`, `footer-2`, `footer-3`, `header-right`).
- **Sections**: High-level homepage modular areas (Hero Banner, Featured Showcase, Latest Posts Feed) that can be reordered or toggled on/off.
- **Theme Mods**: Isolated per-theme styling options (sidebar alignment: right/left/none, brand accent color, custom logo URL, copyright text).

---

## 2. Public Template Helpers

The Core exposes clean global helper functions for theme templates:

```php
// Render all active widgets placed in a specific region
echo render_region('sidebar-primary');

// Check if a region contains any visible widgets before outputting wrapper HTML
if (has_region_widgets('sidebar-primary')) {
    echo '<aside class="sidebar">' . render_region('sidebar-primary') . '</aside>';
}

// Retrieve a theme customization setting value with a fallback default
$layout = get_theme_mod('site_layout', 'right');
$accent = get_theme_mod('accent_color', '#0284c7');
$logo   = get_theme_mod('site_logo_url');

// Set a theme customization setting value programmatically
set_theme_mod('accent_color', '#10b981');

// Register a widget from a plugin or theme functions.php
register_widget(new MyCustomWidget());
// or by class string
register_widget(MyCustomWidget::class);
```

---

## 3. Theme Manifest Declaration (`theme.json`)

Themes declare their supported layout regions, homepage sections, and initial default widget placement in `theme.json`:

```json
{
    "id": "my-theme",
    "name": "My Custom Theme",
    "version": "1.0.0",
    "regions": [
        {
            "id": "sidebar-primary",
            "name": "Primary Sidebar",
            "description": "Displayed beside post and page content."
        },
        {
            "id": "footer-1",
            "name": "Footer Column 1",
            "description": "First column in footer."
        },
        {
            "id": "footer-2",
            "name": "Footer Column 2",
            "description": "Second column in footer."
        }
    ],
    "sections": [
        {
            "id": "hero",
            "name": "Welcome Hero Banner",
            "description": "Top welcome banner.",
            "enabled": true
        },
        {
            "id": "latest-posts",
            "name": "Latest Articles",
            "description": "Recent post stream.",
            "enabled": true
        }
    ],
    "default_widgets": {
        "sidebar-primary": [
            { "widget": "search", "settings": { "title": "Search" } },
            { "widget": "recent_posts", "settings": { "title": "Recent News", "number": 5 } }
        ]
    }
}
```

---

## 4. Registering Custom Widgets from Plugins

Plugins can register custom widgets by listening to the `widgets_init` hook or by calling `register_widget()`:

```php
<?php
use FavoriteCMS\Widgets\AbstractWidget;

class WeatherWidget extends AbstractWidget
{
    protected string $id = 'weather_widget';
    protected string $name = 'Weather Forecast';
    protected string $description = 'Displays local temperature and weather status.';
    protected string $category = 'External';
    protected string $icon = '☀️';

    public function getSchema(): array
    {
        return [
            'title' => [
                'type'    => 'text',
                'label'   => 'Title',
                'default' => 'Weather',
            ],
            'city' => [
                'type'    => 'text',
                'label'   => 'City Name',
                'default' => 'London',
            ],
        ];
    }

    public function render(array $settings = [], array $args = []): string
    {
        $settings = $this->resolveSettings($settings);
        $city = htmlspecialchars($settings['city'] ?? 'London', ENT_QUOTES, 'UTF-8');

        $html = '<div class="weather-box">Current Weather in ' . $city . ': 21°C Clear</div>';
        return $this->wrapOutput($html, $settings, $args);
    }
}

// In plugin bootstrap:
\FavoriteCMS\Core\Hook::addAction('widgets_init', function($registry) {
    $registry->register(new WeatherWidget());
});
```

---

## 5. Storage Model & Theme Isolation

All widget instances, region placements, section ordering, and theme mods are stored in the existing `settings` table with namespace prefixing:
- Widget Instances: `widget_{themeId}`
- Region Lists: `widget_regions_{themeId}`
- Theme Mods: `theme_mods_{themeId}`
- Homepage Sections: `theme_sections_{themeId}`

### Theme Switching Safety
When switching between themes:
1. Configurations for the inactive theme are **never deleted**.
2. If Theme A has `sidebar-primary` with custom widgets, activating Theme B loads Theme B's layout.
3. Switching back to Theme A immediately and completely restores all of Theme A's previous widgets, order, and customization settings.
4. An administrator can click **Reset to Theme Defaults** at any time to restore the original layout declared in `theme.json`.

---

## 6. Security & HTML Sanitization

- **Custom HTML Widget**: Validated using `ContentSanitizer`. Administrators with the `unfiltered_html` permission may insert raw embed scripts and iframes. Standard users have scripts (`<script>`, inline `on*` events, `javascript:` URIs) stripped while preserving semantic HTML tags.
- **CSRF Protection**: All widget actions (add, update, delete, move, duplicate, reset) require valid CSRF session tokens.
- **Output Escaping**: Widget titles, field values, and attributes are sanitized with `htmlspecialchars()` before output.

