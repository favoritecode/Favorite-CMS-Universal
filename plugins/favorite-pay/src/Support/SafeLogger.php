<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Support;

final class SafeLogger
{
    private const SENSITIVE_KEYS = [
        'password',
        'secret',
        'secret_key',
        'api_key',
        'private_key',
        'webhook_secret',
        'token',
        'access_token',
        'cvv',
        'cvc',
        'pan',
        'card_number',
        'card',
        'pin',
        'card_cvv',
        'card_pan',
        'authorization',
        'bearer',
    ];

    public static function sanitize(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        $sanitized = [];
        foreach ($data as $key => $value) {
            $normalizedKey = strtolower((string)$key);
            $isSensitive = false;
            foreach (self::SENSITIVE_KEYS as $pattern) {
                if (str_contains($normalizedKey, $pattern)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = self::sanitize($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    public static function info(string $message, array $context = []): void
    {
        self::log($message, 'info', $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log($message, 'warning', $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log($message, 'error', $context);
    }

    private static function log(string $message, string $level, array $context): void
    {
        $sanitized = self::sanitize($context);
        if (function_exists('cms_log')) {
            cms_log($message, $level, array_merge(['plugin' => 'favorite-pay'], $sanitized));
        } else {
            error_log("[FavoritePay] [{$level}] {$message} " . (!empty($sanitized) ? json_encode($sanitized) : ''));
        }
    }
}
