<?php

declare(strict_types=1);

return [
    'disk' => 'local',
    'upload_path' => public_path('uploads'),
    'max_size' => 1024 * 1024 * 20, // 20MB
    'allowed_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
    ],
];

