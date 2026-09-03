<?php

declare(strict_types=1);

namespace FavoriteCMS\Installer;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Migrator;
use FavoriteCMS\Core\Logger;
use FavoriteCMS\Models\Setting;

class InstallationService
{
    private const REQUIRED_TABLES = [
        'cms_migrations',
        'users',
        'roles',
        'permissions',
        'role_permissions',
        'user_roles',
        'posts',
        'pages',
        'taxonomies',
        'post_taxonomies',
        'settings',
    ];

    public function __construct(
        protected Application $app,
        protected DatabaseProvisioner $databases,
        protected InstallationStateManager $state
    ) {
    }

    public function install(array $dbConfig, array $site, array $admin): array
    {
        $db = $this->databases->testConnection($dbConfig);
        $this->guardExistingInstall($db);

        $this->databases->writeEnv($dbConfig, $site['url']);
        $this->app->instance(Database::class, $db);
        $this->app->instance(Config::class, new Config());

        $migrator = new Migrator($db);
        $applied = $migrator->migrate(APP_ROOT . '/database/migrations');
        $this->repairLegacyColumns($db);
        $this->verifySchema($db);
        $userId = $this->createAdmin($db, $admin);
        $this->writeSettings($site, $admin);
        $this->state->writeLock($site['url'], $admin['username']);
        $this->app->setInstalled(null);

        return [
            'applied_migrations' => $applied,
            'admin_user_id' => $userId,
        ];
    }

    protected function guardExistingInstall(Database $db): void
    {
        if ($this->state->databaseLooksInstalled($db)) {
            throw new \RuntimeException('This database already contains an installed Favorite CMS site. The installer will not reinstall over it.');
        }
    }

    protected function repairLegacyColumns(Database $db): void
    {
        if ($db->tableExists('users')) {
            $colCheck = $db->select("SHOW COLUMNS FROM `users` LIKE 'username'");
            if (empty($colCheck)) {
                $db->execute("ALTER TABLE `users` ADD COLUMN `username` VARCHAR(191) NULL UNIQUE AFTER `id`");
            }
        }
    }

    protected function verifySchema(Database $db): void
    {
        foreach (self::REQUIRED_TABLES as $table) {
            if (!$db->tableExists($table)) {
                throw new \RuntimeException("Required table '{$table}' was not created.");
            }
        }

        $columns = $db->select("SHOW COLUMNS FROM `users`");
        $names = array_map(static fn ($row): string => (string)$row->Field, $columns);
        foreach (['id', 'username', 'email', 'password', 'status'] as $required) {
            if (!in_array($required, $names, true)) {
                throw new \RuntimeException("Required users column '{$required}' is missing.");
            }
        }
    }

    protected function createAdmin(Database $db, array $admin): int
    {
        $existing = $db->selectOne(
            "SELECT id FROM `users` WHERE `email` = ? OR `username` = ? LIMIT 1",
            [$admin['email'], $admin['username']]
        );

        if ($existing) {
            throw new \RuntimeException('An administrator with this username or email already exists. Please sign in or choose a different database/prefix.');
        }

        $now = date('Y-m-d H:i:s');
        $userId = $db->insert('users', [
            'username' => $admin['username'],
            'name' => $admin['username'],
            'email' => $admin['email'],
            'password' => password_hash($admin['password'], PASSWORD_DEFAULT),
            'status' => 'active',
            'email_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $superAdminRole = $db->selectOne("SELECT id FROM `roles` WHERE `slug` = 'super-admin' LIMIT 1");
        if ($superAdminRole) {
            $db->execute(
                "INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`) VALUES (?, ?)",
                [$userId, $superAdminRole->id]
            );
        }

        return $userId;
    }

    protected function writeSettings(array $site, array $admin): void
    {
        Setting::clearCache();
        Setting::set('general', 'site_name', $site['name']);
        Setting::set('general', 'site_url', $site['url']);
        Setting::set('general', 'admin_email', $admin['email']);
    }

    public function publicMessage(\Throwable $e): string
    {
        Logger::error('Installer failure', ['message' => $this->redact($e->getMessage())]);

        $message = $e->getMessage();
        if (str_contains($message, 'SQLSTATE') || str_contains($message, 'Access denied')) {
            return 'Database connection failed. Please verify the database host, username, password, and database name.';
        }

        return $message;
    }

    protected function redact(string $message): string
    {
        return preg_replace('/(password\s*[=:]\s*)([^\s]+)/i', '$1[redacted]', $message) ?? $message;
    }
}
