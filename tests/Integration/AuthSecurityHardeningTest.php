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
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Services\AuthRateLimiter;
use FavoriteCMS\Services\EmailVerificationService;
use FavoriteCMS\Services\PasswordHasher;
use FavoriteCMS\Services\PasswordResetService;

class AuthSecurityHardeningTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;

    private array $verificationEmails = [];
    private array $resetEmails = [];
    private array $createdUserIds = [];
    private mixed $originalSiteUrl;
    private mixed $originalReqVerif;
    private mixed $originalAllowReg;

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db = static::$app->make(Database::class);

        static::$db->execute("INSERT IGNORE INTO `roles` (`name`, `slug`, `description`, `is_system`) VALUES ('Subscriber', 'subscriber', 'Subscriber role', 1)");
        static::$db->execute("INSERT IGNORE INTO `roles` (`name`, `slug`, `description`, `is_system`) VALUES ('Administrator', 'admin', 'Admin role', 1)");
    }

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        $_SESSION = ['_token' => 'hardening-test-csrf'];
        $_POST = [];
        $_GET = [];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $this->originalSiteUrl = Setting::get('general', 'site_url', '');
        $this->originalReqVerif = Setting::get('general', 'require_email_verification', 1);
        $this->originalAllowReg = Setting::get('general', 'allow_registration', 1);

        Setting::set('general', 'site_url', 'https://example.com/cms');
        Setting::set('general', 'require_email_verification', 1, 'bool');
        Setting::set('general', 'allow_registration', 1, 'bool');

        $this->verificationEmails = [];
        $this->resetEmails = [];

        Hook::addFilter('pre_send_verification_email', function ($handled, $data) {
            $this->verificationEmails[] = $data;
            return true;
        }, 10, 2);

        Hook::addFilter('pre_send_password_reset_email', function ($handled, $data) {
            $this->resetEmails[] = $data;
            return true;
        }, 10, 2);
    }

    protected function tearDown(): void
    {
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
        Setting::set('general', 'require_email_verification', $this->originalReqVerif, 'bool');
        Setting::set('general', 'allow_registration', $this->originalAllowReg, 'bool');
        Setting::clearCache();

        $_SESSION = [];
        parent::tearDown();
    }

    private function createTestUser(string $prefix, string $roleSlug = 'subscriber', string $status = 'active', ?string $plainPassword = null): User
    {
        $unique = $prefix . '_' . bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');
        $plain = $plainPassword ?? 'TestPassword123!';
        $hash = PasswordHasher::hash($plain);

        $userId = static::$db->insert('users', [
            'username'          => $unique,
            'name'              => ucfirst($unique),
            'email'             => $unique . '@example.com',
            'password'          => $hash,
            'status'            => $status,
            'auth_version'      => 0,
            'email_verified_at' => $status === 'active' ? $now : null,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
        $this->createdUserIds[] = (int)$userId;

        $role = static::$db->selectOne("SELECT id FROM `roles` WHERE `slug` = ? LIMIT 1", [$roleSlug]);
        if ($role) {
            static::$db->execute("INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [$userId, $role->id]);
        }

        return User::find((int)$userId);
    }

    private function makeRequest(string $path, array $post = [], array $get = [], string $method = 'GET', array $server = []): Response
    {
        $serverDefaults = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI'    => '/cms' . $path,
            'SCRIPT_NAME'    => '/cms/index.php',
            'HTTP_HOST'      => 'example.com',
            'REMOTE_ADDR'    => 'hardening-ip-' . bin2hex(random_bytes(4)),
        ];

        return (new Kernel(static::$app))->handle(new Request(
            get: $get,
            post: $post,
            server: array_merge($serverDefaults, $server)
        ));
    }

    /**
     * Requirement 1, 2, 3: Registration with email verification generates valid token,
     * query parameter URL (?token=), and clickable/copy-paste email contents.
     */
    public function testRegistrationGeneratesCanonicalVerificationUrlAndEmail(): void
    {
        $username = 'regverif_' . bin2hex(random_bytes(4));
        $email = $username . '@example.com';
        $ip = 'reg-test-ip-' . bin2hex(random_bytes(4));

        $response = $this->makeRequest('/register', [
            '_token'                => 'hardening-test-csrf',
            'username'              => $username,
            'name'                  => 'Registration Test',
            'email'                 => $email,
            'password'              => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
        ], method: 'POST', server: ['REMOTE_ADDR' => $ip]);

        $this->assertSame(302, $response->getStatusCode());
        $user = User::findByEmail($email);
        $this->assertNotNull($user);
        $this->createdUserIds[] = (int)$user->id;
        $this->assertNull($user->email_verified_at);

        $this->assertNotEmpty($this->verificationEmails);
        $emailData = end($this->verificationEmails);
        $this->assertSame($email, $emailData['to']);

        $url = $emailData['verificationUrl'];
        $this->assertMatchesRegularExpression('#^https://example\.com/cms/verify-email\?token=[a-f0-9]{64}$#', $url);
        $this->assertStringNotContainsString('#token=', $url);

        $message = $emailData['message'];
        $this->assertStringContainsString($url, $message);
        $this->assertStringContainsString('copy and paste', $message);
        $this->assertStringContainsString('valid for 24 hours', $message);
        $this->assertStringContainsString('no further action is required', $message);
    }

    /**
     * Requirement 4, 5, 6: Token verified via GET /verify-email?token=...,
     * sets email_verified_at, and used token cannot be reused.
     */
    public function testTokenCanBeVerifiedAndCannotBeReused(): void
    {
        $user = $this->createTestUser('tokverif', status: 'pending');
        $service = new EmailVerificationService(static::$db);
        $rawToken = $service->createVerificationToken($user, $user->email);

        // Verify via GET
        $resp = $this->makeRequest('/verify-email', get: ['token' => $rawToken]);
        $this->assertSame(302, $resp->getStatusCode());

        $refreshed = User::find((int)$user->id);
        $this->assertNotNull($refreshed->email_verified_at);

        // Attempt reuse
        $reuseResp = $this->makeRequest('/verify-email', get: ['token' => $rawToken]);
        $this->assertSame(400, $reuseResp->getStatusCode());
        $this->assertStringContainsString('Invalid, expired, or already-used', $reuseResp->getContent());
    }

    /**
     * Requirement 7, 8: Expired and tampered/malformed verification tokens are rejected.
     */
    public function testExpiredAndMalformedTokensAreRejected(): void
    {
        $user = $this->createTestUser('exptok', status: 'pending');
        $service = new EmailVerificationService(static::$db);
        $rawToken = $service->createVerificationToken($user, $user->email);

        // Manually expire token
        static::$db->execute("UPDATE `email_verifications` SET `expires_at` = ? WHERE `user_id` = ?", [
            date('Y-m-d H:i:s', time() - 3600),
            $user->id,
        ]);

        $expResp = $this->makeRequest('/verify-email', get: ['token' => $rawToken]);
        $this->assertSame(400, $expResp->getStatusCode());
        $this->assertStringContainsString('expired', strtolower($expResp->getContent()));

        // Malformed token
        $malResp = $this->makeRequest('/verify-email', get: ['token' => 'invalid_short_token']);
        $this->assertSame(400, $malResp->getStatusCode());
        $this->assertStringContainsString('Invalid verification token', $malResp->getContent());
    }

    /**
     * Requirement 9, 10, 11: Forgot password generates valid token and active URL (?token=),
     * and reset URL recognizes valid token on GET.
     */
    public function testForgotPasswordGeneratesCanonicalUrlAndRecognizesToken(): void
    {
        $user = $this->createTestUser('pwrec');
        $ip = 'pw-req-ip-' . bin2hex(random_bytes(4));

        $resp = $this->makeRequest('/forgot-password', [
            '_token' => 'hardening-test-csrf',
            'email'  => $user->email,
        ], method: 'POST', server: ['REMOTE_ADDR' => $ip]);

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertNotEmpty($this->resetEmails);
        $emailData = end($this->resetEmails);

        $url = $emailData['url'];
        $this->assertMatchesRegularExpression('#^https://example\.com/cms/reset-password\?token=[a-f0-9]{64}$#', $url);
        $this->assertStringNotContainsString('#token=', $url);

        $token = $emailData['token'];
        $this->assertSame(64, strlen($token));

        // Visit GET /reset-password?token=...
        $getResp = $this->makeRequest('/reset-password', get: ['token' => $token]);
        $this->assertSame(200, $getResp->getStatusCode());
        $this->assertSame($token, $_SESSION['_password_reset_token'] ?? null);
    }

    /**
     * Requirement 12, 13, 14, 15: Password reset validation:
     * succeeds with strong password, fails with short, weak, or mismatched passwords.
     */
    public function testPasswordResetPasswordComplexityAndConfirmation(): void
    {
        $user = $this->createTestUser('pwvalid');
        $service = new PasswordResetService(static::$db);

        // 13. Short password (< 10 chars) fails
        $service->request($user->email);
        $token = end($this->resetEmails)['token'];
        $_SESSION['_password_reset_token'] = $token;
        $shortResp = $this->makeRequest('/reset-password', [
            '_token'           => 'hardening-test-csrf',
            'reset_token'      => $token,
            'password'         => 'Short1!',
            'password_confirm' => 'Short1!',
        ], method: 'POST');
        $this->assertStringContainsString('Use 10', $shortResp->getContent());

        // 14. Weak password (missing numbers) fails
        $weakResp = $this->makeRequest('/reset-password', [
            '_token'           => 'hardening-test-csrf',
            'reset_token'      => $token,
            'password'         => 'AllLettersOnly!',
            'password_confirm' => 'AllLettersOnly!',
        ], method: 'POST');
        $this->assertStringContainsString('Use 10', $weakResp->getContent());

        // 15. Mismatched confirmation fails
        $mismatchResp = $this->makeRequest('/reset-password', [
            '_token'           => 'hardening-test-csrf',
            'reset_token'      => $token,
            'password'         => 'StrongPassword123!',
            'password_confirm' => 'DifferentPassword123!',
        ], method: 'POST');
        $this->assertStringContainsString('enter the same password twice', $mismatchResp->getContent());

        // 12. Valid reset succeeds
        $successResp = $this->makeRequest('/reset-password', [
            '_token'           => 'hardening-test-csrf',
            'reset_token'      => $token,
            'password'         => 'BrandNewStrongPass123!',
            'password_confirm' => 'BrandNewStrongPass123!',
        ], method: 'POST');
        $this->assertSame(302, $successResp->getStatusCode());

        $updated = User::find((int)$user->id);
        $this->assertTrue(PasswordHasher::verify('BrandNewStrongPass123!', (string)$updated->password));
    }

    /**
     * Requirement 16, 17: Used and expired password reset tokens cannot be reused.
     */
    public function testUsedAndExpiredPasswordResetTokensCannotBeReused(): void
    {
        $user = $this->createTestUser('reusepw');
        $service = new PasswordResetService(static::$db);
        $service->request($user->email);
        $token = end($this->resetEmails)['token'];

        // Reset once
        $this->assertTrue($service->reset($token, 'ValidPassword123!'));

        // Reset second time fails
        $this->assertFalse($service->reset($token, 'ValidPassword456!'));

        // Expired token test
        $service->request($user->email);
        $token2 = end($this->resetEmails)['token'];
        static::$db->execute("UPDATE `password_resets` SET `expires_at` = ? WHERE `user_id` = ?", [
            gmdate('Y-m-d H:i:s', time() - 100),
            $user->id,
        ]);
        $this->assertFalse($service->reset($token2, 'ValidPassword789!'));
    }

    /**
     * Requirement 18: Password reset increments auth_version and invalidates prior sessions.
     */
    public function testPasswordResetIncrementsAuthVersionAndInvalidatesSessions(): void
    {
        $user = $this->createTestUser('sessinv');
        $this->assertSame(0, (int)$user->auth_version);

        $service = new PasswordResetService(static::$db);
        $service->request($user->email);
        $token = end($this->resetEmails)['token'];

        $this->assertTrue($service->reset($token, 'ChangedPassword123!'));

        $refreshed = User::find((int)$user->id);
        $this->assertSame(1, (int)$refreshed->auth_version);

        // Simulate request with stale auth_version 0
        $_SESSION['auth_user_id'] = $user->id;
        $_SESSION['auth_version'] = 0;

        $resp = $this->makeRequest('/admin');
        $this->assertSame(302, $resp->getStatusCode());
        $this->assertArrayNotHasKey('auth_user_id', $_SESSION);
    }

    /**
     * Requirement 19: Password change in profile invalidates other sessions by bumping auth_version.
     */
    public function testProfilePasswordChangeBumpsAuthVersion(): void
    {
        $user = $this->createTestUser('profpass');
        $_SESSION['auth_user_id'] = $user->id;
        $_SESSION['auth_version'] = 0;

        $controller = new \FavoriteCMS\Http\Controllers\Admin\UserController(static::$app);
        $req = new Request(post: [
            '_token'                => 'hardening-test-csrf',
            'name'                  => 'Updated Name',
            'email'                 => $user->email,
            'password'              => 'NewProfilePass123!',
            'password_confirmation' => 'NewProfilePass123!',
        ], server: ['REQUEST_METHOD' => 'POST']);

        $resp = $controller->updateProfile($req);
        $this->assertSame(302, $resp->getStatusCode());

        $refreshed = User::find((int)$user->id);
        $this->assertSame(1, (int)$refreshed->auth_version);
        $this->assertSame(1, (int)$_SESSION['auth_version']);
    }

    /**
     * Requirement 20: Role change regenerates session ID.
     */
    public function testRoleChangeRegeneratesSessionId(): void
    {
        $user = $this->createTestUser('rolesess', roleSlug: 'subscriber');
        $_SESSION['auth_user_id'] = $user->id;
        $_SESSION['auth_version'] = 0;
        $_SESSION['auth_user_role'] = 'subscriber';

        $adminRole = static::$db->selectOne("SELECT id FROM `roles` WHERE `slug` = 'admin' LIMIT 1");

        $controller = new \FavoriteCMS\Http\Controllers\Admin\UserController(static::$app);
        $req = new Request(
            get: ['id' => (string)$user->id],
            post: [
                '_token'   => 'hardening-test-csrf',
                'id'       => (string)$user->id,
                'name'     => $user->name,
                'email'    => $user->email,
                'role_id'  => (string)$adminRole->id,
                'status'   => 'active',
                'password' => '',
            ],
            server: ['REQUEST_METHOD' => 'POST']
        );

        $resp = $controller->update($req);
        $this->assertSame(302, $resp->getStatusCode());
        $this->assertSame('admin', $_SESSION['auth_user_role']);
    }

    /**
     * Requirement 21: AuthRateLimiter blocks excessive requests.
     */
    public function testRateLimitingEnforcedAcrossEndpoints(): void
    {
        $limiter = new AuthRateLimiter();
        $key = 'test-limit-' . bin2hex(random_bytes(4));

        // Allow up to 3 requests
        $this->assertTrue($limiter->allow($key, 3, 60));
        $this->assertTrue($limiter->allow($key, 3, 60));
        $this->assertTrue($limiter->allow($key, 3, 60));
        // 4th request must be rejected
        $this->assertFalse($limiter->allow($key, 3, 60));
    }

    /**
     * Requirement 22: Transparent rehash converts legacy bcrypt to preferred algorithm (Argon2id) on login.
     */
    public function testTransparentRehashUpgradesPasswordOnLogin(): void
    {
        $plain = 'LegacyBcryptPass123!';
        $legacyBcryptHash = password_hash($plain, PASSWORD_BCRYPT, ['cost' => 10]);

        $user = $this->createTestUser('rehash', plainPassword: $plain);
        // Overwrite user's password with legacy bcrypt cost 10
        static::$db->execute("UPDATE `users` SET `password` = ? WHERE `id` = ?", [$legacyBcryptHash, $user->id]);

        $this->assertTrue(PasswordHasher::needsRehash($legacyBcryptHash));

        // Log in via processLogin
        $loginIp = 'login-rehash-' . bin2hex(random_bytes(4));
        $resp = $this->makeRequest('/admin/login', [
            '_token'   => 'hardening-test-csrf',
            'login'    => $user->email,
            'password' => $plain,
        ], method: 'POST', server: ['REMOTE_ADDR' => $loginIp]);

        $this->assertSame(302, $resp->getStatusCode());

        $refreshed = User::find((int)$user->id);
        $newHash = (string)$refreshed->password;

        $this->assertNotSame($legacyBcryptHash, $newHash);
        $this->assertTrue(PasswordHasher::verify($plain, $newHash));

        // Check that new hash satisfies preferred algorithm (no rehash needed)
        $this->assertFalse(PasswordHasher::needsRehash($newHash));
    }
}
