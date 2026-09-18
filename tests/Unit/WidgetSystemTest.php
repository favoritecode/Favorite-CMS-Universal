<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Widgets\AbstractWidget;
use FavoriteCMS\Widgets\WidgetInstanceManager;
use FavoriteCMS\Widgets\WidgetRegistry;
use PHPUnit\Framework\TestCase;

class CustomTestPluginWidget extends AbstractWidget
{
    protected string $id = 'test_plugin_widget';
    protected string $name = 'Plugin Test Widget';
    protected string $description = 'A custom test widget registered by a plugin.';
    protected string $category = 'Custom';

    public function getSchema(): array
    {
        return [
            'headline' => ['type' => 'text', 'label' => 'Headline', 'default' => 'Hello Plugin'],
        ];
    }

    public function render(array $settings = [], array $args = []): string
    {
        $settings = $this->resolveSettings($settings);
        return '<div class="plugin-widget-content">' . htmlspecialchars((string)$settings['headline'], ENT_QUOTES, 'UTF-8') . '</div>';
    }
}

class WidgetSystemTest extends TestCase
{
    protected static Database $db;
    protected WidgetRegistry $registry;
    protected WidgetInstanceManager $instanceManager;

    public static function setUpBeforeClass(): void
    {
        $app = require APP_ROOT . '/bootstrap.php';
        $app->setInstalled(true);
        static::$db = $app->make(Database::class);
    }

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->registry = WidgetRegistry::getInstance();
        $this->instanceManager = new WidgetInstanceManager($this->registry);
    }

    public function testCoreWidgetsAreDiscoveredAndBooted(): void
    {
        $widgets = $this->registry->all();
        $this->assertNotEmpty($widgets);

        $expectedIds = [
            'search',
            'recent_posts',
            'categories',
            'tags',
            'nav_menu',
            'pages',
            'custom_html',
            'image',
            'featured_post',
            'recent_comments',
        ];

        foreach ($expectedIds as $id) {
            $this->assertTrue($this->registry->has($id), "Core widget '{$id}' should be registered.");
            $widget = $this->registry->get($id);
            $this->assertNotNull($widget);
            $this->assertNotEmpty($widget->getName());
            $this->assertNotEmpty($widget->getCategory());
            $this->assertIsArray($widget->getSchema());
        }
    }

    public function testPluginCanRegisterCustomWidget(): void
    {
        register_widget(new CustomTestPluginWidget());
        $this->assertTrue($this->registry->has('test_plugin_widget'));

        $w = $this->registry->get('test_plugin_widget');
        $this->assertSame('Plugin Test Widget', $w->getName());
        $this->assertSame('Custom', $w->getCategory());

        $rendered = $w->render(['headline' => 'Antigravity Dynamic Widget']);
        $this->assertStringContainsString('Antigravity Dynamic Widget', $rendered);
    }

    public function testCreateInstanceAndRetrieve(): void
    {
        $testTheme = 'test_theme_' . bin2hex(random_bytes(3));
        $instanceId = $this->instanceManager->createInstance('search', 'sidebar-primary', [
            'title'       => 'Custom Search Box',
            'placeholder' => 'Find something...',
        ], $testTheme);

        $this->assertNotEmpty($instanceId);
        $instance = $this->instanceManager->getInstance($instanceId, $testTheme);

        $this->assertNotNull($instance);
        $this->assertSame('search', $instance['widget_id']);
        $this->assertSame('sidebar-primary', $instance['region_id']);
        $this->assertSame('Custom Search Box', $instance['settings']['title']);
        $this->assertSame('Find something...', $instance['settings']['placeholder']);

        // Check region list includes instance
        $regionIds = $this->instanceManager->getRegionInstanceIds('sidebar-primary', $testTheme);
        $this->assertContains($instanceId, $regionIds);

        // Clean up
        $this->instanceManager->deleteInstance($instanceId, $testTheme);
    }

    public function testMultipleInstancesOfTheSameWidgetType(): void
    {
        $testTheme = 'test_theme_' . bin2hex(random_bytes(3));

        // Instance 1: Recent Posts (News, 3 posts)
        $id1 = $this->instanceManager->createInstance('recent_posts', 'sidebar-primary', [
            'title'  => 'Latest News',
            'number' => 3,
        ], $testTheme);

        // Instance 2: Recent Posts (Tutorials, 10 posts)
        $id2 = $this->instanceManager->createInstance('recent_posts', 'sidebar-primary', [
            'title'  => 'Popular Guides',
            'number' => 10,
        ], $testTheme);

        $this->assertNotSame($id1, $id2);

        $inst1 = $this->instanceManager->getInstance($id1, $testTheme);
        $inst2 = $this->instanceManager->getInstance($id2, $testTheme);

        $this->assertSame('Latest News', $inst1['settings']['title']);
        $this->assertSame(3, $inst1['settings']['number']);

        $this->assertSame('Popular Guides', $inst2['settings']['title']);
        $this->assertSame(10, $inst2['settings']['number']);

        $regionIds = $this->instanceManager->getRegionInstanceIds('sidebar-primary', $testTheme);
        $this->assertCount(2, $regionIds);
        $this->assertSame([$id1, $id2], $regionIds);

        // Clean up
        $this->instanceManager->deleteInstance($id1, $testTheme);
        $this->instanceManager->deleteInstance($id2, $testTheme);
    }

    public function testReorderInstancesInRegion(): void
    {
        $testTheme = 'test_theme_' . bin2hex(random_bytes(3));
        $idA = $this->instanceManager->createInstance('search', 'sidebar-primary', ['title' => 'A'], $testTheme);
        $idB = $this->instanceManager->createInstance('categories', 'sidebar-primary', ['title' => 'B'], $testTheme);
        $idC = $this->instanceManager->createInstance('tags', 'sidebar-primary', ['title' => 'C'], $testTheme);

        $this->assertSame([$idA, $idB, $idC], $this->instanceManager->getRegionInstanceIds('sidebar-primary', $testTheme));

        // Invert order
        $this->instanceManager->reorderRegion('sidebar-primary', [$idC, $idB, $idA], $testTheme);
        $this->assertSame([$idC, $idB, $idA], $this->instanceManager->getRegionInstanceIds('sidebar-primary', $testTheme));

        // Clean up
        $this->instanceManager->deleteInstance($idA, $testTheme);
        $this->instanceManager->deleteInstance($idB, $testTheme);
        $this->instanceManager->deleteInstance($idC, $testTheme);
    }

    public function testMoveInstanceBetweenRegions(): void
    {
        $testTheme = 'test_theme_' . bin2hex(random_bytes(3));
        $id = $this->instanceManager->createInstance('search', 'sidebar-primary', ['title' => 'Search'], $testTheme);

        $this->assertContains($id, $this->instanceManager->getRegionInstanceIds('sidebar-primary', $testTheme));
        $this->assertNotContains($id, $this->instanceManager->getRegionInstanceIds('footer-1', $testTheme));

        // Move to footer-1
        $this->instanceManager->moveInstance($id, 'footer-1', -1, $testTheme);

        $this->assertNotContains($id, $this->instanceManager->getRegionInstanceIds('sidebar-primary', $testTheme));
        $this->assertContains($id, $this->instanceManager->getRegionInstanceIds('footer-1', $testTheme));

        $updated = $this->instanceManager->getInstance($id, $testTheme);
        $this->assertSame('footer-1', $updated['region_id']);

        // Clean up
        $this->instanceManager->deleteInstance($id, $testTheme);
    }

    public function testDuplicateInstance(): void
    {
        $testTheme = 'test_theme_' . bin2hex(random_bytes(3));
        $id = $this->instanceManager->createInstance('search', 'sidebar-primary', [
            'title'       => 'Original Search',
            'placeholder' => 'Find...',
        ], $testTheme);

        $copyId = $this->instanceManager->duplicateInstance($id, $testTheme);
        $this->assertNotNull($copyId);
        $this->assertNotSame($id, $copyId);

        $copy = $this->instanceManager->getInstance($copyId, $testTheme);
        $this->assertSame('Original Search (Copy)', $copy['settings']['title']);
        $this->assertSame('Find...', $copy['settings']['placeholder']);

        // Clean up
        $this->instanceManager->deleteInstance($id, $testTheme);
        $this->instanceManager->deleteInstance($copyId, $testTheme);
    }

    public function testVisibilityRules(): void
    {
        $inst = [
            'visibility' => ['show_on' => 'all'],
        ];
        $this->assertTrue($this->instanceManager->isInstanceVisible($inst));

        $instHome = [
            'visibility' => ['show_on' => 'home'],
        ];
        $_SERVER['REQUEST_URI'] = '/';
        $this->assertTrue($this->instanceManager->isInstanceVisible($instHome));

        $_SERVER['REQUEST_URI'] = '/post/test-article';
        $this->assertFalse($this->instanceManager->isInstanceVisible($instHome));

        $instPost = [
            'visibility' => ['show_on' => 'posts'],
        ];
        $this->assertTrue($this->instanceManager->isInstanceVisible($instPost));

        $_SERVER['REQUEST_URI'] = '/page/about-us';
        $this->assertFalse($this->instanceManager->isInstanceVisible($instPost));

        $instPage = [
            'visibility' => ['show_on' => 'pages'],
        ];
        $this->assertTrue($this->instanceManager->isInstanceVisible($instPage));
    }

    public function testHtmlWidgetSanitizesDangerousContent(): void
    {
        $htmlWidget = $this->registry->get('custom_html');
        $this->assertNotNull($htmlWidget);

        // Standard user without unfiltered_html
        $_SESSION['auth_user_id'] = 9999;
        $dangerousInput = '<p>Normal text</p><script>alert("xss")</script><iframe src="evil.com"></iframe>';

        $output = $htmlWidget->render(['content' => $dangerousInput]);
        $this->assertStringContainsString('Normal text', $output);
        $this->assertStringNotContainsString('<script', $output);
        $this->assertStringNotContainsString('<iframe', $output);
    }
}
