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
use FavoriteCMS\Models\Role;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Services\EmailVerificationService;
use FavoriteCMS\Services\MailService;
use FavoriteCMS\Services\PasswordHasher;
use FavoriteCMS\Services\PasswordResetService;

class UniversalAuthMailAndUserDeleteTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;

    private array $interceptedMails = [];
    private array $createdUserIds = [];
    private mixed $originalSiteUrl;
    private mixed $originalAdminEmail;
    private mixed $originalSiteName;
    private mixed $originalReqVerif;

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
        $_SESSION = ['_token' => 'universal-test-csrf'];
        $_POST = [];
        $_GET = [];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $this->originalSiteUrl = Setting::get('general', 'site_url', '');
        $this->originalAdminEmail = Setting::get('general', 'admin_email', '');
        $this->originalSiteName = Setting::get('general', 'site_name', 'Favorite CMS');
        $this->originalReqVerif = Setting::get('general', 'require_email_verification', 1);

        $this->interceptedMails = [];
        $this->createdUserIds = [];
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

        Setting::set('general', 'site_url', $this->originalSiteUrl);
        Setting::set('general', 'admin_email', $this->originalAdminEmail);
        Setting::set('general', 'site_name', $this->originalSiteName);
        Setting::set('general', 'require_email_verification', $this->originalReqVerif, 'bool');
        Setting::clearCache();

        $_SESSION = [];
        parent::tearDown();
    }

    private function createTestUser(string $prefix, string $roleSlug = 'subscriber', string $status = 'active'): User
    {
        $unique = $prefix . '_' . bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');
        $email = $unique . '@example.com';

        $userId = static::$db->insert('users', [
            'username'          => $unique,
            'name'              => ucfirst($unique),
            'email'             => $email,
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
    // PART 1 & PART 10: Universal URL Resolution & Multiple Domains
    // =========================================================================

    public function testUniversalUrlResolutionAcrossMultipleDomains(): void
    {
        $domains = [
            'https://example.com',
            'https://example.org',
            'https://shop.example.com',
            'http://localhost',
            'https://sub.example.com',
        ];

        $token = bin2hex(random_bytes(32));

        foreach ($domains as $domain) {
            Setting::set('general', 'site_url', $domain);
            Setting::clearCache();

            $verifUrl = app_url('/verify-email?token=' . rawurlencode($token));
            $resetUrl = app_url('/reset-password?token=' . rawurlencode($token));

            $expectedVerif = rtrim($domain, '/') . '/verify-email?token=' . rawurlencode($token);
            $expectedReset = rtrim($domain, '/') . '/reset-password?token=' . rawurlencode($token);

            $this->assertSame($expectedVerif, $verifUrl, "Verification URL mismatch for {$domain}");
            $this->assertSame($expectedReset, $resetUrl, "Reset URL mismatch for {$domain}");

            // Ensure no hardcoded favoriteweb.net
            $this->assertStringNotContainsString('favoriteweb.net', $verifUrl);
            $this->assertStringNotContainsString('favoriteweb.net', $resetUrl);
        }
    }

    // =========================================================================
    // PART 2, PART 3, PART 4, PART 11: Mail Configuration & Transport Handoff
    // =========================================================================

    public function testMailServiceUsesConfiguredFromNameAndEmail(): void
    {
        Setting::set('general', 'site_name', 'My Universal Shop');
        Setting::set('general', 'admin_email', 'admin@my-universal-shop.org');
        Setting::clearCache();

        $intercepted = null;
        Hook::addFilter('pre_send_mail', function ($null, $args) use (&$intercepted) {
            $intercepted = $args;
            return true;
        }, 10, 2);

        $sent = MailService::send('customer@example.org', 'Order Confirmation', 'Your order is ready.');
        $this->assertTrue($sent);
        $this->assertNotNull($intercepted);

        $this->assertSame('customer@example.org', $intercepted['to']);
        $this->assertSame('Order Confirmation', $intercepted['subject']);
        $this->assertSame('admin@my-universal-shop.org', $intercepted['from']);
        $this->assertSame('admin@my-universal-shop.org', $intercepted['reply_to']);

        $headerStr = implode("\r\n", $intercepted['headers']);
        $this->assertStringContainsString('From: "My Universal Shop" <admin@my-universal-shop.org>', $headerStr);
        $this->assertStringContainsString('Reply-To: admin@my-universal-shop.org', $headerStr);
        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $headerStr);
    }

    public function testMailServiceFallbackSenderDerivedFromCanonicalSiteUrl(): void
    {
        // Admin email empty: fallback must derive from site_url host
        Setting::set('general', 'admin_email', '');
        Setting::set('general', 'site_url', 'https://platform.example.com');
        Setting::clearCache();

        $intercepted = null;
        Hook::addFilter('pre_send_mail', function ($null, $args) use (&$intercepted) {
            $intercepted = $args;
            return true;
        }, 10, 2);

        $sent = MailService::send('test@example.com', 'Test Subject', 'Test Message');
        $this->assertTrue($sent);
        $this->assertNotNull($intercepted);
        $this->assertSame('noreply@platform.example.com', $intercepted['from']);
    }

    public function testMailServiceRejectsInvalidRecipientAddress(): void
    {
        $this->assertFalse(MailService::send('not-an-email', 'Subject', 'Message'));
        $this->assertFalse(MailService::send("user@example.com\r\nBcc: evil@attacker.com", 'Subject', 'Message'));
        $this->assertFalse(MailService::send('', 'Subject', 'Message'));
    }

    public function testMailServiceEncodesUtf8SubjectAndFromName(): void
    {
        Setting::set('general', 'site_name', 'Café & Bäckerei');
        Setting::set('general', 'admin_email', 'kontakt@baeckerei.de');
        Setting::clearCache();

        $intercepted = null;
        Hook::addFilter('pre_send_mail', function ($null, $args) use (&$intercepted) {
            $intercepted = $args;
            return true;
        }, 10, 2);

        $subject = 'Willkommen im Café & Bäckerei!';
        $sent = MailService::send('kunde@baeckerei.de', $subject, 'Willkommen!');
        $this->assertTrue($sent);
        $this->assertNotNull($intercepted);

        $headerStr = implode("\r\n", $intercepted['headers']);
        // From name must be UTF-8 encoded
        $this->assertStringContainsString('=?UTF-8?B?', $headerStr);
        $this->assertStringContainsString('<kontakt@baeckerei.de>', $headerStr);
    }

    // =========================================================================
    // PART 2 & PART 7: Verification Email Dispatch & Token Lifecycle
    // =========================================================================

    public function testVerificationEmailGeneratesHashedTokenAndContainsCanonicalUrl(): void
    {
        Setting::set('general', 'site_url', 'https://universal-cms.org');
        Setting::clearCache();

        $user = $this->createTestUser('verif_u');
        $service = new EmailVerificationService(static::$db);

        $rawToken = $service->createVerificationToken($user, $user->email);
        $this->assertSame(64, strlen($rawToken));

        // Token hash stored in DB
        $record = static::$db->selectOne("SELECT * FROM `email_verifications` WHERE `user_id` = ?", [$user->id]);
        $this->assertNotNull($record);
        $this->assertSame(hash('sha256', $rawToken), $record->token_hash);

        // Intercept mail dispatch
        $intercepted = null;
        Hook::addFilter('pre_send_mail', function ($null, $args) use (&$intercepted) {
            $intercepted = $args;
            return true;
        }, 10, 2);

        $sent = $service->sendVerificationEmail($user, $user->email, $rawToken, false);
        $this->assertTrue($sent);
        $this->assertNotNull($intercepted);
        $this->assertSame($user->email, $intercepted['to']);
        $this->assertStringContainsString('https://universal-cms.org/verify-email?token=' . $rawToken, $intercepted['message']);

        // Verification succeeds with raw token
        $result = $service->verifyToken($rawToken);
        $this->assertTrue($result['success']);
        $this->assertTrue(User::find((int)$user->id)->isEmailVerified());

        // Token is single-use: second attempt fails
        $result2 = $service->verifyToken($rawToken);
        $this->assertFalse($result2['success']);
    }

    // =========================================================================
    // PART 3 & PART 7: Password Reset Email Dispatch & Token Lifecycle
    // =========================================================================

    public function testPasswordResetGeneratesHashedTokenAndCompletesReset(): void
    {
        Setting::set('general', 'site_url', 'https://auth-test.net');
        Setting::clearCache();

        $user = $this->createTestUser('reset_u');
        $service = new PasswordResetService(static::$db);

        $intercepted = null;
        Hook::addFilter('pre_send_mail', function ($null, $args) use (&$intercepted) {
            $intercepted = $args;
            return true;
        }, 10, 2);

        $requested = $service->request($user->email);
        $this->assertTrue($requested);
        $this->assertNotNull($intercepted);
        $this->assertSame($user->email, $intercepted['to']);

        // Extract raw token from message
        preg_match('/https:\/\/auth-test\.net\/reset-password\?token=([a-f0-9]{64})/', $intercepted['message'], $matches);
        $this->assertCount(2, $matches);
        $rawToken = $matches[1];

        // Token hash stored in DB
        $record = static::$db->selectOne("SELECT * FROM `password_resets` WHERE `user_id` = ?", [$user->id]);
        $this->assertNotNull($record);
        $this->assertSame(hash('sha256', $rawToken), $record->token_hash);

        // Reset with new password
        $newPassword = 'BrandNewPassword456!';
        $this->assertTrue($service->reset($rawToken, $newPassword));

        // Refreshed user has updated password and incremented auth_version
        $refreshed = User::find((int)$user->id);
        $this->assertTrue($refreshed->verifyPassword($newPassword));
        $this->assertFalse($refreshed->verifyPassword('ValidPass123!'));
        $this->assertSame(1, (int)$refreshed->auth_version);

        // Single-use: old token cannot be reused
        $this->assertFalse($service->reset($rawToken, 'AnotherPass789!'));
    }

    // =========================================================================
    // PART 8: Admin User Delete Integration
    // =========================================================================

    public function testAdminUserDeleteFormRendersPostButton(): void
    {
        $admin = $this->createTestUser('admin_ui', roleSlug: 'admin');
        $target = $this->createTestUser('target_ui', roleSlug: 'subscriber');

        $_SESSION['auth_user_id'] = $admin->id;
        $_SESSION['auth_user_role'] = 'admin';

        $db = static::$db;
        $totalItems  = (int)($db->selectOne("SELECT COUNT(*) AS cnt FROM `users`")->cnt ?? 0);
        $totalPages  = max(1, (int)ceil($totalItems / 12));

        $controller = new \FavoriteCMS\Http\Controllers\Admin\UserController(static::$app);
        $request = new Request(
            get: ['p' => (string)$totalPages],
            server: [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI'    => '/admin/users?p=' . $totalPages,
            ]
        );

        $response = $controller->index($request);
        $html = $response->getContent();

        // Must contain core-action-link button with formaction pointing to delete with user id
        $this->assertStringContainsString('form="core-action-form"', $html);
        $this->assertStringContainsString('formmethod="POST"', $html);
        $this->assertStringContainsString('/admin/users/delete?id=' . $target->id, $html);
        $this->assertStringContainsString('Permanently delete user', $html);
    }

    public function testAdminUserDeleteRejectsGetRequestWith405(): void
    {
        $admin = $this->createTestUser('admin_get', roleSlug: 'admin');
        $target = $this->createTestUser('target_get', roleSlug: 'subscriber');

        $_SESSION['auth_user_id'] = $admin->id;
        $_SESSION['auth_user_role'] = 'admin';
        $_SESSION['_token'] = 'valid-token';

        $kernel = new Kernel(static::$app);
        $request = new Request(
            get: ['id' => (string)$target->id],
            server: [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI'    => '/admin/users/delete?id=' . $target->id,
            ]
        );

        $response = $kernel->handle($request);
        $this->assertSame(405, $response->getStatusCode());

        // Target user must NOT be deleted
        $this->assertNotNull(User::find((int)$target->id));
    }

    public function testAdminUserDeleteRejectsPostWithoutCsrfWith403(): void
    {
        $admin = $this->createTestUser('admin_nocsrf', roleSlug: 'admin');
        $target = $this->createTestUser('target_nocsrf', roleSlug: 'subscriber');

        $_SESSION['auth_user_id'] = $admin->id;
        $_SESSION['auth_user_role'] = 'admin';
        $_SESSION['_token'] = 'session-token-123';

        $kernel = new Kernel(static::$app);
        $request = new Request(
            post: ['id' => (string)$target->id, '_token' => 'wrong-token'],
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI'    => '/admin/users/delete',
            ]
        );

        $response = $kernel->handle($request);
        $this->assertSame(403, $response->getStatusCode());

        // Target user must NOT be deleted
        $this->assertNotNull(User::find((int)$target->id));
    }

    public function testAdminUserDeleteSucceedsWithPostValidCsrfAndAdminAuth(): void
    {
        $admin = $this->createTestUser('admin_del', roleSlug: 'admin');
        $target = $this->createTestUser('target_del', roleSlug: 'subscriber');

        $_SESSION['auth_user_id'] = $admin->id;
        $_SESSION['auth_user_role'] = 'admin';
        $_SESSION['_token'] = 'valid-csrf-del';

        $kernel = new Kernel(static::$app);
        $request = new Request(
            get: ['id' => (string)$target->id],
            post: ['_token' => 'valid-csrf-del'],
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI'    => '/admin/users/delete?id=' . $target->id,
            ]
        );

        $response = $kernel->handle($request);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/users', $response->getHeader('Location'));
        $this->assertSame('User deleted.', $_SESSION['flash_success'] ?? '');

        // Target user is genuinely deleted from database
        $this->assertNull(User::find((int)$target->id));
    }

    public function testAdminUserCannotDeleteSelf(): void
    {
        $admin = $this->createTestUser('admin_self', roleSlug: 'admin');

        $_SESSION['auth_user_id'] = $admin->id;
        $_SESSION['auth_user_role'] = 'admin';
        $_SESSION['_token'] = 'valid-csrf-self';

        $kernel = new Kernel(static::$app);
        $request = new Request(
            get: ['id' => (string)$admin->id],
            post: ['_token' => 'valid-csrf-self'],
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI'    => '/admin/users/delete?id=' . $admin->id,
            ]
        );

        $response = $kernel->handle($request);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('cannot delete your own account', $_SESSION['flash_error'] ?? '');

        // Self must NOT be deleted
        $this->assertNotNull(User::find((int)$admin->id));
    }
}
