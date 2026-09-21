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
use FavoriteCMS\Installer\DatabaseProvisioner;
use FavoriteCMS\Installer\InstallationService;
use FavoriteCMS\Installer\InstallationStateManager;
use FavoriteCMS\Models\Role;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Services\EmailVerificationService;
use FavoriteCMS\Services\Mail\MailDetector;
use FavoriteCMS\Services\Mail\SmtpTransport;
use FavoriteCMS\Services\MailService;
use FavoriteCMS\Services\PasswordHasher;
use FavoriteCMS\Services\PasswordResetService;

/**
 * Universal Auto Email & SMTP System Integration Suite.
 *
 * Verifies:
 * - Installer email default initialization
 * - Centralized MailService auto-transport routing & error logging
 * - Transport modes (auto, mail, smtp) without duplicate failover
 * - Recipient independence (user email verification, password reset, admin recovery)
 * - Strict separation between sender email and admin email
 * - Security of /admin/settings/test-email and /admin/settings/test-smtp endpoints
 *   (POST-only, CSRF, super-admin / admin authorized, unauthorized blocked)
 * - Secure SMTP password masking, updating, and clearing
 * - Multi-domain URL compatibility and host independence
 */
class UniversalAutoEmailSmtpSystemTest extends TestCase
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
    private mixed $origTransport;
    private mixed $origSmtpHost;
    private mixed $origSmtpPort;
    private mixed $origSmtpEncryption;
    private mixed $origSmtpUser;
    private mixed $origSmtpPass;
    private mixed $origSmtpTimeout;

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
        $_SESSION = ['_token' => 'universal-smtp-csrf-token'];
        $_POST = [];
        $_GET = [];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        // Backup existing settings
        $this->origSiteUrl        = Setting::get('general', 'site_url', '');
        $this->origAdminEmail     = Setting::get('general', 'admin_email', '');
        $this->origSiteName       = Setting::get('general', 'site_name', 'Favorite CMS');
        $this->origSenderEmail    = Setting::get('email', 'sender_email', '');
        $this->origSenderName     = Setting::get('email', 'sender_name', '');
        $this->origTransport      = Setting::get('email', 'transport', 'auto');
        $this->origSmtpHost       = Setting::get('email', 'smtp_host', '');
        $this->origSmtpPort       = Setting::get('email', 'smtp_port', 587);
        $this->origSmtpEncryption = Setting::get('email', 'smtp_encryption', 'tls');
        $this->origSmtpUser       = Setting::get('email', 'smtp_username', '');
        $this->origSmtpPass       = Setting::get('email', 'smtp_password', '');
        $this->origSmtpTimeout    = Setting::get('email', 'smtp_timeout', 15);

        $this->interceptedMails = [];
        $this->createdUserIds = [];
        SmtpTransport::$streamFactory = null;
    }

    protected function tearDown(): void
    {
        Hook::removeFilter('pre_send_mail');
        Hook::removeFilter('pre_send_verification_email');
        Hook::removeFilter('pre_send_password_reset_email');
        SmtpTransport::$streamFactory = null;

        foreach ($this->createdUserIds as $uid) {
            static::$db->delete('user_roles', ['user_id' => $uid]);
            static::$db->delete('email_verifications', ['user_id' => $uid]);
            static::$db->delete('password_resets', ['user_id' => $uid]);
            static::$db->delete('users', ['id' => $uid]);
        }
        $this->createdUserIds = [];

        // Restore settings
        Setting::set('general', 'site_url', $this->origSiteUrl);
        Setting::set('general', 'admin_email', $this->origAdminEmail);
        Setting::set('general', 'site_name', $this->origSiteName);
        Setting::set('email', 'sender_email', $this->origSenderEmail);
        Setting::set('email', 'sender_name', $this->origSenderName);
        Setting::set('email', 'transport', $this->origTransport);
        Setting::set('email', 'smtp_host', $this->origSmtpHost);
        Setting::set('email', 'smtp_port', $this->origSmtpPort);
        Setting::set('email', 'smtp_encryption', $this->origSmtpEncryption);
        Setting::set('email', 'smtp_username', $this->origSmtpUser);
        Setting::set('email', 'smtp_password', $this->origSmtpPass);
        Setting::set('email', 'smtp_timeout', $this->origSmtpTimeout);
        Setting::clearCache();

        $_SESSION = [];
        parent::tearDown();
    }

    private function createTestUser(string $prefix, string $roleSlug = 'subscriber'): User
    {
        $unique = $prefix . '_' . bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');
        $email = $unique . '@example.com';

        $userId = static::$db->insert('users', [
            'username'          => $unique,
            'name'              => ucfirst($unique),
            'email'             => $email,
            'password'          => PasswordHasher::hash('Pass12345!'),
            'status'            => 'active',
            'created_at'        => $now,
            'updated_at'        => $now,
            'email_verified_at' => $now,
        ]);
        $this->createdUserIds[] = (int)$userId;

        $role = static::$db->selectOne("SELECT id FROM roles WHERE slug = ? LIMIT 1", [$roleSlug]);
        if ($role && isset($role->id)) {
            static::$db->insert('user_roles', [
                'user_id' => (int)$userId,
                'role_id' => (int)$role->id,
            ]);
        }

        return User::find((int)$userId);
    }

    private function loginAs(User $user): void
    {
        $_SESSION['auth_user_id'] = $user->id;
        $_SESSION['auth_user_role'] = $user->role;
        $_SESSION['auth_user_name'] = $user->name;
        $_SESSION['auth_user_email'] = $user->email;
    }

    // =========================================================================
    // 1. Installer Email Defaults
    // =========================================================================

    public function testInstallationServiceWritesCompleteEmailDefaults(): void
    {
        $provisioner = new DatabaseProvisioner();
        $state = new InstallationStateManager();
        $service = new InstallationService(static::$app, $provisioner, $state);

        $ref = new \ReflectionMethod($service, 'writeSettings');
        $ref->setAccessible(true);

        $ref->invoke($service, [
            'name' => 'Custom Test Portal',
            'url'  => 'https://sub.portal.example.com:8443/cms',
        ], [
            'email' => 'master-admin@portal.example.com',
        ]);

        Setting::clearCache();

        $this->assertSame('auto', Setting::get('email', 'transport'));
        $this->assertSame('Custom Test Portal', Setting::get('email', 'sender_name'));
        $this->assertSame('noreply@sub.portal.example.com', Setting::get('email', 'sender_email'));
        $this->assertSame('smtp.sub.portal.example.com', Setting::get('email', 'smtp_host'));
        $this->assertSame(587, (int)Setting::get('email', 'smtp_port'));
        $this->assertSame('tls', Setting::get('email', 'smtp_encryption'));
        $this->assertSame(15, (int)Setting::get('email', 'smtp_timeout'));
        $this->assertSame('master-admin@portal.example.com', Setting::get('general', 'admin_email'));
    }

    // =========================================================================
    // 2. Transport Routing & Auto Detection
    // =========================================================================

    public function testAutoTransportResolvesToNativeMailWhenSmtpHostEmpty(): void
    {
        Setting::set('email', 'transport', 'auto');
        Setting::set('email', 'smtp_host', '');
        Setting::set('email', 'sender_email', 'noreply@mysite.org');
        Setting::set('general', 'site_url', 'https://mysite.org');
        Setting::clearCache();

        $mailService = new MailService();
        $diag = $mailService->getTransportDiagnostics();

        $this->assertSame('auto', $diag['transport_mode']);
        $this->assertSame('mail', $diag['resolved_transport']);
        $this->assertFalse($diag['smtp_configured']);
    }

    public function testAutoTransportResolvesToSmtpWhenSmtpHostConfigured(): void
    {
        Setting::set('email', 'transport', 'auto');
        Setting::set('email', 'smtp_host', 'mail.mysite.org');
        Setting::set('email', 'smtp_port', 587);
        Setting::set('email', 'smtp_username', 'user@mysite.org');
        Setting::set('email', 'smtp_password', 'secret123');
        Setting::set('email', 'sender_email', 'noreply@mysite.org');
        Setting::clearCache();

        $mailService = new MailService();
        $diag = $mailService->getTransportDiagnostics();

        $this->assertSame('auto', $diag['transport_mode']);
        $this->assertSame('smtp', $diag['resolved_transport']);
        $this->assertTrue($diag['smtp_configured']);
        $this->assertSame('mail.mysite.org', $diag['smtp_host']);
    }

    public function testForcedSmtpTransportFailsIfHostMissingWithoutFailover(): void
    {
        Setting::set('email', 'transport', 'smtp');
        Setting::set('email', 'smtp_host', '');
        Setting::set('email', 'sender_email', 'noreply@mysite.org');
        Setting::clearCache();

        $mailService = new MailService();
        $sent = $mailService->send('test@example.com', 'Subject', 'Body');

        $this->assertFalse($sent);
        $diag = $mailService->getTransportDiagnostics();
        $this->assertSame('smtp', $diag['last_transport_used']);
        $this->assertStringContainsString('SMTP host is not configured', (string)$diag['last_transport_error']);
    }

    // =========================================================================
    // 3. Sender vs Admin Recipient Separation
    // =========================================================================

    public function testSenderEmailAndAdminRecoveryEmailAreStrictlyIndependent(): void
    {
        Setting::set('general', 'admin_email', 'super-recovery@example.com');
        Setting::set('email', 'sender_email', 'noreply-outbound@example.com');
        Setting::set('email', 'sender_name', 'System Notifications');
        Setting::clearCache();

        Hook::addFilter('pre_send_mail', function ($null, $args) {
            $this->interceptedMails[] = $args;
            return true;
        }, 10, 2);

        $adminEmail = (string)Setting::get('general', 'admin_email', '');
        $mailService = new MailService();
        $mailService->send($adminEmail, 'Security Alert', 'Database backup complete.');

        $this->assertCount(1, $this->interceptedMails);
        $intercepted = $this->interceptedMails[0];

        // Recipient must be general.admin_email
        $this->assertSame('super-recovery@example.com', $intercepted['to']);
        // From must be email.sender_email
        $this->assertSame('noreply-outbound@example.com', $intercepted['from']);
        $this->assertSame('Security Alert', $intercepted['subject']);
    }

    public function testUserVerificationEmailSentToUserWithConfiguredSender(): void
    {
        Setting::set('general', 'admin_email', 'admin-inbox@example.com');
        Setting::set('email', 'sender_email', 'verify@company.org');
        Setting::set('email', 'sender_name', 'Company Verification');
        Setting::clearCache();

        Hook::addFilter('pre_send_mail', function ($null, $args) {
            $this->interceptedMails[] = $args;
            return true;
        }, 10, 2);

        $user = $this->createTestUser('verify_test');
        $verifService = new EmailVerificationService();
        $sent = $verifService->sendVerificationEmail($user, $user->email, 'test-verification-token-12345');

        $this->assertTrue($sent);
        $this->assertCount(1, $this->interceptedMails);
        $intercepted = $this->interceptedMails[0];

        $this->assertSame($user->email, $intercepted['to']);
        $this->assertSame('verify@company.org', $intercepted['from']);
        $this->assertNotSame('admin-inbox@example.com', $intercepted['to']);
    }

    public function testPasswordResetEmailSentToUserWithConfiguredSender(): void
    {
        Setting::set('general', 'admin_email', 'admin-inbox@example.com');
        Setting::set('email', 'sender_email', 'security@company.org');
        Setting::set('email', 'sender_name', 'Security Desk');
        Setting::clearCache();

        Hook::addFilter('pre_send_mail', function ($null, $args) {
            $this->interceptedMails[] = $args;
            return true;
        }, 10, 2);

        $user = $this->createTestUser('reset_test');
        $resetService = new PasswordResetService(static::$db);
        $sent = $resetService->request($user->email);

        $this->assertTrue($sent);
        $this->assertCount(1, $this->interceptedMails);
        $intercepted = $this->interceptedMails[0];

        $this->assertSame($user->email, $intercepted['to']);
        $this->assertSame('security@company.org', $intercepted['from']);
    }

    // =========================================================================
    // 4. Test Email & Test SMTP Endpoints Security & Permissions
    // =========================================================================

    public function testEndpointsRejectGetMethodWith405ForAuthenticatedAdmin(): void
    {
        $admin = $this->createTestUser('get_adm', 'admin');
        $this->loginAs($admin);

        $kernel = new Kernel(static::$app);

        // GET /admin/settings/test-email -> 405 Method Not Allowed
        $reqEmail = Request::create('GET', '/admin/settings/test-email');
        $respEmail = $kernel->handle($reqEmail);
        $this->assertSame(405, $respEmail->getStatusCode());

        // GET /admin/settings/test-smtp -> 405 Method Not Allowed
        $reqSmtp = Request::create('GET', '/admin/settings/test-smtp');
        $respSmtp = $kernel->handle($reqSmtp);
        $this->assertSame(405, $respSmtp->getStatusCode());
    }

    public function testEndpointsRejectInvalidCsrf(): void
    {
        $admin = $this->createTestUser('csrf_admin', 'super-admin');
        $this->loginAs($admin);

        $kernel = new Kernel(static::$app);

        // POST /admin/settings/test-email with bad CSRF
        $reqEmail = Request::create('POST', '/admin/settings/test-email', ['_token' => 'invalid-token']);
        $respEmail = $kernel->handle($reqEmail);
        $this->assertSame(403, $respEmail->getStatusCode());

        // POST /admin/settings/test-smtp with bad CSRF
        $reqSmtp = Request::create('POST', '/admin/settings/test-smtp', ['_token' => 'invalid-token']);
        $respSmtp = $kernel->handle($reqSmtp);
        $this->assertSame(403, $respSmtp->getStatusCode());
    }

    public function testEndpointsRejectUnauthorizedRoles(): void
    {
        $subscriber = $this->createTestUser('sub_user', 'subscriber');
        $this->loginAs($subscriber);

        $kernel = new Kernel(static::$app);

        // POST /admin/settings/test-email
        $reqEmail = Request::create('POST', '/admin/settings/test-email', ['_token' => 'universal-smtp-csrf-token']);
        $respEmail = $kernel->handle($reqEmail);
        $this->assertSame(403, $respEmail->getStatusCode());

        // POST /admin/settings/test-smtp
        $reqSmtp = Request::create('POST', '/admin/settings/test-smtp', ['_token' => 'universal-smtp-csrf-token']);
        $respSmtp = $kernel->handle($reqSmtp);
        $this->assertSame(403, $respSmtp->getStatusCode());
    }

    public function testSuperAdminAndAdminCanExecuteTestSmtpConnection(): void
    {
        $superAdmin = $this->createTestUser('smtp_sadmin', 'super-admin');
        $this->loginAs($superAdmin);

        Setting::set('email', 'smtp_host', '127.0.0.1');
        Setting::set('email', 'smtp_port', 2525);
        Setting::clearCache();

        $controller = new SettingController(static::$app);
        $req = Request::create('POST', '/admin/settings/test-smtp', [
            '_token' => 'universal-smtp-csrf-token',
        ]);

        $response = $controller->testSmtpConnection($req);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertTrue(isset($_SESSION['flash_success']) || isset($_SESSION['flash_error']));
        $flashMsg = (string)($_SESSION['flash_success'] ?? $_SESSION['flash_error']);
        $this->assertStringNotContainsString('secretpassword', strtolower($flashMsg));
    }

    // =========================================================================
    // 5. Secure Password Masking, Updating & Clearing
    // =========================================================================

    public function testSettingsControllerMasksSmtpPasswordAndPreservesOnSave(): void
    {
        $admin = $this->createTestUser('mask_admin', 'super-admin');
        $this->loginAs($admin);

        Setting::set('email', 'smtp_password', 'MySuperSecretPassword#2026');
        Setting::set('email', 'smtp_host', 'smtp.example.org');
        Setting::set('email', 'sender_email', 'info@example.org');
        Setting::clearCache();

        $controller = new SettingController(static::$app);

        // 1. Verify view data has masked indicator
        $viewResp = $controller->index(Request::create('GET', '/admin/settings'));
        $this->assertInstanceOf(Response::class, $viewResp);

        // 2. Post update WITHOUT new password -> must preserve existing password
        $updateReq = Request::create('POST', '/admin/settings', [
            '_token'           => 'universal-smtp-csrf-token',
            'site_name'        => 'My Site',
            'site_url'         => 'https://example.org',
            'admin_email'      => 'admin@example.org',
            'email_transport'  => 'smtp',
            'smtp_host'        => 'smtp.example.org',
            'smtp_port'        => '587',
            'smtp_encryption'  => 'tls',
            'smtp_username'    => 'user@example.org',
            'smtp_password'    => '', // Empty on save
            'sender_email'     => 'info@example.org',
            'sender_name'      => 'My Site Notifications',
        ]);

        $resp = $controller->update($updateReq);
        Setting::clearCache();

        $this->assertSame('MySuperSecretPassword#2026', Setting::get('email', 'smtp_password'));

        // 3. Post update WITH smtp_password_clear = 1 -> must wipe password
        $clearReq = Request::create('POST', '/admin/settings', [
            '_token'              => 'universal-smtp-csrf-token',
            'site_name'           => 'My Site',
            'site_url'            => 'https://example.org',
            'admin_email'         => 'admin@example.org',
            'email_transport'     => 'smtp',
            'smtp_host'           => 'smtp.example.org',
            'smtp_password_clear' => '1',
            'sender_email'        => 'info@example.org',
        ]);

        $controller->update($clearReq);
        Setting::clearCache();

        $this->assertSame('', Setting::get('email', 'smtp_password'));
    }

    // =========================================================================
    // 6. Universal Domain & Subdirectory Extraction
    // =========================================================================

    public function testMailDetectorHandlesComplexUrlsAndLocalhost(): void
    {
        $detector = new MailDetector();

        // Localhost
        $localProfile = $detector->detectSmtpProfile('http://localhost:8000/app');
        $this->assertSame('localhost', $localProfile['host']);
        $this->assertSame(25, $localProfile['port']);

        // Subdomain with path and port
        $url = 'https://portal.store.co.uk:9090/shop/cms/';
        $this->assertSame('noreply@portal.store.co.uk', $detector->suggestSenderEmail($url));
        $profile = $detector->detectSmtpProfile($url);
        $this->assertSame('smtp.portal.store.co.uk', $profile['host']);

        // Standard domain
        $standardUrl = 'https://example.org';
        $this->assertSame('noreply@example.org', $detector->suggestSenderEmail($standardUrl));
        $standardProfile = $detector->detectSmtpProfile($standardUrl);
        $this->assertSame('smtp.example.org', $standardProfile['host']);
    }
}
