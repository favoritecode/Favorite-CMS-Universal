# Plugin Hooks & Events

Hooks allow plugins to integrate with the Core lifecycle and interact with other extensions without modifying any source code.

---

## 1. Subscribing to Actions

Actions execute side-effects at designated points in the request lifecycle:

```php
// Hook into core application startup
add_action('init', function() {
    cms_log('Plugin initialized');
});

// Hook into plugin activation
add_action('plugin.activated', function(string $pluginId) {
    if ($pluginId === 'my-plugin') {
        // Run first-time setup or table creation
    }
});

// Hook into plugin deactivation
add_action('plugin.deactivated', function(string $pluginId) {
    // Clean temporary cache files
});
```

---

## 2. Using Content Filters

Filters intercept and transform data before it is rendered or stored:

```php
// Modify post content
add_filter('the_content', function(string $content) {
    return $content . '<p class="disclaimer">Views expressed are my own.</p>';
});

// Override template inclusion
add_filter('template_include', function(?string $path, string $templateName, array $data) {
    if ($templateName === 'single' && isset($data['post']) && $data['post']->type === 'book') {
        return APP_ROOT . '/plugins/book-catalog/templates/single-book.php';
    }
    return $path;
}, 10, 3);
```
