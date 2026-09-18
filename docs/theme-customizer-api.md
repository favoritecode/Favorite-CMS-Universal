# Theme Customizer & Visual Builder API Specification

**Favorite CMS Universal Core**  
*Document Version:* 1.0.0 (API Frozen)  
*Status:* Official Architectural Contract  

---

## 1. Overview & Architectural Contract

Favorite CMS Universal Core provides a generic, decoupled, and extensible **Theme Customizer & Visual Builder** foundation. The Customizer operates as a dedicated full-viewport application (`/admin/customize`) providing:
- Fullscreen workspace without standard Admin sidebar/topbar overhead.
- Fixed top navigation toolbar: `[← Appearance] [Theme Name] [Undo] [Redo] [Desktop] [Tablet] [Mobile] [Preview] [Reset] [Save Changes]`.
- Device preview switcher (Desktop 100%, Tablet 768px, Mobile 375px).
- In-memory history (Undo/Redo with `Ctrl+Z`, `Ctrl+Shift+Z`).
- In-memory validated clipboard (Copy/Paste with `Ctrl+C`, `Ctrl+V`).
- Generic Drag & Drop reordering engine with drop indicator.
- Reusable Templates API (Save as Template, Insert, Delete).
- Global Design System tokens (Colors and Typography).
- Extensible Element Registry (`BuilderElementRegistry`).
- Audited same-origin live preview `postMessage` protocol.

Themes can choose between two frontend rendering contracts:
1. **Model A (Full Visual Builder)**: The theme delegates layout rendering to `ThemeLayoutService::renderBuilderTree($tree)`.
2. **Model B (Theme Section Builder)**: The theme consumes saved section configurations and theme mods (`ThemeLayoutService::getSections()`, `ThemeLayoutService::getThemeMod()`) to render native PHP sections with 1:1 default designs.

---

## 2. Theme Customizer Entry Point

A theme may define its own customizer by placing a `customizer.php` file inside its theme root directory:

```
themes/
  └── my-theme/
      ├── theme.json
      ├── customizer.php     <-- Theme Customizer View
      ├── functions.php
      └── index.php
```

Core automatically delegates `/admin/customize` rendering to `themes/{themeId}/customizer.php` when present.

### Filter Hook: `theme_customizer_view`
Extensions or themes can override the customizer view path programmatically:

```php
add_filter('theme_customizer_view', function(string $viewPath, string $themeId, array $mods, array $sections): string {
    return $viewPath;
}, 10, 4);
```

### Fallback Behavior
If a theme does not provide `customizer.php`, Core renders its built-in generic fallback visual customizer (`resources/views/admin/customize/index.php`) embedded inside the master full-viewport shell.

---

## 3. Builder Tree Data Model & Schema

The Visual Builder tree is stored as a validated JSON structure representing a hierarchical composition of sections, containers, and elements.

### Schema Definition

```json
[
  {
    "id": "sec_hero",
    "type": "section",
    "label": "Hero Banner",
    "enabled": true,
    "settings": {
      "section_id": "hero",
      "padding": "60px 0",
      "background": "#ffffff"
    },
    "responsive": {
      "hide_desktop": false,
      "hide_tablet": false,
      "hide_mobile": false
    },
    "children": [
      {
        "id": "cont_1",
        "type": "container",
        "label": "Hero Container",
        "enabled": true,
        "settings": {
          "max_width": "1200px"
        },
        "responsive": {
          "hide_desktop": false,
          "hide_tablet": false,
          "hide_mobile": false
        },
        "children": [
          {
            "id": "el_heading_1",
            "type": "heading",
            "label": "Headline",
            "enabled": true,
            "settings": {
              "text": "Welcome to Our Platform",
              "tag": "h1",
              "color": "#0f172a",
              "alignment": "center"
            },
            "responsive": {
              "hide_desktop": false,
              "hide_tablet": false,
              "hide_mobile": false
            },
            "children": []
          }
        ]
      }
    ]
  }
]
```

### Security & Validation Limits
Before persisting the tree, `ThemeLayoutService::validateBuilderTree()` enforces strict limits:
- **Maximum Depth**: 10 levels (`MAX_TREE_DEPTH = 10`)
- **Maximum Total Elements**: 200 elements (`MAX_TOTAL_ELEMENTS = 200`)
- **Maximum Children per Container**: 50 children (`MAX_CONTAINER_CHILDREN = 50`)
- **Maximum String Length**: 65,535 characters (`MAX_STRING_LENGTH = 65535`)
- **Maximum Payload Size**: 2 MB (`MAX_PAYLOAD_BYTES = 2097152`)
- **Cycle Prevention**: Circular references are blocked.
- **HTML Sanitization**: All `html` field contents are filtered against XSS, scripts, and executable protocols.

---

## 4. Element Registry (`BuilderElementRegistry`)

Core provides an extensible element registry singleton:

```php
use FavoriteCMS\Themes\BuilderElementRegistry;

$registry = BuilderElementRegistry::getInstance();
```

