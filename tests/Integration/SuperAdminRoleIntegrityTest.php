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
use FavoriteCMS\Models\Setting;

class SuperAdminRoleIntegrityTest extends TestCase
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

        // Ensure user 1 exists with known credentials
        $u1 = static::$db->selectOne("SELECT id FROM `users` WHERE `id` = 1");
        $now = date('Y-m-d H:i:s');
        if (!$u1) {
            static::$db->execute(
                "INSERT INTO `users` (`id`, `username`, `name`, `email`, `password`, `status`, `email_verified_at`, `created_at`, `updated_at`) VALUES (1, 'admin', 'Super Administrator', 'admin@example.com', ?, 'active', ?, ?, ?)",
                [
                    password_hash('AdminPassword123!', PASSWORD_DEFAULT),
                    $now,
                    $now,
                    $now,
                ]
            );
        } else {
            static::$db->execute(
                "UPDATE `users` SET `username` = 'admin', `email` = 'admin@example.com', `password` = ?, `status` = 'active' WHERE `id` = 1",
                [password_hash('AdminPassword123!', PASSWORD_DEFAULT)]
            );
        }

        // Configure general admin_email setting
        $adminEmailSetting = static::$db->selectOne("SELECT id FROM `settings` WHERE `group_name` = 'general' AND `setting_key` = 'admin_email'");
        if (!$adminEmailSetting) {
            static::$db->insert('settings', [
                'group_name'  => 'general',
                'setting_key' => 'admin_email',
                'value'       => 'admin@example.com',
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        } else {
            static::$db->execute(
                "UPDATE `settings` SET `value` = 'admin@example.com' WHERE `group_name` = 'general' AND `setting_key` = 'admin_email'"
            );
        }
    }

    public static function tearDownAfterClass(): void
    {
        // Reset user 1 to super-admin and active
        static::$db->execute("UPDATE `users` SET `status` = 'active' WHERE `id` = 1");
        static::$db->execute("DELETE FROM `user_roles` WHERE `user_id` = 1");
        static::$db->execute("INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`) VALUES (1, 1)");

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
        $_SESSION['_token'] = 'test-valid-csrf-token-12345';
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['auth_user_role'] = 'super-admin';

        // Clear auth rate limits cache for clean test runs
        $cacheDir = APP_ROOT . '/storage/cache/auth-limits';
        if (is_dir($cacheDir)) {
            foreach (glob($cacheDir . '/*.json') ?: [] as $f) {
                @unlink($f);
            }
        }

        // Reset user 1 to single super-admin role
        static::$db->execute("UPDATE `users` SET `status` = 'active' WHERE `id` = 1");
        static::$db->execute("DELETE FROM `user_roles` WHERE `user_id` = 1");
        static::$db->execute("INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`) VALUES (1, 1)");
    }

    private function createTestUser(string $username, string $roleSlug = 'moderator', string $status = 'active'): User
    {
        $now = date('Y-m-d H:i:s');
        $email = $username . '@example.com';
        $userId = static::$db->insert('users', [
            'username'          => $username,
            'name'              => ucfirst($username),
            'email'             => $email,
            'password'          => password_hash('UserPassword123!', PASSWORD_DEFAULT),
            'status'            => $status,
            'email_verified_at' => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
        static::$createdUserIds[] = $userId;

        $role = static::$db->selectOne("SELECT id FROM `roles` WHERE `slug` = ?", [$roleSlug]);
        if ($role) {
            static::$db->execute("INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [$userId, $role->id]);
        }

        return User::find($userId);
    }

    /**
     * Test 1: Active Super Admin count helper accurately counts active super admins.
     */
    public function testActiveSuperAdminCountAccuracy(): void
    {
        $initialCount = User::getActiveSuperAdminCount(static::$db);
        $this->assertGreaterThanOrEqual(1, $initialCount);

        // Add a second active Super Admin
        $secondAdmin = $this->createTestUser('second_sa_' . uniqid(), 'super-admin', 'active');
        $newCount = User::getActiveSuperAdminCount(static::$db);
        $this->assertEquals($initialCount + 1, $newCount);

        // Suspend the second admin, count should decrement
        static::$db->execute("UPDATE `users` SET `status` = 'suspended' WHERE `id` = ?", [$secondAdmin->id]);
        $this->assertEquals($initialCount, User::getActiveSuperAdminCount(static::$db));
    }

    /**
     * Test 2: Sole Super Admin self-demotion via /admin/users/update is blocked.
     */
    public function testSoleSuperAdminSelfDemotionIsBlocked(): void
    {
        // Ensure user 1 is sole Super Admin
        $this->ensureSoleSuperAdmin();
        $this->assertEquals(1, User::getActiveSuperAdminCount(static::$db));

        // Attempt self-demotion to Moderator (role_id = 4)
        $request = Request::create('POST', '/admin/users/update', [
            '_token'   => 'test-valid-csrf-token-12345',
            'id'       => 1,
            'name'     => 'Super Administrator',
            'email'    => 'admin@example.com',
            'role_id'  => 4, // Moderator
            'status'   => 'active',
        ]);

        $response = static::$kernel->handle($request);
        $this->assertTrue($response->isRedirect());
        $this->assertStringContainsString('edit?id=1', $response->getHeader('Location') ?? '');

        // Verify flash error was set
        $this->assertNotEmpty($_SESSION['flash_error'] ?? '');
        $this->assertStringContainsString('cannot demote yourself', $_SESSION['flash_error']);

        // Verify user 1 is STILL Super Admin in DB
        $user1 = User::find(1);
        $this->assertTrue($user1->hasRole('super-admin'));
        $this->assertEquals(1, User::getActiveSuperAdminCount(static::$db));
    }

    /**
     * Test 3: Demoting the sole Super Admin via /admin/users/role is blocked.
     */
    public function testDemotingSoleSuperAdminViaChangeRoleIsBlocked(): void
    {
        $this->ensureSoleSuperAdmin();

        // Create a secondary admin to attempt the action
        $actingAdmin = $this->createTestUser('acting_admin_' . uniqid(), 'admin', 'active');
        $_SESSION['auth_user_id'] = $actingAdmin->id;
        $_SESSION['auth_user_role'] = 'admin';

        $request = Request::create('POST', '/admin/users/role', [
            '_token'  => 'test-valid-csrf-token-12345',
            'id'      => 1,
            'role_id' => 4, // Moderator
        ]);

        $response = static::$kernel->handle($request);
        $this->assertTrue($response->isRedirect());

        // Verify flash error
        $this->assertNotEmpty($_SESSION['flash_error'] ?? '');
        $this->assertStringContainsString('Cannot demote the last remaining active Super Admin', $_SESSION['flash_error']);

        // Invariant check: user 1 is still Super Admin
        $user1 = User::find(1);
        $this->assertTrue($user1->hasRole('super-admin'));
        $this->assertEquals(1, User::getActiveSuperAdminCount(static::$db));
    }

    /**
     * Test 4: Suspending or banning the sole Super Admin is blocked across all entry points.
     */
    public function testSuspendingOrBanningSoleSuperAdminIsBlocked(): void
    {
        $this->ensureSoleSuperAdmin();

        $actingAdmin = $this->createTestUser('acting_admin2_' . uniqid(), 'admin', 'active');
        $_SESSION['auth_user_id'] = $actingAdmin->id;
        $_SESSION['auth_user_role'] = 'admin';

        // 1. Via changeStatus endpoint (POST required for mutations)
        $request = Request::create('POST', '/admin/users/status', [
            '_token' => 'test-valid-csrf-token-12345',
            'id'     => 1,
            'status' => 'suspended',
        ]);
        $response = static::$kernel->handle($request);
        $this->assertTrue($response->isRedirect());
        $this->assertStringContainsString('Cannot suspend or ban the last remaining active Super Admin', $_SESSION['flash_error'] ?? '');

        $user1 = User::find(1);
        $this->assertEquals('active', $user1->status);

        // 2. Via user update endpoint
        $requestUpdate = Request::create('POST', '/admin/users/update', [
            '_token'   => 'test-valid-csrf-token-12345',
            'id'       => 1,
            'name'     => 'Super Administrator',
            'email'    => 'admin@example.com',
            'role_id'  => 1,
            'status'   => 'banned',
        ]);
        $responseUpdate = static::$kernel->handle($requestUpdate);
        $this->assertTrue($responseUpdate->isRedirect());
        $this->assertStringContainsString('Cannot suspend or ban the last remaining active Super Admin', $_SESSION['flash_error'] ?? '');

        $user1 = User::find(1);
        $this->assertEquals('active', $user1->status);
    }

    /**
     * Test 5: Deleting the sole Super Admin is blocked.
     */
    public function testDeletingSoleSuperAdminIsBlocked(): void
    {
        $this->ensureSoleSuperAdmin();

        $actingAdmin = $this->createTestUser('acting_admin3_' . uniqid(), 'admin', 'active');
        $_SESSION['auth_user_id'] = $actingAdmin->id;
        $_SESSION['auth_user_role'] = 'admin';

        $request = Request::create('POST', '/admin/users/delete', [
            '_token' => 'test-valid-csrf-token-12345',
            'id'     => 1,
        ]);
        $response = static::$kernel->handle($request);
        $this->assertTrue($response->isRedirect());
        $this->assertStringContainsString('Cannot delete the last remaining active Super Admin', $_SESSION['flash_error'] ?? '');

        // User 1 must still exist
        $user1 = User::find(1);
        $this->assertNotNull($user1);
        $this->assertTrue($user1->hasRole('super-admin'));
        $this->assertEquals(1, User::getActiveSuperAdminCount(static::$db));
    }

    /**
     * Test 6: Self-deleting sole Super Admin via profile is blocked.
     */
    public function testSelfDeletingSoleSuperAdminIsBlocked(): void
    {
        $this->ensureSoleSuperAdmin();

        $user1 = User::find(1);
        $this->assertFalse($user1->canSelfDelete(), 'canSelfDelete() must return false for sole Super Admin');

        $request = Request::create('POST', '/admin/users/profile/delete-account', [
            '_token'         => 'test-valid-csrf-token-12345',
            'confirm_delete' => '1',
            'password'       => 'AdminPassword123!',
        ]);
        $response = static::$kernel->handle($request);
        $this->assertTrue($response->isRedirect());
        $this->assertStringContainsString('not eligible for self-deletion', $_SESSION['flash_error'] ?? '');

        $this->assertNotNull(User::find(1));
    }

    /**
     * Test 7: Handover / demoting / deleting one Super Admin works when 2 Super Admins exist, leaving 1.
     */
    public function testTwoSuperAdminsAllowsDemotingOrDeletingOneLeavingOne(): void
    {
        $secondAdmin = $this->createTestUser('second_sa_handover_' . uniqid(), 'super-admin', 'active');
        $this->assertEquals(2, User::getActiveSuperAdminCount(static::$db));

        $targetRole = Role::find(4);
        $targetRoleName = $targetRole ? $targetRole->name : 'Author';

        // Demote second admin to target role
        $request = Request::create('POST', '/admin/users/role', [
            '_token'  => 'test-valid-csrf-token-12345',
            'id'      => $secondAdmin->id,
            'role_id' => 4,
        ]);
        $response = static::$kernel->handle($request);
        $this->assertTrue($response->isRedirect());
        $this->assertStringContainsString('changed to ' . $targetRoleName, $_SESSION['flash_success'] ?? '');

        // Invariant preserved: 1 Super Admin remains
        $this->assertEquals(1, User::getActiveSuperAdminCount(static::$db));

        // Now attempting to demote the remaining one is blocked
        $secondDemote = Request::create('POST', '/admin/users/role', [
            '_token'  => 'test-valid-csrf-token-12345',
            'id'      => 1,
            'role_id' => 4,
        ]);
        // Set acting user to second user who is now admin to test admin capability
        static::$db->execute("UPDATE `user_roles` SET `role_id` = 2 WHERE `user_id` = ?", [$secondAdmin->id]);
        $_SESSION['auth_user_id'] = $secondAdmin->id;
        $_SESSION['auth_user_role'] = 'admin';

        $response2 = static::$kernel->handle($secondDemote);
        $this->assertTrue($response2->isRedirect());
        $this->assertStringContainsString('Cannot demote the last remaining active Super Admin', $_SESSION['flash_error'] ?? '');
        $this->assertEquals(1, User::getActiveSuperAdminCount(static::$db));
    }

    /**
     * Test 8: Bulk action protects sole Super Admin from suspension or ban.
     */
    public function testBulkActionProtectsSoleSuperAdmin(): void
    {
        $this->ensureSoleSuperAdmin();

        $normalUser = $this->createTestUser('normal_user_' . uniqid(), 'moderator', 'active');
        $actingAdmin = $this->createTestUser('acting_admin_bulk_' . uniqid(), 'super-admin', 'active');
        $_SESSION['auth_user_id'] = $actingAdmin->id;
        $_SESSION['auth_user_role'] = 'super-admin';

        // At this point there are 2 super admins (user 1 and actingAdmin).
        // Try bulk suspending user 1 and normalUser.
        // If user 1 is suspended, actingAdmin remains as sole super admin. That works.
        $this->assertEquals(2, User::getActiveSuperAdminCount(static::$db));

        $request = Request::create('POST', '/admin/users/bulk', [
            '_token'      => 'test-valid-csrf-token-12345',
            'bulk_action' => 'suspend',
            'ids'         => [1, $normalUser->id],
        ]);
        $response = static::$kernel->handle($request);
        $this->assertTrue($response->isRedirect());

        // Now user 1 was suspended, leaving actingAdmin as the SOLE active super admin.
        $this->assertEquals(1, User::getActiveSuperAdminCount(static::$db));

        // Restore user 1 and demote actingAdmin to admin
        static::$db->execute("UPDATE `users` SET `status` = 'active' WHERE `id` = 1");
        static::$db->execute("UPDATE `user_roles` SET `role_id` = 2 WHERE `user_id` = ?", [$actingAdmin->id]);
        $this->assertEquals(1, User::getActiveSuperAdminCount(static::$db));

        // Now user 1 is the sole Super Admin. The admin user attempts to bulk suspend user 1:
        $_SESSION['auth_user_id'] = $actingAdmin->id;
        $_SESSION['auth_user_role'] = 'admin';

        $request2 = Request::create('POST', '/admin/users/bulk', [
            '_token'      => 'test-valid-csrf-token-12345',
            'bulk_action' => 'suspend',
            'ids'         => [1, $normalUser->id],
        ]);
        $response2 = static::$kernel->handle($request2);
        $this->assertTrue($response2->isRedirect());

        // User 1 must STILL be active!
        $user1 = User::find(1);
        $this->assertEquals('active', $user1->status);
        $this->assertEquals(1, User::getActiveSuperAdminCount(static::$db));
    }

    /**
     * Test 9: Hardened Emergency Super Admin Recovery Flow.
     * Verifies:
     * - Ineligible when Super Admin count > 0.
     * - When count is 0, only authenticated eligible user 1 can restore.
     * - Invalid password fails.
     * - Valid password succeeds, updates database role, audit logs, and sets session role.
     * - Second attempt immediately rejected because count is now 1.
     */
    public function testHardenedEmergencySuperAdminRecoveryFlow(): void
    {
        $user1 = User::find(1);
        $this->assertFalse($user1->isEligibleForSuperAdminRecovery(), 'Must be ineligible while active Super Admin count > 0');

        // Simulate zero Super Admins scenario by temporarily downgrading user 1's role in DB
        static::$db->execute("UPDATE `user_roles` SET `role_id` = 4 WHERE `user_id` = 1"); // Downgrade to Moderator
        $this->assertEquals(0, User::getActiveSuperAdminCount(static::$db));

        // Now user 1 should be eligible
        $user1Fresh = User::find(1);
        $this->assertTrue($user1Fresh->isEligibleForSuperAdminRecovery(), 'User 1 must be eligible for recovery when count is 0');

        // Test non-user 1 is NOT eligible
        $user2 = $this->createTestUser('user2_recovery_' . uniqid(), 'moderator', 'active');
        $this->assertFalse($user2->isEligibleForSuperAdminRecovery(), 'User 2 must NEVER be eligible for recovery');

        // Set session as user 2 and attempt recovery
        $_SESSION['auth_user_id'] = $user2->id;
        $_SESSION['auth_user_role'] = 'moderator';

        $reqUser2 = Request::create('POST', '/admin/users/profile/recover-super-admin', [
            '_token'   => 'test-valid-csrf-token-12345',
            'password' => 'UserPassword123!',
        ]);
        $respUser2 = static::$kernel->handle($reqUser2);
        $this->assertTrue($respUser2->isRedirect());
        $this->assertStringContainsString('not eligible for Super Admin emergency recovery', $_SESSION['flash_error'] ?? '');

        // Set session back to user 1
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['auth_user_role'] = 'moderator';

        // 1. Attempt recovery with WRONG password
        $reqWrongPass = Request::create('POST', '/admin/users/profile/recover-super-admin', [
            '_token'   => 'test-valid-csrf-token-12345',
            'password' => 'WrongPassword123!',
        ]);
        $respWrongPass = static::$kernel->handle($reqWrongPass);
        $this->assertTrue($respWrongPass->isRedirect());
        $this->assertStringContainsString('Authentication failed', $_SESSION['flash_error'] ?? '');
        $this->assertEquals(0, User::getActiveSuperAdminCount(static::$db));

        // 2. Attempt recovery with CORRECT password
        $reqCorrect = Request::create('POST', '/admin/users/profile/recover-super-admin', [
            '_token'   => 'test-valid-csrf-token-12345',
            'password' => 'AdminPassword123!',
        ]);
        $respCorrect = static::$kernel->handle($reqCorrect);
        $this->assertTrue($respCorrect->isRedirect());
        $this->assertStringContainsString('Super Admin role successfully restored', $_SESSION['flash_success'] ?? '');

        // Verify database: user 1 is once again Super Admin!
        $user1Restored = User::find(1);
        $this->assertTrue($user1Restored->hasRole('super-admin'));
        $this->assertEquals(1, User::getActiveSuperAdminCount(static::$db));
        $this->assertEquals('super-admin', $_SESSION['auth_user_role']);

        // Verify audit log
        $logPath = APP_ROOT . '/storage/logs/security.log';
        $this->assertFileExists($logPath);
        $logContent = file_get_contents($logPath);
        $this->assertStringContainsString('Super Admin role recovered for user ID 1', $logContent);

        // 3. Second recovery attempt immediately fails because count is now 1
        $reqSecond = Request::create('POST', '/admin/users/profile/recover-super-admin', [
            '_token'   => 'test-valid-csrf-token-12345',
            'password' => 'AdminPassword123!',
        ]);
        $respSecond = static::$kernel->handle($reqSecond);
        $this->assertTrue($respSecond->isRedirect());
        $this->assertStringContainsString('not eligible for Super Admin emergency recovery', $_SESSION['flash_error'] ?? '');
    }

    private function ensureSoleSuperAdmin(): void
    {
        // Demote all users except user 1 away from super-admin
        static::$db->execute(
            "UPDATE `user_roles` SET `role_id` = 4 WHERE `user_id` != 1 AND `role_id` IN (SELECT id FROM `roles` WHERE `slug` = 'super-admin')"
        );
        static::$db->execute("UPDATE `users` SET `status` = 'active' WHERE `id` = 1");
        static::$db->execute("DELETE FROM `user_roles` WHERE `user_id` = 1");
        static::$db->execute("INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`) VALUES (1, 1)");
    }
}
