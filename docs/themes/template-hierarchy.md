# Template Hierarchy

The Favorite CMS template engine resolves templates through a deterministic hierarchy.

---

## 1. Resolution Order

When rendering a template named `article`:

```
1. Active Theme Direct:
   themes/{activeTheme}/article.php

2. Active Theme Templates Directory:
   themes/{activeTheme}/templates/article.php

3. Custom Plugin Path (registered via Engine::addTemplatePath):
   {customPath}/article.php

4. Active Plugins Templates:
   plugins/{pluginId}/templates/article.php
   plugins/{pluginId}/article.php

5. Core Views:
   resources/views/article.php

6. Fallback (if missing):
   themes/{activeTheme}/404.php
```

---

## 2. Overriding Plugin Views in Themes

To override a plugin view (for instance, a forum topic view provided by `plugins/forum/templates/topic.php`):
1. Create `themes/{activeTheme}/templates/topic.php`.
2. The Engine automatically selects your theme version instead of the plugin's default, allowing custom branding without touching plugin code.
