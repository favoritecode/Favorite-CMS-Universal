<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Core;

use CreateSettingsTable;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Http\Controllers\Admin\SettingController;
use FavoriteCMS\Models\Setting;
use PDO;
use PHPUnit\Framework\TestCase;

class SiteBrandingTest extends TestCase
{
    private Database $db;
    private PDO $pdo;
    private Application $app;

    protected function setUp(): void
    {
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
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

    public function testHelpersReturnDefaultWhenEmpty(): void
    {
        $this->assertSame('', get_site_logo_url(''));
        $this->assertSame('https://example.com/default-logo.png', get_site_logo_url('https://example.com/default-logo.png'));
        $this->assertSame('/favicon.ico', get_site_favicon_url());
        $this->assertSame('https://example.com/default-favicon.png', get_site_favicon_url('https://example.com/default-favicon.png'));
    }

    public function testHelpersReturnValuesFromGeneralSettings(): void
    {
        Setting::set('general', 'site_logo_url', 'https://example.com/uploads/logo.png');
        Setting::set('general', 'site_favicon_url', 'https://example.com/uploads/favicon.png');

        $this->assertSame('https://example.com/uploads/logo.png', get_site_logo_url());
        $this->assertSame('https://example.com/uploads/favicon.png', get_site_favicon_url());
    }

    public function testSettingControllerSavesLogoAndFavicon(): void
    {
        $controller = new SettingController($this->app);
        $request = new Request([], [
            'site_name' => 'Brand Test CMS',
            'site_description' => 'Branding Description',
            'site_url' => 'http://brand.test',
            'site_logo_url' => 'https://brand.test/media/logo.png',
            'site_favicon_url' => 'https://brand.test/media/favicon.ico',
            'admin_email' => 'admin@brand.test',
            'timezone' => 'UTC',
            'primary_currency' => 'USD',
            'posts_per_page' => 10,
            'front_page_type' => 'posts',
            'front_page_id' => 0,
            'default_category' => 1,
            'max_upload_size_admin_mb' => 100,
            'max_upload_size_moderator_mb' => 50,
            'max_upload_size_user_mb' => 10,
        ], [], [], [], ['REQUEST_METHOD' => 'POST']);

        $response = $controller->update($request);
        $this->assertSame(302, $response->getStatusCode());

        $this->assertSame('https://brand.test/media/logo.png', Setting::get('general', 'site_logo_url'));
        $this->assertSame('https://brand.test/media/favicon.ico', Setting::get('general', 'site_favicon_url'));
        $this->assertSame('https://brand.test/media/logo.png', get_site_logo_url());
        $this->assertSame('https://brand.test/media/favicon.ico', get_site_favicon_url());
    }
}
