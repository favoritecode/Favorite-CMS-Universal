# Automated Testing & Quality Assurance

Favorite CMS Universal includes an automated test suite powered by PHPUnit 10 to ensure stability, prevent regressions, and validate critical paths across updates.

---

## 1. Running the Test Suite

Execute the entire test suite from the repository root:

```bash
composer test
```
*(or explicitly: `php vendor/bin/phpunit`)*

### Expected Output
```text
PHPUnit 10.5.64 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.2.12
Configuration: C:\xampp\htdocs\Favorite-CMS\.push-CMS\phpunit.xml

...............................................................  63 / 109 ( 57%)
..............................................                  109 / 109 (100%)

Time: 00:02.529, Memory: 12.00 MB

OK (109 tests, 511 assertions)
```

---

## 2. Test Organization

Tests are organized into two categories inside `tests/`:

### 1. Integration Tests (`tests/Integration/`)
- `InstallerTest.php`: Validates browser installation, automatic vs manual database setup, installer session, lockfile persistence, and error handling.
- `DatabaseTest.php`: Validates database connection, schema migration integrity (strictly all 13 migrations), table prefixing, and transactional operations.
- `PostEditorAndMediaSystemTest.php`: Validates dual-mode editor content storage, paste cleanup, HTML sanitization, and role-based upload limits.
- `UserSignupAndModerationTest.php`: Validates public user registration, forced pending moderation on normal user submissions, moderator direct publishing, user suspension/ban lifecycles, and permission guards.

### 2. Unit Tests (`tests/Unit/`)
- `UploadCapabilityServiceTest.php`: Tests byte calculation logic, role limit policies, server ceiling detection, and user upload allowances.
- `ContentSanitizerTest.php`: Validates tag whitelisting, script stripping, and attribute sanitation.
- `HookTest.php`: Tests action and filter registration, priority ordering, and argument passing.

---

## 3. Writing New Tests

When contributing new features or bug fixes, always write tests extending `PHPUnit\Framework\TestCase`:
```php
<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use PHPUnit\Framework\TestCase;
use FavoriteCMS\Core\Application;

class MyFeatureTest extends TestCase
{
    public function testFeatureExecutesSuccessfully(): void
    {
        $app = require APP_ROOT . '/bootstrap.php';
        $this->assertTrue($app->isInstalled());
    }
}
```

