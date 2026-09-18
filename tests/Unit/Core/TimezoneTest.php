<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Core;

use CreateSettingsTable;
use DateTimeZone;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\DateTime;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Http\Controllers\Admin\SettingController;
use FavoriteCMS\Models\Setting;
use PDO;
use PHPUnit\Framework\TestCase;

class TimezoneTest extends TestCase
{
    private Database $db;
    private PDO $pdo;
    private Application $app;

    protected function setUp(): void
    {
        $_SESSION = [];
        Setting::clearCache();

        $this->pdo = new PDO('sqlite::memory:', '', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ]);

        $this->db = new class($this->pdo) extends Database {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
                $this->config = ['driver' => 'sqlite'];
                $this->prefix = '';
            }
            public function getConnection(): PDO
            {
                return $this->pdo;
            }
        };

        require_once APP_ROOT . '/database/migrations/009_create_settings_table.php';
        $migration = new CreateSettingsTable($this->db);
        $migration->up();

        $this->app = Application::getInstance();
        $this->app->instance(Database::class, $this->db);
    }

    protected function tearDown(): void
    {
        Setting::clearCache();
    }

    public function testDefaultTimezoneIsUtc(): void
    {
        $this->assertSame('UTC', DateTime::getTimezone());
        $this->assertSame('UTC', site_timezone());

        $utcObj = DateTime::getTimezoneObject();
        $this->assertSame('UTC', $utcObj->getName());
    }

    public function testValidatesStandardIanaTimezoneIdentifiers(): void
    {
        $this->assertTrue(DateTime::isValidTimezone('UTC'));
        $this->assertTrue(DateTime::isValidTimezone('Asia/Dhaka'));
        $this->assertTrue(DateTime::isValidTimezone('America/New_York'));
        $this->assertTrue(DateTime::isValidTimezone('Europe/London'));
        $this->assertTrue(DateTime::isValidTimezone('Asia/Tokyo'));

        // Rejects invalid, non-standard, or empty strings
        $this->assertFalse(DateTime::isValidTimezone(''));
        $this->assertFalse(DateTime::isValidTimezone('   '));
        $this->assertFalse(DateTime::isValidTimezone('+06:00'));
        $this->assertFalse(DateTime::isValidTimezone('GMT+6'));
        $this->assertFalse(DateTime::isValidTimezone('Invalid/Timezone'));
        $this->assertFalse(DateTime::isValidTimezone('Mars/Olympus'));
    }

    public function testSettingPersistsAndFormatsUtcDatabaseTimestamps(): void
    {
        // Sample database UTC timestamp
        $dbTimestamp = '2026-09-10 12:00:00';

        // 1. Under UTC
        DateTime::setTimezone('UTC');
        $this->assertSame('2026-09-10 12:00:00', format_date($dbTimestamp, 'Y-m-d H:i:s'));
        $this->assertSame('12:00 pm', format_date($dbTimestamp, 'g:i a'));

        // 2. Under Asia/Dhaka (+06:00) -> 12:00 UTC becomes 18:00 (6:00 pm)
        DateTime::setTimezone('Asia/Dhaka');
        $this->assertSame('Asia/Dhaka', site_timezone());
        $this->assertSame('2026-09-10 18:00:00', format_date($dbTimestamp, 'Y-m-d H:i:s'));
        $this->assertSame('6:00 pm', format_date($dbTimestamp, 'g:i a'));
        $this->assertSame('Sep 10, 2026 at 6:00 pm', format_date($dbTimestamp, 'M j, Y \a\t g:i a'));

        // 3. Under America/New_York (EDT in Sept: UTC-4) -> 12:00 UTC becomes 08:00 (8:00 am)
        DateTime::setTimezone('America/New_York');
        $this->assertSame('America/New_York', site_timezone());
        $this->assertSame('2026-09-10 08:00:00', format_date($dbTimestamp, 'Y-m-d H:i:s'));
        $this->assertSame('8:00 am', format_date($dbTimestamp, 'g:i a'));

        // 4. Under Asia/Tokyo (+09:00) -> 12:00 UTC becomes 21:00 (9:00 pm)
        DateTime::setTimezone('Asia/Tokyo');
        $this->assertSame('Asia/Tokyo', site_timezone());
        $this->assertSame('2026-09-10 21:00:00', format_date($dbTimestamp, 'Y-m-d H:i:s'));
        $this->assertSame('9:00 pm', format_date($dbTimestamp, 'g:i a'));
    }

    public function testHistoricalDatabaseTimestampsRemainUncorruptedWhenTimezoneChanges(): void
    {
        // Insert a post with fixed UTC created_at
        $fixedUtcDate = '2026-01-15 04:30:00';
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `posts` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `title` TEXT,
                `created_at` TEXT
            )"
        );
        $this->db->query("INSERT INTO `posts` (`title`, `created_at`) VALUES ('Test Post', '{$fixedUtcDate}')");

        // Verify stored value in DB is exactly the original string
        $row = $this->db->selectOne("SELECT `created_at` FROM `posts` WHERE `id` = 1");
        $this->assertSame($fixedUtcDate, $row->created_at);

        // Switch to Asia/Dhaka (+06:00) -> 04:30 UTC = 10:30 Dhaka
        DateTime::setTimezone('Asia/Dhaka');
        $this->assertSame('2026-01-15 10:30:00', format_date($row->created_at, 'Y-m-d H:i:s'));

        // Switch to America/Los_Angeles (PST in Jan: UTC-8) -> 04:30 UTC = Jan 14 20:30
        DateTime::setTimezone('America/Los_Angeles');
        $this->assertSame('2026-01-14 20:30:00', format_date($row->created_at, 'Y-m-d H:i:s'));

        // Stored database timestamp remains untouched
        $rowAfter = $this->db->selectOne("SELECT `created_at` FROM `posts` WHERE `id` = 1");
        $this->assertSame($fixedUtcDate, $rowAfter->created_at);
    }

    public function testSettingControllerUpdatesTimezoneSuccessfully(): void
    {
        $controller = new SettingController($this->app);
        $token = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $token;

        $request = new Request([], [
            '_token'           => $token,
            'site_name'        => 'My Site',
            'site_url'         => 'http://example.com',
            'admin_email'      => 'admin@example.com',
            'timezone'         => 'Asia/Dhaka',
            'primary_currency' => 'BDT',
        ], [], [], [], ['REQUEST_METHOD' => 'POST']);

        $response = $controller->update($request);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('Asia/Dhaka', Setting::get('general', 'timezone'));
        $this->assertSame('Asia/Dhaka', DateTime::getTimezone());
        $this->assertSame('Settings saved successfully.', $_SESSION['flash_success'] ?? null);
    }

    public function testSettingControllerRejectsInvalidTimezone(): void
    {
        $controller = new SettingController($this->app);
        $token = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $token;

        $request = new Request([], [
            '_token'           => $token,
            'site_name'        => 'My Site',
            'site_url'         => 'http://example.com',
            'admin_email'      => 'admin@example.com',
            'timezone'         => 'Invalid/NonExistentZone',
            'primary_currency' => 'BDT',
        ], [], [], [], ['REQUEST_METHOD' => 'POST']);

        $response = $controller->update($request);
        $this->assertSame(302, $response->getStatusCode());

        // Timezone setting should remain unchanged (UTC)
        $this->assertSame('UTC', DateTime::getTimezone());
        $this->assertNotEmpty($_SESSION['flash_error'] ?? '');
        $this->assertStringContainsString('Invalid Timezone', $_SESSION['flash_error']);
    }

    public function testFormatDateHandlesEdgeCases(): void
    {
        // Empty / null
        $this->assertSame('', format_date(null));
        $this->assertSame('', format_date(''));

        // Numeric Unix timestamp (e.g. 0 = 1970-01-01 00:00:00 UTC)
        $this->assertSame('1970-01-01 00:00:00', format_date(0, 'Y-m-d H:i:s', 'UTC'));
        $this->assertSame('1970-01-01 06:00:00', format_date(0, 'Y-m-d H:i:s', 'Asia/Dhaka'));

        // ISO 8601 with explicit offset or Z
        $iso = '2026-09-10T12:00:00Z';
        $this->assertSame('2026-09-10 18:00:00', format_date($iso, 'Y-m-d H:i:s', 'Asia/Dhaka'));
    }
}