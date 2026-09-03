# Testing Guide

Favorite CMS Universal uses **PHPUnit 10** for automated unit and integration testing.

---

## 1. Running the Automated Test Suite

Execute tests using Composer:

```bash
composer test
```

Or invoke PHPUnit directly:
```bash
vendor/bin/phpunit --colors=always
```

### Running a Specific Test File:
```bash
vendor/bin/phpunit tests/Integration/PluginReadinessTest.php
```

---

## 2. Test Architecture

The `tests/` directory contains:
- `Unit/`: Tests for isolated classes, models, and helper functions.
- `Integration/`: End-to-end tests exercising the request cycle, Kernel, router, plugin hooks, database, and admin layout rendering.

### Example Plugin Test Pattern:
```php
namespace FavoriteCMS\Tests\Integration;

use PHPUnit\Framework\TestCase;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Kernel;

class MyPluginTest extends TestCase
{
    public function testPluginCustomEndpoint(): void
    {
        $app = require APP_ROOT . '/bootstrap.php';
        $kernel = new Kernel($app);
        
        $req = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => '/my-custom-route',
        ]);
        
        $response = $kernel->handle($req);
        $this->assertSame(200, $response->getStatusCode());
    }
}
```
