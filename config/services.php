<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Third-Party Services & Central Gateways
    |--------------------------------------------------------------------------
    |
    | Configuration for centralized external services used by Favorite CMS Core.
    | The Google OAuth Gateway provides universal 1-click "Continue with Google"
    | authentication without requiring administrators to enter Google credentials.
    |
    */

    'google_oauth' => [
        'gateway_url' => env('FAVORITE_OAUTH_GATEWAY_URL'),
    ],
];

