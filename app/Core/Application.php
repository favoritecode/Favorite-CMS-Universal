<?php

declare(strict_types=1);

namespace FavoriteCMS\Core;

use FavoriteCMS\Installer\InstallationStateManager;

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

        $state = new InstallationStateManager();
        $lockFile = $state->lockPath();
        if ($state->hasLock()) {
            return true;
        }

        // Secondary persistent verification: inspect database for valid existing installation
        try {
            if (!file_exists($this->basePath . '/.env') && env('DB_DATABASE', '') === '') {
                return false;
            }

            $db = $this->make(Database::class);
            if ($state->databaseLooksInstalled($db)) {
                // Valid installation detected in database; self-heal the persistent lock file.
                $storageDir = dirname($lockFile);
                if (!is_dir($storageDir)) {
                    @mkdir($storageDir, 0775, true);
                }
                @file_put_contents($lockFile, "installed_at=" . date('c') . "\nrecovered_from=database\n");
                return true;
            }
        } catch (\Throwable) {
            // Database not configured or connection failed -> genuine uninstalled state
            return false;
        }

        return false;
    }
}
