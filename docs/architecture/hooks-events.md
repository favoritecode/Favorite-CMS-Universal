# Hooks & Events Subsystem

Favorite CMS Universal implements an event-driven architecture using priority-based Actions and Filters managed by `FavoriteCMS\Core\Hook`.

---

## 1. Actions vs. Filters

| Feature | Actions (`add_action`, `do_action`) | Filters (`add_filter`, `apply_filters`) |
|---------|--------------------------------------|-----------------------------------------|
| **Purpose** | Execute side-effects at specific lifecycle moments (logging, notifying, updating DB). | Transform or inspect a value and return the modified result. |
| **Return Value** | None (void). | Modified value of the first argument. |
| **Examples** | `init`, `plugin.activated`, `post.created`. | `the_content`, `template_include`, `page_title`. |

---

## 2. Registering and Triggering Actions

### Listening to an Action:
```php
add_action('init', function($app) {
    // Code executes during Core request initialization
}, $priority = 10, $acceptedArgs = 1);
```

### Triggering an Action:
```php
do_action('order.completed', $orderId, $customerEmail);
```

---

## 3. Registering and Applying Filters

### Registering a Filter:
```php
add_filter('post_summary', function(string $summary, int $maxLength = 100) {
    if (strlen($summary) > $maxLength) {
        return substr($summary, 0, $maxLength) . '...';
    }
    return $summary;
}, 10, 2);
```

### Applying a Filter:
```php
$summary = apply_filters('post_summary', $rawExcerpt, 140);
```

---

## 4. Priority Ordering

Callbacks are sorted by priority in ascending order:
- Priority `5` runs before `10`.
- Priority `20` runs after `10`.
- Default priority is `10`.
