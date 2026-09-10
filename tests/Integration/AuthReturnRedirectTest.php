<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Kernel;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Setting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AuthReturnRedirectTest extends TestCase
{
    private const TOKEN = 'auth_return_redirect_token_123';
    private const PASSWORD = 'ReturnRedirect123!';
    private const RETURN_PATH = '/post/return-target-article#comments';

    protected static Application $app;
    protected static Database $db;
    protected static Kernel $kernel;
    protected static int $userId;
    protected static string $username;
    protected static mixed $originalAllowRegistration = null;
    protected static mixed $originalRequireVerification = null;

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db = static::$app->make(Database::class);
        static::$kernel = new Kernel(static::$app);

        static::$originalAllowRegistration = Setting::get('general', 'allow_registration', null);
        static::$originalRequireVerification = Setting::get('general', 'require_email_verification', null);

        static::$username = 'auth_return_' . bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');
        static::$userId = static::$db->insert('users', [
            'username'          => static::$username,
            'name'              => 'Return Redirect User',
            'email'             => static::$username . '@example.com',
            'password'          => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'status'            => 'active',
            'email_verified_at' => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        static::$db->execute("DELETE FROM `user_roles` WHERE `user_id` IN (SELECT `id` FROM `users` WHERE `username` = ? OR `username` LIKE 'auth_ret_su_%')", [static::$username]);
        static::$db->execute("DELETE FROM `users` WHERE `username` = ? OR `username` LIKE 'auth_ret_su_%'", [static::$username]);

        Setting::set('general', 'allow_registration', static::$originalAllowRegistration ?? 1, 'bool');
        Setting::set('general', 'require_email_verification', static::$originalRequireVerification ?? 1, 'bool');
        unset($GLOBALS['favorite_cms_base_path']);
    }

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = ['_token' => self::TOKEN];
        unset($GLOBALS['favorite_cms_base_path']);
        Setting::set('general', 'allow_registration', 1, 'bool');
        Setting::set('general', 'require_email_verification', 0, 'bool');
    }

    public static function unsafeReturnTargets(): array
    {
        return [
            'external https url'        => ['https://evil.example/phish'],
            'protocol-relative url'     => ['//evil.example/phish'],
            'backslash host'            => ['/\\evil.example'],
            'javascript scheme'         => ['javascript:alert(1)'],
            'encoded protocol-relative' => ['/%2F%2Fevil.example'],
            'crlf header injection'     => ["/post/x\r\nSet-Cookie: pwned=1"],
            'relative path'             => ['post/x'],
            'logout endpoint'           => ['/admin/logout'],
        ];
    }

    private function handle(string $method, string $uri, array $get = [], array $post = []): Response
    {
        return static::$kernel->handle(new Request(
            get: $get,
            post: $post,
            server: [
                'REQUEST_METHOD' => $method,
                'REQUEST_URI'    => $uri,
                'HTTP_HOST'      => 'favorite-cms.local',
            ]
        ));
    }

    private function login(array $extra = [], string $password = self::PASSWORD): Response
    {
        return $this->handle('POST', '/admin/login', [], array_merge([
            '_token'   => self::TOKEN,
            'login'    => static::$username,
            'password' => $password,
        ], $extra));
    }

    private function signup(string $redirect): array
    {
        $username = 'auth_ret_su_' . bin2hex(random_bytes(4));
        $response = $this->handle('POST', '/register', [], [
            '_token'                => self::TOKEN,
            'username'              => $username,
            'name'                  => 'Signup Return User',
            'email'                 => $username . '@example.com',
            'password'              => 'SignupReturn123!',
            'password_confirmation' => 'SignupReturn123!',
            'redirect'              => $redirect,
        ]);

        return [$response, $username];
    }

    // ---------------------------------------------------------------------
    // Login
    // ---------------------------------------------------------------------

    public function testLoginRedirectsToSafeLocalReturnPath(): void
    {
        $response = $this->login(['redirect' => self::RETURN_PATH]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(self::RETURN_PATH, $response->getHeader('Location'));
        $this->assertSame(static::$userId, (int)($_SESSION['auth_user_id'] ?? 0));
    }

    public function testLoginWithoutReturnPathKeepsDefaultAdminRedirect(): void
    {
        $response = $this->login();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin', $response->getHeader('Location'));
    }

    #[DataProvider('unsafeReturnTargets')]
    public function testLoginIgnoresUnsafeReturnTargets(string $target): void
    {
        $response = $this->login(['redirect' => $target]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin', $response->getHeader('Location'));
        $this->assertSame(static::$userId, (int)($_SESSION['auth_user_id'] ?? 0));
    }

    public function testLoginFormCarriesOnlySafeReturnPath(): void
    {
        $safeHtml = $this->handle('GET', '/admin/login', ['redirect' => self::RETURN_PATH])->getContent();
        $this->assertStringContainsString('name="redirect" value="' . self::RETURN_PATH . '"', $safeHtml);
        $this->assertStringContainsString('href="/register?redirect=' . rawurlencode(self::RETURN_PATH) . '"', $safeHtml);

        $unsafeHtml = $this->handle('GET', '/admin/login', ['redirect' => 'https://evil.example/phish'])->getContent();
        $this->assertStringNotContainsString('name="redirect"', $unsafeHtml);
        $this->assertStringNotContainsString('evil.example', $unsafeHtml);
        $this->assertStringContainsString('href="/register"', $unsafeHtml);
    }

    public function testFailedLoginKeepsSafeReturnPathInForm(): void
    {
        $response = $this->login(['redirect' => self::RETURN_PATH], 'WrongPassword999!');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($_SESSION['auth_user_id'] ?? null);
        $this->assertStringContainsString('name="redirect" value="' . self::RETURN_PATH . '"', $response->getContent());
    }

    public function testAuthenticatedVisitToLoginHonoursSafeReturnPath(): void
    {
        $_SESSION['auth_user_id'] = static::$userId;

        $this->assertSame(self::RETURN_PATH, $this->handle('GET', '/admin/login', ['redirect' => self::RETURN_PATH])->getHeader('Location'));
        $this->assertSame('/admin', $this->handle('GET', '/admin/login', ['redirect' => '//evil.example'])->getHeader('Location'));
    }

    public function testLegacyLoginRouteKeepsOnlySafeReturnPath(): void
    {
        $this->assertSame(
            '/admin/login?redirect=' . rawurlencode(self::RETURN_PATH),
            $this->handle('GET', '/login', ['redirect' => self::RETURN_PATH])->getHeader('Location')
        );
        $this->assertSame('/admin/login', $this->handle('GET', '/login', ['redirect' => 'https://evil.example'])->getHeader('Location'));
    }

    // ---------------------------------------------------------------------
    // Signup
    // ---------------------------------------------------------------------

    public function testSignupRedirectsToSafeLocalReturnPath(): void
    {
        [$response, $username] = $this->signup(self::RETURN_PATH);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(self::RETURN_PATH, $response->getHeader('Location'));

        $created = static::$db->selectOne("SELECT id FROM `users` WHERE `username` = ?", [$username]);
        $this->assertNotNull($created);
        $this->assertSame((int)$created->id, (int)($_SESSION['auth_user_id'] ?? 0));
    }

    #[DataProvider('unsafeReturnTargets')]
    public function testSignupIgnoresUnsafeReturnTargets(string $target): void
    {
        [$response, $username] = $this->signup($target);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin', $response->getHeader('Location'));
        $this->assertNotNull(static::$db->selectOne("SELECT id FROM `users` WHERE `username` = ?", [$username]));
    }

    public function testSignupFormCarriesOnlySafeReturnPath(): void
    {
        $safeHtml = $this->handle('GET', '/register', ['redirect' => self::RETURN_PATH])->getContent();
        $this->assertStringContainsString('name="redirect" value="' . self::RETURN_PATH . '"', $safeHtml);
        $this->assertStringContainsString('href="/admin/login?redirect=' . rawurlencode(self::RETURN_PATH) . '"', $safeHtml);

        $unsafeHtml = $this->handle('GET', '/register', ['redirect' => '//evil.example/phish'])->getContent();
        $this->assertStringNotContainsString('name="redirect"', $unsafeHtml);
        $this->assertStringNotContainsString('evil.example', $unsafeHtml);
    }

    public function testSignupValidationErrorKeepsSafeReturnPathInForm(): void
    {
        $response = $this->handle('POST', '/register', [], [
            '_token'                => self::TOKEN,
            'username'              => 'auth_ret_su_' . bin2hex(random_bytes(4)),
            'email'                 => 'not-an-email',
            'password'              => 'SignupReturn123!',
            'password_confirmation' => 'SignupReturn123!',
            'redirect'              => self::RETURN_PATH,
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('name="redirect" value="' . self::RETURN_PATH . '"', $response->getContent());
    }

    // ---------------------------------------------------------------------
    // Logout
    // ---------------------------------------------------------------------

    public function testLogoutRedirectAcceptsOnlySafeLocalPaths(): void
    {
        $cases = [
            ['/logout', 'https://evil.example/phish', '/'],
            ['/logout', '//evil.example/phish', '/'],
            ['/logout', 'javascript:alert(1)', '/'],
            ['/logout', '/post/return-target-article', '/post/return-target-article'],
            ['/admin/logout', 'https://evil.example/phish', '/admin/login'],
            ['/admin/logout', '/post/return-target-article', '/post/return-target-article'],
        ];

        foreach ($cases as [$uri, $redirect, $expectedLocation]) {
            $_SESSION = ['_token' => self::TOKEN, 'auth_user_id' => static::$userId];

            $response = $this->handle('GET', $uri, ['redirect' => $redirect]);

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame($expectedLocation, $response->getHeader('Location'), "Logout via {$uri} with redirect '{$redirect}'");
            $this->assertArrayNotHasKey('auth_user_id', $_SESSION);
        }
    }
}
