<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/bootstrap.php';

try {
    /** @var \FavoriteCMS\Core\Application $app */
    $app->run();
} catch (\Throwable $e) {
    if (env('APP_DEBUG', false)) {
        throw $e;
    }
    
    http_response_code(500);
    echo "<h1>500 Internal Server Error</h1>";
    echo "<p>Something went wrong on our end.</p>";
    exit(1);
}

