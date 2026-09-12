<?php
declare(strict_types=1);
namespace FavoriteCMS\Tests\Unit\Services;

use FavoriteCMS\Core\Exceptions\SecurityException;
use FavoriteCMS\Services\MediaService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SvgUploadSafetyTest extends TestCase
{
    public static function svgCases(): array
    {
        return [
            ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M0 0L10 10" stroke="red"/></svg>', true],
            ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', false],
            ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', false],
            ['<svg xmlns="http://www.w3.org/2000/svg"><foreignObject/></svg>', false],
            ['<svg xmlns="http://www.w3.org/2000/svg"><path fill="url(//evil.example/a)"/></svg>', false],
            ['<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>', false],
            ['<?xml-stylesheet href="evil.css"?><svg xmlns="http://www.w3.org/2000/svg"/>', false],
        ];
    }

    #[DataProvider('svgCases')]
    public function testOnlyPassiveSvgIsAccepted(string $svg, bool $safe): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cms_svg_');
        file_put_contents($path, $svg);
        try {
            $service = (new \ReflectionClass(MediaService::class))->newInstanceWithoutConstructor();
            if (!$safe) $this->expectException(SecurityException::class);
            $service->validateSvg($path);
            $this->assertTrue($safe);
        } finally {
            unlink($path);
        }
    }
}
