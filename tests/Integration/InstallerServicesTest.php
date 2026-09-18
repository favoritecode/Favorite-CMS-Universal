<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests;

use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Installer\CsrfService;
use FavoriteCMS\Installer\DatabaseProvisioner;
use FavoriteCMS\Installer\InstallationStateManager;
use FavoriteCMS\Installer\UrlResolver;
use PHPUnit\Framework\TestCase;

final class InstallerServicesTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        unset($GLOBALS['favorite_cms_base_path']);
    }

    public function testSubdomainBaseUrlPreservesFullHost(): void
    {
        $request = new Request([], [], [
            'REQUEST_URI' => '/install',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'cms.canbangla.net',
            'HTTPS' => 'on',
            'REQUEST_METHOD' => 'GET',
        ]);

        $resolver = new UrlResolver();

        self::assertSame('https://cms.canbangla.net/', $resolver->currentBaseUrl($request));
        self::assertSame('/install', $resolver->route($request, '/install'));
    }

    public function testSubdirectoryBasePathIsDetectedAndStrippedFromRequestPath(): void
    {
        $request = new Request([], [], [
            'REQUEST_URI' => '/cms/install',
            'SCRIPT_NAME' => '/cms/public/index.php',
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'on',
            'REQUEST_METHOD' => 'GET',
        ]);

        $resolver = new UrlResolver();
        $request->setBasePath($resolver->basePath($request));

        self::assertSame('/cms', $request->basePath());
        self::assertSame('/install', $request->path());
        self::assertSame('https://example.com/cms/', $resolver->currentBaseUrl($request));
        self::assertSame('/cms/admin/login', $resolver->route($request, '/admin/login'));
    }

    public function testDirectPublicSubdirectoryInstallStillWorksLocally(): void
    {
        $request = new Request([], [], [
            'REQUEST_URI' => '/favorite-cms/public/install',
            'SCRIPT_NAME' => '/favorite-cms/public/index.php',
            'HTTP_HOST' => 'localhost',
            'REQUEST_METHOD' => 'GET',
        ]);

        $resolver = new UrlResolver();
        $request->setBasePath($resolver->basePath($request));

        self::assertSame('/favorite-cms/public', $request->basePath());
        self::assertSame('/install', $request->path());
        self::assertSame('http://localhost/favorite-cms/public/', $resolver->currentBaseUrl($request));
    }

    public function testHostHeaderInjectionFallsBackToLocalhost(): void
    {
        $request = new Request([], [], [
            'REQUEST_URI' => '/install',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'cms.example.com@evil.example',
            'HTTPS' => 'on',
            'REQUEST_METHOD' => 'GET',
        ]);

        self::assertSame('https://localhost/', (new UrlResolver())->currentBaseUrl($request));
    }

    public function testRedirectsAreBasePathAware(): void
    {
        $GLOBALS['favorite_cms_base_path'] = '/cms';

        $response = Response::redirect('/admin/login');
        $property = new \ReflectionProperty($response, 'headers');
        $property->setAccessible(true);

        self::assertSame('/cms/admin/login', $property->getValue($response)['Location']);
    }

    public function testCsrfTokenGenerationValidationAndExpiredRecovery(): void
    {
        $csrf = new CsrfService();
        $token = $csrf->token();

        self::assertNotSame('', $token);
        self::assertTrue($csrf->validate($token));

        $_SESSION['_favorite_installer_token_issued'] = time() - 8000;
        self::assertFalse($csrf->validate($token));
        self::assertNotSame($token, $_SESSION['_favorite_installer_token']);
    }

    public function testDatabaseConfigValidationAcceptsSharedHostingValues(): void
    {
        $provisioner = new DatabaseProvisioner();
        $config = $provisioner->normalize([
            'db_host' => 'localhost',
            'db_port' => '3306',
            'db_name' => 'u123456789_favcms',
            'db_username' => 'u123456789_user',
            'db_password' => 'secret',
            'db_prefix' => 'fvcms_',
        ]);

        self::assertSame([], $provisioner->validate($config));
    }

    public function testDatabasePrefixRejectsInjectionInput(): void
    {
        $provisioner = new DatabaseProvisioner();
        $config = $provisioner->normalize([
            'db_host' => 'localhost',
            'db_port' => '3306',
            'db_name' => 'favorite_cms',
            'db_username' => 'cms_user',
            'db_password' => 'secret',
            'db_prefix' => 'bad`;DROP',
        ]);

        self::assertNotSame([], $provisioner->validate($config));
    }

    public function testInstallationStateDetectsExistingDatabase(): void
    {
        $state = new InstallationStateManager();

        self::assertTrue($state->databaseLooksInstalled(new FakeInstallerDatabase([
            'settings' => true,
            'users' => true,
            'admin' => true,
            'setting' => true,
        ])));
    }

    public function testInstallationStateDetectsPartialDatabase(): void
    {
        $state = new InstallationStateManager();

        self::assertTrue($state->databaseLooksPartial(new FakeInstallerDatabase([
            'users' => true,
        ])));
    }

    public function testAutomaticDatabaseCreationFallsBackWhenConfigIsInvalid(): void
    {
        $provisioner = new DatabaseProvisioner();
        $result = $provisioner->createAutomatically([
            'driver' => 'mysql',
            'host' => 'bad host',
            'port' => '3306',
            'database' => 'favorite_cms',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => 'fvcms_',
        ], 'cms_user', 'secret');

        self::assertFalse($result['ok']);
        self::assertTrue($result['manual_required']);
    }
}

final class FakeInstallerDatabase extends Database
{
    public function __construct(private array $state)
    {
    }

    public function tableExists(string $table): bool
    {
        return (bool)($this->state[$table] ?? false);
    }

    public function selectOne(string $sql, array $bindings = []): ?object
    {
        if (str_contains($sql, 'FROM `users`')) {
            return !empty($this->state['admin']) ? (object)['id' => 1] : null;
        }

        if (str_contains($sql, 'FROM `settings`')) {
            return !empty($this->state['setting']) ? (object)['id' => 1] : null;
        }

        return null;
    }
}
