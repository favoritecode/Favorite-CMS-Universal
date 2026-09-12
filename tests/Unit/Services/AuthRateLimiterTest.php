<?php
declare(strict_types=1);
namespace FavoriteCMS\Tests\Unit\Services;

use FavoriteCMS\Services\AuthRateLimiter;
use PHPUnit\Framework\TestCase;

class AuthRateLimiterTest extends TestCase
{
    public function testLimitsPersistAcrossInstancesAndExpiredWindowsReset(): void
    {
        $dir = sys_get_temp_dir() . '/cms_rate_' . bin2hex(random_bytes(6));
        try {
            $this->assertTrue((new AuthRateLimiter($dir))->allow('identity', 2));
            $this->assertTrue((new AuthRateLimiter($dir))->allow('identity', 2));
            $this->assertFalse((new AuthRateLimiter($dir))->allow('identity', 2));
            $file = $dir . '/' . hash('sha256', 'identity') . '.json';
            file_put_contents($file, json_encode(['expires' => time() - 1, 'count' => 2]));
            $this->assertTrue((new AuthRateLimiter($dir))->allow('identity', 2));
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) unlink($file);
            if (is_dir($dir)) rmdir($dir);
        }
    }
}
