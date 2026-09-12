<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * The bundled Default Theme is shipped twice: themes/default (rendered by the Engine) and
 * public/themes/default (served directly by Apache for static assets). They must never silently diverge.
 */
class DefaultThemeMirrorTest extends TestCase
{
    public function testPublicThemeMirrorMatchesSourceTheme(): void
    {
        $source = APP_ROOT . '/themes/default';
        $mirror = APP_ROOT . '/public/themes/default';

        if (!is_dir($mirror)) {
            $this->markTestSkipped('No public theme mirror in this installation.');
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        $checked = 0;
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
            $mirrorFile = $mirror . '/' . $relative;

            $this->assertFileExists($mirrorFile, "public/themes/default is missing {$relative}");
            $this->assertSame(hash_file('sha256', $file->getPathname()), hash_file('sha256', $mirrorFile), "public/themes/default/{$relative} differs from themes/default/{$relative}");
            $checked++;
        }

        $this->assertGreaterThan(0, $checked);
    }
}
