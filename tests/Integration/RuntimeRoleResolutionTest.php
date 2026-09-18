<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use PHPUnit\Framework\TestCase;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Kernel;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Models\User;
use FavoriteCMS\Models\Role;

class RuntimeRoleResolutionTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;
    protected static Kernel $kernel;
    private static array $createdUserIds = [];

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db = static::$app->make(Database::class);
        static::$kernel = new Kernel(static::$app);

        // Ensure default roles exist
        static::$db->execute("INSERT IGNORE INTO `roles` (`id`, `name`, `slug`, `description`, `is_system`) VALUES (1, 'Super Admin', 'super-admin', 'Full system access', 1)");
        static::$db->execute("INSERT IGNORE INTO `roles` (`id`, `name`, `slug`, `description`, `is_system`) VALUES (2, 'Admin', 'admin', 'Administrative access', 1)");
        static::$db->execute("INSERT IGNORE INTO `roles` (`id`, `name`, `slug`, `description`, `is_system`) VALUES (4, 'Moderator', 'moderator', 'Can moderate comments and content', 1)");

        // Ensure user 1 exists with super-admin role
        $u1 = static::$db->selectOne("SELECT id FROM `users` WHERE `id` = 1");
        if (!$u1) {
            $now = date('Y-m-d H:i:s');
            static::$db->execute("INSERT INTO `users` (`id`, `username`, `name`, `email`, `password`, `status`, `email_verified_at`, `created_at`, `updated_at`) VALUES (1, 'admin', 'Administrator', 'admin@example.com', ?, 'active', ?, ?, ?)", [
                password_hash('AdminPassword123!', PASSWORD_DEFAULT),
                $now,
                $now,
                $now,
            ]);
        }
        static::$db->execute("INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`) VALUES (1, 1)");
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
        static::$app->setInstalled(true);
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
    }

    protected function createTestUser(string $prefix, string $roleSlug = 'moderator', string $status = 'active'): User
    {
        $unique = $prefix . '_' . bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');
        $hash = password_hash('TestPassword123!', PASSWORD_DEFAULT);

        $userId = static::$db->insert('users', [
            'username'          => $unique,
            'name'              => ucfirst($unique),
            'email'             => $unique . '@example.com',
            'password'          => $hash,
            'status'            => $status,
            'email_verified_at' => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        static::$createdUserIds[] = $userId;

        $role = static::$db->selectOne("SELECT id FROM `roles` WHERE `slug` = ? LIMIT 1", [$roleSlug]);
        if ($role) {
            static::$db->execute("INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [$userId, $role->id]);
        }

        return User::find($userId);
    }

    /**
     * 1. user_id=1 + role_id=1 resolves to Super Admin
     */
    public function testUserId1WithRoleId1ResolvesToSuperAdmin(): void
    {
        $user1 = User::find(1);
        $this->assertNotNull($user1);
        $this->assertTrue($user1->isSuperAdmin(), 'user_id=1 must return true for isSuperAdmin()');
        $this->assertTrue($user1->hasRole('super-admin'), 'user_id=1 must return true for hasRole("super-admin")');
        $this->assertSame('super-admin', $user1->getPrimaryRoleSlug(), 'user_id=1 primary role slug must be super-admin');
    }

    /**
     * 2. slug 'super-admin' is recognized correctly (including formatting variations)
     */
    public function testSlugSuperAdminRecognizedCorrectly(): void
    {
        $user1 = User::find(1);
        $this->assertNotNull($user1);

        $this->assertTrue($user1->hasRole('super-admin'));
        $this->assertTrue($user1->hasRole('super_admin'));
        $this->assertTrue($user1->hasRole('Super Admin'));
        $this->assertTrue($user1->hasRole('superadmin'));
    }

    /**
     * 3. current_user_can() receives Super Admin capabilities
     */
    public function testCurrentUserCanReceivesSuperAdminCapabilities(): void
    {
        $_SESSION['auth_user_id'] = 1;

        $this->assertTrue(current_user_can('manage_options'));
        $this->assertTrue(current_user_can('manage_users'));
        $this->assertTrue(current_user_can('manage_plugins'));
        $this->assertTrue(current_user_can('manage_themes'));
        $this->assertTrue(current_user_can('manage_settings'));
        $this->assertTrue(current_user_can('unfiltered_html'));
        $this->assertTrue(current_user_can('arbitrary_future_capability'));
    }

    /**
     * 4. Super Admin-only admin routes authorize user_id=1
     */
    public function testSuperAdminOnlyAdminRoutesAuthorizeUser1(): void
    {
        $_SESSION['auth_user_id'] = 1;

        $reqSettings = Request::create('GET', '/admin/settings');
        $respSettings = static::$kernel->handle($reqSettings);
        $this->assertSame(200, $respSettings->getStatusCode());

        $reqPlugins = Request::create('GET', '/admin/plugins');
        $respPlugins = static::$kernel->handle($reqPlugins);
        $this->assertSame(200, $respPlugins->getStatusCode());

        $reqThemes = Request::create('GET', '/admin/themes');
        $respThemes = static::$kernel->handle($reqThemes);
        $this->assertSame(200, $respThemes->getStatusCode());

        $reqUsers = Request::create('GET', '/admin/users');
        $respUsers = static::$kernel->handle($reqUsers);
        $this->assertSame(200, $respUsers->getStatusCode());
    }

    /**
     * 5. Settings/Plugins/Themes/Users/Updates/Tools authorization works for Super Admin
     */
    public function testSettingsPluginsThemesUsersUpdatesAuthorizationWorks(): void
    {
        $_SESSION['auth_user_id'] = 1;

        $reqUpdates = Request::create('GET', '/admin/updates');
        $respUpdates = static::$kernel->handle($reqUpdates);
        $this->assertSame(200, $respUpdates->getStatusCode());

        $reqTools = Request::create('GET', '/admin/tools');
        $respTools = static::$kernel->handle($reqTools);
        $this->assertSame(200, $respTools->getStatusCode());

        $reqCustomize = Request::create('GET', '/admin/customize');
        $respCustomize = static::$kernel->handle($reqCustomize);
        $this->assertSame(200, $respCustomize->getStatusCode());

        $reqWidgets = Request::create('GET', '/admin/widgets');
        $respWidgets = static::$kernel->handle($reqWidgets);
        $this->assertSame(200, $respWidgets->getStatusCode());

        $reqMenus = Request::create('GET', '/admin/menus');
        $respMenus = static::$kernel->handle($reqMenus);
        $this->assertSame(200, $respMenus->getStatusCode());
    }

    /**
     * 6. Moderator does NOT receive Super Admin capabilities
     */
    public function testModeratorDoesNotReceiveSuperAdminCapabilities(): void
    {
        $moderator = $this->createTestUser('mod_test', 'moderator', 'active');
        $this->assertFalse($moderator->isSuperAdmin());
        $this->assertFalse($moderator->hasRole('super-admin'));
        $this->assertFalse($moderator->hasPermission('manage_options'));
        $this->assertFalse($moderator->canManagePlugins());
        $this->assertFalse($moderator->canManageUsers());

        $_SESSION['auth_user_id'] = $moderator->id;

        $this->assertFalse(current_user_can('manage_options'));
        $this->assertFalse(current_user_can('manage_plugins'));

        // Admin-only modules must reject Moderator with HTTP 403
        $reqSettings = Request::create('GET', '/admin/settings');
        $respSettings = static::$kernel->handle($reqSettings);
        $this->assertSame(403, $respSettings->getStatusCode());

        $reqPlugins = Request::create('GET', '/admin/plugins');
        $respPlugins = static::$kernel->handle($reqPlugins);
        $this->assertSame(403, $respPlugins->getStatusCode());

        $reqThemes = Request::create('GET', '/admin/themes');
        $respThemes = static::$kernel->handle($reqThemes);
        $this->assertSame(403, $respThemes->getStatusCode());

        $reqUsers = Request::create('GET', '/admin/users');
        $respUsers = static::$kernel->handle($reqUsers);
        $this->assertSame(403, $respUsers->getStatusCode());
    }

    /**
     * 7. Fresh login correctly loads the Super Admin role
     */
    public function testFreshLoginCorrectlyLoadsSuperAdminRole(): void
    {
        $admin = User::find(1);
        $plainPassword = 'FreshLoginPass123!';
        $admin->update(['password' => password_hash($plainPassword, PASSWORD_DEFAULT)]);

        $_SESSION = [];
        $token = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $token;

        $loginReq = Request::create('POST', '/admin/login', [
            '_token' => $token,
            'login' => $admin->email,
            'password' => $plainPassword,
        ]);

        $loginResp = static::$kernel->handle($loginReq);
        $this->assertTrue($loginResp->isRedirect());
        $this->assertSame(1, (int)($_SESSION['auth_user_id'] ?? 0));
        $this->assertSame('super-admin', $_SESSION['auth_user_role'] ?? '');
    }

    /**
     * 8. No stale session data can override the database role
     */
    public function testNoStaleSessionDataCanOverrideDatabaseRole(): void
    {
        // Deliberately poison session with a lower role
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['auth_user_role'] = 'moderator';

        $user = current_user();
        $this->assertNotNull($user);
        $this->assertTrue($user->isSuperAdmin());

        // Session role must be automatically repaired to database state
        $this->assertSame('super-admin', $_SESSION['auth_user_role']);

        // Capabilities must still be granted based on database
        $this->assertTrue(current_user_can('manage_options'));

        // Admin routes must still authorize
        $req = Request::create('GET', '/admin/settings');
        $resp = static::$kernel->handle($req);
        $this->assertSame(200, $resp->getStatusCode());
    }
}
