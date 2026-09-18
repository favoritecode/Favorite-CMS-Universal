# Dynamic Frontend Routes in Plugins

Favorite CMS allows plugins to register custom frontend HTTP routes using `add_route()`.

---

## 1. Basic Route Registration

```php
add_route('GET', '/events', function(\FavoriteCMS\Core\Request $request) {
    return \FavoriteCMS\Core\Response::make('<h1>Upcoming Events</h1>', 200);
});
```

---

## 2. Route Parameters (`{param}`)

Use curly braces to capture dynamic segments:

```php
add_route('GET', '/events/{year}/{month}', function(\FavoriteCMS\Core\Request $request, string $year, string $month) {
    return \FavoriteCMS\Core\Response::json([
        'year'  => $year,
        'month' => $month,
        'count' => 12,
    ]);
});
```

---

## 3. Returning Responses

Your route callback can return:
1. An instance of `\FavoriteCMS\Core\Response`.
2. An array (automatically serialized to JSON with `Content-Type: application/json`).
3. A raw HTML string (wrapped in a 200 OK HTML response).

```php
// Returning JSON
add_route('GET', '/api/status', function() {
    return ['status' => 'operational', 'time' => time()];
});
```
