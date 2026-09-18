# Template Rendering Engine

The template engine (`FavoriteCMS\Rendering\Engine`) manages view resolution, template inheritance, scope isolation, and developer template overrides.

---

## 1. Resolution Precedence

When a controller or plugin calls:
```php
$html = app(Engine::class)->render('single', ['post' => $post]);
```

The engine checks locations in strict order:

```
1. Active Theme Direct:
   themes/{activeTheme}/{template}.php

2. Active Theme Templates Subfolder:
   themes/{activeTheme}/templates/{template}.php

3. Custom Paths Registered via Engine::addTemplatePath():
   {customPath}/{template}.php

4. Active Plugins Templates:
   plugins/{activePlugin}/templates/{template}.php
   plugins/{activePlugin}/{template}.php

5. Core / System Views:
   resources/views/{template}.php

6. Fallback:
   themes/{activeTheme}/404.php (or resources/views/404.php)
```

---

## 2. Template Filter Hook (`template_include`)

Before evaluating the template, the engine passes the resolved file path through the `template_include` filter hook:

```php
add_filter('template_include', function(?string $templatePath, string $templateName, array $data) {
    if ($templateName === 'single' && ($data['post']->type ?? '') === 'custom_event') {
        return APP_ROOT . '/plugins/events-manager/templates/event-single.php';
    }
    return $templatePath;
}, 10, 3);
```

---

## 3. Scope Isolation & Variable Extraction

Templates are evaluated inside an isolated buffer (`evaluateTemplate`) using `extract($data, EXTR_SKIP)`. Variables passed in the controller array become local variables inside the template without polluting global or engine state.
