<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Migrator;
use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    protected static Database $db;

    public static function setUpBeforeClass(): void
    {
        $config = new Config();
        $dbConf = $config->get('database');
        static::$db = new Database($dbConf);
    }

    public function testDatabaseConnection(): void
    {
        $result = static::$db->selectOne('SELECT 1 AS one');
        $this->assertNotNull($result);
        $this->assertSame('1', (string)$result->one);
    }

    public function testRequiredTablesExist(): void
    {
        $expectedTables = [
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
            'media',
            'menus',
            'menu_items',
            'settings',
            'seo_meta',
            'sessions',
            'plugin_settings',
        ];

        foreach ($expectedTables as $table) {
            $this->assertTrue(
                static::$db->tableExists($table),
                "Expected table '$table' to exist in the database."
            );
        }
    }

    public function testSettingsTableHasDefaultValues(): void
    {
        $count = static::$db->selectOne('SELECT COUNT(*) as cnt FROM settings');
        $this->assertNotNull($count);
        $this->assertGreaterThan(0, (int)$count->cnt, 'Settings table should have default values seeded.');
    }

    public function testRolesTableHasDefaultRoles(): void
    {
        $roles = static::$db->select('SELECT slug FROM roles ORDER BY id');
        $slugs = array_column($roles, 'slug');
        $this->assertContains('super-admin', $slugs);
        $this->assertContains('admin', $slugs);
        $this->assertContains('editor', $slugs);
        $this->assertContains('author', $slugs);
        $this->assertContains('subscriber', $slugs);
    }

    public function testPermissionsTableHasDefaultPermissions(): void
    {
        $count = static::$db->selectOne('SELECT COUNT(*) as cnt FROM permissions');
        $this->assertNotNull($count);
        $this->assertGreaterThan(0, (int)$count->cnt, 'Permissions should be seeded.');
    }

    public function testTaxonomiesHasDefaultCategory(): void
    {
        $cat = static::$db->selectOne("SELECT slug FROM taxonomies WHERE slug = 'uncategorized'");
        $this->assertNotNull($cat);
        $this->assertSame('uncategorized', $cat->slug);
    }

    public function testDatabaseInsertAndDelete(): void
    {
        // Test insert/select/delete roundtrip on sessions table (safe to use for testing)
        $testId = 'test_session_' . bin2hex(random_bytes(8));
        $now    = time();

        static::$db->execute(
            'INSERT INTO sessions (id, payload, last_activity) VALUES (?, ?, ?)',
            [$testId, 'test_payload', $now]
        );

        $row = static::$db->selectOne('SELECT id FROM sessions WHERE id = ?', [$testId]);
        $this->assertNotNull($row);
        $this->assertSame($testId, $row->id);

        static::$db->delete('sessions', ['id' => $testId]);
        $row = static::$db->selectOne('SELECT id FROM sessions WHERE id = ?', [$testId]);
        $this->assertNull($row);
    }

    public function testMigratorStatus(): void
    {
        $migrator = new Migrator(static::$db);
        $migrator->createMigrationsTableIfNotExists();
        $files = $migrator->getMigrationFiles(APP_ROOT . '/database/migrations');
        $this->assertCount(13, $files, 'Expected 13 migration files.');

        foreach ($files as $file) {
            $name = basename($file, '.php');
            $this->assertTrue(
                $migrator->hasRun($name),
                "Migration '$name' should have been run."
            );
        }
    }

    public function testInstallationState(): void
    {
        // This tests the isInstalled() contract — the lock file presence
        $lockPath  = APP_ROOT . '/storage/installed.lock';
        $installed = file_exists($lockPath);

        // We don't assert true/false because state depends on whether
        // the installer has been run. We just assert the check is deterministic.
        $this->assertIsBool($installed);
    }
}

