<?php
declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use FavoriteCMS\Core\{Application, Database, Hook, Kernel, Migrator, Request, Response};
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Services\PasswordResetService;
use PHPUnit\Framework\TestCase;

class PasswordResetTest extends TestCase
{
    private static Application $app;
    private static Database $db;
    private int $id;
    private string $email;
    private array $mail = [];
    private mixed $siteUrl;

    public static function setUpBeforeClass(): void
    {
        self::$app = require APP_ROOT . '/bootstrap.php';
        self::$app->setInstalled(true);
        self::$db = self::$app->make(Database::class);
        (new Migrator(self::$db))->migrate(APP_ROOT . '/database/migrations');
    }

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION = ['_token' => 'reset-test-csrf'];
        unset($GLOBALS['favorite_cms_base_path']);
        $this->siteUrl = Setting::get('general', 'site_url', '');
        Setting::set('general', 'site_url', 'https://example.com/cms');
        $name = 'pw_reset_' . bin2hex(random_bytes(6));
        $this->email = $name . '@example.com';
        $this->id = self::$db->insert('users', [
            'username' => $name, 'name' => 'Reset Test', 'email' => $this->email,
            'password' => password_hash('OldPassword123', PASSWORD_DEFAULT), 'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Hook::addFilter('pre_send_password_reset_email', function ($handled, $message) {
            $this->mail[] = $message;
            return true;
        }, 10, 2);
    }

    protected function tearDown(): void
    {
        Hook::removeFilter('pre_send_password_reset_email');
        self::$db->delete('user_roles', ['user_id' => $this->id]);
        self::$db->delete('password_resets', ['user_id' => $this->id]);
        self::$db->delete('users', ['id' => $this->id]);
        Setting::set('general', 'site_url', $this->siteUrl);
        $_SESSION = [];
        unset($GLOBALS['favorite_cms_base_path']);
    }

    private function request(string $path, array $post = [], string $method = 'POST'): Response
    {
        return (new Kernel(self::$app))->handle(new Request(post: $post, server: [
            'REQUEST_METHOD' => $method, 'REQUEST_URI' => '/cms' . $path,
            'SCRIPT_NAME' => '/cms/index.php', 'HTTP_HOST' => 'untrusted.example',
            'REMOTE_ADDR' => 'reset-test-' . $this->id,
        ]));
    }

    private function issueToken(): string
    {
        (new PasswordResetService(self::$db))->request($this->email);
        $this->assertNotEmpty($this->mail);
        preg_match('/https:\/\/example\.com\/cms\/reset-password#token=([a-f0-9]{64})/', end($this->mail)['message'], $match);
        $this->assertCount(2, $match);
        return $match[1];
    }

    public function testSingleUseHashedTokenChangesPasswordAndInvalidatesSessions(): void
    {
        $token = $this->issueToken();
        $row = self::$db->selectOne('SELECT * FROM password_resets WHERE user_id = ?', [$this->id]);
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertGreaterThan(gmdate('Y-m-d H:i:s'), $row->expires_at);
        $this->assertTrue((new PasswordResetService(self::$db))->reset($token, 'NewPassword456'));
        $user = self::$db->selectOne('SELECT * FROM users WHERE id = ?', [$this->id]);
        $this->assertTrue(password_verify('NewPassword456', $user->password));
        $this->assertSame(1, (int)$user->auth_version);
        $this->assertFalse((new PasswordResetService(self::$db))->reset($token, 'OtherPassword789'));
        $_SESSION['auth_user_id'] = $this->id;
        $_SESSION['auth_version'] = 0;
        $response = $this->request('/admin', [], 'GET');
        $this->assertSame(302, $response->getStatusCode());
        $this->assertArrayNotHasKey('auth_user_id', $_SESSION);
    }

    public function testExpiredAndReplacedTokensCannotResetPassword(): void
    {
        $old = $this->issueToken();
        $current = $this->issueToken();
        $service = new PasswordResetService(self::$db);
        $this->assertFalse($service->reset($old, 'NewPassword456'));
        self::$db->query('UPDATE password_resets SET expires_at = ? WHERE user_id = ?', [gmdate('Y-m-d H:i:s', time() - 1), $this->id]);
        $this->assertFalse($service->reset($current, 'NewPassword456'));
        $this->assertTrue(password_verify('OldPassword123', self::$db->selectOne('SELECT password FROM users WHERE id = ?', [$this->id])->password));
    }

    public function testEmailChangeAndInactiveAccountInvalidateReset(): void
    {
        $token = $this->issueToken();
        self::$db->query('UPDATE users SET email = ? WHERE id = ?', ['changed_' . $this->email, $this->id]);
        $this->assertFalse((new PasswordResetService(self::$db))->reset($token, 'NewPassword456'));
        self::$db->query("UPDATE users SET email = ?, status = 'suspended' WHERE id = ?", [$this->email, $this->id]);
        $count = count($this->mail);
        (new PasswordResetService(self::$db))->request($this->email);
        $this->assertCount($count, $this->mail);
    }

    public function testRecoveryResponseDoesNotEnumerateAccountsAndRequiresCsrf(): void
    {
        $invalid = $this->request('/forgot-password', ['email' => $this->email]);
        $this->assertStringContainsString('Invalid security token', $invalid->getContent());
        $this->assertCount(0, $this->mail);
        $known = $this->request('/forgot-password', ['_token' => 'reset-test-csrf', 'email' => $this->email]);
        $unknown = $this->request('/forgot-password', ['_token' => 'reset-test-csrf', 'email' => 'absent_' . $this->email]);
        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($known->getContent(), $unknown->getContent());
        $this->assertStringContainsString('action="/cms/forgot-password"', $known->getContent());
        $this->assertSame('no-store', $known->getHeader('Cache-Control'));
        $this->assertCount(1, $this->mail);
    }

    public function testResetPostRequiresCsrfRetainsTokenForValidationAndNeverRendersSecrets(): void
    {
        $token = $this->issueToken();
        $fields = ['reset_token' => $token, 'password' => 'NewPassword456', 'password_confirm' => 'NewPassword456'];
        $denied = $this->request('/reset-password', $fields);
        $this->assertStringContainsString('Invalid security token', $denied->getContent());
        $validation = $this->request('/reset-password', array_merge($fields, ['_token' => 'reset-test-csrf', 'password_confirm' => 'DifferentPassword789']));
        $this->assertSame(200, $validation->getStatusCode());
        $this->assertSame($token, $_SESSION['_password_reset_token']);
        $this->assertStringNotContainsString($token, $validation->getContent());
        unset($fields['reset_token']);
        $success = $this->request('/reset-password', $fields + ['_token' => 'reset-test-csrf']);
        $this->assertSame(302, $success->getStatusCode());
        $this->assertSame('/cms/admin/login', $success->getHeader('Location'));
        $this->assertStringNotContainsString($token, $denied->getContent());
        $this->assertStringNotContainsString('NewPassword456', $denied->getContent());
        $this->assertArrayNotHasKey('auth_user_id', $_SESSION);
        $this->assertArrayNotHasKey('_password_reset_token', $_SESSION);
    }

    public function testAdminMutationsRejectGetAndMissingCsrfWithoutChangingState(): void
    {
        $role = self::$db->selectOne("SELECT id FROM roles WHERE slug = 'admin'");
        self::$db->insert('user_roles', ['user_id' => $this->id, 'role_id' => $role->id]);
        $_SESSION['auth_user_id'] = $this->id;
        foreach (['/admin/plugins/activate', '/admin/plugins/deactivate', '/admin/plugins/upload', '/admin/plugins/delete', '/admin/themes/activate', '/admin/themes/upload', '/admin/themes/delete', '/admin/menus/create', '/admin/widgets/store', '/admin/customize/save', '/admin/settings/update', '/admin/posts/trash'] as $path) {
            $this->assertSame(405, $this->request($path, [], 'GET')->getStatusCode(), $path);
            $this->assertSame(403, $this->request($path)->getStatusCode(), $path);
        }
        $this->assertSame(200, $this->request('/admin/plugins', [], 'GET')->getStatusCode());
        $html = $this->request('/admin/themes', [], 'GET')->getContent();
        $this->assertStringContainsString('id="core-action-form"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertSame(403, $this->request('/logout')->getStatusCode());
        $this->assertSame($this->id, $_SESSION['auth_user_id']);
    }
}
