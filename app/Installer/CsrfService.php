<?php

declare(strict_types=1);

namespace FavoriteCMS\Installer;

class CsrfService
{
    private const TOKEN_KEY = '_favorite_installer_token';
    private const ISSUED_KEY = '_favorite_installer_token_issued';
    private const TTL_SECONDS = 7200;

    public function token(): string
    {
        if ($this->expired() || empty($_SESSION[self::TOKEN_KEY])) {
            $this->rotate();
        }

        return (string)$_SESSION[self::TOKEN_KEY];
    }

    public function validate(string $submitted): bool
    {
        $stored = (string)($_SESSION[self::TOKEN_KEY] ?? '');
        if ($submitted === '' || $stored === '' || $this->expired()) {
            $this->rotate();
            return false;
        }

        return hash_equals($stored, $submitted);
    }

    public function rotate(): string
    {
        $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(32));
        $_SESSION[self::ISSUED_KEY] = time();

        return (string)$_SESSION[self::TOKEN_KEY];
    }

    protected function expired(): bool
    {
        $issued = (int)($_SESSION[self::ISSUED_KEY] ?? 0);
        return $issued <= 0 || $issued + self::TTL_SECONDS < time();
    }
}
