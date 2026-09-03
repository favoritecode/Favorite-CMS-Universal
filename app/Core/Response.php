<?php

declare(strict_types=1);

namespace FavoriteCMS\Core;

class Response
{
    protected string $content;
    protected int $status;
    protected array $headers;

    public function __construct(string $content = '', int $status = 200, array $headers = [])
    {
        $this->setContent($content);
        $this->setStatus($status);
        $this->headers = $headers;
    }

    public static function make(string $content = '', int $status = 200, array $headers = []): static
    {
        return new static($content, $status, $headers);
    }

    public static function json(mixed $data, int $status = 200): static
    {
        $response = new static(json_encode($data, JSON_THROW_ON_ERROR), $status);
        return $response->header('Content-Type', 'application/json');
    }

    public static function redirect(string $url, int $status = 302): static
    {
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            $basePath = (string)($GLOBALS['favorite_cms_base_path'] ?? '');
            if ($basePath !== '' && ($url === '/' || !str_starts_with($url . '/', $basePath . '/'))) {
                $url = rtrim($basePath, '/') . ($url === '/' ? '/' : $url);
            }
        }

        $response = new static('', $status);
        return $response->header('Location', $url);
    }

    public function setContent(string $content): static
    {
        $this->content = $content;
        return $this;
    }

    public function setStatus(int $code): static
    {
        $this->status = $code;
        return $this;
    }

    public function header(string $name, string $value): static
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header(sprintf('%s: %s', $name, $value), false);
            }
        }
        echo $this->content;
    }
}
