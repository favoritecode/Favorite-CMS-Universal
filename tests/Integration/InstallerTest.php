<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Kernel;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Installer\InstallerController;
use FavoriteCMS\Models\User;
use PHPUnit\Framework\TestCase;

class InstallerTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;
    protected static string $lockFile;
    protected static string $backupLockFile;

    public static function setUpBeforeClass(): void
    {
        static::$lockFile = APP_ROOT . '/storage/installed.lock';
        static::$backupLockFile = APP_ROOT . '/storage/installed.lock.testbak';
        if (file_exists(static::$lockFile)) {
            rename(static::$lockFile, static::$backupLockFile);
        }

        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$db  = static::$app->make(Database::class);

        static::$app->setInstalled(false);

        // Clean up any test users if needed
        static::$db->execute("DELETE FROM `users` WHERE `username` LIKE 'testadmin_%' OR `email` LIKE 'testadmin_%@example.com'");
    }

    protected function tearDown(): void
    {
        // For tests that fail validation, keep lock file absent
        if ($this->name() !== 'testSuccessfulInstallationCreatesAdminAndLockFile') {
            if (file_exists(static::$lockFile)) {
                unlink(static::$lockFile);
            }
            static::$app->setInstalled(false);
        }
    }

    public static function tearDownAfterClass(): void
    {
        static::$app->setInstalled(null);
        if (file_exists(static::$backupLockFile)) {
            if (file_exists(static::$lockFile)) {
                unlink(static::$lockFile);
            }
            rename(static::$backupLockFile, static::$lockFile);
        } elseif (!file_exists(static::$lockFile)) {
            @file_put_contents(static::$lockFile, "installed\n");
        }
        static::$db->execute("DELETE FROM `users` WHERE `username` LIKE 'testadmin_%' OR `email` LIKE 'testadmin_%@example.com'");
    }

    public function testInstallerFormRendersRequiredFieldsWhenNotInstalled(): void
    {
        $this->assertFalse(static::$app->isInstalled());

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/']);
        $installer = new InstallerController(static::$app);
        $response = $installer->handle($request);

        $ref = new \ReflectionProperty(Response::class, 'content');
        $ref->setAccessible(true);
        $html = $ref->getValue($response);

        $this->assertStringContainsString('Favorite CMS', $html);
        $this->assertStringContainsString('name="site_name"', $html);
        $this->assertStringContainsString('name="admin_username"', $html);
        $this->assertStringContainsString('name="admin_password"', $html);
        $this->assertStringContainsString('name="admin_password_confirm"', $html);
        $this->assertStringContainsString('name="admin_email"', $html);
        $this->assertStringContainsString('Install Favorite CMS', $html);
        $this->assertStringContainsString('Connected to database', $html);
    }

    public function testValidationFailsWhenFieldsAreEmpty(): void
    {
        $sessionToken = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $sessionToken;

        $post = [
            '_token'                => $sessionToken,
            'site_name'             => '',
            'admin_username'        => '',
            'admin_email'           => '',
            'admin_password'        => '',
            'admin_password_confirm'=> '',
        ];

        $request = new Request([], $post, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/install']);
        $installer = new InstallerController(static::$app);
        $response = $installer->handle($request);

        $ref = new \ReflectionProperty(Response::class, 'content');
        $ref->setAccessible(true);
        $html = $ref->getValue($response);

        $this->assertStringContainsString('Please provide a Site Name', $html);
        $this->assertStringContainsString('Please choose an Admin Username', $html);
        $this->assertStringContainsString('Please provide an Admin Email', $html);
        $this->assertStringContainsString('Please enter an Admin Password', $html);

        // Verify installed.lock was NOT created
        $this->assertFileDoesNotExist(static::$lockFile);
    }

    public function testValidationFailsWhenPasswordsDoNotMatch(): void
    {
        $sessionToken = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $sessionToken;

        $post = [
            '_token'                => $sessionToken,
            'site_name'             => 'Test Site',
            'admin_username'        => 'myadmin',
            'admin_email'           => 'admin@example.com',
            'admin_password'        => 'secret123',
            'admin_password_confirm'=> 'different_pass',
        ];

        $request = new Request([], $post, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/install']);
        $installer = new InstallerController(static::$app);
        $response = $installer->handle($request);

        $ref = new \ReflectionProperty(Response::class, 'content');
        $ref->setAccessible(true);
        $html = $ref->getValue($response);

        $this->assertStringContainsString('The password and confirmation password do not match', $html);
        $this->assertFileDoesNotExist(static::$lockFile);
    }

    public function testValidationFailsWhenEmailIsInvalid(): void
    {
        $sessionToken = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $sessionToken;

        $post = [
            '_token'                => $sessionToken,
            'site_name'             => 'Test Site',
            'admin_username'        => 'myadmin',
            'admin_email'           => 'not-an-email',
            'admin_password'        => 'secret123',
            'admin_password_confirm'=> 'secret123',
        ];

        $request = new Request([], $post, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/install']);
        $installer = new InstallerController(static::$app);
        $response = $installer->handle($request);

        $ref = new \ReflectionProperty(Response::class, 'content');
        $ref->setAccessible(true);
        $html = $ref->getValue($response);

        $this->assertStringContainsString('Please provide a valid email address', $html);
        $this->assertFileDoesNotExist(static::$lockFile);
    }

    public function testSuccessfulInstallationCreatesAdminAndLockFile(): void
    {
        $sessionToken = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $sessionToken;

        $uniq = bin2hex(random_bytes(4));
        $username = 'testadmin_' . $uniq;
        $email    = 'testadmin_' . $uniq . '@example.com';
        $password = 'SecretPass123!';
        $siteName = 'My Test Site ' . $uniq;

        $post = [
            '_token'                => $sessionToken,
            'site_name'             => $siteName,
            'admin_username'        => $username,
            'admin_email'           => $email,
            'admin_password'        => $password,
            'admin_password_confirm'=> $password,
        ];

        $request = new Request([], $post, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/install']);
        $installer = new InstallerController(static::$app);
        $response = $installer->handle($request);

        // 1. Verify Success screen returned
        $ref = new \ReflectionProperty(Response::class, 'content');
        $ref->setAccessible(true);
        $html = $ref->getValue($response);

        $this->assertStringContainsString('Success!', $html);
        $this->assertStringContainsString('Favorite CMS has been installed', $html);
        $this->assertStringContainsString($username, $html);

        // 2. Verify installed.lock exists
        $this->assertFileExists(static::$lockFile);
        $this->assertTrue(static::$app->isInstalled());

        // 3. Verify user in database
        $user = static::$db->selectOne("SELECT * FROM `users` WHERE `username` = ?", [$username]);
        $this->assertNotNull($user);
        $this->assertSame($email, $user->email);
        $this->assertSame('active', $user->status);

        // 4. Verify password_hash verification
        $this->assertTrue(password_verify($password, $user->password));

        // 5. Verify settings updated
        $setting = static::$db->selectOne("SELECT value FROM `settings` WHERE `group_name` = 'general' AND `setting_key` = 'site_name'");
        $this->assertSame($siteName, $setting->value);

        // 6. Verify Kernel handles installed state: /install redirects to /
        static::$app->setInstalled(null);
        $kernel = new Kernel(static::$app);
        $installReq = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/install']);
        $installResp = $kernel->handle($installReq);

        $statusRef = new \ReflectionProperty(Response::class, 'status');
        $statusRef->setAccessible(true);
        $this->assertSame(302, $statusRef->getValue($installResp));

        // 7. Verify homepage displays the site name
        $homeReq = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/']);
        $homeResp = $kernel->handle($homeReq);
        $homeHtml = $ref->getValue($homeResp);
        $this->assertStringContainsString($siteName, $homeHtml);
        $this->assertStringContainsString('Favorite CMS', $homeHtml);

        // 8. Verify Admin Login with created user credentials
        $loginToken = $_SESSION['_token'] ?? bin2hex(random_bytes(32));
        $_SESSION['_token'] = $loginToken;
        $loginPost = [
            '_token'   => $loginToken,
            'login'    => $username,
            'password' => $password,
        ];
        $loginReq = new Request([], $loginPost, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/login']);
        $loginResp = $kernel->handle($loginReq);
        $this->assertSame(302, $statusRef->getValue($loginResp));
        $this->assertNotEmpty($_SESSION['auth_user_id']);
        $this->assertSame((int)$user->id, (int)$_SESSION['auth_user_id']);

        // 9. Verify Admin Login fails with wrong password
        unset($_SESSION['auth_user_id']);
        $badToken = $_SESSION['_token'] ?? bin2hex(random_bytes(32));
        $_SESSION['_token'] = $badToken;
        $badLoginPost = [
            '_token'   => $badToken,
            'login'    => $username,
            'password' => 'wrong_password',
        ];
        $badReq = new Request([], $badLoginPost, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/login']);
        $badResp = $kernel->handle($badReq);
        $badHtml = $ref->getValue($badResp);
        $this->assertStringContainsString('Error: The password you entered for the username or email is incorrect', $badHtml);
        $this->assertArrayNotHasKey('auth_user_id', $_SESSION);
    }
}