### Built-In Elements
1. `heading`: Structured titles (`h1`-`h6`, color, size, weight, alignment).
2. `text`: Paragraphs and rich text content.
3. `image`: Media image with URL, alt, link, width, height, radius, object-fit.
4. `video`: Structured video embed (YouTube, Vimeo, MP4 direct) with controls.
5. `button`: Action button with label, URL, target, variant, hover states.
6. `divider`: Separator line (solid, dashed, dotted, color, thickness, spacing).
7. `spacer`: Responsive vertical height gap.
8. `html`: Strictly sanitized HTML block.

### Registering Custom Elements

Themes and plugins can register custom elements via the `builder_register_elements` action hook:

```php
add_action('builder_register_elements', function(BuilderElementRegistry $registry) {
    $registry->register('callout_box', [
        'name'            => 'Callout Box',
        'icon'            => 'alert-circle',
        'category'        => 'content',
        'description'     => 'Highlighted callout message container.',
        'defaultSettings' => [
            'title'   => 'Notice',
            'message' => 'Important message text.',
            'type'    => 'info',
        ],
        'renderCallback'  => function(array $settings, array $responsive): string {
            $title = htmlspecialchars($settings['title'], ENT_QUOTES, 'UTF-8');
            $msg = htmlspecialchars($settings['message'], ENT_QUOTES, 'UTF-8');
            return "<div class=\"callout-box callout-box--{$settings['type']}\"><h4>{$title}</h4><p>{$msg}</p></div>";
        },
    ]);
});
```

---

## 5. Templates API

Reusable sections or containers can be stored as local templates.

### Endpoints
- `GET  /admin/customize/templates`: Returns JSON list of saved templates.
- `POST /admin/customize/templates/save`: Saves template JSON. Payload: `_token`, `name`, `data` (JSON).
- `POST /admin/customize/templates/delete`: Deletes template by ID. Payload: `_token`, `template_id`.

### Template Limits
- **Maximum Templates per Theme**: 50 (`MAX_TEMPLATES_PER_THEME = 50`)
- **Maximum Template Size**: 512 KB (`MAX_TEMPLATE_BYTES = 524288`)

---

## 6. Global Design System Tokens

Core manages global design tokens for colors and typography accessible via `ThemeLayoutService`:

```php
$layoutService = new ThemeLayoutService($app);
$tokens = $layoutService->getGlobalDesignTokens();
```

### Standard Tokens
- **Colors**: `primary`, `secondary`, `heading`, `text`, `background`, `surface`, `border`, `success`, `danger`.
- **Typography**: `body`, `h1`, `h2`, `h3`, `button`, `navigation`, `caption`.

Tokens are automatically emitted into the live preview iframe as CSS Custom Properties (`--accent`, `--primary`, `--heading-color`, etc.).

---

## 7. Preview `postMessage` Protocol

Live preview updates communicate between the Customizer parent window and the preview `<iframe>` using strict same-origin messaging:

### Parent Window Emission

```javascript
var message = {
    source: 'favorite-cms-customizer',
    type: 'setting_change', // 'setting_change', 'section_toggle', 'token_update', 'custom_css'
    payload: {
        key: 'hero_title',
        value: 'New Headline Text'
    }
};

iframe.contentWindow.postMessage(message, window.location.origin);
```

### Preview Frame Verification (`index.php` or theme footer)

```javascript
window.addEventListener('message', function(event) {
    // 1. Strict Origin Validation
    if (event.origin !== window.location.origin) return;

    // 2. Source & Structure Validation
    if (!event.data || event.data.source !== 'favorite-cms-customizer') return;

    var type = event.data.type;
    var payload = event.data.payload || {};

    // 3. Process validated payload in-memory (no reload required)
    if (type === 'setting_change') {
        applyLiveChange(payload.key, payload.value);
    }
});
```

> [!CAUTION]
> Never use `postMessage('*')`. The target origin must always equal `window.location.origin`.

---

## 8. Keyboard Shortcuts & User Controls

The Customizer engine exposes standard keyboard shortcuts with an active input guard (shortcuts do not trigger while typing inside `<input>`, `<textarea>`, `<select>`, or `contenteditable`):

| Shortcut | Action | Scope |
|:---|:---|:---|
| `Ctrl+S` / `Cmd+S` | Save Changes | Global |
| `Ctrl+Z` / `Cmd+Z` | Undo | In-Memory History |
| `Ctrl+Shift+Z` / `Ctrl+Y` | Redo | In-Memory History |
| `Ctrl+C` / `Cmd+C` | Copy Element Settings | In-Memory Clipboard |
| `Ctrl+V` / `Cmd+V` | Paste Element Settings | In-Memory Clipboard |
| `Delete` / `Backspace` | Delete Selected Node | Canvas / Navigator |
| `Escape` | Close Modal / Deselect | Global |

---

## 9. Security & Capability Matrix

All Customizer endpoints require:
1. Authenticated user session.
2. Administrator capability (`canManageThemes()`).
3. Valid CSRF token (`_token`).

All inputs are sanitized before persistence:
- URLs sanitized via `sanitize_branding_url()` / `esc_url()`.
- HTML sanitized via `BuilderElementRegistry::sanitizeHtml()`.
- CSS sanitized via `fw_sanitize_custom_css()` (blocking `@import`, `javascript:`, `expression(`, `behavior:`).
- Payload sizes strictly bounded.

