<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use PHPUnit\Framework\TestCase;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Kernel;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Core\Hook;
use FavoriteCMS\Core\Router;
use FavoriteCMS\Core\AdminMenu;
use FavoriteCMS\Core\Logger;
use FavoriteCMS\Models\PluginSetting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Rendering\Engine;
use FavoriteCMS\Plugins\PluginManager;

class PluginReadinessTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db  = static::$app->make(Database::class);
    }

    protected function setUp(): void
    {
        Hook::reset();
        Router::reset();
        AdminMenu::reset();
        PluginSetting::clearCache();
    }

    /**
     * 1. Test Actions and Filters with Priorities
     */
    public function testHookActionsAndFiltersWorkWithPriorities(): void
    {
        $order = [];

        add_action('test_event', function() use (&$order) {
            $order[] = 'second';
        }, 20);

        add_action('test_event', function() use (&$order) {
            $order[] = 'first';
        }, 10);

        $this->assertTrue(has_action('test_event'));

        do_action('test_event');

        $this->assertSame(['first', 'second'], $order);

        // Filters test
        add_filter('filter_text', function(string $text) {
            return strtoupper($text);
        }, 10);

        add_filter('filter_text', function(string $text) {
            return $text . '!!!';
        }, 20);

        $this->assertTrue(has_filter('filter_text'));
        $result = apply_filters('filter_text', 'hello world');
        $this->assertSame('HELLO WORLD!!!', $result);
    }

    /**
     * 2. Test Dynamic Plugin Frontend Route Registration
     */
    public function testPluginDynamicFrontendRoutingWithParams(): void
    {
        add_route('GET', '/plugin-api/greeting/{name}', function(Request $req, string $name) {
            return Response::json(['greeting' => "Hello, {$name}!"]);
        });

        $kernel = new Kernel(static::$app);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/plugin-api/greeting/Developer']);
        $resp = $kernel->handle($req);

        $refContent = new \ReflectionProperty(Response::class, 'content');
        $refContent->setAccessible(true);
        $json = json_decode($refContent->getValue($resp), true);

        $this->assertIsArray($json);
        $this->assertSame('Hello, Developer!', $json['greeting'] ?? null);
    }

    /**
     * 3. Test Plugin Admin Menu and Custom Admin Page Registration
     */
    public function testPluginAdminMenuAndPageRegistration(): void
    {
        add_admin_menu('my-plugin-page', 'Custom Plugin Tool', '🛠️', function(Request $req) {
            return '<h2>Custom Plugin Content Area</h2>';
        }, 'manage_options');

        $page = AdminMenu::findPage('my-plugin-page');
        $this->assertNotNull($page);
        $this->assertSame('Custom Plugin Tool', $page['title']);

        // Simulate admin user session
        $admin = User::findByUsername('admin');
        $_SESSION['auth_user_id'] = (int)($admin->id ?? 1);
        $_SESSION['auth_user_name'] = $admin->username ?? 'admin';

        $kernel = new Kernel(static::$app);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/my-plugin-page']);
        $resp = $kernel->handle($req);

        $refContent = new \ReflectionProperty(Response::class, 'content');
        $refContent->setAccessible(true);
        $html = $refContent->getValue($resp);

        $this->assertStringContainsString('Custom Plugin Content Area', $html);
        $this->assertStringContainsString('Custom Plugin Tool', $html);
    }

    /**
     * 4. Test Plugin Settings Storage API
     */
    public function testPluginSettingsIsolatedCrud(): void
    {
        $pluginId = 'demo-plugin-xyz';

        // Clean up
        PluginSetting::deleteSetting($pluginId);

        set_plugin_setting($pluginId, 'api_key', 'secret_token_12345');
        set_plugin_setting($pluginId, 'options', ['enabled' => true, 'count' => 42]);

        $this->assertSame('secret_token_12345', plugin_setting($pluginId, 'api_key'));
        $options = plugin_setting($pluginId, 'options');
        $this->assertTrue($options['enabled']);
        $this->assertSame(42, $options['count']);

        $all = PluginSetting::forPlugin($pluginId);
        $this->assertArrayHasKey('api_key', $all);
        $this->assertArrayHasKey('options', $all);

        PluginSetting::deleteSetting($pluginId, 'api_key');
        $this->assertNull(plugin_setting($pluginId, 'api_key'));

        PluginSetting::deleteSetting($pluginId);
        $this->assertEmpty(PluginSetting::forPlugin($pluginId));
    }

    /**
     * 5. Test Template Override via Filter
     */
    public function testPluginTemplateResolutionAndFilterOverride(): void
    {
        $engine = new Engine(static::$app);

        // Test template_include filter
        $tempTpl = APP_ROOT . '/storage/test_override.php';
        file_put_contents($tempTpl, '<?php echo "Overridden: " . ($text ?? "none"); ?>');

        add_filter('template_include', function(?string $tplPath, string $tplName, array $data) use ($tempTpl) {
            if ($tplName === 'custom_plugin_view') {
                return $tempTpl;
            }
            return $tplPath;
        }, 10, 3);

        $rendered = $engine->render('custom_plugin_view', ['text' => 'PluginTemplate']);
        $this->assertSame('Overridden: PluginTemplate', $rendered);

        if (file_exists($tempTpl)) {
            unlink($tempTpl);
        }
    }

    /**
     * 6. Test Plugin Lifecycle Hooks
     */
    public function testPluginLifecycleEventTriggers(): void
    {
        $events = [];

        add_action('plugin.activated', function(string $id) use (&$events) {
            $events[] = "activated:{$id}";
        });

        add_action('plugin.deactivated', function(string $id) use (&$events) {
            $events[] = "deactivated:{$id}";
        });

        add_action('plugin.uninstalled', function(string $id) use (&$events) {
            $events[] = "uninstalled:{$id}";
        });

        $mgr = new PluginManager(static::$app);
        $testPluginId = 'favorite-quick-notes';

        $mgr->activatePlugin($testPluginId);
        $mgr->deactivatePlugin($testPluginId);

        $this->assertContains("activated:{$testPluginId}", $events);
        $this->assertContains("deactivated:{$testPluginId}", $events);
    }

    /**
     * 7. Test Logger Service
     */
    public function testLoggerServiceWritesToFile(): void
    {
        $testMsg = "PluginReadinessTest entry " . uniqid();
        cms_log($testMsg, 'info', ['source' => 'test']);

        $logFile = Logger::getLogFile();
        $this->assertFileExists($logFile);

        $content = file_get_contents($logFile);
        $this->assertStringContainsString($testMsg, $content);
        $this->assertStringContainsString('[INFO]', $content);
    }

    /**
     * 8. Test Super Admin Permission Bypass
     */
    public function testCurrentUserAndSuperAdminPermissionBypass(): void
    {
        $adminUser = User::findByUsername('admin');
        $this->assertNotNull($adminUser);

        // Super-admin must automatically have any permission
        $this->assertTrue($adminUser->hasPermission('manage_options'));
        $this->assertTrue($adminUser->hasPermission('custom_plugin_permission_xyz'));
    }

    /**
     * 9. Test Plugin Boot Failure Isolation
     */
    public function testPluginBootFailureIsolationDoesNotCrashSite(): void
    {
        $badPluginDir = APP_ROOT . '/plugins/test-broken-plugin';
        if (!is_dir($badPluginDir)) {
            mkdir($badPluginDir, 0775, true);
        }

        file_put_contents($badPluginDir . '/plugin.json', json_encode([
            'id'          => 'test-broken-plugin',
            'name'        => 'Broken Plugin',
            'version'     => '1.0.0',
            'entry_point' => 'plugin.php',
        ]));

        file_put_contents($badPluginDir . '/plugin.php', '<?php throw new \Exception("Broken plugin crash test"); ?>');

        $mgr = new PluginManager(static::$app);
        $mgr->activatePlugin('test-broken-plugin');

        // Boot plugins - must NOT throw exception, but isolate error
        $mgr->bootActivePlugins();

        $errors = $mgr->getBootErrors();
        $this->assertArrayHasKey('test-broken-plugin', $errors);
        $this->assertStringContainsString('Broken plugin crash test', $errors['test-broken-plugin']);

        // Clean up
        $mgr->deactivatePlugin('test-broken-plugin');
        $mgr->uninstallPlugin('test-broken-plugin');
    }
}
