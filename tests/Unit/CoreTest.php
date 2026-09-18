<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit;

use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use PHPUnit\Framework\TestCase;

class CoreTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Container
    // -------------------------------------------------------------------------
    public function testContainerBindAndMake(): void
    {
        $container = new Container();
        $container->bind('greeting', fn() => 'hello');
        $this->assertSame('hello', $container->make('greeting'));
    }

    public function testContainerSingletonReturnsSameInstance(): void
    {
        $container = new Container();
        $container->singleton('obj', fn() => new \stdClass());
        $a = $container->make('obj');
        $b = $container->make('obj');
        $this->assertSame($a, $b);
    }

    public function testContainerHas(): void
    {
        $container = new Container();
        $container->bind('foo', fn() => 'bar');
        $this->assertTrue($container->has('foo'));
        $this->assertFalse($container->has('nonexistent'));
    }

    // -------------------------------------------------------------------------
    // Config
    // -------------------------------------------------------------------------
    public function testConfigGetDotNotation(): void
    {
        $config = new Config();
        // database config should be loaded from config/database.php
        $driver = $config->get('database.driver');
        $this->assertSame('mysql', $driver);
    }

    public function testConfigGetDefault(): void
    {
        $config = new Config();
        $val    = $config->get('nonexistent.key', 'default_value');
        $this->assertSame('default_value', $val);
    }

    public function testConfigSet(): void
    {
        $config = new Config();
        $config->set('test.key', 'test_value');
        $this->assertSame('test_value', $config->get('test.key'));
    }

    // -------------------------------------------------------------------------
    // Request
    // -------------------------------------------------------------------------
    public function testRequestCapture(): void
    {
        $_GET    = ['foo' => 'bar'];
        $_POST   = ['baz' => 'qux'];
        $_SERVER = array_merge($_SERVER, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/test?foo=bar']);
        $_FILES  = [];
        $_COOKIE = [];

        $request = Request::capture();
        $this->assertSame('POST', $request->method());
        $this->assertSame('/test', $request->path());
        $this->assertSame('bar', $request->get('foo'));
        $this->assertSame('qux', $request->post('baz'));
    }

    public function testRequestMethodOverride(): void
    {
        $_SERVER = array_merge($_SERVER, ['REQUEST_METHOD' => 'POST']);
        $_POST   = ['_method' => 'DELETE'];
        $_GET    = [];
        $_FILES  = [];
        $_COOKIE = [];

        $request = Request::capture();
        $this->assertSame('DELETE', $request->method());
    }

    // -------------------------------------------------------------------------
    // Response
    // -------------------------------------------------------------------------
    public function testResponseMake(): void
    {
        $response = Response::make('Hello', 200);
        // Inspect via reflection since content is protected
        $r = new \ReflectionProperty(Response::class, 'content');
        $r->setAccessible(true);
        $this->assertSame('Hello', $r->getValue($response));

        $s = new \ReflectionProperty(Response::class, 'status');
        $s->setAccessible(true);
        $this->assertSame(200, $s->getValue($response));
    }

    public function testResponseJson(): void
    {
        $response = Response::json(['key' => 'value']);
        $r = new \ReflectionProperty(Response::class, 'content');
        $r->setAccessible(true);
        $this->assertSame('{"key":"value"}', $r->getValue($response));
    }

    public function testResponseRedirect(): void
    {
        $response = Response::redirect('/somewhere');
        $s = new \ReflectionProperty(Response::class, 'status');
        $s->setAccessible(true);
        $this->assertSame(302, $s->getValue($response));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------
    public function testEnvHelper(): void
    {
        putenv('TEST_VAR_CMS=hello');
        $_ENV['TEST_VAR_CMS'] = 'hello';
        $this->assertSame('hello', env('TEST_VAR_CMS'));
        $this->assertSame('default', env('NONEXISTENT_VAR_XYZ', 'default'));
    }

    public function testEscapeHelper(): void
    {
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', e('<script>alert(1)</script>'));
    }

    public function testStrSlugHelper(): void
    {
        $this->assertSame('hello-world', str_slug('Hello World'));
        $this->assertSame('my-post-title', str_slug('My Post Title!'));
    }

    public function testBasePath(): void
    {
        $this->assertDirectoryExists(base_path());
        $this->assertSame(APP_ROOT, base_path());
        $this->assertStringEndsWith('storage', storage_path());
    }
}

