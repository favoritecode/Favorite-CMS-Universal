<?php

declare(strict_types=1);

/**
 * Favorite CMS — CLI Migration Runner
 *
 * Usage:
 *   php migrate.php              Run all pending migrations
 *   php migrate.php --fresh      Drop all tables and re-run (DESTRUCTIVE)
 *   php migrate.php --status     Show migration status
 */

define('APP_ROOT', __DIR__);

if (PHP_SAPI !== 'cli') {
    exit("This script must be run from the command line.\n");
}

require APP_ROOT . '/vendor/autoload.php';
require APP_ROOT . '/app/Core/helpers.php';

use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Migrator;

// Load .env
if (file_exists(APP_ROOT . '/.env')) {
    $lines = file(APP_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
        $name  = trim($name);
        $value = trim($value, " \t\"'");
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv("$name=$value");
            $_ENV[$name]    = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Configure error reporting for CLI
ini_set('display_errors', '1');
error_reporting(E_ALL);

$args  = array_slice($argv, 1);
$fresh  = in_array('--fresh', $args, true);
$status = in_array('--status', $args, true);

echo "=== Favorite CMS Migration Runner ===\n\n";

try {
    $config   = new Config();
    $dbConfig = $config->get('database');

    echo "Connecting to database: {$dbConfig['database']} @ {$dbConfig['host']}:{$dbConfig['port']}\n";

    $db       = new Database($dbConfig);
    $migrator = new Migrator($db);

    echo "Database connected successfully.\n\n";

    $migrationsPath = APP_ROOT . '/database/migrations';

    if ($status) {
        // Show status
        $migrator->createMigrationsTableIfNotExists();
        $files = $migrator->getMigrationFiles($migrationsPath);
        echo str_pad('Migration', 55) . str_pad('Status', 12) . "Batch\n";
        echo str_repeat('-', 75) . "\n";
        foreach ($files as $file) {
            $name   = basename($file, '.php');
            $ran    = $migrator->hasRun($name);
            $batch  = '';
            if ($ran) {
                $row   = $db->selectOne('SELECT batch FROM cms_migrations WHERE migration = ?', [$name]);
                $batch = $row ? (string)$row->batch : '?';
            }
            $statusStr = $ran ? 'Ran' : 'Pending';
            echo str_pad($name, 55) . str_pad($statusStr, 12) . $batch . "\n";
        }
        echo "\n";
        exit(0);
    }

    if ($fresh) {
        echo "WARNING: --fresh will drop all tables and re-run migrations.\n";
        echo "Type 'yes' to continue: ";
        $confirm = trim(fgets(STDIN));
        if ($confirm !== 'yes') {
            echo "Aborted.\n";
            exit(0);
        }

        echo "\nDropping all tables...\n";
        // Disable FK checks
        $db->execute('SET FOREIGN_KEY_CHECKS=0');
        $tables = $db->select('SHOW TABLES');
        foreach ($tables as $row) {
            $table = array_values((array)$row)[0];
            $db->execute("DROP TABLE IF EXISTS `$table`");
            echo "  Dropped: $table\n";
        }
        $db->execute('SET FOREIGN_KEY_CHECKS=1');
        echo "\n";
    }

    echo "Running migrations...\n";
    $applied = $migrator->migrate($migrationsPath);

    if (empty($applied)) {
        echo "Nothing to migrate. All migrations are up to date.\n";
    } else {
        foreach ($applied as $migration) {
            echo "  [OK] $migration\n";
        }
        echo "\n" . count($applied) . " migration(s) applied successfully.\n";
    }

} catch (\Throwable $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n";
    echo "\nTrace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\nDone.\n";

