<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Import;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\Post;
use FavoriteCMS\Models\User;
use FavoriteCMS\Services\Import\ImportEngine;
use PHPUnit\Framework\TestCase;

class ImportEngineTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;
    protected static int $adminUserId;
    protected ImportEngine $engine;

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db = static::$app->make(Database::class);

        $now = date('Y-m-d H:i:s');
        $hash = password_hash('Pass123!', PASSWORD_DEFAULT);
        $unique = 'import_admin_' . bin2hex(random_bytes(4));

        static::$adminUserId = static::$db->insert('users', [
            'username'          => $unique,
            'name'              => 'Import Administrator',
            'email'             => $unique . '@example.com',
            'password'          => $hash,
            'status'            => 'active',
            'email_verified_at' => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(static::$adminUserId) && static::$adminUserId > 0) {
            static::$db->execute("DELETE FROM users WHERE id = ?", [static::$adminUserId]);
        }
    }

    protected function setUp(): void
    {
        $this->engine = new ImportEngine(static::$app);
    }

    public function testPlatformReadinessRegistryIntegrity(): void
    {
        $registry = $this->engine->getPlatformRegistry();

        $this->assertArrayHasKey('blogger', $registry);
        $this->assertSame('READY', $registry['blogger']['status']);

        $this->assertArrayHasKey('wordpress', $registry);
        $this->assertSame('READY', $registry['wordpress']['status']);

        $this->assertArrayHasKey('rss_atom', $registry);
        $this->assertSame('READY', $registry['rss_atom']['status']);

        $this->assertArrayHasKey('json', $registry);
        $this->assertSame('READY', $registry['json']['status']);

        // Explicitly check NOT_READY platforms
        $this->assertArrayHasKey('ghost', $registry);
        $this->assertSame('NOT_READY', $registry['ghost']['status']);

        $this->assertArrayHasKey('medium', $registry);
        $this->assertSame('NOT_READY', $registry['medium']['status']);

        $this->assertArrayHasKey('drupal', $registry);
        $this->assertSame('NOT_READY', $registry['drupal']['status']);

        $this->assertArrayHasKey('joomla', $registry);
        $this->assertSame('NOT_READY', $registry['joomla']['status']);
    }

    public function testAutoDetectsDifferentFormats(): void
    {
        $bloggerXml = '<feed xmlns="http://www.w3.org/2005/Atom"><category scheme="http://schemas.google.com/g/2005#kind" term="http://schemas.google.com/blogger/2008/kind#post"/></feed>';
        $wpXml = '<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/"><channel><title>WP</title></channel></rss>';
        $rssXml = '<rss version="2.0"><channel><title>Standard RSS</title></channel></rss>';
        $json = json_encode(['posts' => []]);

        $this->assertSame('blogger', $this->engine->detectAdapter($bloggerXml)?->getId());
        $this->assertSame('wordpress', $this->engine->detectAdapter($wpXml)?->getId());
        $this->assertSame('rss_atom', $this->engine->detectAdapter($rssXml)?->getId());
        $this->assertSame('json', $this->engine->detectAdapter($json)?->getId());
    }

    public function testPreviewGeneratesAccurateStatistics(): void
    {
        $json = json_encode([
            'posts' => [
                ['title' => 'Post A', 'content' => 'Content A', 'status' => 'published'],
                ['title' => 'Post B', 'content' => 'Content B', 'status' => 'draft'],
            ],
            'pages' => [
                ['title' => 'Page 1', 'content' => 'Page Content'],
            ],
        ]);

        $preview = $this->engine->preview($json);
        $this->assertTrue($preview['success']);
        $this->assertSame(2, $preview['counts']['posts']);
        $this->assertSame(1, $preview['counts']['posts_published']);
        $this->assertSame(1, $preview['counts']['posts_draft']);
        $this->assertSame(1, $preview['counts']['pages']);
    }

    public function testImportDeduplicationModes(): void
    {
        $uniqueTitle = 'Unique Import Title ' . bin2hex(random_bytes(4));
        $slug = 'unique-import-slug-' . bin2hex(random_bytes(4));

        $exportJson = json_encode([
            'posts' => [
                [
                    'id'           => 'source_1001',
                    'title'        => $uniqueTitle,
                    'slug'         => $slug,
                    'content'      => '<p>Original Content</p>',
                    'status'       => 'published',
                    'published_at' => '2024-03-01 10:00:00',
                ],
            ],
        ]);

        $options = [
            'deduplication_mode' => 'skip',
            'import_media'       => false,
            'author_id'          => static::$adminUserId,
        ];

        // First Import: Should import 1 post
        $report1 = $this->engine->import($exportJson, $options, 'json');
        $this->assertSame(1, $report1['posts']['imported']);
        $this->assertSame(0, $report1['posts']['skipped']);

        // Second Import with 'skip': Should skip the duplicate
        $report2 = $this->engine->import($exportJson, $options, 'json');
        $this->assertSame(0, $report2['posts']['imported']);
        $this->assertSame(1, $report2['posts']['skipped']);

        // Third Import with 'update': Should update existing content
        $updatedJson = json_encode([
            'posts' => [
                [
                    'id'           => 'source_1001',
                    'title'        => $uniqueTitle,
                    'slug'         => $slug,
                    'content'      => '<p>Refreshed Content</p>',
                    'status'       => 'published',
                    'published_at' => '2024-03-01 10:00:00',
                ],
            ],
        ]);

        $updateOptions = [
            'deduplication_mode' => 'update',
            'import_media'       => false,
            'author_id'          => static::$adminUserId,
        ];

        $report3 = $this->engine->import($updatedJson, $updateOptions, 'json');
        $this->assertSame(1, $report3['posts']['updated']);
        $this->assertSame(0, $report3['posts']['skipped']);

        // Verify updated post in database
        $post = Post::findBySlug($slug);
        $this->assertNotNull($post);
        $this->assertStringContainsString('Refreshed Content', $post->content);

        // Fourth Import with 'create_new': Should import as a new entry with an incremented slug
        $createNewOptions = [
            'deduplication_mode' => 'create_new',
            'import_media'       => false,
            'author_id'          => static::$adminUserId,
        ];

        $report4 = $this->engine->import($exportJson, $createNewOptions, 'json');
        $this->assertSame(1, $report4['posts']['imported']);

        // Clean up created test posts
        static::$db->execute("DELETE FROM posts WHERE slug LIKE ?", [$slug . '%']);
    }

    public function testAuthorCreationNeverGrantsAdminPrivileges(): void
    {
        $uniqueAuthor = 'author_' . bin2hex(random_bytes(4));
        $authorEmail = $uniqueAuthor . '@testdomain.test';

        $exportJson = json_encode([
            'posts' => [
                [
                    'title'   => 'Author Test Post',
                    'content' => '<p>Test</p>',
                    'author'  => [
                        'name'  => $uniqueAuthor,
                        'email' => $authorEmail,
                    ],
                ],
            ],
        ]);

        $options = [
            'author_handling'    => 'create_author',
            'import_media'       => false,
            'author_id'          => static::$adminUserId,
            'deduplication_mode' => 'create_new',
        ];

        $report = $this->engine->import($exportJson, $options, 'json');
        $this->assertSame(1, $report['authors']['created']);

        $createdUser = User::findByEmail($authorEmail);
        $this->assertNotNull($createdUser);
        $this->assertFalse($createdUser->hasRole('admin'));
        $this->assertFalse($createdUser->hasRole('super-admin'));

        // Clean up test user & post
        static::$db->execute("DELETE FROM posts WHERE title = 'Author Test Post'");
        static::$db->execute("DELETE FROM user_roles WHERE user_id = ?", [$createdUser->id]);
        static::$db->execute("DELETE FROM users WHERE id = ?", [$createdUser->id]);
    }
}
