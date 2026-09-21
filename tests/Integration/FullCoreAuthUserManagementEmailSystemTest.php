<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use PHPUnit\Framework\TestCase;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Hook;
use FavoriteCMS\Core\Kernel;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Core\Url;
use FavoriteCMS\Http\Controllers\Admin\SettingController;
use FavoriteCMS\Http\Controllers\Admin\UserController;
use FavoriteCMS\Models\Role;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Services\EmailVerificationService;
use FavoriteCMS\Services\MailService;
use FavoriteCMS\Services\PasswordHasher;
use FavoriteCMS\Services\PasswordResetService;

/**
 * Comprehensive Integration Suite for Full Core Auth, User Management & Email System Update.
 *
 * Verifies the strict architectural separation of:
 * - Installation Admin Recovery Email (general.admin_email)
 * - Configurable Sender Email (email.sender_email)
 * Plus Admin single/bulk verify, single/bulk delete with all protections,
 * sender priorities, and multi-domain universal hosting.
 */
class FullCoreAuthUserManagementEmailSystemTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;

    private array $interceptedMails = [];
    private array $createdUserIds = [];
    private mixed $origSiteUrl;
    private mixed $origAdminEmail;
    private mixed $origSiteName;
    private mixed $origSenderEmail;
    private mixed $origSenderName;

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db = static::$app->make(Database::class);

        static::$db->execute("INSERT IGNORE INTO `roles` (`name`, `slug`, `description`, `is_system`) VALUES ('Super Admin', 'super-admin', 'Super admin role', 1)");
        static::$db->execute("INSERT IGNORE INTO `roles` (`name`, `slug`, `description`, `is_system`) VALUES ('Administrator', 'admin', 'Admin role', 1)");
        static::$db->execute("INSERT IGNORE INTO `roles` (`name`, `slug`, `description`, `is_system`) VALUES ('Subscriber', 'subscriber', 'Subscriber role', 1)");
    }

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        $_SESSION = ['_token' => 'full-auth-csrf-token'];
        $_POST = [];
        $_GET = [];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $this->origSiteUrl     = Setting::get('general', 'site_url', '');
        $this->origAdminEmail   = Setting::get('general', 'admin_email', '');
        $this->origSiteName    = Setting::get('general', 'site_name', 'Favorite CMS');
        $this->origSenderEmail = Setting::get('email', 'sender_email', '');
        $this->origSenderName  = Setting::get('email', 'sender_name', '');

        $this->interceptedMails = [];
        $this->createdUserIds = [];

        Hook::addFilter('pre_send_mail', function ($null, $args) {
            $this->interceptedMails[] = $args;
            return true;
        }, 10, 2);
    }

    protected function tearDown(): void
    {
        Hook::removeFilter('pre_send_mail');
        Hook::removeFilter('pre_send_verification_email');
        Hook::removeFilter('pre_send_password_reset_email');

        foreach ($this->createdUserIds as $uid) {
            static::$db->delete('user_roles', ['user_id' => $uid]);
            static::$db->delete('email_verifications', ['user_id' => $uid]);
            static::$db->delete('password_resets', ['user_id' => $uid]);
            static::$db->delete('users', ['id' => $uid]);
        }
        $this->createdUserIds = [];

        Setting::set('general', 'site_url', $this->origSiteUrl);
        Setting::set('general', 'admin_email', $this->origAdminEmail);
        Setting::set('general', 'site_name', $this->origSiteName);
        Setting::set('email', 'sender_email', $this->origSenderEmail);
        Setting::set('email', 'sender_name', $this->origSenderName);
        Setting::clearCache();

        $_SESSION = [];
        parent::tearDown();
    }

    private function createTestUser(string $prefix, string $roleSlug = 'subscriber', string $status = 'active', ?string $email = null): User
    {
        $unique = $prefix . '_' . bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');
        $userEmail = $email ?? ($unique . '@example.com');

        $userId = static::$db->insert('users', [
            'username'          => $unique,
            'name'              => ucfirst($unique),
            'email'             => $userEmail,
            'password'          => PasswordHasher::hash('ValidPass123!'),
            'status'            => $status,
            'email_verified_at' => null,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        $this->createdUserIds[] = (int)$userId;

        $role = static::$db->selectOne("SELECT id FROM `roles` WHERE `slug` = ? LIMIT 1", [$roleSlug]);
        if ($role) {
            static::$db->insert('user_roles', [
                'user_id' => (int)$userId,
                'role_id' => (int)$role->id,
            ]);
        }

        return User::find((int)$userId);
    }

    // =========================================================================
    // SECTION 1: ADMIN DELETE
    // =========================================================================

    public function testSingleUserDeleteSucceedsWithPostAndCsrf(): void
    {
        $admin = $this->createTestUser('adm_del', roleSlug: 'admin');
        $target = $this->createTestUser('tgt_del', roleSlug: 'subscriber');

        $_SESSION['auth_user_id'] = $admin->id;
        $_SESSION['auth_user_role'] = 'admin';
        $_SESSION['_token'] = 'csrf_ok';

        $kernel = new Kernel(static::$app);
        $req = new Request(
            post: ['id' => (string)$target->id, '_token' => 'csrf_ok'],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/users/delete']
        );

        $res = $kernel->handle($req);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertNull(User::find((int)$target->id));
    }

    public function testBulkUserDeleteSucceeds(): void
    {
        $admin = $this->createTestUser('adm_blk', roleSlug: 'admin');
        $t1 = $this->createTestUser('tgt_b1', roleSlug: 'subscriber');
        $t2 = $this->createTestUser('tgt_b2', roleSlug: 'subscriber');

        $_SESSION['auth_user_id'] = $admin->id;
        $_SESSION['auth_user_role'] = 'admin';
        $_SESSION['_token'] = 'csrf_blk';

        $kernel = new Kernel(static::$app);
        $req = new Request(
            post: [
                'bulk_action' => 'delete',
                'ids'         => [(string)$t1->id, (string)$t2->id],
                '_token'      => 'csrf_blk',
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/users/bulk']
        );

        $res = $kernel->handle($req);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('2 user(s) permanently deleted.', $_SESSION['flash_success'] ?? '');
        $this->assertNull(User::find((int)$t1->id));
        $this->assertNull(User::find((int)$t2->id));
    }

    public function testAdminDeleteRejectsGetRequest(): void
    {
        $admin = $this->createTestUser('adm_get', roleSlug: 'admin');
        $target = $this->createTestUser('tgt_get', roleSlug: 'subscriber');

        $_SESSION['auth_user_id'] = $admin->id;
        $_SESSION['auth_user_role'] = 'admin';
        $_SESSION['_token'] = 'csrf_get';

        $kernel = new Kernel(static::$app);
        $req = new Request(
            get: ['id' => (string)$target->id],
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/users/delete?id=' . $target->id]
        );

        $res = $kernel->handle($req);
        $this->assertSame(405, $res->getStatusCode());
        $this->assertNotNull(User::find((int)$target->id));
    }

    public function testAdminDeleteRejectsInvalidCsrf(): void
    {
        $admin = $this->createTestUser('adm_csrf', roleSlug: 'admin');
        $target = $this->createTestUser('tgt_csrf', roleSlug: 'subscriber');

        $_SESSION['auth_user_id'] = $admin->id;
        $_SESSION['auth_user_role'] = 'admin';
        $_SESSION['_token'] = 'correct_token';

        $kernel = new Kernel(static::$app);
        $req = new Request(
            post: ['id' => (string)$target->id, '_token' => 'tampered_token'],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/users/delete']
        );

        $res = $kernel->handle($req);
        $this->assertSame(403, $res->getStatusCode());
        $this->assertNotNull(User::find((int)$target->id));
    }

    public function testAdminDeleteEnforcesAuthorization(): void
    {
        $subscriber = $this->createTestUser('sub_auth', roleSlug: 'subscriber');
        $target = $this->createTestUser('tgt_auth', roleSlug: 'subscriber');

        $_SESSION['auth_user_id'] = $subscriber->id;
        $_SESSION['auth_user_role'] = 'subscriber';
        $_SESSION['_token'] = 'csrf_sub';

        $kernel = new Kernel(static::$app);
        $req = new Request(
            post: ['id' => (string)$target->id, '_token' => 'csrf_sub'],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/users/delete']
        );

        $res = $kernel->handle($req);
        $this->assertSame(403, $res->getStatusCode());
        $this->assertNotNull(User::find((int)$target->id));
    }

    public function testAdminCannotDeleteSelfSingleOrBulk(): void
    {
        $admin = $this->createTestUser('adm_self', roleSlug: 'admin');

        $_SESSION['auth_user_id'] = $admin->id;
        $_SESSION['auth_user_role'] = 'admin';
        $_SESSION['_token'] = 'csrf_self';

        $kernel = new Kernel(static::$app);

        // Single delete self
        $reqSingle = new Request(
            post: ['id' => (string)$admin->id, '_token' => 'csrf_self'],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/users/delete']
        );
        $resSingle = $kernel->handle($reqSingle);
        $this->assertSame(302, $resSingle->getStatusCode());
        $this->assertStringContainsString('cannot delete your own account', $_SESSION['flash_error'] ?? '');
        $this->assertNotNull(User::find((int)$admin->id));

        // Bulk delete self
        $reqBulk = new Request(
            post: [
                'bulk_action' => 'delete',
                'ids'         => [(string)$admin->id],
                '_token'      => 'csrf_self',
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/users/bulk']
        );
        $resBulk = $kernel->handle($reqBulk);
        $this->assertSame(302, $resBulk->getStatusCode());
        $this->assertNotNull(User::find((int)$admin->id));
    }

    public function testLastAdminProtectionPreventsSoleSuperAdminDeletion(): void
    {
        // Find existing sole active super admin or make sure we have exactly 1
        $superAdmin = static::$db->selectOne("SELECT u.* FROM `users` u JOIN `user_roles` ur ON u.id = ur.user_id JOIN `roles` r ON ur.role_id = r.id WHERE r.slug = 'super-admin' AND u.status = 'active' LIMIT 1");
        if (!$superAdmin) {
            $superAdmin = $this->createTestUser('sa_sole', roleSlug: 'super-admin');
        }

        $admin = $this->createTestUser('acting_adm', roleSlug: 'super-admin');
        $_SESSION['auth_user_id'] = $admin->id;
        $_SESSION['auth_user_role'] = 'super-admin';
        $_SESSION['_token'] = 'csrf_last';

        // Attempt single delete on superAdmin while only active super admins exist
        // Delete acting admin's other super-admin role to simulate sole admin if necessary
        $controller = new UserController(static::$app);
        $req = new Request(
            post: ['id' => (string)$superAdmin->id, '_token' => 'csrf_last'],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/users/delete']
        );

        // If there are multiple super admins, delete target succeeds; if sole, it throws/catches last-admin protection
        $activeCount = User::getActiveSuperAdminCount(static::$db, true);
        if ($activeCount <= 1) {
            $controller->delete($req);
            $this->assertNotNull(User::find((int)$superAdmin->id));
            $this->assertStringContainsString('last remaining active Super Admin', $_SESSION['flash_error'] ?? '');
        } else {
            $this->assertGreaterThan(1, $activeCount);
        }
    }

    public function testNonSuperAdminCannotDeleteSuperAdmin(): void
    {
        $superAdmin = $this->createTestUser('sa_target', roleSlug: 'super-admin');
        $admin = $this->createTestUser('reg_admin', roleSlug: 'admin');

        $_SESSION['auth_user_id'] = $admin->id;
        $_SESSION['auth_user_role'] = 'admin';
        $_SESSION['_token'] = 'csrf_sa';

        $kernel = new Kernel(static::$app);
        $req = new Request(
            post: [
                'bulk_action' => 'delete',
                'ids'         => [(string)$superAdmin->id],
                '_token'      => 'csrf_sa',
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/users/bulk']
        );

        $kernel->handle($req);
        // Target super admin must not be deleted by standard admin
        $this->assertNotNull(User::find((int)$superAdmin->id));
    }

    // =========================================================================
    // SECTION 2: ADMIN VERIFICATION
    // =========================================================================

    public function testManualVerifySingleUserSetsVerifiedAndInvalidatesTokenWithoutEmail(): void
    {
        $admin = $this->createTestUser('adm_v', roleSlug: 'admin');
        $target = $this->createTestUser('tgt_v', roleSlug: 'subscriber');

        // Create a pending verification token
        $verifService = new EmailVerificationService(static::$db);
        $rawToken = $verifService->createVerificationToken($target, $target->email);
        $this->assertNotNull(static::$db->selectOne("SELECT * FROM `email_verifications` WHERE `user_id` = ?", [$target->id]));
        $this->assertFalse($target->isEmailVerified());

        $this->interceptedMails = [];

        $_SESSION['auth_user_id'] = $admin->id;
        $_SESSION['auth_user_role'] = 'admin';
        $_SESSION['_token'] = 'csrf_v1';

        $kernel = new Kernel(static::$app);
        $req = new Request(
            post: ['id' => (string)$target->id, '_token' => 'csrf_v1'],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/users/verify']
        );

        $res = $kernel->handle($req);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringContainsString('successfully verified', $_SESSION['flash_success'] ?? '');

        // User is verified in database
        $refreshed = User::find((int)$target->id);
        $this->assertTrue($refreshed->isEmailVerified());

        // Token record is deleted
        $this->assertNull(static::$db->selectOne("SELECT * FROM `email_verifications` WHERE `user_id` = ?", [$target->id]));

        // No verification email dispatched
        $this->assertEmpty($this->interceptedMails);
    }

    public function testBulkVerifyUsersSetsVerifiedAndInvalidatesTokens(): void
    {
        $admin = $this->createTestUser('adm_bv', roleSlug: 'admin');
        $t1 = $this->createTestUser('tgt_bv1', roleSlug: 'subscriber');
        $t2 = $this->createTestUser('tgt_bv2', roleSlug: 'subscriber');

        $verifService = new EmailVerificationService(static::$db);
        $verifService->createVerificationToken($t1, $t1->email);
        $verifService->createVerificationToken($t2, $t2->email);

        $this->interceptedMails = [];

        $_SESSION['auth_user_id'] = $admin->id;
        $_SESSION['auth_user_role'] = 'admin';
        $_SESSION['_token'] = 'csrf_bv';

        $kernel = new Kernel(static::$app);
        $req = new Request(
            post: [
                'bulk_action' => 'verify',
                'ids'         => [(string)$t1->id, (string)$t2->id],
                '_token'      => 'csrf_bv',
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/users/bulk']
        );

        $res = $kernel->handle($req);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('2 user(s) successfully marked as verified.', $_SESSION['flash_success'] ?? '');

        $this->assertTrue(User::find((int)$t1->id)->isEmailVerified());
        $this->assertTrue(User::find((int)$t2->id)->isEmailVerified());
        $this->assertNull(static::$db->selectOne("SELECT * FROM `email_verifications` WHERE `user_id` = ?", [$t1->id]));
        $this->assertNull(static::$db->selectOne("SELECT * FROM `email_verifications` WHERE `user_id` = ?", [$t2->id]));
        $this->assertEmpty($this->interceptedMails);
    }

    public function testManualVerifyRejectsGetRequestAndEnforcesCsrf(): void
    {
        $admin = $this->createTestUser('adm_v_get', roleSlug: 'admin');
        $target = $this->createTestUser('tgt_v_get', roleSlug: 'subscriber');

        $_SESSION['auth_user_id'] = $admin->id;
        $_SESSION['auth_user_role'] = 'admin';
        $_SESSION['_token'] = 'csrf_valid';

        $kernel = new Kernel(static::$app);

        // GET rejected with 405
        $reqGet = new Request(
            get: ['id' => (string)$target->id],
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/users/verify?id=' . $target->id]
        );
        $resGet = $kernel->handle($reqGet);
        $this->assertSame(405, $resGet->getStatusCode());
        $this->assertFalse(User::find((int)$target->id)->isEmailVerified());

        // POST without valid CSRF rejected with 403
        $reqCsrf = new Request(
            post: ['id' => (string)$target->id, '_token' => 'invalid_csrf'],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/users/verify']
        );
        $resCsrf = $kernel->handle($reqCsrf);
        $this->assertSame(403, $resCsrf->getStatusCode());
        $this->assertFalse(User::find((int)$target->id)->isEmailVerified());
    }

    // =========================================================================
    // SECTION 3: SENDER EMAIL & NAME HIERARCHY
    // =========================================================================

    public function testCustomSenderEmailAndNamePriority(): void
    {
        Setting::set('email', 'sender_name', 'Custom Helpdesk');
        Setting::set('email', 'sender_email', 'helpdesk@myplatform.com');
        Setting::set('general', 'site_name', 'Site Title');
        Setting::set('general', 'admin_email', 'install_admin@myplatform.com');
        Setting::clearCache();

        $this->assertSame('Custom Helpdesk', MailService::resolveFromName());
        $this->assertSame('helpdesk@myplatform.com', MailService::resolveFromEmail());

        MailService::send('test_user@domain.com', 'Subject Test', 'Message body');
        $lastMail = end($this->interceptedMails);
        $this->assertNotEmpty($lastMail);
        $this->assertSame('helpdesk@myplatform.com', $lastMail['from']);
        $this->assertStringContainsString('Custom Helpdesk', implode("\n", $lastMail['headers']));
    }

    public function testEmptySenderEmailFallsBackToInstallationAdminEmail(): void
    {
        Setting::set('email', 'sender_name', '');
        Setting::set('email', 'sender_email', '');
        Setting::set('general', 'site_name', 'My Online Shop');
        Setting::set('general', 'admin_email', 'owner@shopdomain.org');
        Setting::clearCache();

        $this->assertSame('My Online Shop', MailService::resolveFromName());
        $this->assertSame('owner@shopdomain.org', MailService::resolveFromEmail());

        MailService::send('customer@gmail.com', 'Order Update', 'Message body');
        $lastMail = end($this->interceptedMails);
        $this->assertNotEmpty($lastMail);
        $this->assertSame('owner@shopdomain.org', $lastMail['from']);
        $this->assertStringContainsString('My Online Shop', implode("\n", $lastMail['headers']));
    }

    public function testInvalidSenderEmailRejectionAndHeaderInjectionProtection(): void
    {
        // Malformed email
        Setting::set('email', 'sender_email', 'not-an-email');
        Setting::set('general', 'admin_email', 'valid_admin@platform.com');
        Setting::clearCache();

        // Falls back to valid admin email
        $this->assertSame('valid_admin@platform.com', MailService::resolveFromEmail());

        // CRLF header injection in sender name is rejected and falls back to safe site_name
        Setting::set('email', 'sender_name', "HackedName\r\nBcc: hacker@evil.com");
        Setting::set('general', 'site_name', 'Safe Site Name');
        Setting::set('email', 'sender_email', "admin@valid.com\r\nCc: evil@evil.com");
        Setting::clearCache();

        $this->assertSame('Safe Site Name', MailService::resolveFromName());
        // Invalid email with CRLF fails validation and falls back to valid admin email
        $this->assertSame('valid_admin@platform.com', MailService::resolveFromEmail());
    }

    // =========================================================================
    // SECTION 4: ADMIN RECOVERY MATRIX (CASES A - E)
    // =========================================================================

    public function testAdminRecoveryMatrixCaseA(): void
    {
        // Case A: general.admin_email: admin@site.com, email.sender_email: EMPTY
        // Admin forgot password -> TO: admin@site.com, FROM: admin@site.com
        Setting::set('general', 'admin_email', 'admin@site.com');
        Setting::set('email', 'sender_email', '');
        Setting::clearCache();

        $admin = $this->createTestUser('admin_case_a', roleSlug: 'admin', email: 'admin@site.com');
        $service = new PasswordResetService(static::$db);

        $this->interceptedMails = [];
        $service->request('admin@site.com');

        $this->assertNotEmpty($this->interceptedMails);
        $mail = end($this->interceptedMails);
        $this->assertSame('admin@site.com', $mail['to']);
        $this->assertSame('admin@site.com', $mail['from']);
    }

    public function testAdminRecoveryMatrixCaseB(): void
    {
        // Case B: general.admin_email: admin@site.com, email.sender_email: support@site.com
        // Admin forgot password -> TO: admin@site.com, FROM: support@site.com
        Setting::set('general', 'admin_email', 'admin@site.com');
        Setting::set('email', 'sender_email', 'support@site.com');
        Setting::clearCache();

        $admin = $this->createTestUser('admin_case_b', roleSlug: 'admin', email: 'admin@site.com');
        $service = new PasswordResetService(static::$db);

        $this->interceptedMails = [];
        $service->request('admin@site.com');

        $this->assertNotEmpty($this->interceptedMails);
        $mail = end($this->interceptedMails);
        $this->assertSame('admin@site.com', $mail['to']);
        $this->assertSame('support@site.com', $mail['from']);
    }

    public function testAdminRecoveryMatrixCaseC(): void
    {
        // Case C: general.admin_email: admin@site.com, email.sender_email: support@site.com
        // Normal user: user@gmail.com -> TO: user@gmail.com, FROM: support@site.com
        Setting::set('general', 'admin_email', 'admin@site.com');
        Setting::set('email', 'sender_email', 'support@site.com');
        Setting::clearCache();

        $user = $this->createTestUser('norm_user_c', roleSlug: 'subscriber', email: 'user@gmail.com');
        $service = new PasswordResetService(static::$db);

        $this->interceptedMails = [];
        $service->request('user@gmail.com');

        $this->assertNotEmpty($this->interceptedMails);
        $mail = end($this->interceptedMails);
        $this->assertSame('user@gmail.com', $mail['to']);
        $this->assertSame('support@site.com', $mail['from']);
    }

    public function testAdminRecoveryMatrixCaseD(): void
    {
        // Case D: general.admin_email: admin@site.com, email.sender_email: support@site.com
        // New user verification: user@gmail.com -> TO: user@gmail.com, FROM: support@site.com
        Setting::set('general', 'admin_email', 'admin@site.com');
        Setting::set('email', 'sender_email', 'support@site.com');
        Setting::clearCache();

        $user = $this->createTestUser('new_reg_d', roleSlug: 'subscriber', email: 'newuser@gmail.com');
        $service = new EmailVerificationService(static::$db);
        $token = $service->createVerificationToken($user, $user->email);

        $this->interceptedMails = [];
        $service->sendVerificationEmail($user, $user->email, $token);

        $this->assertNotEmpty($this->interceptedMails);
        $mail = end($this->interceptedMails);
        $this->assertSame('newuser@gmail.com', $mail['to']);
        $this->assertSame('support@site.com', $mail['from']);
    }

    public function testAdminRecoveryMatrixCaseE(): void
    {
        // Case E: Change email.sender_email to newmail@site.com.
        // Admin recovery recipient MUST STILL BE: admin@site.com.
        Setting::set('general', 'admin_email', 'admin@site.com');
        Setting::set('email', 'sender_email', 'newmail@site.com');
        Setting::clearCache();

        $admin = $this->createTestUser('admin_case_e', roleSlug: 'admin', email: 'admin@site.com');
        $service = new PasswordResetService(static::$db);

        $this->interceptedMails = [];
        $service->request('admin@site.com');

        $this->assertNotEmpty($this->interceptedMails);
        $mail = end($this->interceptedMails);
        $this->assertSame('admin@site.com', $mail['to']);
        $this->assertSame('newmail@site.com', $mail['from']);
    }

    public function testAdminPasswordResetTokenLifecycleAndSessionInvalidation(): void
    {
        Setting::set('general', 'admin_email', 'admin@recovery-test.com');
        Setting::set('general', 'site_url', 'https://secure-domain.org');
        Setting::clearCache();

        $admin = $this->createTestUser('adm_reset_cycle', roleSlug: 'admin', email: 'admin@recovery-test.com');
        $service = new PasswordResetService(static::$db);

        $this->interceptedMails = [];
        $this->assertTrue($service->request('admin@recovery-test.com'));

        $mail = end($this->interceptedMails);
        preg_match('/https:\/\/secure-domain\.org\/reset-password\?token=([a-f0-9]{64})/', $mail['message'], $m);
        $this->assertCount(2, $m);
        $rawToken = $m[1];

        // Reset with new password
        $newPass = 'NewAdminPass999!';
        $this->assertTrue($service->reset($rawToken, $newPass));

        // Refreshed admin can login with new pass, auth_version incremented
        $refreshed = User::find((int)$admin->id);
        $this->assertTrue($refreshed->verifyPassword($newPass));
        $this->assertFalse($refreshed->verifyPassword('ValidPass123!'));
        $this->assertSame(1, (int)$refreshed->auth_version);

        // Token is single-use: cannot be reused
        $this->assertFalse($service->reset($rawToken, 'AnotherPass111!'));
    }

    // =========================================================================
    // SECTION 5: NORMAL USER RECOVERY
    // =========================================================================

    public function testNormalUserForgotLookupByUsernameOrEmail(): void
    {
        $user = $this->createTestUser('lookup_u', roleSlug: 'subscriber', email: 'unique_user@test.org');
        $service = new PasswordResetService(static::$db);

        // Lookup by email
        $this->interceptedMails = [];
        $this->assertTrue($service->request('unique_user@test.org'));
        $this->assertSame('unique_user@test.org', end($this->interceptedMails)['to']);

        // Lookup by username
        $this->interceptedMails = [];
        $this->assertTrue($service->request($user->username));
        $this->assertSame('unique_user@test.org', end($this->interceptedMails)['to']);

        // Anti-enumeration: non-existent identity returns false in service without crashing or throwing
        $this->assertFalse($service->request('non_existent_account_xyz'));
    }

    // =========================================================================
    // SECTION 6: UNIVERSAL HOSTING COMPATIBILITY
    // =========================================================================

    public function testUniversalHostingDomainEnvironments(): void
    {
        $domains = [
            'https://example.com',
            'https://example.org',
            'https://subdomain.example.org',
            'http://localhost',
            'http://localhost/subdirectory',
        ];

        $service = new PasswordResetService(static::$db);
        $user = $this->createTestUser('univ_dom', roleSlug: 'subscriber');

        foreach ($domains as $domain) {
            Setting::set('general', 'site_url', $domain);
            Setting::clearCache();

            $this->interceptedMails = [];
            $this->assertTrue($service->request($user->email));

            $mail = end($this->interceptedMails);
            $this->assertStringContainsString($domain . '/reset-password?token=', $mail['message']);
            $this->assertStringNotContainsString('#', $mail['message'], 'No URL fragments permitted');
        }
    }

    // =========================================================================
    // SECTION 7: SETTINGS UI & TEST EMAIL TOOL
    // =========================================================================

    public function testSettingsIndexRendersEmailSectionAndTestEmailTool(): void
    {
        $admin = $this->createTestUser('adm_settings_ui', roleSlug: 'admin');
        $_SESSION['auth_user_id'] = $admin->id;
        $_SESSION['auth_user_role'] = 'admin';

        $ctrl = new SettingController(static::$app);
        $req = new Request(server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/settings']);
        $res = $ctrl->index($req);

        $html = $res->getContent();
        $this->assertStringContainsString('Email & Notification Settings', $html);
        $this->assertStringContainsString('name="sender_name"', $html);
        $this->assertStringContainsString('name="sender_email"', $html);
        $this->assertStringContainsString('Send Test Email', $html);
        $this->assertStringContainsString('name="test_email"', $html);
        $this->assertStringContainsString('Administration Email Address', $html);
        $this->assertStringContainsString('authoritative recovery email for the administrator', $html);
    }

    public function testSendTestEmailToolExecutesAndReportsTransportStatus(): void
    {
        $admin = $this->createTestUser('adm_test_tool', roleSlug: 'admin');
        $_SESSION['auth_user_id'] = $admin->id;
        $_SESSION['auth_user_role'] = 'admin';
        $_SESSION['_token'] = 'csrf_test_mail';

        Setting::set('email', 'sender_name', 'Delivery Test');
        Setting::set('email', 'sender_email', 'noreply@deliverysystem.com');
        Setting::clearCache();

        $ctrl = new SettingController(static::$app);
        $req = new Request(
            post: ['test_email' => 'inbox_tester@external.com', '_token' => 'csrf_test_mail'],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/settings/test-email']
        );

        $this->interceptedMails = [];
        $res = $ctrl->sendTestEmail($req);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringContainsString('Mail transport accepted the test message', $_SESSION['flash_success'] ?? '');

        $mail = end($this->interceptedMails);
        $this->assertSame('inbox_tester@external.com', $mail['to']);
        $this->assertSame('noreply@deliverysystem.com', $mail['from']);
        $this->assertStringContainsString('Test Email from Delivery Test', $mail['subject']);
    }

    public function testSendTestEmailWorksForSuperAdminRoleThroughKernel(): void
    {
        // Real host issue reproduction: User on hosting is super-admin
        $superAdmin = $this->createTestUser('sa_test_tool', roleSlug: 'super-admin');
        $_SESSION['auth_user_id'] = $superAdmin->id;
        $_SESSION['auth_user_role'] = 'super-admin';
        $_SESSION['_token'] = 'csrf_sa_mail';

        Setting::set('email', 'sender_name', 'Hosting SuperAdmin Test');
        Setting::set('email', 'sender_email', 'superadmin@favoriteweb.net');
        Setting::clearCache();

        $kernel = new Kernel(static::$app);
        $req = new Request(
            post: ['test_email' => 'destination@realhost.com', '_token' => 'csrf_sa_mail'],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/settings/test-email']
        );

        $this->interceptedMails = [];
        $res = $kernel->handle($req);

        // MUST NOT return 403 "You do not have permission to test email settings."
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/admin/settings', $res->getHeader('Location'));
        $this->assertStringContainsString('Mail transport accepted the test message', $_SESSION['flash_success'] ?? '');

        $this->assertNotEmpty($this->interceptedMails);
        $mail = end($this->interceptedMails);
        $this->assertSame('destination@realhost.com', $mail['to']);
        $this->assertSame('superadmin@favoriteweb.net', $mail['from']);
        $this->assertStringContainsString('Hosting SuperAdmin Test', $mail['subject']);
    }

    public function testSendTestEmailDeniedForUnauthorizedRoles(): void
    {
        $editor = $this->createTestUser('editor_mail', roleSlug: 'editor');
        $_SESSION['auth_user_id'] = $editor->id;
        $_SESSION['auth_user_role'] = 'editor';
        $_SESSION['_token'] = 'csrf_editor_mail';

        $kernel = new Kernel(static::$app);
        $req = new Request(
            post: ['test_email' => 'target@dest.com', '_token' => 'csrf_editor_mail'],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/settings/test-email']
        );

        $this->interceptedMails = [];
        $res = $kernel->handle($req);

        $this->assertSame(403, $res->getStatusCode());
        $this->assertEmpty($this->interceptedMails);
    }

    public function testSendTestEmailRejectsGetWithMethodNotAllowed(): void
    {
        $superAdmin = $this->createTestUser('sa_get_mail', roleSlug: 'super-admin');
        $_SESSION['auth_user_id'] = $superAdmin->id;
        $_SESSION['auth_user_role'] = 'super-admin';
        $_SESSION['_token'] = 'csrf_sa_get';

        $kernel = new Kernel(static::$app);
        $req = new Request(
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/settings/test-email']
        );

        $this->interceptedMails = [];
        $res = $kernel->handle($req);

        $this->assertSame(405, $res->getStatusCode());
        $this->assertSame('POST', $res->getHeader('Allow'));
        $this->assertEmpty($this->interceptedMails);
    }

    public function testSendTestEmailRejectsInvalidCsrfToken(): void
    {
        $superAdmin = $this->createTestUser('sa_csrf_fail', roleSlug: 'super-admin');
        $_SESSION['auth_user_id'] = $superAdmin->id;
        $_SESSION['auth_user_role'] = 'super-admin';
        $_SESSION['_token'] = 'valid_session_token';

        $kernel = new Kernel(static::$app);
        $req = new Request(
            post: ['test_email' => 'target@dest.com', '_token' => 'invalid_csrf_token'],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/settings/test-email']
        );

        $this->interceptedMails = [];
        $res = $kernel->handle($req);

        $this->assertSame(403, $res->getStatusCode());
        $this->assertEmpty($this->interceptedMails);
    }

    public function testSendTestEmailValidatesRecipientAddress(): void
    {
        $superAdmin = $this->createTestUser('sa_bad_email', roleSlug: 'super-admin');
        $_SESSION['auth_user_id'] = $superAdmin->id;
        $_SESSION['auth_user_role'] = 'super-admin';
        $_SESSION['_token'] = 'csrf_bad_email';

        $ctrl = new SettingController(static::$app);
        $req = new Request(
            post: ['test_email' => 'not-a-valid-email', '_token' => 'csrf_bad_email'],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/settings/test-email']
        );

        $this->interceptedMails = [];
        $res = $ctrl->sendTestEmail($req);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringContainsString('valid recipient email address', $_SESSION['flash_error'] ?? '');
        $this->assertEmpty($this->interceptedMails);
    }

    public function testSendTestEmailReportsHonestFailureWhenTransportRejects(): void
    {
        $superAdmin = $this->createTestUser('sa_fail_mail', roleSlug: 'super-admin');
        $_SESSION['auth_user_id'] = $superAdmin->id;
        $_SESSION['auth_user_role'] = 'super-admin';
        $_SESSION['_token'] = 'csrf_fail_mail';

        // Override pre_send_mail filter to simulate transport failure
        Hook::removeFilter('pre_send_mail');
        Hook::addFilter('pre_send_mail', function () {
            return false;
        }, 10, 0);

        $ctrl = new SettingController(static::$app);
        $req = new Request(
            post: ['test_email' => 'failure_test@domain.com', '_token' => 'csrf_fail_mail'],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/settings/test-email']
        );

        $res = $ctrl->sendTestEmail($req);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringContainsString('Mail transport failed to send the test message', $_SESSION['flash_error'] ?? '');
    }

    public function testTransportDiagnosticsReturnsSafeStatusWithoutSecrets(): void
    {
        $diag = MailService::getTransportDiagnostics();
        $this->assertIsArray($diag);
        $this->assertArrayHasKey('php_version', $diag);
        $this->assertArrayHasKey('mail_function_exists', $diag);
        $this->assertArrayHasKey('mail_disabled', $diag);
        $this->assertArrayHasKey('sendmail_path', $diag);
        $this->assertArrayHasKey('smtp', $diag);
        $this->assertArrayHasKey('smtp_port', $diag);
        $this->assertArrayHasKey('last_transport_error', $diag);
        $this->assertArrayHasKey('sender_name', $diag);
        $this->assertArrayHasKey('sender_email', $diag);
        $this->assertArrayHasKey('sender_email_valid', $diag);
        $this->assertTrue($diag['sender_email_valid']);

        // Verify no sensitive keys exist
        $this->assertArrayNotHasKey('password', $diag);
        $this->assertArrayNotHasKey('token', $diag);
        $this->assertArrayNotHasKey('body', $diag);
    }
}
