<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Kernel;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Installer\CsrfService;
use FavoriteCMS\Installer\InstallerController;
use FavoriteCMS\Models\User;
use PHPUnit\Framework\TestCase;

class InstallerTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;
    protected static string $lockFile;
    protected static string $backupLockFile;
    protected static string $envFile;
    protected static string $backupEnvFile;
    protected static array $cleanupPrefixes = [];

    public static function setUpBeforeClass(): void
    {
        static::$lockFile = APP_ROOT . '/storage/installed.lock';
        static::$backupLockFile = APP_ROOT . '/storage/installed.lock.testbak';
        static::$envFile = APP_ROOT . '/.env';
        static::$backupEnvFile = APP_ROOT . '/.env.testbak';
        if (file_exists(static::$lockFile)) {
            rename(static::$lockFile, static::$backupLockFile);
        }
        if (file_exists(static::$envFile)) {
            rename(static::$envFile, static::$backupEnvFile);
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
        foreach (static::$cleanupPrefixes as $prefix) {
            static::dropPrefixedTables($prefix);
        }
        if (file_exists(static::$backupLockFile)) {
            if (file_exists(static::$lockFile)) {
                unlink(static::$lockFile);
            }
            rename(static::$backupLockFile, static::$lockFile);
        } elseif (file_exists(static::$lockFile)) {
            unlink(static::$lockFile);
        }
        if (file_exists(static::$backupEnvFile)) {
            if (file_exists(static::$envFile)) {
                unlink(static::$envFile);
            }
            rename(static::$backupEnvFile, static::$envFile);
        } elseif (file_exists(static::$envFile)) {
            unlink(static::$envFile);
        }
        static::$db->execute("DELETE FROM `users` WHERE `username` LIKE 'testadmin_%' OR `email` LIKE 'testadmin_%@example.com'");
        foreach (['APP_URL', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_PREFIX'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
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
        $this->assertStringContainsString('Step 1 - Welcome & Requirements', $html);
        $this->assertStringContainsString('Step 2 - Database', $html);
        $this->assertStringContainsString('Test Database Connection', $html);
    }

    public function testValidationFailsWhenFieldsAreEmpty(): void
    {
        $sessionToken = (new CsrfService())->token();

        $post = [
            '_token'                => $sessionToken,
            'db_host'               => 'localhost',
            'db_port'               => '3306',
            'db_name'               => 'favorite_cms',
            'db_username'           => 'root',
            'db_password'           => '',
            'db_prefix'             => 'fvcms_',
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

        $this->assertStringContainsString('Please provide a site name', $html);
        $this->assertStringContainsString('Please choose an admin username', $html);
        $this->assertStringContainsString('Please provide a valid admin email address', $html);
        $this->assertStringContainsString('Admin password must be at least 10 characters long', $html);

        // Verify installed.lock was NOT created
        $this->assertFileDoesNotExist(static::$lockFile);
    }

    public function testValidationFailsWhenPasswordsDoNotMatch(): void
    {
        $sessionToken = (new CsrfService())->token();

        $post = [
            '_token'                => $sessionToken,
            'db_host'               => 'localhost',
            'db_port'               => '3306',
            'db_name'               => 'favorite_cms',
            'db_username'           => 'root',
            'db_password'           => '',
            'db_prefix'             => 'fvcms_',
            'site_name'             => 'Test Site',
            'admin_username'        => 'myadmin',
            'admin_email'           => 'admin@example.com',
            'admin_password'        => 'secret12345',
            'admin_password_confirm'=> 'different_pass',
        ];

        $request = new Request([], $post, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/install']);
        $installer = new InstallerController(static::$app);
        $response = $installer->handle($request);

        $ref = new \ReflectionProperty(Response::class, 'content');
        $ref->setAccessible(true);
        $html = $ref->getValue($response);

        $this->assertStringContainsString('The admin password and confirmation do not match', $html);
        $this->assertFileDoesNotExist(static::$lockFile);
    }

    public function testValidationFailsWhenEmailIsInvalid(): void
    {
        $sessionToken = (new CsrfService())->token();

        $post = [
            '_token'                => $sessionToken,
            'db_host'               => 'localhost',
            'db_port'               => '3306',
            'db_name'               => 'favorite_cms',
            'db_username'           => 'root',
            'db_password'           => '',
            'db_prefix'             => 'fvcms_',
            'site_name'             => 'Test Site',
            'admin_username'        => 'myadmin',
            'admin_email'           => 'not-an-email',
            'admin_password'        => 'secret12345',
            'admin_password_confirm'=> 'secret12345',
        ];

        $request = new Request([], $post, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/install']);
        $installer = new InstallerController(static::$app);
        $response = $installer->handle($request);

        $ref = new \ReflectionProperty(Response::class, 'content');
        $ref->setAccessible(true);
        $html = $ref->getValue($response);

        $this->assertStringContainsString('Please provide a valid admin email address', $html);
        $this->assertFileDoesNotExist(static::$lockFile);
    }

    public function testSuccessfulInstallationCreatesAdminAndLockFile(): void
    {
        $sessionToken = (new CsrfService())->token();

        $uniq = bin2hex(random_bytes(4));
        $username = 'testadmin_' . $uniq;
        $email    = 'testadmin_' . $uniq . '@example.com';
        $password = 'SecretPass123!';
        $siteName = 'My Test Site ' . $uniq;
        $prefix = 'test_' . $uniq . '_';
        static::$cleanupPrefixes[] = $prefix;

        $post = [
            '_token'                => $sessionToken,
            'db_host'               => env('DB_HOST', 'localhost'),
            'db_port'               => env('DB_PORT', '3306'),
            'db_name'               => env('DB_DATABASE', 'favorite_cms'),
            'db_username'           => env('DB_USERNAME', 'root'),
            'db_password'           => env('DB_PASSWORD', ''),
            'db_prefix'             => $prefix,
            'site_name'             => $siteName,
            'site_url'              => 'http://favorite-cms.local/',
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

        $this->assertStringContainsString('Favorite CMS installed successfully', $html);
        $this->assertStringContainsString($username, $html);

        // 2. Verify installed.lock exists
        $this->assertFileExists(static::$lockFile);
        $this->assertTrue(static::$app->isInstalled());

        // 3. Verify user in database
        $testDb = new Database([
            'driver' => 'mysql',
            'host' => (string)$post['db_host'],
            'port' => (string)$post['db_port'],
            'database' => (string)$post['db_name'],
            'username' => (string)$post['db_username'],
            'password' => (string)$post['db_password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => $prefix,
        ]);
        $user = $testDb->selectOne("SELECT * FROM `users` WHERE `username` = ?", [$username]);
        $this->assertNotNull($user);
        $this->assertSame($email, $user->email);
        $this->assertSame('active', $user->status);

        // 4. Verify password_hash verification
        $this->assertTrue(password_verify($password, $user->password));

        // 5. Verify settings updated
        $setting = $testDb->selectOne("SELECT value FROM `settings` WHERE `group_name` = 'general' AND `setting_key` = 'site_name'");
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

    protected static function dropPrefixedTables(string $prefix): void
    {
        $tables = [
            'comments',
            'plugin_settings',
            'sessions',
            'seo_meta',
            'settings',
            'menu_items',
            'menus',
            'media',
            'post_taxonomies',
            'taxonomies',
            'pages',
            'posts',
            'user_roles',
            'role_permissions',
            'permissions',
            'roles',
            'users',
            'cms_migrations',
        ];

        foreach ($tables as $table) {
            static::$db->execute('DROP TABLE IF EXISTS `' . str_replace('`', '``', $prefix . $table) . '`');
        }
    }
}
