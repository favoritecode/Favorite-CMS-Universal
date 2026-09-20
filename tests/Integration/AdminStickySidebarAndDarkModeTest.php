<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use PHPUnit\Framework\TestCase;

class AdminStickySidebarAndDarkModeTest extends TestCase
{
    private string $layoutContent;

    protected function setUp(): void
    {
        parent::setUp();
        $layoutFile = APP_ROOT . '/resources/views/admin/layout.php';
        $this->assertFileExists($layoutFile);
        $this->layoutContent = (string)file_get_contents($layoutFile);
    }

    public function testLayoutContainsSemanticLinkTokens(): void
    {
        $this->assertStringContainsString('--admin-link: #2563eb;', $this->layoutContent);
        $this->assertStringContainsString('--admin-link-hover: #1d4ed8;', $this->layoutContent);
        $this->assertStringContainsString('--admin-link: #60a5fa;', $this->layoutContent);
        $this->assertStringContainsString('--admin-link-hover: #93c5fd;', $this->layoutContent);
    }

    public function testLayoutContainsRowTitleClass(): void
    {
        $this->assertStringContainsString('.row-title {', $this->layoutContent);
        $this->assertStringContainsString('color: var(--admin-text-heading);', $this->layoutContent);
        $this->assertStringContainsString('.row-title:hover', $this->layoutContent);
    }

    public function testLayoutContainsStickyAdminSidebar(): void
    {
        $this->assertStringContainsString('.wp-sidebar {', $this->layoutContent);
        $this->assertStringContainsString('position: sticky;', $this->layoutContent);
        $this->assertStringContainsString('top: 42px;', $this->layoutContent);
        $this->assertStringContainsString('height: calc(100vh - 42px);', $this->layoutContent);
        $this->assertStringContainsString('overflow-y: auto;', $this->layoutContent);
        $this->assertStringContainsString('overscroll-behavior: contain;', $this->layoutContent);
    }

    public function testLayoutPreservesMobileDrawerBehavior(): void
    {
        $this->assertStringContainsString('@media (max-width: 782px)', $this->layoutContent);
        $this->assertStringContainsString('position: fixed;', $this->layoutContent);
        $this->assertStringContainsString('left: -240px;', $this->layoutContent);
    }

    public function testLayoutContainsUniversalDarkModeColorOverrides(): void
    {
        $this->assertStringContainsString('[data-admin-theme="dark"] [style*="color: #0f172a"]', $this->layoutContent);
        $this->assertStringContainsString('[data-admin-theme="dark"] [style*="color: #1d2327"]', $this->layoutContent);
        $this->assertStringContainsString('[data-admin-theme="dark"] [style*="color: #334155"]', $this->layoutContent);
        $this->assertStringContainsString('[data-admin-theme="dark"] [style*="color: #475569"]', $this->layoutContent);
        $this->assertStringContainsString('[data-admin-theme="dark"] .row-title', $this->layoutContent);
        $this->assertStringContainsString('[data-admin-theme="dark"] .row-actions a', $this->layoutContent);
        $this->assertStringContainsString('[data-admin-theme="dark"] ul.subsubsub a', $this->layoutContent);
        $this->assertStringContainsString('[data-admin-theme="dark"] ::placeholder', $this->layoutContent);
    }

    public function testViewsUseRowTitleInsteadOfHardcodedColors(): void
    {
        $postsIndex = (string)file_get_contents(APP_ROOT . '/resources/views/admin/posts/index.php');
        $this->assertStringContainsString('class="row-title"', $postsIndex);
        $this->assertStringNotContainsString('style="color: #1d2327; font-size: 14px; font-weight: 600;"', $postsIndex);

        $pagesIndex = (string)file_get_contents(APP_ROOT . '/resources/views/admin/pages/index.php');
        $this->assertStringContainsString('class="row-title"', $pagesIndex);
        $this->assertStringNotContainsString('style="color: #1d2327; font-size: 14px;"', $pagesIndex);

        $usersIndex = (string)file_get_contents(APP_ROOT . '/resources/views/admin/users/index.php');
        $this->assertStringContainsString('class="row-title"', $usersIndex);
        $this->assertStringNotContainsString('style="font-size: 13.5px; color: #1d2327;"', $usersIndex);

        $dashboard = (string)file_get_contents(APP_ROOT . '/resources/views/admin/dashboard.php');
        $this->assertStringContainsString('color: var(--admin-text-heading);', $dashboard);
    }

    public function testVersionMetadataIsSynchronizedTo100(): void
    {
        $bootstrap = (string)file_get_contents(APP_ROOT . '/bootstrap.php');
        $this->assertStringContainsString("define('APP_VERSION', '1.0.0');", $bootstrap);

        require_once APP_ROOT . '/bootstrap.php';
        $this->assertSame('1.0.0', constant('APP_VERSION'));

        $appConfig = require APP_ROOT . '/config/app.php';
        $this->assertSame('1.0.0', $appConfig['version']);

        $composer = json_decode((string)file_get_contents(APP_ROOT . '/composer.json'), true);
        $this->assertSame('1.0.0', $composer['version'] ?? null);

        $readme = (string)file_get_contents(APP_ROOT . '/README.txt');
        $this->assertStringContainsString('Favorite CMS Universal - Version 1.0.0', $readme);

        $changelog = (string)file_get_contents(APP_ROOT . '/CHANGELOG.md');
        $this->assertStringContainsString('## [1.0.0] - 2026-09-21', $changelog);
    }
}
