<?php

declare(strict_types=1);

return [
    'driver' => env('CACHE_DRIVER', 'file'),
    'path'   => storage_path('cache'),
    'prefix' => 'favorite_cache_',
    'ttl'    => 3600,
];

