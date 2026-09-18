<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use FavoriteCMS\Services\Update\UpdatePackageValidator;
use ZipArchive;

class UpdatePackageValidatorTest extends TestCase
{
    protected string $tempDir;
    protected UpdatePackageValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/fvcms_val_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0775, true);
        $this->validator = new UpdatePackageValidator();
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

    /**
     * Helper to create a test ZIP package with custom files.
     */
    protected function createZip(string $filename, array $files, string $prefix = 'Favorite-CMS-Universal/'): string
    {
        $zipPath = $this->tempDir . '/' . $filename;
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $name => $content) {
            $zip->addFromString($prefix . $name, $content);
        }

        $zip->close();
        return $zipPath;
    }

    public function testValidateValidPackageWithReleaseJson(): void
    {
        $zipPath = $this->createZip('valid_package.zip', [
            'release.json' => json_encode([
                'product'          => 'Favorite CMS Universal',
                'product_id'       => 'favorite-cms-universal',
                'version'          => '1.0.10',
                'min_core_version' => '1.0.0',
                'min_php'          => '8.1.0',
            ]),
            'bootstrap.php'            => "<?php define('APP_VERSION', '1.0.10');",
            'index.php'                => "<?php // index",
            'migrate.php'              => "<?php // migrate",
            'app/Core/Application.php' => "<?php namespace FavoriteCMS\Core; class Application {}",
            'public/index.php'         => "<?php // public index",
        ]);

        $result = $this->validator->validate($zipPath);

        $this->assertTrue($result['valid']);
        $this->assertEquals('1.0.10', $result['version']);
        $this->assertEquals('Favorite CMS Universal', $result['product']);
        $this->assertEquals('Favorite-CMS-Universal', $result['root_prefix']);
        $this->assertEmpty($result['errors']);
    }

    public function testValidateRejectsMissingRequiredCoreFiles(): void
    {
        $zipPath = $this->createZip('missing_core.zip', [
            'release.json' => json_encode([
                'product' => 'Favorite CMS Universal',
                'version' => '1.0.10',
            ]),
            'bootstrap.php' => "<?php define('APP_VERSION', '1.0.10');",
            // missing app/Core/Application.php, index.php, migrate.php, public/index.php
        ]);

        $result = $this->validator->validate($zipPath);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $errorStr = implode(' ', $result['errors']);
        $this->assertStringContainsString('missing required Core file', $errorStr);
    }

    public function testValidateRejectsZipSlipPathTraversal(): void
    {
        $zipPath = $this->tempDir . '/zipslip.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('Favorite-CMS-Universal/../../etc/passwd', 'malicious');
        $zip->addFromString('Favorite-CMS-Universal/bootstrap.php', "<?php define('APP_VERSION', '1.0.10');");
        $zip->close();

        $result = $this->validator->validate($zipPath);

        $this->assertFalse($result['valid']);
        $errorStr = implode(' ', $result['errors']);
        $this->assertStringContainsString('path traversal', strtolower($errorStr));
    }

    public function testValidateRejectsForbiddenEnvFile(): void
    {
        $zipPath = $this->createZip('with_env.zip', [
            '.env'                     => "DB_PASS=secret\n",
            'bootstrap.php'            => "<?php define('APP_VERSION', '1.0.10');",
            'index.php'                => "<?php",
            'migrate.php'              => "<?php",
            'app/Core/Application.php' => "<?php",
            'public/index.php'         => "<?php",
        ]);

        $result = $this->validator->validate($zipPath);

        $this->assertFalse($result['valid']);
        $errorStr = implode(' ', $result['errors']);
        $this->assertStringContainsString('forbidden or dangerous entry', $errorStr);
    }

    public function testValidateRejectsForbiddenFavoritePay(): void
    {
        $zipPath = $this->createZip('with_favorite_pay.zip', [
            'plugins/favorite-pay/plugin.php' => "<?php",
            'bootstrap.php'                   => "<?php define('APP_VERSION', '1.0.10');",
            'index.php'                       => "<?php",
            'migrate.php'                     => "<?php",
            'app/Core/Application.php'        => "<?php",
            'public/index.php'                => "<?php",
        ]);

        $result = $this->validator->validate($zipPath);

        $this->assertFalse($result['valid']);
        $errorStr = implode(' ', $result['errors']);
        $this->assertStringContainsString('forbidden or dangerous entry', $errorStr);
    }

    public function testValidateRejectsChecksumMismatch(): void
    {
        $zipPath = $this->createZip('checksum_test.zip', [
            'bootstrap.php'            => "<?php define('APP_VERSION', '1.0.10');",
            'index.php'                => "<?php",
            'migrate.php'              => "<?php",
            'app/Core/Application.php' => "<?php",
            'public/index.php'         => "<?php",
        ]);

        $wrongChecksum = str_repeat('a', 64);
        $result = $this->validator->validate($zipPath, $wrongChecksum);

        $this->assertFalse($result['valid']);
        $errorStr = implode(' ', $result['errors']);
        $this->assertStringContainsString('checksum mismatch', strtolower($errorStr));
    }

    public function testValidateRejectsWrongProduct(): void
    {
        $zipPath = $this->createZip('wrong_product.zip', [
            'release.json' => json_encode([
                'product' => 'Arbitrary CMS',
                'version' => '2.0.0',
            ]),
            'bootstrap.php'            => "<?php define('APP_VERSION', '2.0.0');",
            'index.php'                => "<?php",
            'migrate.php'              => "<?php",
            'app/Core/Application.php' => "<?php",
            'public/index.php'         => "<?php",
        ]);

        $result = $this->validator->validate($zipPath);

        $this->assertFalse($result['valid']);
        $errorStr = implode(' ', $result['errors']);
        $this->assertStringContainsString('does not match Favorite CMS Universal', $errorStr);
    }

    public function testValidateRejectsIncompatiblePhp(): void
    {
        $zipPath = $this->createZip('incompatible_php.zip', [
            'release.json' => json_encode([
                'product' => 'Favorite CMS Universal',
                'version' => '1.0.10',
                'min_php' => '99.0.0',
            ]),
            'bootstrap.php'            => "<?php define('APP_VERSION', '1.0.10');",
            'index.php'                => "<?php",
            'migrate.php'              => "<?php",
            'app/Core/Application.php' => "<?php",
            'public/index.php'         => "<?php",
        ]);

        $result = $this->validator->validate($zipPath);

        $this->assertFalse($result['valid']);
        $errorStr = implode(' ', $result['errors']);
        $this->assertStringContainsString('requires PHP 99.0.0', $errorStr);
    }

    public function testVersionComparisonHelpers(): void
    {
        $this->assertTrue($this->validator->isNewer('1.0.10', '1.0.9-beta'));
        $this->assertTrue($this->validator->isNewer('1.1.0', '1.0.9-beta'));
        $this->assertTrue($this->validator->isNewer('2.0.0', '1.0.9-beta'));

        $this->assertFalse($this->validator->isNewer('1.0.9-beta', '1.0.9-beta'));
        $this->assertFalse($this->validator->isNewer('1.0.8-beta', '1.0.9-beta'));
        $this->assertFalse($this->validator->isNewer('1.0.0', '1.0.9-beta'));

        $this->assertEquals(0, $this->validator->compareVersions('v1.0.9-beta', '1.0.9-beta'));
        $this->assertEquals(1, $this->validator->compareVersions('1.0.10', '1.0.9-beta'));
        $this->assertEquals(-1, $this->validator->compareVersions('1.0.8-beta', '1.0.9-beta'));
    }
}

