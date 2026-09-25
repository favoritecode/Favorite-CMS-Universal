<?php

declare(strict_types=1);

namespace FavoriteCMS\Services;

class PasswordHasher
{
    /**
     * Get the preferred password hashing algorithm.
     */
    public static function getAlgorithm(): string|int
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return PASSWORD_ARGON2ID;
        }

        return PASSWORD_BCRYPT;
    }

    /**
     * Get the options array for the current algorithm.
     */
    public static function getOptions(): array
    {
        if (defined('PASSWORD_ARGON2ID')) {
            $memoryCost = defined('PASSWORD_ARGON2_DEFAULT_MEMORY_COST') ? PASSWORD_ARGON2_DEFAULT_MEMORY_COST : 65536;
            $timeCost = defined('PASSWORD_ARGON2_DEFAULT_TIME_COST') ? PASSWORD_ARGON2_DEFAULT_TIME_COST : 4;
            $threads = defined('PASSWORD_ARGON2_DEFAULT_THREADS') ? PASSWORD_ARGON2_DEFAULT_THREADS : 1;

            return [
                'memory_cost' => $memoryCost,
                'time_cost'   => $timeCost,
                'threads'     => $threads,
            ];
        }

        return [
            'cost' => 12,
        ];
    }

    /**
     * Hash a plain-text password using the preferred algorithm and options.
     */
    public static function hash(string $password): string
    {
        $algo = self::getAlgorithm();
        $options = self::getOptions();

        $hash = password_hash($password, $algo, $options);
        if ($hash === false) {
            throw new \RuntimeException('Password hashing failed.');
        }

        return $hash;
    }

    /**
     * Verify a plain-text password against a stored hash.
     */
    public static function verify(string $password, string $hash): bool
    {
        if ($password === '' || $hash === '') {
            return false;
        }

        return password_verify($password, $hash);
    }

    /**
     * Check if a hash needs to be rehashed to match current preferred algorithm and options.
     */
    public static function needsRehash(string $hash): bool
    {
        if ($hash === '') {
            return true;
        }

        $algo = self::getAlgorithm();
        $options = self::getOptions();

        return password_needs_rehash($hash, $algo, $options);
    }
}

