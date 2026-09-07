<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use FavoriteCMS\Services\AvatarService;

class AvatarServiceTest extends TestCase
{
    private AvatarService $service;
    private string $avatarDir;

    // Minimal valid 1x1 PNG bytes (RFC-compliant PNG)
    private string $validPngBytes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AvatarService();
        $this->avatarDir = APP_ROOT . '/public/uploads/avatars';
        if (!is_dir($this->avatarDir)) {
            mkdir($this->avatarDir, 0755, true);
        }
        $this->validPngBytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
    }

    public function testValidateExternalUrlAcceptsValidHttpAndHttps(): void
    {
        $httpsRes = $this->service->validateExternalUrl('https://example.com/images/avatar.jpg');
        $this->assertTrue($httpsRes['valid']);
        $this->assertSame('https://example.com/images/avatar.jpg', $httpsRes['url']);

        $httpRes = $this->service->validateExternalUrl('http://cdn.example.org/photo.png?size=200');
        $this->assertTrue($httpRes['valid']);
        $this->assertSame('http://cdn.example.org/photo.png?size=200', $httpRes['url']);
    }

    public function testValidateExternalUrlRejectsEmptyOrWhitespace(): void
    {
        $res = $this->service->validateExternalUrl('   ');
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('empty', strtolower($res['error']));
    }

    public function testValidateExternalUrlRejectsDangerousProtocols(): void
    {
        $dangerous = [
            'javascript:alert(1)',
            'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            'file:///etc/passwd',
            'ftp://example.com/avatar.jpg',
            '//cdn.example.com/avatar.jpg',
            "https://example.com/avatar.jpg\r\nSet-Cookie: evil=1",
        ];

        foreach ($dangerous as $url) {
            $res = $this->service->validateExternalUrl($url);
            $this->assertFalse($res['valid'], "URL {$url} should have been rejected");
            $this->assertNotEmpty($res['error']);
        }
    }

    public function testValidateExternalUrlRejectsExcessiveLength(): void
    {
        $tooLong = 'https://example.com/' . str_repeat('a', 550) . '.jpg';
        $res = $this->service->validateExternalUrl($tooLong);
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('exceed', strtolower($res['error']));
    }

    public function testDeleteLocalAvatarFileIgnoresExternalUrls(): void
    {
        $res = $this->service->deleteLocalAvatarFile('https://example.com/avatar.jpg');
        $this->assertFalse($res);
    }

    public function testDeleteLocalAvatarFileProtectsAgainstPathTraversal(): void
    {
        $traversalAttempts = [
            '/uploads/avatars/../../index.php',
            '../../config.php',
            '/uploads/avatars/..%2f..%2findex.php',
        ];

        foreach ($traversalAttempts as $path) {
            $res = $this->service->deleteLocalAvatarFile($path);
            $this->assertFalse($res, "Path traversal attempt {$path} should have returned false");
        }
    }

    public function testDeleteLocalAvatarFileDeletesRealFileInAvatarsDir(): void
    {
        $testFile = $this->avatarDir . '/test_avatar_' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($testFile, 'dummy image data');
        $this->assertFileExists($testFile);

        $relPath = '/uploads/avatars/' . basename($testFile);
        $res = $this->service->deleteLocalAvatarFile($relPath);
        $this->assertTrue($res);
        $this->assertFileDoesNotExist($testFile);
    }

    public function testStoreUploadedAvatarRejectsOversizedFiles(): void
    {
        $fakeFile = [
            'name'     => 'avatar.png',
            'type'     => 'image/png',
            'tmp_name' => tempnam(sys_get_temp_dir(), 'av_'),
            'error'    => UPLOAD_ERR_OK,
            'size'     => 3 * 1024 * 1024, // 3MB (limit is 2MB)
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('maximum allowed size of 2 MB');
        $this->service->storeUploadedAvatar($fakeFile, 1);
    }

    public function testStoreUploadedAvatarRejectsInvalidExtension(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'av_');
        file_put_contents($tmp, '<?php echo "evil"; ?>');

        $fakeFile = [
            'name'     => 'shell.php',
            'type'     => 'image/jpeg',
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => 100,
        ];

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Invalid file extension');
            $this->service->storeUploadedAvatar($fakeFile, 1);
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }
    }

    public function testStoreUploadedAvatarRejectsExecutableDisguisedAsImage(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'av_');
        file_put_contents($tmp, '<?php phpinfo(); ?>');

        $fakeFile = [
            'name'     => 'evil.png',
            'type'     => 'image/png',
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => 100,
        ];

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Invalid image type detected');
            $this->service->storeUploadedAvatar($fakeFile, 1);
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }
    }

    public function testStoreUploadedAvatarAcceptsValidPngImage(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'png_');
        file_put_contents($tmp, $this->validPngBytes);

        $fakeFile = [
            'name'     => 'valid_avatar.png',
            'type'     => 'image/png',
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => strlen($this->validPngBytes),
        ];

        $savedPath = $this->service->storeUploadedAvatar($fakeFile, 42);

        $this->assertStringStartsWith('/uploads/avatars/avatar_42_', $savedPath);
        $this->assertStringEndsWith('.png', $savedPath);

        $absolutePath = APP_ROOT . '/public' . $savedPath;
        $this->assertFileExists($absolutePath);

        // Cleanup
        if (file_exists($absolutePath)) {
            @unlink($absolutePath);
        }
        if (file_exists($tmp)) {
            @unlink($tmp);
        }
    }

    public function testStoreUploadedAvatarCleansUpPreviousAvatarFile(): void
    {
        // Create an "old" avatar file
        $oldFile = $this->avatarDir . '/avatar_99_oldfile.png';
        file_put_contents($oldFile, $this->validPngBytes);
        $oldRel = '/uploads/avatars/avatar_99_oldfile.png';
        $this->assertFileExists($oldFile);

        // Upload a new valid image
        $tmp = tempnam(sys_get_temp_dir(), 'png_');
        file_put_contents($tmp, $this->validPngBytes);

        $fakeFile = [
            'name'     => 'new_avatar.png',
            'type'     => 'image/png',
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => strlen($this->validPngBytes),
        ];

        $newRel = $this->service->storeUploadedAvatar($fakeFile, 99, $oldRel);

        // Old file must be removed
        $this->assertFileDoesNotExist($oldFile);

        // New file must exist
        $newAbs = APP_ROOT . '/public' . $newRel;
        $this->assertFileExists($newAbs);

        // Cleanup
        if (file_exists($newAbs)) {
            @unlink($newAbs);
        }
        if (file_exists($tmp)) {
            @unlink($tmp);
        }
    }
}

