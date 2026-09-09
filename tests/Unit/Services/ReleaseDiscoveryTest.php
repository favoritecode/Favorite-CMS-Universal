<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use FavoriteCMS\Services\Update\ReleaseDiscovery;

class ReleaseDiscoveryTest extends TestCase
{
    protected string $tempDir;
    protected string $cacheFile;
    protected ReleaseDiscovery $discovery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/fvcms_disc_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir . '/storage/cache', 0775, true);
        $this->cacheFile = $this->tempDir . '/storage/cache/update_release.json';
        $this->discovery = new ReleaseDiscovery($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    protected function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        if (!$items) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $p = $dir . '/' . $item;
            is_dir($p) ? $this->removeDir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    public function testCheckUsesFreshLocalCache(): void
    {
        $cachedPayload = [
            'checked_at'       => date('c'),
            'current_version'  => '1.0.10',
            'update_available' => true,
            'latest_version'   => '1.0.11',
            'tag_name'         => 'v1.0.11',
            'release_name'     => 'Favorite CMS Universal v1.0.11',
            'release_notes'    => 'Improvements to core updates',
            'published_at'     => date('c'),
            'release_url'      => 'https://github.com/favoritecode/Favorite-CMS-Universal/releases/tag/v1.0.11',
            'download_url'     => 'https://github.com/favoritecode/Favorite-CMS-Universal/releases/download/v1.0.11/Favorite-CMS-Universal.zip',
            'package_name'     => 'Favorite-CMS-Universal.zip',
            'package_size'     => 1234567,
            'sha256'           => str_repeat('b', 64),
            'cached'           => false,
            'error'            => null,
        ];

        file_put_contents($this->cacheFile, json_encode($cachedPayload));

        $result = $this->discovery->check(false);

        $this->assertTrue($result['cached']);
        $this->assertEquals('1.0.11', $result['latest_version']);
        $this->assertTrue($result['update_available']);
    }

    public function testCheckHandlesNetworkFailureGracefully(): void
    {
        // When testing in isolated environment or with invalid route, check() does not throw exception
        $result = $this->discovery->check(true);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('current_version', $result);
        $this->assertArrayHasKey('latest_version', $result);
        $this->assertArrayHasKey('update_available', $result);
    }
}

