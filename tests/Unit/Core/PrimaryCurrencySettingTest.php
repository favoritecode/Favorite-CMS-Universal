<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Core;

use CreateSettingsTable;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Currency;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Http\Controllers\Admin\SettingController;
use FavoriteCMS\Models\Setting;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

class PrimaryCurrencySettingTest extends TestCase
{
    private Database $db;
    private PDO $pdo;
    private Application $app;

    protected function setUp(): void
    {
        $_SESSION = [];
        Setting::clearCache();

        // In-memory SQLite for complete relational isolation
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

        // Run Core settings migration
        require_once APP_ROOT . '/database/migrations/009_create_settings_table.php';
        $migration = new CreateSettingsTable($this->db);
        $migration->up();

        $this->app = Application::getInstance();
        $this->app->instance(Database::class, $this->db);
    }

    protected function tearDown(): void
    {
        try {
            Currency::setPrimaryCurrency('BDT');
        } catch (\Throwable) {
        }
        Setting::clearCache();
    }

    public function testDefaultPrimaryCurrencyIsBdt(): void
    {
        $this->assertSame('BDT', Currency::DEFAULT_CURRENCY);
        $this->assertSame('BDT', Currency::getPrimaryCurrency());
        $this->assertSame('BDT', primary_currency());
    }

    public function testSettingCanBeChangedToSupportedCurrencies(): void
    {
        Currency::setPrimaryCurrency('USD');
        $this->assertSame('USD', Currency::getPrimaryCurrency());
        $this->assertSame('USD', primary_currency());

        Currency::setPrimaryCurrency('INR');
        $this->assertSame('INR', Currency::getPrimaryCurrency());
        $this->assertSame('INR', primary_currency());

        Currency::setPrimaryCurrency('EUR');
        $this->assertSame('EUR', Currency::getPrimaryCurrency());

        Currency::setPrimaryCurrency('JPY');
        $this->assertSame('JPY', Currency::getPrimaryCurrency());
    }

    public function testStoredValueIsAlwaysNormalizedUppercase(): void
    {
        Currency::setPrimaryCurrency('  usd  ');
        $this->assertSame('USD', Currency::getPrimaryCurrency());

        Currency::setPrimaryCurrency('inr');
        $this->assertSame('INR', Currency::getPrimaryCurrency());
    }

    public function testRejectsUnsupportedOrInvalidCurrencyCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported or invalid primary currency code");
        Currency::setPrimaryCurrency('XYZ');
    }

    public function testRejectsArbitraryStringFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported or invalid primary currency code");
        Currency::setPrimaryCurrency('TOOLONG');
    }

    public function testSupportedCurrenciesListContainsRequiredIsoCurrencies(): void
    {
        $required = ['BDT', 'USD', 'EUR', 'GBP', 'INR', 'PKR', 'AED', 'SAR', 'CAD', 'AUD', 'JPY', 'CNY'];
        $supported = Currency::getSupportedCodes();

        foreach ($required as $code) {
            $this->assertTrue(Currency::isSupported($code), "Currency {$code} should be supported.");
            $this->assertContains($code, $supported);

            $meta = Currency::get($code);
            $this->assertNotNull($meta);
            $this->assertSame($code, $meta['code']);
            $this->assertNotEmpty($meta['name']);
            $this->assertNotEmpty($meta['symbol']);
            $this->assertIsInt($meta['decimals']);
        }

        // JPY must have 0 decimals
        $this->assertSame(0, Currency::getDecimals('JPY'));
        // USD must have 2 decimals
        $this->assertSame(2, Currency::getDecimals('USD'));
        // BDT must have 2 decimals
        $this->assertSame(2, Currency::getDecimals('BDT'));
    }

    public function testSettingHelpersOperateCorrectly(): void
    {
        set_setting('general', 'site_name', 'My Global Site');
        $this->assertSame('My Global Site', get_setting('general', 'site_name'));

        set_setting('general', 'primary_currency', 'GBP');
        $this->assertSame('GBP', get_setting('general', 'primary_currency'));
        $this->assertSame('GBP', primary_currency());
    }

    public function testAdminSettingControllerUpdatesPrimaryCurrencySuccessfully(): void
    {
        $controller = new SettingController($this->app);

        $request = new Request([], [
            'site_name'        => 'Global Store',
            'site_url'         => 'http://example.com',
            'admin_email'      => 'admin@example.com',
            'timezone'         => 'UTC',
            'primary_currency' => 'USD',
        ], [], [], [], ['REQUEST_METHOD' => 'POST']);

        $response = $controller->update($request);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('USD', Currency::getPrimaryCurrency());
        $this->assertSame('Settings saved successfully.', $_SESSION['flash_success'] ?? null);
    }

    public function testAdminSettingControllerRejectsInvalidPrimaryCurrency(): void
    {
        $controller = new SettingController($this->app);

        $request = new Request([], [
            'site_name'        => 'Global Store',
            'primary_currency' => 'INVALID_CURRENCY',
        ], [], [], [], ['REQUEST_METHOD' => 'POST']);

        $response = $controller->update($request);
        $this->assertSame(302, $response->getStatusCode());
        // Currency should NOT have changed
        $this->assertSame('BDT', Currency::getPrimaryCurrency());
        $this->assertStringContainsString('Invalid Primary Currency', $_SESSION['flash_error'] ?? '');
    }
}
