# Custom Templates & Overrides in Plugins

Plugins can provide custom frontend views that integrate directly with the active theme and allow theme authors to override them.

---

## 1. Providing Plugin Views

Place view files inside your plugin's `templates/` folder:
```
plugins/my-plugin/templates/
└── custom-form.php
```

To render your view within a route handler:

```php
add_route('GET', '/my-form', function(\FavoriteCMS\Core\Request $request) {
    $engine = app(\FavoriteCMS\Rendering\Engine::class);
    
    // Engine automatically finds plugins/my-plugin/templates/custom-form.php
    $html = $engine->render('custom-form', [
        'title' => 'Submit Your Application',
    ]);
    
    return \FavoriteCMS\Core\Response::make($html, 200);
});
```

---

## 2. Allowing Theme Overrides

Because the `Engine` resolves active theme directories **before** plugin directories:
1. If the active theme has `themes/{activeTheme}/templates/custom-form.php`, the theme's custom version is rendered.
2. If the active theme does not contain that file, the plugin's default `plugins/my-plugin/templates/custom-form.php` is seamlessly rendered.

This gives theme developers full control over presentation without breaking the plugin!
