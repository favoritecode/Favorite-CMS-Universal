# Core Foundation

The Core layer lives under `app/Core/` and provides fundamental architectural services.

---

## 1. Application Container (`FavoriteCMS\Core\Application`)

Extends `Container` and serves as the central dependency injection and service registry.

```php
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;

$app = app(); // Returns Container instance
$db  = app(Database::class); // Resolves Database singleton
```

### Installation Verification:
```php
if ($app->isInstalled()) {
    // Application is in normal operation mode
}
```

---

## 2. Configuration Service (`FavoriteCMS\Core\Config`)

Loads `.env` files and manages default configuration arrays:

```php
$config = app(\FavoriteCMS\Core\Config::class);
$debugMode = $config->get('app.debug', false);
```

---

## 3. HTTP Abstraction: Request & Response

Favorite CMS uses clean, lightweight HTTP request and response objects:

### Request (`FavoriteCMS\Core\Request`):
```php
$method   = $request->method();     // 'GET', 'POST', etc.
$path     = $request->path();       // '/post/my-article'
$queryVal = $request->query('q');   // $_GET['q']
$postVal  = $request->post('name'); // $_POST['name']
$isAjax   = $request->isAjax();     // boolean
```

### Response (`FavoriteCMS\Core\Response`):
```php
// HTML response
return Response::make('<h1>Hello</h1>', 200);

// JSON response
return Response::json(['status' => 'success', 'data' => $items]);

// Redirect
return Response::redirect('/admin/posts');
```

---

## 4. Hook & Event Subsystem (`FavoriteCMS\Core\Hook`)

Manages priority-ordered actions and filters for application extensibility:

```php
use FavoriteCMS\Core\Hook;

// Actions (fire-and-forget)
Hook::addAction('init', function() { ... }, $priority = 10);
Hook::doAction('init');

// Filters (modify and return)
Hook::addFilter('the_title', function($title) { return strtoupper($title); });
$title = Hook::applyFilters('the_title', 'My Post');
```

---

## 5. Dynamic Router (`FavoriteCMS\Core\Router`)

Allows plugins and core modules to declare clean routes dynamically:

```php
use FavoriteCMS\Core\Router;

Router::get('/api/v1/posts', [PostApiController::class, 'index']);
Router::post('/contact-submit', function(Request $req) { ... });
```

---

## 6. Standard Logger (`FavoriteCMS\Core\Logger`)

Writes formatted, timestamped log messages to `storage/logs/favorite_cms.log`:

```php
use FavoriteCMS\Core\Logger;

Logger::info('User logged in', ['user_id' => 1]);
Logger::error('Failed database transaction', ['error' => $e->getMessage()]);
```
Global helper: `cms_log('Message', 'info', ['context' => 'demo']);`
