<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use PHPUnit\Framework\TestCase;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Kernel;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;

class AdminAppearanceTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;
    private static array $createdUserIds = [];

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db = static::$app->make(Database::class);

        static::$db->execute("INSERT IGNORE INTO `roles` (`name`, `slug`, `description`, `is_system`) VALUES ('Administrator', 'admin', 'Site administrator', 1)");
    }

    public static function tearDownAfterClass(): void
    {
        if (!empty(static::$createdUserIds)) {
            $inUsers = implode(',', array_map('intval', static::$createdUserIds));
            static::$db->execute("DELETE FROM `user_roles` WHERE `user_id` IN ({$inUsers})");
            static::$db->execute("DELETE FROM `users` WHERE `id` IN ({$inUsers})");
            foreach (static::$createdUserIds as $uid) {
                static::$db->execute("DELETE FROM `settings` WHERE `group_name` = 'admin_appearance' AND `setting_key` = ?", ['user_' . $uid]);
            }
        }
    }

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        $_SESSION = [];
        Setting::clearCache();
    }

    private function createAdminUser(string $prefix = 'adm_app_'): User
    {
        $unique = bin2hex(random_bytes(4));
        $email = "{$prefix}{$unique}@example.com";
        $username = "{$prefix}{$unique}";

        static::$db->execute(
            "INSERT INTO `users` (`username`, `name`, `email`, `password`, `email_verified_at`, `status`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, NOW(), 'active', NOW(), NOW())",
            [$username, 'Admin ' . $unique, $email, password_hash('Secret123!', PASSWORD_DEFAULT)]
        );

        $id = (int)static::$db->getPdo()->lastInsertId();
        static::$createdUserIds[] = $id;

        $role = static::$db->selectOne("SELECT `id` FROM `roles` WHERE `slug` = 'admin' LIMIT 1");
        if ($role) {
            static::$db->execute("INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [$id, (int)$role->id]);
        }

        $user = User::find($id);
        $this->assertNotNull($user);
        return $user;
    }

    private function authenticateAs(User $user): void
    {
        $_SESSION['auth_user_id'] = (int)$user->id;
        $_SESSION['auth_user_name'] = (string)($user->name ?: $user->username);
        $_SESSION['auth_user_email'] = (string)$user->email;
        $_SESSION['auth_user_role'] = 'admin';
        $_SESSION['_token'] = bin2hex(random_bytes(16));
    }

    public function testDefaultAdminAppearanceLoadsDarkMode(): void
    {
        $admin = $this->createAdminUser();
        $this->authenticateAs($admin);

        $kernel = new Kernel(static::$app);
        $req = Request::create('GET', '/admin');
        $resp = $kernel->handle($req);

        $html = (string)$resp->getContent();
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertStringContainsString('data-admin-theme="dark"', $html);
        $this->assertStringContainsString('id="admin-theme-toggle"', $html);
        $this->assertStringContainsString('aria-label="Switch to Light Mode"', $html);
        $this->assertStringContainsString('aria-pressed="true"', $html);
        $this->assertStringContainsString('--admin-bg: #f8fafc;', $html);
        $this->assertStringContainsString(':root[data-admin-theme="dark"]', $html);
    }

    public function testLightModeRendersCorrectlyWhenPreferenceIsLight(): void
    {
        $admin = $this->createAdminUser();
        Setting::set('admin_appearance', 'user_' . $admin->id, 'light');
        $this->authenticateAs($admin);

        $kernel = new Kernel(static::$app);
        $req = Request::create('GET', '/admin');
        $resp = $kernel->handle($req);

        $html = (string)$resp->getContent();
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertStringContainsString('data-admin-theme="light"', $html);
        $this->assertStringContainsString('aria-label="Switch to Dark Mode"', $html);
        $this->assertStringContainsString('aria-pressed="false"', $html);
    }

    public function testDarkModeRendersCorrectlyWhenPreferenceIsDark(): void
    {
        $admin = $this->createAdminUser();
        Setting::set('admin_appearance', 'user_' . $admin->id, 'dark');
        $this->authenticateAs($admin);

        $kernel = new Kernel(static::$app);
        $req = Request::create('GET', '/admin');
        $resp = $kernel->handle($req);

        $html = (string)$resp->getContent();
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertStringContainsString('data-admin-theme="dark"', $html);
        $this->assertStringContainsString('aria-label="Switch to Light Mode"', $html);
        $this->assertStringContainsString('aria-pressed="true"', $html);
    }

    public function testToggleEndpointRequiresAuthentication(): void
    {
        $_SESSION = ['_token' => 'test-csrf-token'];
        $kernel = new Kernel(static::$app);
        $req = Request::create('POST', '/admin/appearance/toggle', ['_token' => 'test-csrf-token', 'theme' => 'dark']);
        $resp = $kernel->handle($req);

        // Unauthenticated access to /admin/* must redirect to login
        $this->assertSame(302, $resp->getStatusCode());
        $this->assertSame('/admin/login', $resp->getHeader('Location'));
    }

    public function testToggleEndpointEnforcesCsrf(): void
    {
        $admin = $this->createAdminUser();
        $this->authenticateAs($admin);

        $kernel = new Kernel(static::$app);
        $req = Request::create('POST', '/admin/appearance/toggle', ['_token' => 'invalid-token', 'theme' => 'dark']);
        $resp = $kernel->handle($req);

        $this->assertSame(403, $resp->getStatusCode());
        $this->assertStringContainsString('Invalid security token', (string)$resp->getContent());
    }

    public function testToggleEndpointUpdatesAuthoritativeSettingAndSession(): void
    {
        $admin = $this->createAdminUser();
        $this->authenticateAs($admin);

        $kernel = new Kernel(static::$app);

        // 1. Toggle to dark
        $reqDark = Request::create('POST', '/admin/appearance/toggle', ['_token' => $_SESSION['_token'], 'theme' => 'dark']);
        $respDark = $kernel->handle($reqDark);
        $this->assertSame(200, $respDark->getStatusCode());

        $jsonDark = json_decode((string)$respDark->getContent(), true);
        $this->assertTrue($jsonDark['success']);
        $this->assertSame('dark', $jsonDark['theme']);

        // Authoritative persistent Core Setting
        $this->assertSame('dark', Setting::get('admin_appearance', 'user_' . $admin->id));
        // Session cache
        $this->assertSame('dark', $_SESSION['admin_theme']);

        // 2. Toggle back to light
        $reqLight = Request::create('POST', '/admin/appearance/toggle', ['_token' => $_SESSION['_token'], 'theme' => 'light']);
        $respLight = $kernel->handle($reqLight);
        $this->assertSame(200, $respLight->getStatusCode());

        $jsonLight = json_decode((string)$respLight->getContent(), true);
        $this->assertTrue($jsonLight['success']);
        $this->assertSame('light', $jsonLight['theme']);

        $this->assertSame('light', Setting::get('admin_appearance', 'user_' . $admin->id));
        $this->assertSame('light', $_SESSION['admin_theme']);
    }

    public function testPreferencePersistsAfterNavigationAndReload(): void
    {
        $admin = $this->createAdminUser();
        Setting::set('admin_appearance', 'user_' . $admin->id, 'dark');
        $this->authenticateAs($admin);

        $kernel = new Kernel(static::$app);

        // Check Dashboard
        $req1 = Request::create('GET', '/admin');
        $resp1 = $kernel->handle($req1);
        $this->assertStringContainsString('data-admin-theme="dark"', (string)$resp1->getContent());

        // Check Posts
        $req2 = Request::create('GET', '/admin/posts');
        $resp2 = $kernel->handle($req2);
        $this->assertStringContainsString('data-admin-theme="dark"', (string)$resp2->getContent());

        // Check Menus
        $req3 = Request::create('GET', '/admin/menus');
        $resp3 = $kernel->handle($req3);
        $this->assertStringContainsString('data-admin-theme="dark"', (string)$resp3->getContent());
    }

    public function testLoginSynchronizationRestoresUserSavedAppearance(): void
    {
        $adminDark = $this->createAdminUser('dark_u_');
        Setting::set('admin_appearance', 'user_' . $adminDark->id, 'dark');

        $_SESSION = ['_token' => 'valid-csrf-token'];

        $kernel = new Kernel(static::$app);
        $loginReq = Request::create('POST', '/admin/login', [
            '_token' => 'valid-csrf-token',
            'login' => (string)$adminDark->email,
            'password' => 'Secret123!',
        ]);

        $resp = $kernel->handle($loginReq);
        $this->assertSame(302, $resp->getStatusCode());
        $this->assertSame('dark', $_SESSION['admin_theme'] ?? null);
    }

    public function testAdminAppearancePreferenceIsCompletelyIsolatedFromPublicTheme(): void
    {
        $admin = $this->createAdminUser();
        Setting::set('admin_appearance', 'user_' . $admin->id, 'dark');
        $this->authenticateAs($admin);

        // Public request
        $kernel = new Kernel(static::$app);
        $publicReq = Request::create('GET', '/');
        $resp = $kernel->handle($publicReq);

        $html = (string)$resp->getContent();
        // Public HTML must NOT use data-admin-theme
        $this->assertStringNotContainsString('data-admin-theme="dark"', $html);
        $this->assertStringNotContainsString('id="admin-theme-toggle"', $html);

        // Setting check: admin_appearance group must not affect general or theme settings
        $this->assertSame('dark', Setting::get('admin_appearance', 'user_' . $admin->id));
        $this->assertNull(Setting::get('general', 'admin_appearance'));
    }

    public function testDarkModeStylesCoverSidebarTablesFormsAndButtons(): void
    {
        $admin = $this->createAdminUser();
        Setting::set('admin_appearance', 'user_' . $admin->id, 'dark');
        $this->authenticateAs($admin);

        $kernel = new Kernel(static::$app);
        $req = Request::create('GET', '/admin');
        $resp = $kernel->handle($req);

        $html = (string)$resp->getContent();
        $this->assertStringContainsString('[data-admin-theme="dark"] .wp-content', $html);
        $this->assertStringContainsString('[data-admin-theme="dark"] .card', $html);
        $this->assertStringContainsString('[data-admin-theme="dark"] .form-control', $html);
        $this->assertStringContainsString('[data-admin-theme="dark"] table.wp-table th', $html);
        $this->assertStringContainsString('[data-admin-theme="dark"] table.wp-table td', $html);
        $this->assertStringContainsString('[data-admin-theme="dark"] .btn-secondary', $html);
        $this->assertStringContainsString('[data-admin-theme="dark"] .notice', $html);
        $this->assertStringContainsString('[data-admin-theme="dark"] .nav-tabs', $html);
    }

    public function testDarkModePreservesMenuManagementControlsAndStructure(): void
    {
        $admin = $this->createAdminUser();
        Setting::set('admin_appearance', 'user_' . $admin->id, 'dark');
        $this->authenticateAs($admin);

        $kernel = new Kernel(static::$app);
        $req = Request::create('GET', '/admin/menus');
        $resp = $kernel->handle($req);

        $html = (string)$resp->getContent();
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertStringContainsString('data-admin-theme="dark"', $html);

        // Core Menu Management UI preserved
        $this->assertStringContainsString('[data-admin-theme="dark"] .menu-item-row', $html);
        $this->assertStringContainsString('[data-admin-theme="dark"] .btn-menu-action.btn-move-up', $html);
        $this->assertStringContainsString('[data-admin-theme="dark"] .btn-menu-action.btn-move-down', $html);
        $this->assertStringContainsString('[data-admin-theme="dark"] .btn-menu-action.btn-menu-edit', $html);
        $this->assertStringContainsString('[data-admin-theme="dark"] .btn-menu-action.btn-menu-remove', $html);
    }

    public function testFlashFreeBootstrapScriptPresentInHead(): void
    {
        $admin = $this->createAdminUser();
        $this->authenticateAs($admin);

        $kernel = new Kernel(static::$app);
        $req = Request::create('GET', '/admin');
        $resp = $kernel->handle($req);

        $html = (string)$resp->getContent();
        $this->assertStringContainsString("localStorage.setItem('favorite_admin_theme'", $html);
    }

    public function testAdminThemesPageLoadsAndIsNotBlank(): void
    {
        $admin = $this->createAdminUser();
        $this->authenticateAs($admin);

        $kernel = new Kernel(static::$app);
        $req = Request::create('GET', '/admin/themes');
        $resp = $kernel->handle($req);

        $html = (string)$resp->getContent();
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertStringContainsString('Themes', $html);
        $this->assertStringContainsString('Upload Theme', $html);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('Active Theme', $html);
    }

    public function testAdminPluginsPageLoadsAndIsNotBlank(): void
    {
        $admin = $this->createAdminUser();
        $this->authenticateAs($admin);

        $kernel = new Kernel(static::$app);
        $req = Request::create('GET', '/admin/plugins');
        $resp = $kernel->handle($req);

        $html = (string)$resp->getContent();
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertStringContainsString('Plugins', $html);
        $this->assertStringContainsString('Upload Plugin', $html);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
    }

    public function testAdminLayoutHandlesMissingContentViewGracefully(): void
    {
        $admin = $this->createAdminUser();
        $this->authenticateAs($admin);

        $layoutFile = APP_ROOT . '/resources/views/admin/layout.php';
        $pageTitle = 'Testing Fallback';
        $activeMenu = 'dashboard';
        $contentView = APP_ROOT . '/resources/views/admin/non_existent_view_test_123.php';

        ob_start();
        include $layoutFile;
        $html = ob_get_clean();

        $this->assertStringContainsString('The requested view template could not be loaded', $html);
        $this->assertStringContainsString('notice notice-error', $html);
    }
}
