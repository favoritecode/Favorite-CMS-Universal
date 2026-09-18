<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Import;

use FavoriteCMS\Services\Import\Security\SsrfGuard;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SsrfGuardTest extends TestCase
{
    public function testRejectsLoopbackIpv4(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SsrfGuard::assertUrlSafe('http://127.0.0.1/image.png');
    }

    public function testRejectsLocalhostHostname(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SsrfGuard::assertUrlSafe('http://localhost/image.png');
    }

    public function testRejectsPrivateIpv4ClassA(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SsrfGuard::assertUrlSafe('http://10.10.1.5/photo.jpg');
    }

    public function testRejectsPrivateIpv4ClassB(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SsrfGuard::assertUrlSafe('http://172.16.5.20/photo.jpg');
    }

    public function testRejectsPrivateIpv4ClassC(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SsrfGuard::assertUrlSafe('http://192.168.1.100/avatar.png');
    }

    public function testRejectsAwsMetadataIp(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SsrfGuard::assertUrlSafe('http://169.254.169.254/latest/meta-data/');
    }

    public function testRejectsDisallowedScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SsrfGuard::assertUrlSafe('file:///etc/passwd');
    }

    public function testRejectsDisallowedPort(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SsrfGuard::assertUrlSafe('http://example.com:22/secret.png');
    }

    public function testRejectsDotLocalDomain(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SsrfGuard::assertUrlSafe('http://myserver.local/image.png');
    }

    public function testIsUrlSafeHelper(): void
    {
        $this->assertFalse(SsrfGuard::isUrlSafe('http://127.0.0.1/test.png'));
        $this->assertFalse(SsrfGuard::isUrlSafe('ftp://example.com/test.png'));
        $this->assertFalse(SsrfGuard::isUrlSafe('javascript:alert(1)'));
        $this->assertFalse(SsrfGuard::isUrlSafe(''));
    }
}
