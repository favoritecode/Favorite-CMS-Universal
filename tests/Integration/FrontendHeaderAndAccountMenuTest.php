<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use PHPUnit\Framework\TestCase;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Kernel;
use FavoriteCMS\Models\User;
use FavoriteCMS\Models\Role;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Core\AccountMenu;

class FrontendHeaderAndAccountMenuTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;
    private static array $createdUserIds = [];

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db = static::$app->make(Database::class);

        static::$db->execute("INSERT IGNORE INTO `roles` (`name`, `slug`, `description`, `is_system`) VALUES ('Subscriber', 'subscriber', 'Regular registered user', 1)");
        static::$db->execute("INSERT IGNORE INTO `roles` (`name`, `slug`, `description`, `is_system`) VALUES ('Administrator', 'admin', 'Site administrator', 1)");
    }

    public static function tearDownAfterClass(): void
    {
        if (!empty(static::$createdUserIds)) {
            $inClause = implode(',', array_map('intval', static::$createdUserIds));
            static::$db->execute("DELETE FROM `user_roles` WHERE `user_id` IN ({$inClause})");
            static::$db->execute("DELETE FROM `users` WHERE `id` IN ({$inClause})");
        }
    }

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_FILES = [];
        Setting::set('general', 'allow_registration', 1, 'bool');
        Setting::set('general', 'require_email_verification', 1, 'bool');
    }

    private function createTestUser(string $prefix, string $roleSlug = 'subscriber'): User
    {
        $unique = $prefix . '_' . bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');
        $hash = password_hash('SecretPassword123!', PASSWORD_DEFAULT);

        $userId = static::$db->insert('users', [
            'username'          => $unique,
            'name'              => ucfirst($unique),
            'email'             => $unique . '@example.com',
            'password'          => $hash,
            'status'            => 'active',
            'email_verified_at' => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        static::$createdUserIds[] = (int)$userId;

        $role = static::$db->selectOne("SELECT id FROM `roles` WHERE `slug` = ? LIMIT 1", [$roleSlug]);
        if ($role) {
            static::$db->execute("INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [$userId, $role->id]);
        }

        return User::find((int)$userId);
    }

    public function testGuestHeaderOrderAndAbsenceOfAccount(): void
    {
        $kernel = new Kernel(static::$app);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/']);
        $resp = $kernel->handle($req);

        $this->assertSame(200, $resp->getStatusCode());
        $html = $resp->getContent();

        // Check DOM sequence: Logo -> Menu -> Search
        $posLogo = strpos($html, 'class="site-branding"');
        $posMenu = strpos($html, 'class="main-nav"');
        $posSearch = strpos($html, 'class="header-search"');
        $posAccount = strpos($html, 'class="header-account-wrap"');

        $this->assertNotFalse($posLogo, 'Logo branding must exist in header');
        $this->assertNotFalse($posMenu, 'Main navigation must exist in header');
        $this->assertNotFalse($posSearch, 'Search box must exist in header');
        $this->assertFalse($posAccount, 'Account menu must be ABSENT for guests');

        $this->assertLessThan($posMenu, $posLogo, 'Logo must appear before menu');
        $this->assertLessThan($posSearch, $posMenu, 'Menu must appear before search');
    }

    public function testAuthenticatedNormalUserHeaderOrderAndAccountMenuItems(): void
    {
        $user = $this->createTestUser('hdr_subscriber', 'subscriber');
        $_SESSION['auth_user_id'] = $user->id;

        $kernel = new Kernel(static::$app);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/']);
        $resp = $kernel->handle($req);

        $this->assertSame(200, $resp->getStatusCode());
        $html = $resp->getContent();

        // Check exact order: Logo -> Menu -> Search -> Account
        $posLogo = strpos($html, 'class="site-branding"');
        $posMenu = strpos($html, 'class="main-nav"');
        $posSearch = strpos($html, 'class="header-search"');
        $posAccount = strpos($html, 'class="header-account-wrap"');

        $this->assertNotFalse($posLogo, 'Logo branding must exist');
        $this->assertNotFalse($posMenu, 'Main navigation must exist');
        $this->assertNotFalse($posSearch, 'Search box must exist');
        $this->assertNotFalse($posAccount, 'Account wrap must exist for authenticated user');

        $this->assertLessThan($posMenu, $posLogo, 'Logo must appear before Menu');
        $this->assertLessThan($posSearch, $posMenu, 'Menu must appear before Search');
        $this->assertLessThan($posAccount, $posSearch, 'Search must appear before Account (Account is final)');

        // Check Account Menu items for regular subscriber:
        // Must contain: Profile, Log Out
        // Must NOT contain: Account Settings, Administration
        $this->assertStringContainsString('Profile', $html);
        $this->assertStringContainsString('/admin/users/profile', $html);
        $this->assertStringContainsString('Log Out', $html);
        $this->assertStringContainsString('/admin/logout', $html);

        $this->assertStringNotContainsString('Account Settings', $html, 'Duplicate Account Settings must be removed');
        $this->assertStringNotContainsString('Administration', $html, 'Regular user must not see Administration');
    }

    public function testAuthenticatedAdminHeaderOrderAndAccountMenuItems(): void
    {
        $admin = $this->createTestUser('hdr_admin', 'admin');
        $_SESSION['auth_user_id'] = $admin->id;

        $kernel = new Kernel(static::$app);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/']);
        $resp = $kernel->handle($req);

        $this->assertSame(200, $resp->getStatusCode());
        $html = $resp->getContent();

        // Check exact order: Logo -> Menu -> Search -> Account
        $posLogo = strpos($html, 'class="site-branding"');
        $posMenu = strpos($html, 'class="main-nav"');
        $posSearch = strpos($html, 'class="header-search"');
        $posAccount = strpos($html, 'class="header-account-wrap"');

        $this->assertLessThan($posMenu, $posLogo, 'Logo must appear before Menu');
        $this->assertLessThan($posSearch, $posMenu, 'Menu must appear before Search');
        $this->assertLessThan($posAccount, $posSearch, 'Search must appear before Account (Account is final/rightmost)');

        // Check Account Menu items for authorized admin:
        // Must contain: Profile, Administration, Log Out
        // Must NOT contain: Account Settings
        $this->assertStringContainsString('Profile', $html);
        $this->assertStringContainsString('/admin/users/profile', $html);
        $this->assertStringContainsString('Administration', $html, 'Admin must see Administration link');
        $this->assertStringContainsString('/admin', $html);
        $this->assertStringContainsString('Log Out', $html);
        $this->assertStringContainsString('/admin/logout', $html);

        $this->assertStringNotContainsString('Account Settings', $html, 'Duplicate Account Settings must be removed');
    }
}