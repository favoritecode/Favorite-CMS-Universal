<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Rendering;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Rendering\BackToTop;
use FavoriteCMS\Rendering\Engine;
use PHPUnit\Framework\TestCase;

class BackToTopTest extends TestCase
{
    public function testRenderOutputsAccessibleButtonAndSvg(): void
    {
        $html = BackToTop::render();

        $this->assertNotEmpty($html);
        $this->assertStringContainsString('<button type="button" class="cms-back-to-top"', $html);
        $this->assertStringContainsString('id="cms-back-to-top"', $html);
        $this->assertStringContainsString('aria-label="Back to top"', $html);
        $this->assertStringContainsString('hidden', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('viewBox="0 0 24 24"', $html);
    }

    public function testRenderIncludesThemeAwareStylesAndReducedMotion(): void
    {
        $html = BackToTop::render();

        $this->assertStringContainsString('--cms-btt-bg', $html);
        $this->assertStringContainsString('--surface', $html);
        $this->assertStringContainsString('--accent', $html);
        $this->assertStringContainsString('prefers-color-scheme: dark', $html);
        $this->assertStringContainsString('[data-theme="dark"]', $html);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $html);
        $this->assertStringContainsString('safe-area-inset-bottom', $html);
    }

    public function testRenderIncludesDesktopStickySidebarStyles(): void
    {
        $html = BackToTop::render();

        $this->assertStringContainsString('@media (min-width: 1024px)', $html);
        $this->assertStringContainsString('position: sticky', $html);
        $this->assertStringContainsString('--cms-sidebar-top', $html);
        $this->assertStringContainsString('overflow-y: auto', $html);
        $this->assertStringContainsString('overscroll-behavior: contain', $html);
    }

    public function testRenderIncludesLightweightScrollScript(): void
    {
        $html = BackToTop::render();

        $this->assertStringContainsString('<script>', $html);
        $this->assertStringContainsString('requestAnimationFrame', $html);
        $this->assertStringContainsString('scrollThreshold', $html);
        $this->assertStringContainsString('scrollTo', $html);
        $this->assertStringContainsString('prefers-reduced-motion', $html);
    }

    public function testGlobalHelperFunctionExistsAndMatches(): void
    {
        $this->assertTrue(function_exists('cms_back_to_top'));
        $this->assertSame(BackToTop::render(), cms_back_to_top());
    }

    public function testEngineInjectsBackToTopBeforeClosingBody(): void
    {
        $app = new Application();
        $engine = new Engine($app);

        $tempDir = sys_get_temp_dir() . '/fcms_btt_test_' . bin2hex(random_bytes(4));
        mkdir($tempDir, 0777, true);
        $tplPath = $tempDir . '/test_page.php';
        file_put_contents($tplPath, '<!DOCTYPE html><html><head><title>Test</title></head><body><h1>Content</h1></body></html>');

        Engine::addTemplatePath($tempDir);

        try {
            $output = $engine->render('test_page');
            $this->assertStringContainsString('class="cms-back-to-top"', $output);
            $this->assertStringContainsString('</body>', $output);
            // Verify it was injected before </body>
            $bttPos = strpos($output, 'class="cms-back-to-top"');
            $bodyPos = strpos($output, '</body>');
            $this->assertLessThan($bodyPos, $bttPos);
        } finally {
            @unlink($tplPath);
            @rmdir($tempDir);
        }
    }

    public function testEngineDoesNotDuplicateBackToTopIfAlreadyPresent(): void
    {
        $app = new Application();
        $engine = new Engine($app);

        $tempDir = sys_get_temp_dir() . '/fcms_btt_dup_test_' . bin2hex(random_bytes(4));
        mkdir($tempDir, 0777, true);
        $tplPath = $tempDir . '/existing_btt.php';
        file_put_contents($tplPath, '<!DOCTYPE html><html><body><button class="cms-back-to-top">Custom</button></body></html>');

        Engine::addTemplatePath($tempDir);

        try {
            $output = $engine->render('existing_btt');
            $this->assertSame(1, substr_count($output, 'cms-back-to-top'));
        } finally {
            @unlink($tplPath);
            @rmdir($tempDir);
        }
    }
}

