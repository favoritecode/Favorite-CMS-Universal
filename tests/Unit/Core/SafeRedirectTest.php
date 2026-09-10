<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Core;

use FavoriteCMS\Core\SafeRedirect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SafeRedirectTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['favorite_cms_base_path']);
        parent::tearDown();
    }

    public static function safeLocalPaths(): array
    {
        return [
            'site root'              => ['/'],
            'post with fragment'     => ['/post/hello-world#comments'],
            'query string'           => ['/?page=2'],
            'admin area'             => ['/admin'],
            'profile page'           => ['/admin/users/profile'],
            'subdirectory path'      => ['/cms/post/hello-world'],
            'encoded unicode slug'   => ['/post/%E0%A6%AC%E0%A6%BE%E0%A6%82%E0%A6%B2%E0%A6%BE'],
            'encoded slash mid-path' => ['/search?q=a%2Fb'],
        ];
    }

    public static function unsafeTargets(): array
    {
        return [
            'empty string'                 => [''],
            'whitespace only'              => ['   '],
            'external https url'           => ['https://evil.example/phish'],
            'external http url'            => ['http://evil.example'],
            'protocol-relative url'        => ['//evil.example/phish'],
            'backslash host'               => ['/\\evil.example'],
            'double backslash'             => ['\\\\evil.example'],
            'javascript scheme'            => ['javascript:alert(1)'],
            'data scheme'                  => ['data:text/html,<script>alert(1)</script>'],
            'relative path'                => ['post/hello-world'],
            'bare host'                    => ['evil.example'],
            'encoded protocol-relative'    => ['/%2F%2Fevil.example'],
            'double-encoded slashes'       => ['/%252F%252Fevil.example'],
            'encoded backslash'            => ['/%5Cevil.example'],
            'crlf header injection'        => ["/post/x\r\nSet-Cookie: pwned=1"],
            'encoded crlf injection'       => ['/post/x%0D%0ASet-Cookie:%20pwned=1'],
            'embedded space'               => ['/post/hello world'],
            'tab character'                => ["/post/\thello"],
            'too long'                     => ['/' . str_repeat('a', 2100)],
            'logout endpoint'              => ['/admin/logout'],
            'frontend logout endpoint'     => ['/logout?redirect=/'],
            'login endpoint'               => ['/admin/login'],
            'legacy login endpoint'        => ['/login'],
            'register endpoint'            => ['/register'],
            'signup endpoint trailing /'   => ['/signup/'],
            'admin register endpoint'      => ['/admin/register'],
            'case-varied logout'           => ['/Admin/Logout'],
        ];
    }

    #[DataProvider('safeLocalPaths')]
    public function testAcceptsSafeLocalPaths(string $target): void
    {
        $this->assertSame($target, SafeRedirect::localPath($target));
    }

    #[DataProvider('unsafeTargets')]
    public function testRejectsUnsafeTargets(string $target): void
    {
        $this->assertNull(SafeRedirect::localPath($target));
    }

    public function testRejectsNonStringValues(): void
    {
        $this->assertNull(SafeRedirect::localPath(null));
        $this->assertNull(SafeRedirect::localPath(['/post/x']));
        $this->assertNull(SafeRedirect::localPath(42));
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $this->assertSame('/post/hello-world', SafeRedirect::localPath("  /post/hello-world \n"));
    }

    public function testBlocksAuthEndpointsBehindSubdirectoryBasePath(): void
    {
        $GLOBALS['favorite_cms_base_path'] = '/cms';

        $this->assertNull(SafeRedirect::localPath('/cms/admin/logout'));
        $this->assertNull(SafeRedirect::localPath('/cms/register'));
        $this->assertSame('/cms/post/hello-world', SafeRedirect::localPath('/cms/post/hello-world'));
    }
}
