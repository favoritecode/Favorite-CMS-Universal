<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Services;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Services\Update\ExtensionReleaseDiscovery;
use PHPUnit\Framework\TestCase;

class ExtensionReleaseDiscoveryTest extends TestCase
{
    protected Application $app;

    protected function setUp(): void
    {
        $this->app = new Application();
    }

    public function testFindLatestReleaseWithPrefixTag(): void
    {
        $discovery = new ExtensionReleaseDiscovery($this->app);

        $releases = [
            [
                'tag_name'     => 'v1.0.1-mock-plugin',
                'name'         => 'Mock Plugin v1.0.1',
                'body'         => 'SHA-256: `abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890`',
                'published_at' => '2026-09-18T10:00:00Z',
                'assets'       => [
                    [
                        'name'                 => 'mock-plugin-v1.0.1.zip',
                        'size'                 => 102400,
                        'browser_download_url' => 'https://example.com/mock-plugin-v1.0.1.zip',
                    ],
                ],
            ],
            [
                'tag_name'     => 'v1.0.0-mock-plugin',
                'name'         => 'Mock Plugin v1.0.0',
                'published_at' => '2026-09-01T10:00:00Z',
                'assets'       => [],
            ],
        ];

        $matched = $discovery->findLatestReleaseForExtension('mock-plugin', $releases);
        $this->assertNotNull($matched);
        $this->assertSame('1.0.1', $matched['version']);
        $this->assertSame('v1.0.1-mock-plugin', $matched['tag_name']);
        $this->assertSame('https://example.com/mock-plugin-v1.0.1.zip', $matched['download_url']);
        $this->assertSame('abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890', $matched['sha256']);
    }

    public function testFindLatestReleaseWithSuffixTag(): void
    {
        $discovery = new ExtensionReleaseDiscovery($this->app);

        $releases = [
            [
                'tag_name'     => 'favorite-web-v1.2.0',
                'name'         => 'Favorite Web v1.2.0',
                'body'         => '',
                'published_at' => '2026-09-18T10:00:00Z',
                'assets'       => [
                    [
                        'name'                 => 'Favorite-Web-Official.zip',
                        'size'                 => 524288,
                        'browser_download_url' => 'https://example.com/favorite-web.zip',
                    ],
                ],
            ],
        ];

        $matched = $discovery->findLatestReleaseForExtension('favorite-web', $releases);
        $this->assertNotNull($matched);
        $this->assertSame('1.2.0', $matched['version']);
        $this->assertSame('favorite-web-v1.2.0', $matched['tag_name']);
        $this->assertSame('https://example.com/favorite-web.zip', $matched['download_url']);
    }

    public function testUninstalledExtensionsAreStrictlyIgnored(): void
    {
        // Subclass to inject controlled installed extensions and releases
        $discovery = new class($this->app) extends ExtensionReleaseDiscovery {
            public array $mockInstalled = [];
            public array $mockReleases = [];

            public function getInstalledExtensions(): array
            {
                return $this->mockInstalled;
            }

            protected function fetchReleasesForRepo(string $repo): ?array
            {
                return $this->mockReleases;
            }
        };

        // Only 'sample-plugin' is installed
        $discovery->mockInstalled = [
            'plugin:sample-plugin' => [
                'id'            => 'sample-plugin',
                'type'          => 'plugin',
                'name'          => 'Sample Plugin',
                'version'       => '1.0.0',
                'update_source' => 'favoritecode/Favorite-CMS-Assets',
            ],
        ];

        // Releases include updates for other extensions NOT installed (e.g. uninstalled-plugin, uninstalled-theme)
        $discovery->mockReleases = [
            [
                'tag_name' => 'v2.0.0-uninstalled-plugin',
                'name'     => 'Uninstalled Plugin v2.0.0',
                'assets'   => [],
            ],
            [
                'tag_name' => 'v3.0.0-uninstalled-theme',
                'name'     => 'Uninstalled Theme v3.0.0',
                'assets'   => [],
            ],
            [
                'tag_name' => 'v1.1.0-sample-plugin',
                'name'     => 'Sample Plugin v1.1.0',
                'assets'   => [
                    [
                        'name'                 => 'sample-plugin.zip',
                        'browser_download_url' => 'https://example.com/sample-plugin.zip',
                        'size'                 => 1024,
                    ],
                ],
            ],
        ];

        $updates = $discovery->checkUpdates(true);

        $this->assertCount(1, $updates);
        $this->assertSame('sample-plugin', $updates[0]['id']);
        $this->assertSame('1.1.0', $updates[0]['available_version']);
        $this->assertSame('1.0.0', $updates[0]['installed_version']);

        // Assert uninstalled extensions were NEVER returned
        $updateIds = array_column($updates, 'id');
        $this->assertNotContains('uninstalled-plugin', $updateIds);
        $this->assertNotContains('uninstalled-theme', $updateIds);
    }

    public function testNoUpdatesWhenInstalledVersionIsCurrentOrNewer(): void
    {
        $discovery = new class($this->app) extends ExtensionReleaseDiscovery {
            public array $mockInstalled = [];
            public array $mockReleases = [];

            public function getInstalledExtensions(): array
            {
                return $this->mockInstalled;
            }

            protected function fetchReleasesForRepo(string $repo): ?array
            {
                return $this->mockReleases;
            }
        };

        $discovery->mockInstalled = [
            'plugin:current-plugin' => [
                'id'            => 'current-plugin',
                'type'          => 'plugin',
                'name'          => 'Current Plugin',
                'version'       => '1.5.0',
                'update_source' => 'favoritecode/Favorite-CMS-Assets',
            ],
        ];

        // Release is equal to or older than installed
        $discovery->mockReleases = [
            [
                'tag_name' => 'v1.5.0-current-plugin',
                'assets'   => [],
            ],
            [
                'tag_name' => 'v1.4.0-current-plugin',
                'assets'   => [],
            ],
        ];

        $updates = $discovery->checkUpdates(true);
        $this->assertEmpty($updates);
    }

    public function testEmptyResultWhenNoExtensionsInstalled(): void
    {
        $discovery = new class($this->app) extends ExtensionReleaseDiscovery {
            public function getInstalledExtensions(): array
            {
                return [];
            }
        };

        $updates = $discovery->checkUpdates(true);
        $this->assertEmpty($updates);
    }
}

