<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Core;

use FavoriteCMS\Core\AccountMenu;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Models\Setting;
use PHPUnit\Framework\TestCase;

class SiteUrlHelpersTest extends TestCase
{
    protected static Application $app;
    private array $serverBackup = [];

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
    }

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        unset($GLOBALS['favorite_cms_base_path']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        unset($GLOBALS['favorite_cms_base_path']);
        AccountMenu::reset();
    }

    public function testSitePathInRootInstall(): void
    {
        $this->assertSame('/', site_path());
        $this->assertSame('/', site_path(''));
        $this->assertSame('/post/example', site_path('/post/example'));
        $this->assertSame('/search?q=a', site_path('/search?q=a'));
    }

    public function testSitePathInSubdirectoryInstallPrefixesExactlyOnce(): void
    {
        $GLOBALS['favorite_cms_base_path'] = '/cms/';

        $this->assertSame('/cms', site_base_path());
        $this->assertSame('/cms/', site_path('/'));
        $this->assertSame('/cms/post/example', site_path('/post/example'));
        $this->assertSame('/cms/post/example', site_path('/cms/post/example'), 'Already prefixed paths must not be double-prefixed');
        $this->assertSame('/cms', site_path('/cms'));
        $this->assertSame('/cms?page=2', site_path('/cms?page=2'));
        $this->assertSame('/cms/cms-news', site_path('/cms-news'), 'A path that merely starts with the base name is still prefixed');
        $this->assertSame('https://example.com/a', site_path('https://example.com/a'));
        $this->assertSame('//cdn.example.com/a.js', site_path('//cdn.example.com/a.js'));
        $this->assertSame('#top', site_path('#top'));
        $this->assertSame('?page=2', site_path('?page=2'));
        $this->assertSame('relative/path', site_path('relative/path'));
        $this->assertSame('mailto:info@example.com', site_path('mailto:info@example.com'));
    }

    public function testSiteRequestPathStripsBasePathQueryAndTrailingSlash(): void
    {
        $GLOBALS['favorite_cms_base_path'] = '/cms';

        $this->assertSame('/category/news', site_request_path('/cms/category/news/?page=2#top'));
        $this->assertSame('/', site_request_path('/cms'));
        $this->assertSame('/', site_request_path('/cms/'));
        $this->assertSame('/cms-news', site_request_path('/cms-news'));

        unset($GLOBALS['favorite_cms_base_path']);
        $this->assertSame('/about', site_request_path('/about/'));
        $this->assertSame('/', site_request_path('/?x=1'));
    }

    public function testIsCurrentUrlHandlesBasePathQueryStringsAndHosts(): void
    {
        $GLOBALS['favorite_cms_base_path'] = '/cms';
        $_SERVER['REQUEST_URI'] = '/cms/about/?ref=menu';
        $_SERVER['HTTP_HOST'] = 'example.test';

        $this->assertTrue(is_current_url('/about'));
        $this->assertTrue(is_current_url('/cms/about'));
        $this->assertTrue(is_current_url('/about/'));
        $this->assertTrue(is_current_url('http://example.test/cms/about'));
        $this->assertFalse(is_current_url('https://other.test/cms/about'));
        $this->assertFalse(is_current_url('/contact'));
        $this->assertFalse(is_current_url('#'));
        $this->assertFalse(is_current_url('about'));
        $this->assertFalse(is_current_url('javascript:alert(1)'));
    }

    public function testMenuItemUrlKeepsStoredDataCompatible(): void
    {
        $GLOBALS['favorite_cms_base_path'] = '/cms';

        $this->assertSame('#', menu_item_url(['url' => '']));
        $this->assertSame('/cms/page/about', menu_item_url((object)['url' => '/page/about']));
        $this->assertSame('https://example.com/', menu_item_url((object)['url' => 'https://example.com/']));
    }

    public function testThemeAssetUrlIsBasePathAwareVersionedAndSafe(): void
    {
        $this->assertMatchesRegularExpression('#^/themes/default/assets/css/style\.css\?v=\d+$#', theme_asset_url('assets/css/style.css', 'default'));

        $GLOBALS['favorite_cms_base_path'] = '/cms';
        $this->assertMatchesRegularExpression('#^/cms/themes/default/assets/js/main\.js\?v=\d+$#', theme_asset_url('/assets/js/main.js', 'default'));
        $this->assertSame('/cms/themes/default/', theme_asset_url('../../.env', 'default'));
        $this->assertStringStartsWith('/cms/themes/', theme_asset_url('assets/css/style.css', 'bad theme/../id'));
    }

    public function testSiteLanguageUsesSettingAndRejectsInvalidValues(): void
    {
        $original = Setting::get('general', 'site_language', '');
        try {
            Setting::set('general', 'site_language', 'bn_BD');
            $this->assertSame('bn-BD', site_language());

            Setting::set('general', 'site_language', '"><script>');
            $this->assertSame('en', site_language());

            Setting::set('general', 'site_language', '');
            $this->assertMatchesRegularExpression('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', site_language());
        } finally {
            Setting::set('general', 'site_language', (string)$original);
        }
    }

    public function testAccountMenuLinksAreBasePathAwareAndInitialsAreMultibyteSafe(): void
    {
        AccountMenu::reset();
        $GLOBALS['favorite_cms_base_path'] = '/cms';

        $user = new class {
            public int $id = 77;
            public string $name = 'রহিম উদ্দিন';
            public string $username = 'rahim';
            public ?string $avatar = null;
            public function hasPermission(string $permission): bool { return false; }
            public function getRoles(): array { return []; }
        };

        $html = AccountMenu::render(['user' => $user]);

        $this->assertStringContainsString('class="cms-account-menu"', $html);
        $this->assertStringContainsString('class="cms-account-trigger"', $html);
        $this->assertStringContainsString('href="/cms/admin/users/profile"', $html);
        $this->assertStringContainsString('href="/cms/admin/logout"', $html);
        $this->assertStringContainsString('<span class="cms-account-avatar-initial">র</span>', $html);
        $this->assertTrue(mb_check_encoding($html, 'UTF-8'), 'Initial must not cut a multibyte character');
        $this->assertSame('/admin/users/profile', AccountMenu::getItem('profile')['url'], 'Stored item URLs stay unchanged');
    }
}
