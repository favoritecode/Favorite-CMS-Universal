<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Services\Update\MaintenanceMode;

class MaintenanceModeTest extends TestCase
{
    protected string $tempDir;
    protected MaintenanceMode $maintenance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/fvcms_maint_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir . '/storage', 0775, true);
        $this->maintenance = new MaintenanceMode($this->tempDir);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $this->maintenance->disable();
        $this->removeDir($this->tempDir);
        $_SESSION = [];
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

    public function testEnableCreatesLockFileWithMetadata(): void
    {
        $this->assertFalse($this->maintenance->isActive());

        $result = $this->maintenance->enable([
            'target_version' => '1.0.10',
            'phase'          => 'TESTING',
        ]);

        $this->assertTrue($result);
        $this->assertTrue($this->maintenance->isActive());
        $this->assertFileExists($this->maintenance->lockPath());

        $meta = $this->maintenance->getMetadata();
        $this->assertIsArray($meta);
        $this->assertEquals('1.0.10', $meta['target_version']);
        $this->assertEquals('TESTING', $meta['phase']);
        $this->assertNotEmpty($meta['token']);
        $this->assertNotEmpty($meta['enabled_at']);
    }

    public function testDisableRemovesLockFile(): void
    {
        $this->maintenance->enable(['target_version' => '1.0.10']);
        $this->assertTrue($this->maintenance->isActive());

        $disabled = $this->maintenance->disable();
        $this->assertTrue($disabled);
        $this->assertFalse($this->maintenance->isActive());
        $this->assertFileDoesNotExist($this->maintenance->lockPath());
    }

    public function testIsBypassedWithSessionToken(): void
    {
        $this->maintenance->enable(['target_version' => '1.0.10']);
        $meta = $this->maintenance->getMetadata();

        $req = Request::create('GET', '/');

        // Session token was automatically set in enable()
        $this->assertTrue($this->maintenance->isBypassed($req));

        // Clear session token
        unset($_SESSION['_maintenance_bypass_token']);
        $this->assertFalse($this->maintenance->isBypassed($req));
    }

    public function testIsBypassedWithQueryToken(): void
    {
        $this->maintenance->enable(['target_version' => '1.0.10']);
        $meta = $this->maintenance->getMetadata();
        unset($_SESSION['_maintenance_bypass_token']);

        // Request with correct bypass token
        $reqValid = Request::create('GET', '/?bypass_token=' . $meta['token']);
        $this->assertTrue($this->maintenance->isBypassed($reqValid));

        // Reset session bypass before testing invalid query token
        unset($_SESSION['_maintenance_bypass_token']);
        $reqInvalid = Request::create('GET', '/?bypass_token=wrong_token');
        $this->assertFalse($this->maintenance->isBypassed($reqInvalid));
    }

    public function testIsBypassedForAdminOnUpdateRoutes(): void
    {
        $this->maintenance->enable(['target_version' => '1.0.10']);
        unset($_SESSION['_maintenance_bypass_token']);

        $_SESSION['auth_user_id'] = 1;
        $_SESSION['auth_user_role'] = 'administrator';

        $reqAdmin = Request::create('GET', '/admin/updates');
        $this->assertTrue($this->maintenance->isBypassed($reqAdmin));

        $reqPublic = Request::create('GET', '/blog/post-1');
        $this->assertFalse($this->maintenance->isBypassed($reqPublic));
    }

    public function testRenderResponseReturns503WithRetryAfter(): void
    {
        $this->maintenance->enable(['target_version' => '1.0.10']);
        $req = Request::create('GET', '/');

        $response = $this->maintenance->renderResponse($req);

        $this->assertEquals(503, $response->getStatusCode());
        $this->assertEquals('300', $response->getHeader('Retry-After'));
        $content = $response->getContent();
        $this->assertStringContainsString('Maintenance in Progress', $content);
        $this->assertStringContainsString('503 Service Unavailable', $content);
    }
}
