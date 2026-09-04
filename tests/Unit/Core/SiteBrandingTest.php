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

    public function testSanitizeBrandingUrlAllowsValidUrlsAndPaths(): void
    {
        $this->assertSame('https://example.com/logo.png', sanitize_branding_url('https://example.com/logo.png'));
        $this->assertSame('http://cdn.test/assets/logo.svg', sanitize_branding_url('http://cdn.test/assets/logo.svg'));
        $this->assertSame('/uploads/2026/09/logo.png', sanitize_branding_url('/uploads/2026/09/logo.png'));
        $this->assertSame('/favicon.ico', sanitize_branding_url('/favicon.ico'));
    }

    public function testSanitizeBrandingUrlRejectsDangerousSchemesAndPaths(): void
    {
        $this->assertSame('', sanitize_branding_url('javascript:alert(1)'));
        $this->assertSame('', sanitize_branding_url('data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='));
        $this->assertSame('', sanitize_branding_url('vbscript:msgbox(1)'));
        $this->assertSame('', sanitize_branding_url('file:///etc/passwd'));
        $this->assertSame('', sanitize_branding_url('//evil.com/xss.png'));
        $this->assertSame('', sanitize_branding_url('/uploads/../../etc/passwd'));
        $this->assertSame('', sanitize_branding_url(null));
        $this->assertSame('', sanitize_branding_url('   '));
    }

    public function testDeterministicSourceResolutionAndNonDestructiveStorage(): void
    {
        // Set both uploaded path and custom URL
        Setting::set('general', 'site_logo_upload_path', '/uploads/uploaded-logo.png');
        Setting::set('general', 'site_logo_url', 'https://example.com/custom-logo.png');

        // 1. When source is 'upload', uploaded path is returned
        Setting::set('general', 'site_logo_source', 'upload');
        $this->assertSame('upload', get_site_logo_source());
        $this->assertSame('/uploads/uploaded-logo.png', get_site_logo_url());

        // 2. When source is 'url', custom URL is returned, and uploaded path remains intact
        Setting::set('general', 'site_logo_source', 'url');
        $this->assertSame('url', get_site_logo_source());
        $this->assertSame('https://example.com/custom-logo.png', get_site_logo_url());
        $this->assertSame('/uploads/uploaded-logo.png', Setting::get('general', 'site_logo_upload_path'));

        // Favicon source resolution
        Setting::set('general', 'site_favicon_upload_path', '/uploads/fav.ico');
        Setting::set('general', 'site_favicon_url', 'https://example.com/fav.png');

        Setting::set('general', 'site_favicon_source', 'upload');
        $this->assertSame('upload', get_site_favicon_source());
        $this->assertSame('/uploads/fav.ico', get_site_favicon_url());

        Setting::set('general', 'site_favicon_source', 'url');
        $this->assertSame('url', get_site_favicon_source());
        $this->assertSame('https://example.com/fav.png', get_site_favicon_url());
        $this->assertSame('/uploads/fav.ico', Setting::get('general', 'site_favicon_upload_path'));
    }

    public function testSettingControllerSavesLogoAndFavicon(): void
    {
        $controller = new SettingController($this->app);
        $token = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $token;

        $request = new Request([], [
            '_token' => $token,
            'site_name' => 'Brand Test CMS',
            'site_description' => 'Branding Description',
            'site_url' => 'http://brand.test',
            'site_logo_source' => 'url',
            'site_logo_url' => 'https://brand.test/media/logo.png',
            'site_favicon_source' => 'url',
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

    public function testSettingControllerRejectsDangerousLogoUrl(): void
    {
        $controller = new SettingController($this->app);
        $token = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $token;

        $request = new Request([], [
            '_token' => $token,
            'site_name' => 'Brand Test CMS',
            'site_description' => 'Branding Description',
            'site_url' => 'http://brand.test',
            'site_logo_source' => 'url',
            'site_logo_url' => 'javascript:alert(document.cookie)',
            'site_favicon_source' => 'url',
            'site_favicon_url' => '/favicon.ico',
            'admin_email' => 'admin@brand.test',
            'timezone' => 'UTC',
            'primary_currency' => 'USD',
        ], [], [], [], ['REQUEST_METHOD' => 'POST']);

        $response = $controller->update($request);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertNotEmpty($_SESSION['flash_error']);
        $this->assertStringContainsString('Invalid Logo URL', $_SESSION['flash_error']);
    }
}
