<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Http\Controllers\Admin\CustomizeController;
use FavoriteCMS\Http\Controllers\Admin\WidgetController;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Themes\ThemeLayoutService;
use FavoriteCMS\Themes\ThemeManager;
use FavoriteCMS\Widgets\WidgetInstanceManager;
use FavoriteCMS\Widgets\WidgetRegistry;
use PHPUnit\Framework\TestCase;

class ThemeLayoutSystemTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;
    protected ThemeLayoutService $layoutService;
    protected WidgetInstanceManager $instanceManager;

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db  = static::$app->make(Database::class);
    }

    protected function setUp(): void
    {
        $_SESSION = [
            'auth_user_id' => 1, // Admin user
            '_token'       => 'test_token',
        ];
        $this->instanceManager = new WidgetInstanceManager();
        $this->layoutService = new ThemeLayoutService(static::$app, $this->instanceManager);
    }

    public function testThemeManifestParsesRegionsAndSections(): void
    {
        $manifest = $this->layoutService->getThemeManifest('default');

        $this->assertSame('default', $manifest['id']);
        $this->assertNotEmpty($manifest['regions']);
        $this->assertNotEmpty($manifest['sections']);

        $regionIds = array_column($manifest['regions'], 'id');
        $this->assertContains('sidebar-primary', $regionIds);
        $this->assertContains('footer-1', $regionIds);
        $this->assertContains('footer-2', $regionIds);
        $this->assertContains('footer-3', $regionIds);

        $sectionIds = array_column($manifest['sections'], 'id');
        $this->assertContains('hero', $sectionIds);
        $this->assertContains('featured-posts', $sectionIds);
        $this->assertContains('latest-posts', $sectionIds);
    }

    public function testDefaultLayoutSeedingForTheme(): void
    {
        $testTheme = 'theme_' . bin2hex(random_bytes(3));
        $this->layoutService->ensureDefaultLayout($testTheme);

        $isSeeded = Setting::get("widget_seeded_{$testTheme}", 'is_seeded', false);
        $this->assertTrue($isSeeded);

        $sidebarWidgets = $this->instanceManager->getRegionInstances('sidebar-primary', $testTheme);
        $this->assertNotEmpty($sidebarWidgets);

        $widgetTypes = array_column($sidebarWidgets, 'widget_id');
        $this->assertContains('search', $widgetTypes);
        $this->assertContains('recent_posts', $widgetTypes);

        // Reset/clean up
        $this->layoutService->resetThemeLayout($testTheme);
    }

    public function testThemeCustomizerMods(): void
    {
        $testTheme = 'theme_' . bin2hex(random_bytes(3));

        // Default fallback
        $this->assertSame('right', $this->layoutService->getThemeMod('site_layout', 'right', $testTheme));

        // Update theme mod
        $this->layoutService->setThemeMod('site_layout', 'left', $testTheme);
        $this->assertSame('left', $this->layoutService->getThemeMod('site_layout', 'right', $testTheme));

        $this->layoutService->setThemeMod('accent_color', '#ff5500', $testTheme);
        $this->assertSame('#ff5500', $this->layoutService->getThemeMod('accent_color', '#0284c7', $testTheme));

        $this->layoutService->setThemeMod('site_logo_url', 'https://example.com/brand-logo.png', $testTheme);
        $this->assertSame('https://example.com/brand-logo.png', $this->layoutService->getThemeMod('site_logo_url', '', $testTheme));

        // Clean up
        $this->layoutService->resetThemeLayout($testTheme);
    }

    public function testHomepageSectionManagement(): void
    {
        $testTheme = 'theme_' . bin2hex(random_bytes(3));
        $sections = $this->layoutService->getSections($testTheme);
        $this->assertNotEmpty($sections);

        $firstSectionId = $sections[0]['id'];

        // Disable first section
        $this->layoutService->updateSection($firstSectionId, ['enabled' => false], $testTheme);
        $updated = $this->layoutService->getSections($testTheme);
        $this->assertFalse($updated[0]['enabled']);

        // Reorder sections
        $newOrder = array_reverse(array_column($sections, 'id'));
        $this->layoutService->reorderSections($newOrder, $testTheme);

        $reordered = $this->layoutService->getSections($testTheme);
        $this->assertSame($newOrder, array_column($reordered, 'id'));

        // Clean up
        $this->layoutService->resetThemeLayout($testTheme);
    }

    public function testThemeSwitchingPreservesLayoutAndSettings(): void
    {
        $themeA = 'theme_alpha_' . bin2hex(random_bytes(2));
        $themeB = 'theme_beta_' . bin2hex(random_bytes(2));

        // Configure Theme A
        $this->layoutService->setThemeMod('site_layout', 'left', $themeA);
        $idA = $this->instanceManager->createInstance('search', 'sidebar-primary', ['title' => 'Theme A Search'], $themeA);

        // Configure Theme B
        $this->layoutService->setThemeMod('site_layout', 'none', $themeB);
        $idB = $this->instanceManager->createInstance('custom_html', 'sidebar-primary', ['content' => 'Theme B Content'], $themeB);

        // Verify Theme A is preserved
        $this->assertSame('left', $this->layoutService->getThemeMod('site_layout', 'right', $themeA));
        $instA = $this->instanceManager->getInstance($idA, $themeA);
        $this->assertNotNull($instA);
        $this->assertSame('Theme A Search', $instA['settings']['title']);

        // Verify Theme B is preserved
        $this->assertSame('none', $this->layoutService->getThemeMod('site_layout', 'right', $themeB));
        $instB = $this->instanceManager->getInstance($idB, $themeB);
        $this->assertNotNull($instB);
        $this->assertSame('Theme B Content', $instB['settings']['content']);

        // Clean up
        $this->layoutService->resetThemeLayout($themeA);
        $this->layoutService->resetThemeLayout($themeB);
    }

    public function testRenderRegionOutput(): void
    {
        $testTheme = 'theme_' . bin2hex(random_bytes(3));
        $id = $this->instanceManager->createInstance('search', 'sidebar-primary', [
            'title'       => 'Articles Finder',
            'placeholder' => 'Type words...',
        ], $testTheme);

        $this->assertTrue($this->instanceManager->hasRegionWidgets('sidebar-primary', $testTheme));

        $html = $this->instanceManager->renderRegion('sidebar-primary', [], $testTheme);
        $this->assertStringContainsString('Articles Finder', $html);
        $this->assertStringContainsString('Type words...', $html);
        $this->assertStringContainsString('class="widget widget_search"', $html);

        // Clean up
        $this->layoutService->resetThemeLayout($testTheme);
    }

    public function testWidgetControllerEndpoints(): void
    {
        $ctrl = new WidgetController(static::$app);

        // 1. Store via POST
        $postReq = new Request([], [
            '_token'    => 'test_token',
            'widget_id' => 'categories',
            'region_id' => 'footer-1',
        ]);
        $resp = $ctrl->store($postReq);
        $this->assertSame(302, $resp->getStatus());

        $instances = $this->instanceManager->getRegionInstances('footer-1');
        $this->assertNotEmpty($instances);
        $lastInst = end($instances);
        $this->assertSame('categories', $lastInst['widget_id']);

        // 2. Update via POST
        $updateReq = new Request([], [
            '_token'      => 'test_token',
            'instance_id' => $lastInst['id'],
            'settings'    => ['title' => 'Updated Categories Title', 'show_count' => '1'],
            'visibility'  => ['show_on' => 'posts'],
        ]);
        $respUpdate = $ctrl->update($updateReq);
        $this->assertSame(302, $respUpdate->getStatus());

        $updatedInst = $this->instanceManager->getInstance($lastInst['id']);
        $this->assertSame('Updated Categories Title', $updatedInst['settings']['title']);
        $this->assertSame('posts', $updatedInst['visibility']['show_on']);

        // 3. Move via POST
        $moveReq = new Request([], [
            '_token'           => 'test_token',
            'instance_id'      => $lastInst['id'],
            'target_region_id' => 'footer-2',
        ]);
        $respMove = $ctrl->move($moveReq);
        $this->assertSame(302, $respMove->getStatus());

        $movedInst = $this->instanceManager->getInstance($lastInst['id']);
        $this->assertSame('footer-2', $movedInst['region_id']);

        // 4. Delete via POST
        $delReq = new Request([], [
            '_token'      => 'test_token',
            'instance_id' => $lastInst['id'],
        ]);
        $respDel = $ctrl->delete($delReq);
        $this->assertSame(302, $respDel->getStatus());

        $this->assertNull($this->instanceManager->getInstance($lastInst['id']));
    }

    public function testCustomizeControllerEndpoints(): void
    {
        $ctrl = new CustomizeController(static::$app);

        $saveReq = new Request([], [
            '_token' => 'test_token',
            'mods'   => [
                'site_layout'      => 'left',
                'accent_color'     => '#10b981',
                'footer_copyright' => 'Custom 2026 Copyright',
            ],
            'sections' => [
                'hero'           => ['enabled' => '1'],
                'featured-posts' => [], // unchecked
            ],
        ]);

        $resp = $ctrl->save($saveReq);
        $this->assertSame(302, $resp->getStatus());

        $this->assertSame('left', $this->layoutService->getThemeMod('site_layout'));
        $this->assertSame('#10b981', $this->layoutService->getThemeMod('accent_color'));
        $this->assertSame('Custom 2026 Copyright', $this->layoutService->getThemeMod('footer_copyright'));
    }
}
