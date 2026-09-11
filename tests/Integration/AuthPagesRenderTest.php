<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Kernel;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Setting;
use PHPUnit\Framework\TestCase;

class AuthPagesRenderTest extends TestCase
{
    private const TOKEN = 'auth_pages_render_token_123';

    protected static Application $app;
    protected static Database $db;
    protected static Kernel $kernel;
    protected static mixed $originalAllowRegistration = null;

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db = static::$app->make(Database::class);
        static::$kernel = new Kernel(static::$app);
        static::$originalAllowRegistration = Setting::get('general', 'allow_registration', null);
    }

    public static function tearDownAfterClass(): void
    {
        Setting::set('general', 'allow_registration', static::$originalAllowRegistration ?? 1, 'bool');
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
    }

    private function request(string $method, string $uri, array $get = [], array $post = [], string $scriptName = '/index.php'): Response
    {
        return static::$kernel->handle(new Request(
            get: $get,
            post: $post,
            server: [
                'REQUEST_METHOD' => $method,
                'REQUEST_URI'    => $uri,
                'SCRIPT_NAME'    => $scriptName,
                'HTTP_HOST'      => 'favorite-cms.local',
            ]
        ));
    }

    public function testLoginScreenUsesSharedShellWithAccessibleFields(): void
    {
        $response = $this->request('GET', '/admin/login');
        $html = $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('class="fc-auth"', $html);
        $this->assertStringContainsString('<h1 class="fc-auth__title" id="auth-title">Log in</h1>', $html);
        $this->assertStringContainsString('<label class="fc-label" for="login">', $html);
        $this->assertStringContainsString('<label class="fc-label" for="password">', $html);
        $this->assertStringContainsString('data-toggle-password="password"', $html);
        $this->assertStringContainsString('action="/admin/login"', $html);
        $this->assertStringContainsString('name="_token" value="' . self::TOKEN . '"', $html);
        $this->assertStringContainsString('href="/register"', $html);
        $this->assertStringContainsString('href="/resend-verification"', $html);
        $this->assertStringNotContainsString('style="', $html, 'Auth screens use semantic classes instead of inline styles');
    }

    public function testAuthLinksAndFormsAreBasePathAwareInSubdirectory(): void
    {
        $returnPath = '/post/subdirectory-article#comments';
        $encoded = rawurlencode($returnPath);

        $login = $this->request('GET', '/cms/admin/login', ['redirect' => $returnPath], [], '/cms/index.php')->getContent();
        $this->assertStringContainsString('action="/cms/admin/login"', $login);
        $this->assertStringContainsString('name="redirect" value="' . $returnPath . '"', $login);
        $this->assertStringContainsString('href="/cms/register?redirect=' . $encoded . '"', $login);
        $this->assertStringContainsString('href="/cms/resend-verification"', $login);
        $this->assertStringContainsString('href="/cms/"', $login);
        $this->assertStringNotContainsString('action="/admin/login"', $login);
        $this->assertStringNotContainsString('href="/register', $login);

        $register = $this->request('GET', '/cms/register', ['redirect' => $returnPath], [], '/cms/index.php')->getContent();
        $this->assertStringContainsString('action="/cms/register"', $register);
        $this->assertStringContainsString('name="redirect" value="' . $returnPath . '"', $register);
        $this->assertStringContainsString('href="/cms/admin/login?redirect=' . $encoded . '"', $register);
        $this->assertStringNotContainsString('href="/admin/login', $register);

        $resend = $this->request('GET', '/cms/resend-verification', [], [], '/cms/index.php')->getContent();
        $this->assertStringContainsString('action="/cms/resend-verification"', $resend);
        $this->assertStringContainsString('href="/cms/admin/login"', $resend);

        $verify = $this->request('GET', '/cms/verify-email', ['token' => 'definitely-not-a-valid-token'], [], '/cms/index.php');
        $this->assertSame(400, $verify->getStatusCode());
        $this->assertStringContainsString('Verification failed', $verify->getContent());
        $this->assertStringContainsString('href="/cms/admin/login"', $verify->getContent());
        $this->assertStringContainsString('href="/cms/resend-verification"', $verify->getContent());
    }

    public function testSignupLinkIsHiddenOnLoginWhenRegistrationIsDisabled(): void
    {
        Setting::set('general', 'allow_registration', 0, 'bool');

        $login = $this->request('GET', '/admin/login')->getContent();
        $this->assertStringNotContainsString('/register', $login);

        $register = $this->request('GET', '/register')->getContent();
        $this->assertStringContainsString('registration is currently disabled', $register);
        $this->assertMatchesRegularExpression('/<button type="submit"[^>]*disabled/', $register);
    }

    public function testFailedLoginKeepsUsernameButNeverRendersPassword(): void
    {
        $response = $this->request('POST', '/admin/login', [], [
            '_token'   => self::TOKEN,
            'login'    => 'someone_who_does_not_exist',
            'password' => 'SuperSecretPasswordValue987',
        ]);
        $html = $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('value="someone_who_does_not_exist"', $html);
        $this->assertStringNotContainsString('SuperSecretPasswordValue987', $html);
    }

    public function testResendVerificationValidationErrorIsAccessible(): void
    {
        $response = $this->request('POST', '/resend-verification', [], [
            '_token' => self::TOKEN,
            'email'  => 'not-an-email',
        ]);
        $html = $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('Please enter a valid email address.', $html);
        $this->assertStringContainsString('<label class="fc-label" for="email">', $html);
    }
}
