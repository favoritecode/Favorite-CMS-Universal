<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Installer\CsrfService;
use FavoriteCMS\Installer\DatabaseProvisioner;
use FavoriteCMS\Installer\InstallationService;
use FavoriteCMS\Installer\InstallerController;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class InstallerAutomaticSetupTest extends TestCase
{
    private array $savedGlobals;
    private array $savedEnvironment = [];
    private array $mysqlConfig;
    private const KEYS = ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_PREFIX',
        'MYSQL_HOST', 'MYSQL_PORT', 'MYSQL_DATABASE', 'MYSQL_USER', 'MYSQL_PASSWORD', 'DATABASE_URL'];

    protected function setUp(): void
    {
        $this->mysqlConfig = require APP_ROOT . '/config/database.php';
        $this->savedGlobals = [$_ENV, $_SERVER, $_SESSION ?? [], $_FILES];
        foreach (self::KEYS as $key) {
            $this->savedEnvironment[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $_SESSION = [];
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER, $_SESSION, $_FILES] = $this->savedGlobals;
        foreach ($this->savedEnvironment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    private function credentials(): array
    {
        return ['host' => 'db.internal', 'port' => '3306', 'database' => 'provided_database',
            'username' => 'provided_account', 'password' => 'private+secret', 'prefix' => 'provided_'];
    }

    private function supplyEnvironment(array $config): void
    {
        foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'database' => 'DB_DATABASE',
            'username' => 'DB_USERNAME', 'password' => 'DB_PASSWORD', 'prefix' => 'DB_PREFIX'] as $key => $name) {
            if (array_key_exists($key, $config)) {
                putenv($name . '=' . $config[$key]);
            }
        }
    }

    private function controller(DatabaseProvisioner $db, ?InstallationService $service = null): InstallerController
    {
        $app = new Application();
        $app->setInstalled(false);
        $controller = new InstallerController($app);
        (new \ReflectionProperty($controller, 'databases'))->setValue($controller, $db);
        if ($service !== null) {
            (new \ReflectionProperty($controller, 'installer'))->setValue($controller, $service);
        }
        return $controller;
    }

    private function request(array $post = [], array $get = []): Request
    {
        return new Request($get, $post, ['REQUEST_METHOD' => $post === [] ? 'GET' : 'POST',
            'REQUEST_URI' => '/cms/install', 'SCRIPT_NAME' => '/cms/index.php', 'HTTP_HOST' => 'example.test']);
    }

    private function installInput(): array
    {
        return ['_token' => (new CsrfService())->token(), 'setup_mode' => 'environment',
            'site_name' => 'Automatic site', 'site_url' => 'https://example.test/cms/',
            'admin_username' => 'site_owner', 'admin_email' => 'owner@example.test',
            'admin_password' => 'AdminPassword123!', 'admin_password_confirm' => 'AdminPassword123!'];
    }

    public function testFreshFormOmitsAllDatabaseFieldsAndEnvironmentSecrets(): void
    {
        $this->supplyEnvironment($this->credentials());
        $db = $this->getMockBuilder(DatabaseProvisioner::class)->onlyMethods(['testConnection', 'connectServer'])->getMock();
        $db->expects(self::never())->method('testConnection');
        $db->expects(self::never())->method('connectServer');
        $html = $this->controller($db)->handle($this->request())->getContent();
        self::assertStringContainsString('name="setup_mode" value="environment"', $html);
        self::assertStringContainsString('Site Information', $html);
        self::assertStringContainsString('Admin Account', $html);
        self::assertStringContainsString('/cms/install?mode=restore', $html);
        self::assertStringNotContainsString('id="step-database"', $html);
        self::assertDoesNotMatchRegularExpression('/name="db_(host|port|username|password|name|prefix)"/', $html);
        foreach ($this->credentials() as $key => $value) {
            if ($key !== 'port') {
                self::assertStringNotContainsString($value, $html);
            }
        }
    }

    public function testAbsentOrIncompleteCredentialsNeverAttemptAConnection(): void
    {
        $db = $this->getMockBuilder(DatabaseProvisioner::class)->onlyMethods(['testConnection', 'connectServer'])->getMock();
        $db->expects(self::never())->method('testConnection');
        $db->expects(self::never())->method('connectServer');
        self::assertFalse($db->configureAutomatically('test_')['ok']);
        $config = $this->credentials();
        unset($config['password']);
        $this->supplyEnvironment($config);
        self::assertFalse($db->configureAutomatically('test_')['ok']);
        $config['password'] = 'supplied';
        $config['database'] = 'unsafe`;DROP DATABASE';
        $this->supplyEnvironment($config);
        self::assertFalse($db->configureAutomatically('test_')['ok']);
    }

    public function testRequirementsContinueFindsManualFallbackBeforeSiteAndAdminInput(): void
    {
        $service = $this->createMock(InstallationService::class);
        $service->expects(self::never())->method('install');
        $html = $this->controller(new DatabaseProvisioner(), $service)->handle($this->request([
            '_token' => (new CsrfService())->token(), 'db_action' => 'prepare_database',
        ]))->getContent();
        self::assertStringContainsString('data-initial-step="database"', $html);
        self::assertStringContainsString('name="db_name"', $html);
        self::assertStringContainsString('name="site_name"', $html);
        self::assertStringContainsString('name="setup_mode" value="advanced"', $html);
        self::assertStringNotContainsString('Please provide a site name', $html);
        self::assertStringNotContainsString('Please choose an admin username', $html);
        self::assertStringNotContainsString('role="alert" data-error-summary', $html);
    }

    public function testRequirementsContinueSkipsDatabaseOnlyAfterAutomaticSuccess(): void
    {
        $db = $this->getMockBuilder(DatabaseProvisioner::class)->onlyMethods(['generateTablePrefix', 'configureAutomatically'])->getMock();
        $db->method('generateTablePrefix')->willReturn('prepared_');
        $db->expects(self::once())->method('configureAutomatically')->with('prepared_')
            ->willReturn(['ok' => true, 'config' => $this->credentials()]);
        $service = $this->createMock(InstallationService::class);
        $service->expects(self::never())->method('install');
        $html = $this->controller($db, $service)->handle($this->request([
            '_token' => (new CsrfService())->token(), 'db_action' => 'prepare_database',
        ]))->getContent();
        self::assertStringContainsString('data-initial-step="site"', $html);
        self::assertStringContainsString('name="site_name"', $html);
        self::assertStringContainsString('name="admin_username"', $html);
        self::assertStringContainsString('name="setup_mode" value="environment"', $html);
        self::assertStringNotContainsString('name="db_password"', $html);
        self::assertStringNotContainsString('private+secret', $html);
        self::assertStringNotContainsString('provided_account', $html);
    }

    public function testRequirementsPreparationRequiresCsrf(): void
    {
        $db = $this->getMockBuilder(DatabaseProvisioner::class)->onlyMethods(['configureAutomatically'])->getMock();
        $db->expects(self::never())->method('configureAutomatically');
        $html = $this->controller($db)->handle($this->request([
            '_token' => 'invalid', 'db_action' => 'prepare_database',
        ]))->getContent();
        self::assertStringContainsString('Invalid or expired security token', $html);
        self::assertStringNotContainsString('name="admin_password"', $html);
    }

    public function testUnavailableAutomaticSetupShowsManualFallbackAndPreservesSiteInput(): void
    {
        $html = $this->controller(new DatabaseProvisioner())->handle($this->request($this->installInput()))->getContent();
        self::assertStringContainsString('does not provide usable automatic database setup', $html);
        self::assertStringContainsString('data-initial-step="database"', $html);
        self::assertStringContainsString('name="setup_mode" value="advanced"', $html);
        self::assertStringNotContainsString('role="alert" data-error-summary', $html);
        self::assertStringNotContainsString('Please review this step', $html);
        self::assertStringContainsString('id="database-feedback" class="fc-alert fc-alert--info"', $html);
        foreach (['host', 'port', 'name', 'username', 'password', 'prefix'] as $field) {
            self::assertStringContainsString('name="db_' . $field . '"', $html);
        }
        self::assertStringContainsString('value="Automatic site"', $html);
        self::assertStringNotContainsString('value="AdminPassword123!"', $html);
    }

    public function testManualConnectionCheckClearsFallbackWithoutInstallingOrReturningSecrets(): void
    {
        $connection = $this->createMock(Database::class);
        $db = $this->getMockBuilder(DatabaseProvisioner::class)->onlyMethods(['testConnection'])->getMock();
        $db->expects(self::once())->method('testConnection')->with(self::callback(static fn(array $config): bool =>
            $config['database'] === 'manual_db' && $config['username'] === 'manual_user' && $config['password'] === 'manual_secret'
        ))->willReturn($connection);
        $service = $this->createMock(InstallationService::class);
        $service->expects(self::never())->method('install');
        $controller = $this->controller($db, $service);
        $fallback = $controller->handle($this->request($this->installInput()))->getContent();
        self::assertStringContainsString('does not provide usable automatic database setup', $fallback);
        $response = $controller->handle($this->request([
            '_token' => (new CsrfService())->token(), 'setup_mode' => 'advanced',
            'db_action' => 'test_database', '_response' => 'json', 'db_host' => 'localhost', 'db_port' => '3306',
            'db_name' => 'manual_db', 'db_username' => 'manual_user', 'db_password' => 'manual_secret', 'db_prefix' => 'manual_',
        ]));
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(json_decode($response->getContent(), true)['ok']);
        self::assertStringContainsString('no-store', $response->getHeader('Cache-Control'));
        self::assertStringNotContainsString('manual_secret', $response->getContent());
        self::assertStringNotContainsString('does not provide usable automatic database setup', $response->getContent());
    }

    public function testManualConnectionCheckRejectsBadCredentialsAndInvalidCsrf(): void
    {
        $db = $this->getMockBuilder(DatabaseProvisioner::class)->onlyMethods(['testConnection'])->getMock();
        $db->expects(self::once())->method('testConnection')->willThrowException(new PDOException('Access denied: manual_user manual_secret', 1045));
        $controller = $this->controller($db);
        $post = ['_token' => 'invalid', 'setup_mode' => 'advanced', 'db_action' => 'test_database', '_response' => 'json',
            'db_name' => 'manual_db', 'db_username' => 'manual_user', 'db_password' => 'manual_secret'];
        $response = $controller->handle($this->request($post));
        self::assertSame(403, $response->getStatusCode());
        self::assertFalse(json_decode($response->getContent(), true)['ok']);
        $post['_token'] = (new CsrfService())->token();
        $response = $controller->handle($this->request($post));
        self::assertSame(422, $response->getStatusCode());
        self::assertFalse(json_decode($response->getContent(), true)['ok']);
        self::assertStringNotContainsString('manual_secret', $response->getContent());
    }

    public function testAutomaticInstallUsesServerCredentialsAndDoesNotRenderThem(): void
    {
        $config = $this->credentials();
        $db = $this->createMock(DatabaseProvisioner::class);
        $db->method('generateTablePrefix')->willReturn('session_');
        $db->expects(self::once())->method('configureAutomatically')->with('session_')->willReturn(['ok' => true, 'config' => $config]);
        $db->method('validate')->willReturn([]);
        $service = $this->createMock(InstallationService::class);
        $service->expects(self::once())->method('install')->with($config, self::anything(), self::anything())
            ->willReturn(['applied_migrations' => []]);
        $post = $this->installInput() + ['db_username' => 'attacker', 'db_password' => 'ignored'];
        $html = $this->controller($db, $service)->handle($this->request($post))->getContent();
        self::assertStringContainsString('Favorite CMS installed successfully', $html);
        self::assertStringNotContainsString($config['username'], $html);
        self::assertStringNotContainsString($config['password'], $html);
        self::assertArrayNotHasKey('_favorite_installer_db_prefix', $_SESSION);
    }

    public function testCsrfAndSiteValidationRunBeforeAutomaticProvisioning(): void
    {
        $db = $this->getMockBuilder(DatabaseProvisioner::class)->onlyMethods(['configureAutomatically'])->getMock();
        $db->expects(self::never())->method('configureAutomatically');
        $controller = $this->controller($db);
        $post = $this->installInput();
        $post['_token'] = 'invalid';
        self::assertStringContainsString('Invalid or expired security token', $controller->handle($this->request($post))->getContent());
        $post = $this->installInput();
        $post['site_name'] = '';
        self::assertStringContainsString('Please provide a site name', $controller->handle($this->request($post))->getContent());
    }

    public function testRestoreKeepsExplicitDatabaseFieldsAndValidation(): void
    {
        $db = $this->getMockBuilder(DatabaseProvisioner::class)->onlyMethods(['configureAutomatically', 'validate'])->getMock();
        $db->expects(self::never())->method('configureAutomatically');
        $db->expects(self::once())->method('validate')->with(self::callback(static fn(array $config): bool =>
            $config['database'] === 'restore_db' && $config['username'] === 'restore_user' && $config['password'] === 'restore_secret'
        ))->willReturn(['Stop before restoring the test archive.']);
        $controller = $this->controller($db);
        $html = $controller->handle($this->request([], ['mode' => 'restore']))->getContent();
        self::assertStringContainsString('id="restore-form"', $html);
        foreach (['name', 'username', 'password'] as $field) {
            self::assertStringContainsString('id="res_db_' . $field . '" name="db_' . $field . '"', $html);
        }
        $_FILES['backup_file'] = ['tmp_name' => 'test.zip', 'error' => UPLOAD_ERR_OK];
        $post = ['_token' => (new CsrfService())->token(), 'db_action' => 'restore',
            'db_name' => 'restore_db', 'db_username' => 'restore_user', 'db_password' => 'restore_secret'];
        $html = $controller->handle($this->request($post))->getContent();
        self::assertStringContainsString('Stop before restoring the test archive.', $html);
        self::assertStringNotContainsString('value="restore_secret"', $html);
    }

    public function testEnvironmentUrlsPreservePasswordsAndNeverMixCredentialSources(): void
    {
        $this->supplyEnvironment($this->credentials());
        putenv('DATABASE_URL=mysql://url_user:a+b%23c@db.internal/url_db');
        $db = new DatabaseProvisioner();
        self::assertSame('a+b#c', $db->detectEnvironmentCredentials()['password']);
        putenv('DATABASE_URL=mysql://url_user@db.internal/url_db');
        self::assertFalse($db->configureAutomatically('test_')['ok']);
        putenv('DATABASE_URL=postgres://user:secret@other/db');
        self::assertSame([], $db->detectEnvironmentCredentials());
        putenv('DATABASE_URL');
        putenv('DB_PASSWORD=');
        self::assertSame('', $db->detectEnvironmentCredentials()['password']);
        putenv('DB_PASSWORD=0');
        self::assertSame('0', $db->detectEnvironmentCredentials()['password']);
    }

    public function testCreationRequiresVerifiedCreatePrivilegeAndSanitizesFailure(): void
    {
        foreach (['GRANT SELECT ON *.* TO `test`@`localhost`', 'GRANT CREATE ON `another_database`.* TO `test`@`localhost`'] as $grant) {
            $statement = $this->createMock(PDOStatement::class);
            $statement->method('fetchAll')->willReturn([$grant]);
            $pdo = $this->createMock(PDO::class);
            $pdo->method('query')->willReturn($statement);
            $pdo->expects(self::never())->method('exec');
            $db = $this->getMockBuilder(DatabaseProvisioner::class)->onlyMethods(['connectServer'])->getMock();
            $db->method('connectServer')->willReturn($pdo);
            self::assertFalse($db->createAutomatically($this->credentials(), 'provided_account', 'private+secret')['ok']);
        }
        $this->supplyEnvironment($this->credentials());
        $db = $this->getMockBuilder(DatabaseProvisioner::class)->onlyMethods(['testConnection', 'connectServer'])->getMock();
        $db->method('testConnection')->willThrowException(new PDOException('Access denied for provided_account private+secret', 1045));
        $db->expects(self::never())->method('connectServer');
        $result = $db->configureAutomatically('test_');
        self::assertFalse($result['ok']);
        self::assertStringNotContainsString('private+secret', json_encode($result));
        self::assertStringNotContainsString('provided_account', json_encode($result));
    }

    public function testRealMysqlConfigurationAndCreationFromSuppliedEnvironment(): void
    {
        // Reuse the existing test environment; these are test inputs, never production defaults.
        $config = $this->mysqlConfig;
        try {
            $server = new PDO('mysql:host=' . $config['host'] . ';port=' . $config['port'], $config['username'], $config['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (\Throwable) {
            self::markTestSkipped('The configured MySQL test server is unavailable.');
        }
        $this->supplyEnvironment($config);
        $db = new DatabaseProvisioner();
        self::assertTrue($db->configureAutomatically('auto_test_')['ok']);
        $name = 'fc_auto_test_' . bin2hex(random_bytes(6));
        $config['database'] = $name;
        $this->supplyEnvironment($config);
        try {
            $result = $db->configureAutomatically('auto_test_');
            self::assertTrue($result['ok'], 'The local test account must allow creation for this provisioning test.');
            self::assertInstanceOf(Database::class, $db->testConnection($result['config']));
        } finally {
            $server->exec('DROP DATABASE IF EXISTS `' . $name . '`');
        }
    }
}
