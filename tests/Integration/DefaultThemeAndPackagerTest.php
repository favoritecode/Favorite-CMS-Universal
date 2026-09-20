<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use PHPUnit\Framework\TestCase;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Exceptions\SecurityException;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Kernel;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\Post;
use FavoriteCMS\Models\User;
use FavoriteCMS\Themes\ThemeManager;
use FavoriteCMS\Plugins\PluginManager;

class DefaultThemeAndPackagerTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;
    private static array $cleanupPostIds = [];
    private static array $cleanupPageIds = [];
    private static array $cleanupUserIds = [];

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db = static::$app->make(Database::class);
    }

    public static function tearDownAfterClass(): void
    {
        if (!empty(static::$cleanupPostIds)) {
            $in = implode(',', array_map('intval', static::$cleanupPostIds));
            static::$db->execute("DELETE FROM `posts` WHERE `id` IN ({$in})");
        }
        if (!empty(static::$cleanupPageIds)) {
            $in = implode(',', array_map('intval', static::$cleanupPageIds));
            static::$db->execute("DELETE FROM `pages` WHERE `id` IN ({$in})");
        }
        if (!empty(static::$cleanupUserIds)) {
            $in = implode(',', array_map('intval', static::$cleanupUserIds));
            static::$db->execute("DELETE FROM `user_roles` WHERE `user_id` IN ({$in})");
            static::$db->execute("DELETE FROM `users` WHERE `id` IN ({$in})");
        }
    }

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        $_SESSION = [];
        Setting::clearCache();
        Setting::set('theme', 'active_theme', 'default');
    }

    public function testDefaultThemeDiscoveryAndManifest(): void
    {
        $manager = new ThemeManager(static::$app);
        $themes = $manager->getInstalledThemes();

        $this->assertArrayHasKey('default', $themes);
        $defaultTheme = $themes['default'];

        $this->assertSame('default', $defaultTheme['id']);
        $this->assertSame('Favorite Default', $defaultTheme['name']);
        $this->assertSame('1.1.0', $defaultTheme['version']);
        $this->assertSame('Favorite CMS Team', $defaultTheme['author']);
        $this->assertTrue(is_dir($defaultTheme['path']));

        // Verify required templates exist
        $requiredFiles = [
            'theme.json',
            'functions.php',
            'header.php',
            'footer.php',
            'index.php',
            'single.php',
            'page.php',
            'archive.php',
            'search.php',
            '404.php',
            'sidebar.php',
            '_posts_feed.php',
            'assets/css/style.css',
            'assets/js/main.js',
        ];

        foreach ($requiredFiles as $rel) {
            $this->assertFileExists($defaultTheme['path'] . '/' . $rel);
        }
    }

    public function testFrontendRendersDefaultThemeWithDarkModeDefault(): void
    {
        $kernel = new Kernel(static::$app);
        $req = Request::create('GET', '/');
        $resp = $kernel->handle($req);

        $this->assertSame(200, $resp->getStatusCode());
        $html = (string)$resp->getContent();

        // Must have data-theme="dark" by default
        $this->assertStringContainsString('data-theme="dark"', $html);
        $this->assertStringContainsString('id="theme-toggle-btn"', $html);
        $this->assertStringContainsString('themes/default/assets/css/style.css', $html);
        $this->assertStringContainsString('themes/default/assets/js/main.js', $html);
    }

    public function testFrontendRendersLightModeWhenCookieIsLight(): void
    {
        $_COOKIE['favorite_admin_theme'] = 'light';

        $kernel = new Kernel(static::$app);
        $req = Request::create('GET', '/');
        $resp = $kernel->handle($req);

        unset($_COOKIE['favorite_admin_theme']);

        $this->assertSame(200, $resp->getStatusCode());
        $html = (string)$resp->getContent();

        $this->assertStringContainsString('data-theme="light"', $html);
    }

    public function testDefaultThemePostAndPageTemplates(): void
    {
        $unique = bin2hex(random_bytes(4));
        // Create a test published post
        static::$db->execute(
            "INSERT INTO `posts` (`title`, `slug`, `content`, `excerpt`, `type`, `status`, `author_id`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, 'post', 'published', 1, NOW(), NOW())",
            ['Test Post ' . $unique, 'test-post-' . $unique, '<p>Test post body content.</p>', 'Test excerpt']
        );
        $postId = (int)static::$db->getPdo()->lastInsertId();
        static::$cleanupPostIds[] = $postId;

        // Create a test published page
        static::$db->execute(
            "INSERT INTO `pages` (`title`, `slug`, `content`, `excerpt`, `status`, `author_id`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, 'published', 1, NOW(), NOW())",
            ['Test Page ' . $unique, 'test-page-' . $unique, '<p>Test page body content.</p>', '']
        );
        $pageId = (int)static::$db->getPdo()->lastInsertId();
        static::$cleanupPageIds[] = $pageId;

        $kernel = new Kernel(static::$app);

        // View single post
        $postReq = Request::create('GET', '/post/test-post-' . $unique);
        $postResp = $kernel->handle($postReq);
        $this->assertSame(200, $postResp->getStatusCode());
        $this->assertStringContainsString('Test Post ' . $unique, (string)$postResp->getContent());
        $this->assertStringContainsString('Test post body content', (string)$postResp->getContent());

        // View single page
        $pageReq = Request::create('GET', '/test-page-' . $unique);
        $pageResp = $kernel->handle($pageReq);
        $this->assertSame(200, $pageResp->getStatusCode());
        $this->assertStringContainsString('Test Page ' . $unique, (string)$pageResp->getContent());

        // View 404
        $notFoundReq = Request::create('GET', '/non-existent-page-' . $unique);
        $notFoundResp = $kernel->handle($notFoundReq);
        $this->assertSame(404, $notFoundResp->getStatusCode());
        $this->assertStringContainsString('404', (string)$notFoundResp->getContent());
    }

    public function testPackagerExclusionLogicPreservesAdminThemesAndPlugins(): void
    {
        $excludePatterns = [
            '#(?:^|/)\.git(?:/|$)#',
            '#(?:^|/)\.github(?:/|$)#',
            '#(?:^|/)\.idea(?:/|$)#',
            '#(?:^|/)\.vscode(?:/|$)#',
            '#(?:^|/)\.env(?:\.|$|/)#',
            '#(?:^|/)tests(?:/|$)#',
            '#/phpunit\.xml$#',
            '#/installed\.lock$#',
            '#\.log$#i',
            '#(?:^|/)cache/#i',
            '#(?:^|/)sessions/#i',
            '#(?:^|/)release/#i',
            '#(?:^|/)scratch(?:/|$)#i',
            '#^/public/(plugins|themes)(/|$)#',
            '#^/public/uploads/(?!\.gitkeep$)#',
            '#^/plugins/(?!\.gitkeep$)#',
            '#^/themes/(?!default(/|$)|\\.gitkeep$)#',
            '#\.(zip|sql|bak|backup|tmp|temp|log|map)$#i',
            '#(?:^|/)node_modules(?:/|$)#',
            '#(?:^|/)dfre(?:/|$)#',
            '#(?:^|/)claude(?:/|$)#i',
            '#(?:^|/)codex(?:/|$)#i',
        ];

        $testCases = [
            // Should be KEPT (skip === false)
            '/resources/views/admin/themes' => false,
            '/resources/views/admin/themes/index.php' => false,
            '/resources/views/admin/plugins' => false,
            '/resources/views/admin/plugins/index.php' => false,
            '/themes/default' => false,
            '/themes/default/index.php' => false,
            '/themes/default/assets/css/style.css' => false,
            '/themes/.gitkeep' => false,
            '/plugins/.gitkeep' => false,
            '/public/uploads/.gitkeep' => false,
            '/app/Core/Application.php' => false,

            // Should be EXCLUDED (skip === true)
            '/themes/favorite-web' => true,
            '/themes/favorite-web/functions.php' => true,
            '/plugins/favorite-digital' => true,
            '/plugins/favorite-pay' => true,
            '/.git' => true,
            '/.git/config' => true,
            '/.github/workflows/deploy.yml' => true,
            '/.env' => true,
            '/.env.local' => true,
            '/tests/TestCase.php' => true,
            '/phpunit.xml' => true,
            '/public/uploads/test.jpg' => true,
            '/release/v1.0.0.zip' => true,
            '/scratch/test.php' => true,
        ];

        foreach ($testCases as $path => $shouldSkip) {
            $skipped = false;
            foreach ($excludePatterns as $pat) {
                if (preg_match($pat, $path)) {
                    $skipped = true;
                    break;
                }
            }
            $this->assertSame(
                $shouldSkip,
                $skipped,
                "Failed asserting exclusion behavior for path '{$path}'. Expected skipped=" . ($shouldSkip ? 'true' : 'false') . ", got " . ($skipped ? 'true' : 'false')
            );
        }
    }

    public function testZipSlipSecurityExceptionOnMaliciousThemeZip(): void
    {
        $manager = new ThemeManager(static::$app);

        $tempZip = tempnam(sys_get_temp_dir(), 'theme_slip_');
        $zip = new \ZipArchive();
        $zip->open($tempZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('../../../evil.php', '<?php echo "evil";');
        $zip->close();

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Malicious path detected');

        try {
            $manager->installFromZip(['tmp_name' => $tempZip, 'name' => 'malicious.zip']);
        } finally {
            @unlink($tempZip);
        }
    }

    public function testZipSlipSecurityExceptionOnMaliciousPluginZip(): void
    {
        $manager = new PluginManager(static::$app);

        $tempZip = tempnam(sys_get_temp_dir(), 'plugin_slip_');
        $zip = new \ZipArchive();
        $zip->open($tempZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('../../../evil_plugin.php', '<?php echo "evil";');
        $zip->close();

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Malicious path detected');

        try {
            $manager->installFromZip(['tmp_name' => $tempZip, 'name' => 'malicious_plugin.zip']);
        } finally {
            @unlink($tempZip);
        }
    }
}
