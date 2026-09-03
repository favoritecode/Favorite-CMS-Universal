# Testing Plugins

How to write automated tests for your Favorite CMS plugins.

---

## 1. Setting Up Plugin Tests

You can add test cases in your plugin's `tests/` directory or integrate them directly with the CMS test suite under `tests/Integration/`.

```php
declare(strict_types=1);

namespace MyPlugin\Tests;

use PHPUnit\Framework\TestCase;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Kernel;
use FavoriteCMS\Models\PluginSetting;

class QuickCounterTest extends TestCase
{
    public function testCounterIncrements(): void
    {
        $app = require APP_ROOT . '/bootstrap.php';
        $kernel = new Kernel($app);

        // Initial count
        PluginSetting::set('quick-counter', 'hits', 5);

        $req = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => '/quick-counter',
        ]);

        $res = $kernel->handle($req);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(6, (int)PluginSetting::get('quick-counter', 'hits'));
    }
}
```

---

## 2. Running Plugin Tests

Run PHPUnit from the project root:
```bash
vendor/bin/phpunit plugins/my-plugin/tests
```
