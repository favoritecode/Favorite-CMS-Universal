<?php

declare(strict_types=1);

namespace FavoriteCMS\Core;

class Application extends Container
{
    /**
     * @var string
     */
    protected string $basePath;

    /**
     * Optional override for testing environments.
     */
    protected ?bool $installedOverride = null;

    public function __construct()
    {
        $this->basePath = APP_ROOT;
        $this->registerBaseBindings();
    }

    protected function registerBaseBindings(): void
    {
        static::setInstance($this);
        $this->instance('app', $this);
        $this->instance(Container::class, $this);
        $this->instance(Application::class, $this);
    }

    public function run(): void
    {
        $request = Request::capture();
        
        // Ensure Config is loaded before resolving Kernel
        $this->make(Config::class);

        /** @var Kernel $kernel */
        $kernel = $this->make(Kernel::class);
        $response = $kernel->handle($request);
        $response->send();
    }

    public function environment(): string
    {
        return env('APP_ENV', 'production');
    }

    public function version(): string
    {
        return APP_VERSION;
    }

    /**
     * Override installation state (useful for test isolation without destroying persistent files).
     */
    public function setInstalled(?bool $state): void
    {
        $this->installedOverride = $state;
    }

    /**
     * Determine whether Favorite CMS is persistently installed.
     * Survives Apache, MySQL, XAMPP, and OS restarts.
     */
    public function isInstalled(): bool
    {
        if ($this->installedOverride !== null) {
            return $this->installedOverride;
        }

        $lockFile = $this->basePath . '/storage/installed.lock';
        if (file_exists($lockFile)) {
            return true;
        }

        // Secondary persistent verification: inspect database for valid existing installation
        try {
            $db = $this->make(Database::class);
            $tables = $db->select("SHOW TABLES LIKE 'settings'");
            if (!empty($tables)) {
                $userCheck = $db->selectOne("SELECT id FROM `users` WHERE `status` = 'active' LIMIT 1");
                if ($userCheck) {
                    // Valid installation detected in database; self-heal the persistent lock file
                    $storageDir = $this->basePath . '/storage';
                    if (!is_dir($storageDir)) {
                        @mkdir($storageDir, 0775, true);
                    }
                    @file_put_contents($lockFile, "installed\n");
                    return true;
                }
            }
        } catch (\Throwable) {
            // Database not configured or connection failed -> genuine uninstalled state
            return false;
        }

        return false;
    }
}
