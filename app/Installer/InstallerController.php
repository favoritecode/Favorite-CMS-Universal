<?php

declare(strict_types=1);

namespace FavoriteCMS\Installer;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Migrator;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;

/**
 * WordPress-style single-page first-time installer for Favorite CMS.
 *
 * Requirements:
 * - Uses ONLY the database credentials from .env
 * - NEVER prompts for or overwrites MySQL/database credentials
 * - Prompts for: Site Name, Admin Username, Admin Email, Admin Password, Confirm Password
 * - Runs pending migrations
 * - Creates admin user with password_hash(..., PASSWORD_DEFAULT)
 * - Sets site name and admin email in settings
 * - Creates storage/installed.lock ONLY AFTER all steps succeed
 */
class InstallerController
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(Request $request): Response
    {
        // If already installed, prevent re-installation
        if ($this->app->isInstalled()) {
            return Response::redirect('/');
        }

        if ($request->method() === 'POST') {
            return $this->processInstall($request);
        }

        return $this->showInstallForm($request);
    }

    /**
     * Display the WordPress-style installer form.
     */
    protected function showInstallForm(Request $request, array $errors = [], array $oldInput = []): Response
    {
        // Check database connection using ONLY .env credentials
        $dbStatus = $this->checkDatabaseConnection();

        $token = $this->csrfToken();

        $content = $this->renderView('installer/install', [
            'dbStatus' => $dbStatus,
            'errors'   => $errors,
            'old'      => $oldInput,
            'token'    => $token,
        ]);

        return Response::make($content, 200);
    }

    /**
     * Process the installation submission.
     */
    protected function processInstall(Request $request): Response
    {
        // Verify CSRF
        if (!$this->verifyCsrf($request)) {
            return $this->showInstallForm($request, ['Invalid or expired security token. Please try again.']);
        }

        $siteName        = trim((string)$request->post('site_name', ''));
        $adminUsername   = trim((string)$request->post('admin_username', ''));
        $adminEmail      = trim((string)$request->post('admin_email', ''));
        $adminPassword   = (string)$request->post('admin_password', '');
        $confirmPassword = (string)$request->post('admin_password_confirm', '');

        $oldInput = [
            'site_name'      => $siteName,
            'admin_username' => $adminUsername,
            'admin_email'    => $adminEmail,
        ];

        // 1. Validate inputs
        $errors = [];

        if ($siteName === '') {
            $errors[] = 'Please provide a Site Name.';
        }

        if ($adminUsername === '') {
            $errors[] = 'Please choose an Admin Username.';
        } elseif (strlen($adminUsername) < 3) {
            $errors[] = 'Admin Username must be at least 3 characters long.';
        } elseif (!preg_match('/^[a-zA-Z0-9_\.\-]+$/', $adminUsername)) {
            $errors[] = 'Admin Username can only contain letters, numbers, underscores, hyphens, and periods.';
        }

        if ($adminEmail === '') {
            $errors[] = 'Please provide an Admin Email address.';
        } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please provide a valid email address.';
        }

        if ($adminPassword === '') {
            $errors[] = 'Please enter an Admin Password.';
        } elseif (strlen($adminPassword) < 6) {
            $errors[] = 'Admin Password must be at least 6 characters long.';
        }

        if ($adminPassword !== $confirmPassword) {
            $errors[] = 'The password and confirmation password do not match.';
        }

        // If validation failed, return form with errors
        if (!empty($errors)) {
            return $this->showInstallForm($request, $errors, $oldInput);
        }

        // 2. Connect to database using ONLY .env credentials
        try {
            $db = $this->app->make(Database::class);
            $db->selectOne('SELECT 1');
        } catch (\Throwable $e) {
            $dbError = 'Database connection failed: ' . $e->getMessage() . '. Please check your database credentials in .env.';
            return $this->showInstallForm($request, [$dbError], $oldInput);
        }

        // 3. Run migrations
        try {
            $migrator = new Migrator($db);
            $migrator->createMigrationsTableIfNotExists();
            $migrator->migrate(APP_ROOT . '/database/migrations');

            // Ensure username column exists on users table (for backwards compatibility)
            $colCheck = $db->select("SHOW COLUMNS FROM `users` LIKE 'username'");
            if (empty($colCheck)) {
                $db->execute("ALTER TABLE `users` ADD COLUMN `username` VARCHAR(191) NULL UNIQUE AFTER `id`");
            }
        } catch (\Throwable $e) {
            $migrationError = 'Failed to execute database migrations: ' . $e->getMessage();
            return $this->showInstallForm($request, [$migrationError], $oldInput);
        }

        // 4. Create or update admin account & update settings
        try {
            $now = date('Y-m-d H:i:s');
            $hashedPassword = password_hash($adminPassword, PASSWORD_DEFAULT);

            $existing = $db->selectOne(
                "SELECT id FROM `users` WHERE `email` = ? OR `username` = ? LIMIT 1",
                [$adminEmail, $adminUsername]
            );

            if ($existing) {
                $userId = (int)$existing->id;
                $db->execute(
                    "UPDATE `users` SET `username` = ?, `name` = ?, `email` = ?, `password` = ?, `status` = 'active', `updated_at` = ? WHERE `id` = ?",
                    [$adminUsername, $adminUsername, $adminEmail, $hashedPassword, $now, $userId]
                );
            } else {
                $userId = $db->insert('users', [
                    'username'          => $adminUsername,
                    'name'              => $adminUsername,
                    'email'             => $adminEmail,
                    'password'          => $hashedPassword,
                    'status'            => 'active',
                    'email_verified_at' => $now,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]);
            }

            // Assign super-admin role
            $superAdminRole = $db->selectOne("SELECT id FROM `roles` WHERE `slug` = 'super-admin' LIMIT 1");
            if ($superAdminRole) {
                $db->execute(
                    "INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`) VALUES (?, ?)",
                    [$userId, $superAdminRole->id]
                );
            }

            // Update site settings
            \FavoriteCMS\Models\Setting::clearCache();
            \FavoriteCMS\Models\Setting::set('general', 'site_name', $siteName);
            \FavoriteCMS\Models\Setting::set('general', 'admin_email', $adminEmail);

        } catch (\Throwable $e) {
            return $this->showInstallForm(
                $request,
                ['Failed to create administrator account: ' . $e->getMessage()],
                $oldInput
            );
        }

        // 6. Create storage/installed.lock ONLY AFTER EVERYTHING SUCCEEDED
        try {
            $storageDir = APP_ROOT . '/storage';
            if (!is_dir($storageDir)) {
                mkdir($storageDir, 0775, true);
            }

            $lockContent = sprintf(
                "installed_at=%s\ninstalled_by=%s\napp_url=%s\n",
                date('c'),
                $adminUsername,
                config('app.url', 'http://favorite-cms.local')
            );

            file_put_contents($storageDir . '/installed.lock', $lockContent);
            $this->app->setInstalled(null);
        } catch (\Throwable $e) {
            return $this->showInstallForm(
                $request,
                ['Could not write storage/installed.lock: ' . $e->getMessage()],
                $oldInput
            );
        }

        // 7. Show success screen
        $content = $this->renderView('installer/success', [
            'siteName'      => $siteName,
            'adminUsername' => $adminUsername,
            'adminEmail'    => $adminEmail,
            'loginUrl'      => '/admin/login',
        ]);

        return Response::make($content, 200);
    }

    /**
     * Check whether the database is accessible with current .env settings.
     *
     * @return array{connected: bool, message: string, database: string, host: string}
     */
    protected function checkDatabaseConnection(): array
    {
        $config = $this->app->make(Config::class);
        $dbConfig = $config->get('database', []);

        $dbName = (string)($dbConfig['database'] ?? 'favorite_cms');
        $dbHost = (string)($dbConfig['host'] ?? '127.0.0.1');

        try {
            $db = $this->app->make(Database::class);
            $db->selectOne('SELECT 1');

            return [
                'connected' => true,
                'message'   => "Connected to database '{$dbName}' on {$dbHost}",
                'database'  => $dbName,
                'host'      => $dbHost,
            ];
        } catch (\Throwable $e) {
            return [
                'connected' => false,
                'message'   => $e->getMessage(),
                'database'  => $dbName,
                'host'      => $dbHost,
            ];
        }
    }

    protected function csrfToken(): string
    {
        if (empty($_SESSION['_token'])) {
            $_SESSION['_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_token'];
    }

    protected function verifyCsrf(Request $request): bool
    {
        $token  = $request->post('_token', '');
        $stored = $_SESSION['_token'] ?? '';
        return $stored !== '' && hash_equals($stored, $token);
    }

    protected function renderView(string $template, array $data = []): string
    {
        $path = APP_ROOT . '/resources/views/' . $template . '.php';
        if (!file_exists($path)) {
            throw new \RuntimeException("Installer view not found: {$template}");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $path;
        return (string)ob_get_clean();
    }
}
