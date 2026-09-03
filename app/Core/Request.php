<?php

declare(strict_types=1);

namespace FavoriteCMS\Core;

class Request
{
    protected array $get;
    protected array $post;
    protected array $server;
    protected array $files;
    protected array $cookies;

    public function __construct(array $get = [], array $post = [], array $server = [], array $files = [], array $cookies = [])
    {
        $this->get = $get;
        $this->post = $post;
        $this->server = $server;
        $this->files = $files;
        $this->cookies = $cookies;
    }

    public static function capture(): self
    {
        return new static($_GET, $_POST, $_SERVER, $_FILES, $_COOKIE);
    }

    public function method(): string
    {
        $method = $this->server['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'POST' && isset($this->post['_method'])) {
            return strtoupper($this->post['_method']);
        }
        return strtoupper($method);
    }

    public function uri(): string
    {
        return $this->server['REQUEST_URI'] ?? '/';
    }

    public function path(): string
    {
        $uri = $this->uri();
        if (($pos = strpos($uri, '?')) !== false) {
            return substr($uri, 0, $pos);
        }
        return $uri;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->get[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post($key, $this->get($key, $default));
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function header(string $key, string $default = ''): string
    {
        $header = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
        return $this->server[$header] ?? $default;
    }

    public function ip(): string
    {
        return $this->server['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    public function isAjax(): bool
    {
        return strtolower($this->header('X_REQUESTED_WITH')) === 'xmlhttprequest';
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    public function isGet(): bool
    {
        return $this->method() === 'GET';
    }

    public function all(): array
    {
        return array_merge($this->get, $this->post);
    }

    public function only(array $keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }
}

