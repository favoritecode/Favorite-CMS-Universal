<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit;

use FavoriteCMS\Services\UploadCapabilityService;
use PHPUnit\Framework\TestCase;

class UploadCapabilityServiceTest extends TestCase
{
    public function testParseByteStringHandlesVariousUnits(): void
    {
        $this->assertSame(1024, UploadCapabilityService::parseByteString('1K'));
        $this->assertSame(1024, UploadCapabilityService::parseByteString('1k'));
        $this->assertSame(20971520, UploadCapabilityService::parseByteString('20M'));
        $this->assertSame(134217728, UploadCapabilityService::parseByteString('128M'));
        $this->assertSame(2147483648, UploadCapabilityService::parseByteString('2G'));
        $this->assertSame(500, UploadCapabilityService::parseByteString('500'));
        $this->assertSame(500, UploadCapabilityService::parseByteString(500));
        $this->assertSame(0, UploadCapabilityService::parseByteString(''));
        $this->assertSame(0, UploadCapabilityService::parseByteString(null));
    }

    public function testFormatBytesReturnsHumanReadableStrings(): void
    {
        $this->assertSame('0 B', UploadCapabilityService::formatBytes(0));
        $this->assertSame('500 B', UploadCapabilityService::formatBytes(500));
        $this->assertSame('1 KB', UploadCapabilityService::formatBytes(1024));
        $this->assertSame('1 MB', UploadCapabilityService::formatBytes(1048576));
        $this->assertSame('20 MB', UploadCapabilityService::formatBytes(20971520));
        $this->assertSame('1.5 GB', UploadCapabilityService::formatBytes((int)(1.5 * 1024 * 1024 * 1024)));
    }

    public function testGetServerLimitsDetectsPhpConfiguration(): void
    {
        $service = new UploadCapabilityService();
        $limits = $service->getServerLimits();

        $this->assertArrayHasKey('upload_max_filesize_raw', $limits);
        $this->assertArrayHasKey('upload_max_filesize_bytes', $limits);
        $this->assertArrayHasKey('post_max_size_raw', $limits);
        $this->assertArrayHasKey('post_max_size_bytes', $limits);
        $this->assertArrayHasKey('effective_server_bytes', $limits);
        $this->assertArrayHasKey('effective_server_formatted', $limits);

        // Effective server limit must be <= upload_max_filesize and <= post_max_size (if post_max_size > 0)
        $this->assertLessThanOrEqual($limits['upload_max_filesize_bytes'], $limits['effective_server_bytes']);
        if ($limits['post_max_size_bytes'] > 0) {
            $this->assertLessThanOrEqual($limits['post_max_size_bytes'], $limits['effective_server_bytes']);
        }
    }

    public function testGetUserCapabilitiesReturnsStructuredResponse(): void
    {
        $service = new UploadCapabilityService();
        $caps = $service->getUserCapabilities();

        $this->assertArrayHasKey('server', $caps);
        $this->assertArrayHasKey('user', $caps);
        $this->assertArrayHasKey('allowed_categories', $caps);

        $this->assertArrayHasKey('images', $caps['allowed_categories']);
        $this->assertArrayHasKey('videos', $caps['allowed_categories']);
        $this->assertArrayHasKey('audio', $caps['allowed_categories']);
        $this->assertArrayHasKey('documents', $caps['allowed_categories']);
        $this->assertArrayHasKey('archives', $caps['allowed_categories']);

        $this->assertContains('mp4', $caps['allowed_categories']['videos']);
        $this->assertContains('webm', $caps['allowed_categories']['videos']);
        $this->assertContains('mkv', $caps['allowed_categories']['videos']);
        $this->assertContains('mp3', $caps['allowed_categories']['audio']);
        $this->assertContains('pdf', $caps['allowed_categories']['documents']);
        $this->assertContains('zip', $caps['allowed_categories']['archives']);
    }
}

