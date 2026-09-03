<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'Favorite CMS'),
    'version' => '1.0.0',
    'env' => env('APP_ENV', 'production'),
    'debug' => env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'admin_prefix' => env('ADMIN_PREFIX', 'admin'),
    'timezone' => 'UTC',
    'locale' => 'en',
    'key' => env('APP_KEY', ''),
    'session' => [
        'driver' => env('SESSION_DRIVER', 'file'),
        'lifetime' => env('SESSION_LIFETIME', 120),
    ],
    'upload' => [
        'max_size' => 1024 * 1024 * 10, // 10MB
    ],
];

