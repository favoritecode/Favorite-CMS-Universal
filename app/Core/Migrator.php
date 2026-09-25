<?php

declare(strict_types=1);

namespace FavoriteCMS\Core;

class Migrator
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function migrate(string $migrationsPath): array
    {
        $this->createMigrationsTableIfNotExists();
        $applied = [];
        $files = $this->getMigrationFiles($migrationsPath);
        $batch = $this->getNextBatchNumber();

        foreach ($files as $file) {
            $migrationName = basename($file, '.php');
            if (!$this->hasRun($migrationName)) {
                require_once $file;
                $class = $this->resolveClassName($migrationName);
                $migration = new $class($this->db);
                $migration->up();
                
                $this->markAsRun($migrationName, $batch);
                $applied[] = $migrationName;
            }
        }

        return $applied;
    }

    public function rollback(string $migrationsPath, int $steps = 1): array
    {
        $rolledBack = [];
        // simple rollback logic would go here
        return $rolledBack;
    }

    public function getMigrationFiles(string $path): array
    {
        $files = glob($path . '/*.php');
        if ($files === false) {
            return [];
        }
        sort($files);
        return $files;
    }

    public function hasRun(string $migration): bool
    {
        $result = $this->db->selectOne("SELECT id FROM `cms_migrations` WHERE migration = ?", [$migration]);
        return $result !== null;
    }

    public function markAsRun(string $migration, int $batch): void
    {
        $this->db->insert('cms_migrations', [
            'migration' => $migration,
            'batch' => $batch
        ]);
    }

    public function createMigrationsTableIfNotExists(): void
    {
        if (!$this->db->tableExists('cms_migrations')) {
            $this->db->execute("
                CREATE TABLE `cms_migrations` (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    migration VARCHAR(255) NOT NULL,
                    batch INT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
        }
    }

    protected function getNextBatchNumber(): int
    {
        $result = $this->db->selectOne("SELECT MAX(batch) as max_batch FROM `cms_migrations`");
        return ($result && $result->max_batch) ? (int)$result->max_batch + 1 : 1;
    }

    protected function resolveClassName(string $filename): string
    {
        $parts = explode('_', $filename);
        array_shift($parts); // remove the numbers
        return implode('', array_map('ucfirst', $parts));
    }
}
