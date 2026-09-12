<?php

declare(strict_types=1);

namespace FavoriteCMS\Services;

/** Small, process-safe fixed-window limiter for shared-hosting authentication endpoints. */
class AuthRateLimiter
{
    public function __construct(private ?string $directory = null)
    {
        $this->directory ??= APP_ROOT . '/storage/cache/auth-limits';
    }

    public function allow(string $key, int $limit, int $seconds = 900): bool
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            return false;
        }
        $file = @fopen($this->directory . '/' . hash('sha256', $key) . '.json', 'c+');
        if (!$file) {
            return false;
        }
        try {
            if (!flock($file, LOCK_EX)) {
                return false;
            }
            $state = json_decode(stream_get_contents($file), true);
            if (!is_array($state) || ($state['expires'] ?? 0) <= time()) {
                $state = ['expires' => time() + $seconds, 'count' => 0];
            }
            if ($state['count'] >= $limit) {
                return false;
            }
            $state['count']++;
            rewind($file);
            ftruncate($file, 0);
            $encoded = json_encode($state);
            return fwrite($file, $encoded) === strlen($encoded) && fflush($file);
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
}
