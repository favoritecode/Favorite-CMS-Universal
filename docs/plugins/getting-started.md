# Getting Started with Plugin Development

This tutorial guides you through creating your first Favorite CMS plugin in 5 minutes.

---

## 1. Create the Plugin Directory

Navigate to the `plugins/` directory and create a new folder named `quick-counter`:
```
plugins/quick-counter/
```

---

## 2. Create the Manifest (`plugin.json`)

Create `plugins/quick-counter/plugin.json`:

```json
{
    "id": "quick-counter",
    "name": "Quick Counter",
    "version": "1.0.0",
    "description": "Displays a simple visitor counter on the frontend.",
    "author": "Your Name",
    "requires_php": "8.1.0",
    "dependencies": [],
    "entry_point": "plugin.php"
}
```

---

## 3. Create the Entry File (`plugin.php`)

Create `plugins/quick-counter/plugin.php`:

```php
<?php

declare(strict_types=1);

// Register a public route to display the counter
add_route('GET', '/quick-counter', function(\FavoriteCMS\Core\Request $request) {
    // Retrieve current count
    $count = (int)plugin_setting('quick-counter', 'hits', 0);
    $count++;
    set_plugin_setting('quick-counter', 'hits', $count);

    return \FavoriteCMS\Core\Response::make("<h1>Quick Counter</h1><p>Total page visits: <strong>{$count}</strong></p>", 200);
});
```

---

## 4. Activate Your Plugin

1. Log into your admin dashboard at `/admin`.
2. Go to **Plugins**.
3. Locate **Quick Counter** in the list.
4. Click **Activate**.

---

## 5. Test Your Plugin

Open your browser and navigate to:
```
http://favorite-cms.local/quick-counter
```
Refresh the page a few times. You will see the counter increment reliably. You have built a fully functional Favorite CMS plugin!
