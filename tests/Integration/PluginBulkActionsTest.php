<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Kernel;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Http\Controllers\Admin\PluginController;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Plugins\PluginManager;
use PHPUnit\Framework\TestCase;

class PluginBulkActionsTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;
    protected array $createdPluginDirs = [];
    protected array $createdUsers = [];

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db = static::$app->make(Database::class);
    }

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_FILES = [];
        Setting::clearCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdPluginDirs as $dir) {
            if (is_dir($dir)) {
                $this->removeDirectory($dir);
            }
        }
        $this->createdPluginDirs = [];

        foreach ($this->createdUsers as $user) {
            try {
                $user->delete();
            } catch (\Throwable) {
            }
        }
        $this->createdUsers = [];

        Setting::clearCache();
    }

    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    protected function createTestPlugin(string $id, array $overrides = []): string
    {
        $dir = APP_ROOT . '/plugins/' . $id;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->createdPluginDirs[] = $dir;

        $manifest = array_merge([
            'id'           => $id,
            'name'         => ucfirst(str_replace('-', ' ', $id)),
            'version'      => '1.0.0',
            'description'  => 'Test Plugin for Bulk Actions',
            'author'       => 'Test Suite',
            'requires_php' => '8.1.0',
            'dependencies' => [],
            'entry_point'  => 'plugin.php',
        ], $overrides);

        file_put_contents($dir . '/plugin.json', json_encode($manifest, JSON_PRETTY_PRINT));
        file_put_contents($dir . '/plugin.php', "<?php // Test plugin {$id}\n");

        return $dir;
    }

    protected function createTestAdmin(): User
    {
        $unique = 'admin_' . bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');
        $hash = password_hash('Pass12345!', PASSWORD_DEFAULT);

        $userId = static::$db->insert('users', [
            'username'          => $unique,
            'name'              => ucfirst($unique),
            'email'             => $unique . '@example.com',
            'password'          => $hash,
            'status'            => 'active',
            'email_verified_at' => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        $role = static::$db->selectOne("SELECT id FROM `roles` WHERE `slug` = 'admin' LIMIT 1");
        if ($role) {
            static::$db->execute("INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [$userId, $role->id]);
        }

        $user = User::find($userId);
        $this->createdUsers[] = $user;
        return $user;
    }

    protected function createTestUser(string $roleSlug = 'subscriber'): User
    {
        $unique = 'user_' . bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');
        $hash = password_hash('Pass12345!', PASSWORD_DEFAULT);

        $userId = static::$db->insert('users', [
            'username'          => $unique,
            'name'              => ucfirst($unique),
            'email'             => $unique . '@example.com',
            'password'          => $hash,
            'status'            => 'active',
            'email_verified_at' => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        $role = static::$db->selectOne("SELECT id FROM `roles` WHERE `slug` = ? LIMIT 1", [$roleSlug]);
        if ($role) {
            static::$db->execute("INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [$userId, $role->id]);
        }

        $user = User::find($userId);
        $this->createdUsers[] = $user;
        return $user;
    }

    public function testPluginListRendersMasterCheckboxAndMultiSelectForm(): void
    {
        $admin = $this->createTestAdmin();
        $_SESSION['auth_user_id'] = $admin->id;

        $controller = new PluginController(static::$app);
        $response = $controller->index(new Request());

        $html = $response->getContent();
        $this->assertStringContainsString('id="plugins-bulk-form"', $html);
        $this->assertStringContainsString('data-select-all', $html);
        $this->assertStringContainsString('class="bulk-count-badge"', $html);
        $this->assertStringContainsString('class="row-checkbox"', $html);
        $this->assertStringContainsString("initAdminMultiSelect('plugins-bulk-form'", $html);
    }

    public function testBulkActivateAndDeactivatePlugins(): void
    {
        $admin = $this->createTestAdmin();
        $_SESSION['auth_user_id'] = $admin->id;
        $token = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $token;

        $idA = 'test-plugin-a-' . bin2hex(random_bytes(3));
        $idB = 'test-plugin-b-' . bin2hex(random_bytes(3));
        $this->createTestPlugin($idA);
        $this->createTestPlugin($idB);

        $controller = new PluginController(static::$app);
        $manager = new PluginManager(static::$app);

        // Ensure initially inactive
        $this->assertNotContains($idA, $manager->getActivePlugins());
        $this->assertNotContains($idB, $manager->getActivePlugins());

        // 1. Bulk Activate
        $reqActivate = new Request([], [
            '_token'      => $token,
            'bulk_action' => 'activate',
            'ids'         => [$idA, $idB],
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $controller->bulkAction($reqActivate);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertNotEmpty($_SESSION['flash_success'] ?? '');
        $this->assertStringContainsString('2 plugin(s) successfully activated', $_SESSION['flash_success']);

        $managerFresh = new PluginManager(static::$app);
        $this->assertContains($idA, $managerFresh->getActivePlugins());
        $this->assertContains($idB, $managerFresh->getActivePlugins());

        // 2. Bulk Deactivate
        $reqDeactivate = new Request([], [
            '_token'      => $token,
            'bulk_action' => 'deactivate',
            'ids'         => [$idA, $idB],
        ], ['REQUEST_METHOD' => 'POST']);

        $resDeact = $controller->bulkAction($reqDeactivate);
        $this->assertSame(302, $resDeact->getStatusCode());
        $this->assertNotEmpty($_SESSION['flash_success'] ?? '');
        $this->assertStringContainsString('2 plugin(s) successfully deactivated', $_SESSION['flash_success']);

        $managerAfter = new PluginManager(static::$app);
        $this->assertNotContains($idA, $managerAfter->getActivePlugins());
        $this->assertNotContains($idB, $managerAfter->getActivePlugins());
    }

    public function testBulkDeletePlugins(): void
    {
        $admin = $this->createTestAdmin();
        $_SESSION['auth_user_id'] = $admin->id;
        $token = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $token;

        $idA = 'test-del-a-' . bin2hex(random_bytes(3));
        $idB = 'test-del-b-' . bin2hex(random_bytes(3));
        $dirA = $this->createTestPlugin($idA);
        $dirB = $this->createTestPlugin($idB);

        $this->assertTrue(is_dir($dirA));
        $this->assertTrue(is_dir($dirB));

        $controller = new PluginController(static::$app);
        $reqDelete = new Request([], [
            '_token'      => $token,
            'bulk_action' => 'delete',
            'ids'         => [$idA, $idB],
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $controller->bulkAction($reqDelete);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringContainsString('2 plugin(s) successfully uninstalled and deleted', $_SESSION['flash_success'] ?? '');

        $this->assertFalse(is_dir($dirA));
        $this->assertFalse(is_dir($dirB));
    }

    public function testBulkActionFailsOnInvalidCsrfToken(): void
    {
        $admin = $this->createTestAdmin();
        $_SESSION['auth_user_id'] = $admin->id;
        $_SESSION['_token'] = 'valid_session_token';

        $controller = new PluginController(static::$app);
        $req = new Request([], [
            '_token'      => 'wrong_token',
            'bulk_action' => 'activate',
            'ids'         => ['some-plugin'],
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $controller->bulkAction($req);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringContainsString('CSRF', $_SESSION['flash_error'] ?? '');
    }

    public function testBulkActionFailsOnUnauthorizedUser(): void
    {
        $normalUser = $this->createTestUser('subscriber');
        $this->assertFalse($normalUser->canManagePlugins());

        $_SESSION['auth_user_id'] = $normalUser->id;
        $token = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $token;

        $controller = new PluginController(static::$app);
        $req = new Request([], [
            '_token'      => $token,
            'bulk_action' => 'activate',
            'ids'         => ['some-plugin'],
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $controller->bulkAction($req);
        $this->assertSame(403, $res->getStatusCode());
        $this->assertStringContainsString('Access Denied', $res->getContent());
    }

    public function testBulkActionFailsOnEmptySelection(): void
    {
        $admin = $this->createTestAdmin();
        $_SESSION['auth_user_id'] = $admin->id;
        $token = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $token;

        $controller = new PluginController(static::$app);

        // Empty IDs
        $reqEmptyIds = new Request([], [
            '_token'      => $token,
            'bulk_action' => 'activate',
            'ids'         => [],
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $controller->bulkAction($reqEmptyIds);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringContainsString('select at least one', $_SESSION['flash_error'] ?? '');

        // Empty Action
        $reqEmptyAction = new Request([], [
            '_token'      => $token,
            'bulk_action' => '',
            'ids'         => ['plugin-a'],
        ], ['REQUEST_METHOD' => 'POST']);

        $resAction = $controller->bulkAction($reqEmptyAction);
        $this->assertSame(302, $resAction->getStatusCode());
        $this->assertStringContainsString('select at least one', $_SESSION['flash_error'] ?? '');
    }

    public function testBulkActionHandlesInvalidAndNonExistentPluginIdsSafely(): void
    {
        $admin = $this->createTestAdmin();
        $_SESSION['auth_user_id'] = $admin->id;
        $token = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $token;

        $controller = new PluginController(static::$app);
        $req = new Request([], [
            '_token'      => $token,
            'bulk_action' => 'activate',
            'ids'         => ['non-existent-plugin-123', 'another-ghost-plugin'],
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $controller->bulkAction($req);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertNotEmpty($_SESSION['flash_error'] ?? '');
        $this->assertStringContainsString('does not exist', $_SESSION['flash_error']);
    }

    public function testBulkActionDeduplicatesIds(): void
    {
        $admin = $this->createTestAdmin();
        $_SESSION['auth_user_id'] = $admin->id;
        $token = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $token;

        $idA = 'test-dedup-' . bin2hex(random_bytes(3));
        $this->createTestPlugin($idA);

        $controller = new PluginController(static::$app);
        $req = new Request([], [
            '_token'      => $token,
            'bulk_action' => 'activate',
            'ids'         => [$idA, $idA, $idA],
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $controller->bulkAction($req);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringContainsString('1 plugin(s) successfully activated', $_SESSION['flash_success'] ?? '');
    }

    public function testBulkDeactivateRespectsDependencies(): void
    {
        $admin = $this->createTestAdmin();
        $_SESSION['auth_user_id'] = $admin->id;
        $token = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $token;

        $parent = 'dep-parent-' . bin2hex(random_bytes(3));
        $child = 'dep-child-' . bin2hex(random_bytes(3));

        $this->createTestPlugin($parent);
        $this->createTestPlugin($child, ['dependencies' => [$parent]]);

        $manager = new PluginManager(static::$app);
        $manager->activatePlugin($parent);
        $manager->activatePlugin($child);

        $this->assertContains($parent, $manager->getActivePlugins());
        $this->assertContains($child, $manager->getActivePlugins());

        $controller = new PluginController(static::$app);

        // Try to deactivate only parent while child remains active
        $req = new Request([], [
            '_token'      => $token,
            'bulk_action' => 'deactivate',
            'ids'         => [$parent],
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $controller->bulkAction($req);
        $this->assertSame(302, $res->getStatusCode());

        // Parent must NOT be deactivated
        $managerFresh = new PluginManager(static::$app);
        $this->assertContains($parent, $managerFresh->getActivePlugins());
        $this->assertStringContainsString('depends on it', $_SESSION['flash_error'] ?? '');
    }

    public function testBulkDeleteProtectsCoreOrSystemPlugins(): void
    {
        $admin = $this->createTestAdmin();
        $_SESSION['auth_user_id'] = $admin->id;
        $token = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $token;

        $coreId = 'core-plugin-' . bin2hex(random_bytes(3));
        $dir = $this->createTestPlugin($coreId, ['core' => true]);

        $controller = new PluginController(static::$app);
        $req = new Request([], [
            '_token'      => $token,
            'bulk_action' => 'delete',
            'ids'         => [$coreId],
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $controller->bulkAction($req);
        $this->assertSame(302, $res->getStatusCode());

        $this->assertTrue(is_dir($dir));
        $this->assertStringContainsString('protected system plugin', $_SESSION['flash_error'] ?? '');
    }

    public function testBulkDeletePreventsPathTraversalAndArbitraryFileDeletion(): void
    {
        $admin = $this->createTestAdmin();
        $_SESSION['auth_user_id'] = $admin->id;
        $token = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $token;

        $controller = new PluginController(static::$app);
        $req = new Request([], [
            '_token'      => $token,
            'bulk_action' => 'delete',
            'ids'         => ['../../app', '../config', '..'],
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $controller->bulkAction($req);
        $this->assertSame(302, $res->getStatusCode());
        // All sanitized to empty or rejected
        $this->assertStringContainsString('select at least one', $_SESSION['flash_error'] ?? '');

        // Verify app and config still intact
        $this->assertTrue(is_dir(APP_ROOT . '/app'));
        $this->assertTrue(is_dir(APP_ROOT . '/config'));
    }

    public function testPartialSuccessAndFailureReporting(): void
    {
        $admin = $this->createTestAdmin();
        $_SESSION['auth_user_id'] = $admin->id;
        $token = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $token;

        $validId = 'valid-plugin-' . bin2hex(random_bytes(3));
        $this->createTestPlugin($validId);

        $ghostId = 'ghost-plugin-' . bin2hex(random_bytes(3));

        $controller = new PluginController(static::$app);
        $req = new Request([], [
            '_token'      => $token,
            'bulk_action' => 'activate',
            'ids'         => [$validId, $ghostId],
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $controller->bulkAction($req);
        $this->assertSame(302, $res->getStatusCode());

        // 1 activated, 1 failed, noted in message
        $this->assertStringContainsString('1 plugin(s) activated', $_SESSION['flash_success'] ?? '');
        $this->assertStringContainsString('does not exist', $_SESSION['flash_success'] ?? '');

        $manager = new PluginManager(static::$app);
        $this->assertContains($validId, $manager->getActivePlugins());
    }
}