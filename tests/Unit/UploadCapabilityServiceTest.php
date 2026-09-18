<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit;

use FavoriteCMS\Models\User;
use FavoriteCMS\Services\UploadCapabilityService;
use PHPUnit\Framework\TestCase;

class UploadCapabilityServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
    }

    public function testParseByteStringHandlesVariousUnits(): void
    {
        $this->assertSame(1024, UploadCapabilityService::parseByteString('1K'));
        $this->assertSame(1024, UploadCapabilityService::parseByteString('1k'));
        $this->assertSame(20971520, UploadCapabilityService::parseByteString('20M'));
        $this->assertSame(134217728, UploadCapabilityService::parseByteString('128M'));
        $this->assertSame(2147483648, UploadCapabilityService::parseByteString('2G'));
        $this->assertSame(7516192768, UploadCapabilityService::parseByteString('7G'));
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
        $this->assertSame('200 MB', UploadCapabilityService::formatBytes(209715200));
        $this->assertSame('500 MB', UploadCapabilityService::formatBytes(524288000));
        $this->assertSame('7 GB', UploadCapabilityService::formatBytes(7516192768));
    }

    public function testDefaultConfiguredLimitsMatchRequirements(): void
    {
        $this->assertSame(7516192768, UploadCapabilityService::DEFAULT_ADMIN_LIMIT_BYTES); // 7 GB
        $this->assertSame(524288000, UploadCapabilityService::DEFAULT_MODERATOR_LIMIT_BYTES); // 500 MB
        $this->assertSame(209715200, UploadCapabilityService::DEFAULT_USER_LIMIT_BYTES); // 200 MB
    }

    public function testRoleBasedConfiguredLimits(): void
    {
        $service = new UploadCapabilityService();

        // 1. Unauthenticated / Normal User -> 200 MB
        $this->assertSame(209715200, $service->getConfiguredUserLimit(null));
        $this->assertSame('user', $service->getUserRoleCategory(null));

        // 2. Mock Admin User
        $adminUser = $this->createMock(User::class);
        $adminUser->method('hasRole')->willReturnCallback(fn($role) => $role === 'admin');
        $adminUser->method('hasPermission')->willReturn(false);

        $this->assertSame('admin', $service->getUserRoleCategory($adminUser));
        $this->assertSame(7516192768, $service->getConfiguredUserLimit($adminUser));

        // 3. Mock Moderator User
        $modUser = $this->createMock(User::class);
        $modUser->method('hasRole')->willReturnCallback(fn($role) => $role === 'moderator');
        $modUser->method('hasPermission')->willReturn(false);

        $this->assertSame('moderator', $service->getUserRoleCategory($modUser));
        $this->assertSame(524288000, $service->getConfiguredUserLimit($modUser));

        // 4. Mock Editor User (moderator tier)
        $editorUser = $this->createMock(User::class);
        $editorUser->method('hasRole')->willReturnCallback(fn($role) => $role === 'editor');
        $editorUser->method('hasPermission')->willReturn(false);

        $this->assertSame('moderator', $service->getUserRoleCategory($editorUser));
        $this->assertSame(524288000, $service->getConfiguredUserLimit($editorUser));

        // 5. Mock Standard Subscriber
        $subUser = $this->createMock(User::class);
        $subUser->method('hasRole')->willReturnCallback(fn($role) => $role === 'subscriber');
        $subUser->method('hasPermission')->willReturn(false);

        $this->assertSame('user', $service->getUserRoleCategory($subUser));
        $this->assertSame(209715200, $service->getConfiguredUserLimit($subUser));
    }

    public function testEffectiveLimitCalculatesLowerOfCmsAndServerMax(): void
    {
        $service = new UploadCapabilityService();
        $serverLimits = $service->getServerLimits();
        $serverMax = $serverLimits['effective_server_bytes'];

        $adminUser = $this->createMock(User::class);
        $adminUser->method('hasRole')->willReturnCallback(fn($role) => $role === 'admin');
        $adminUser->method('hasPermission')->willReturn(false);

        $effectiveLimit = $service->getEffectiveUserLimit($adminUser);

        if ($serverMax > 0 && $serverMax < 7516192768) {
            // Server limits are lower than 7 GB, so effective limit MUST be capped at serverMax
            $this->assertSame($serverMax, $effectiveLimit);
        } else {
            $this->assertLessThanOrEqual(7516192768, $effectiveLimit);
        }
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

        $this->assertArrayHasKey('role_category', $caps['user']);
        $this->assertArrayHasKey('configured_limit_bytes', $caps['user']);
        $this->assertArrayHasKey('configured_limit_formatted', $caps['user']);
        $this->assertArrayHasKey('max_upload_bytes', $caps['user']);
        $this->assertArrayHasKey('max_upload_formatted', $caps['user']);
        $this->assertArrayHasKey('is_server_capped', $caps['user']);

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
