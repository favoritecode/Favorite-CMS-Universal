<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Kernel;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Post;
use FavoriteCMS\Models\Page;
use FavoriteCMS\Models\Taxonomy;
use FavoriteCMS\Models\Comment;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Themes\ThemeManager;
use FavoriteCMS\Plugins\PluginManager;
use PHPUnit\Framework\TestCase;

class CmsFeatureTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;
    protected static string $lockFile;
    protected static int $adminUserId;

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$db  = static::$app->make(Database::class);
        static::$lockFile = APP_ROOT . '/storage/installed.lock';

        // Ensure installed lock exists for feature tests
        if (!file_exists(static::$lockFile)) {
            file_put_contents(static::$lockFile, "installed\n");
        }

        // Ensure at least one admin user exists
        $user = static::$db->selectOne("SELECT id FROM `users` WHERE `username` = 'feature_admin' LIMIT 1");
        if (!$user) {
            static::$adminUserId = static::$db->insert('users', [
                'username'   => 'feature_admin',
                'name'       => 'Feature Admin',
                'email'      => 'feature_admin@example.com',
                'password'   => password_hash('AdminPass123!', PASSWORD_DEFAULT),
                'status'     => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            static::$adminUserId = (int)$user->id;
        }

        $_SESSION['auth_user_id']   = static::$adminUserId;
        $_SESSION['auth_user_name'] = 'Feature Admin';
    }

    public static function tearDownAfterClass(): void
    {
        // Cleanup test data
        static::$db->execute("DELETE FROM `comments` WHERE `author_name` LIKE 'TestCommenter%'");
        static::$db->execute("DELETE FROM `posts` WHERE `slug` LIKE 'test-feature-%'");
        static::$db->execute("DELETE FROM `pages` WHERE `slug` LIKE 'test-page-%'");
        static::$db->execute("DELETE FROM `taxonomies` WHERE `slug` LIKE 'test-cat-%' OR `slug` LIKE 'test-tag-%'");
        static::$db->execute("DELETE FROM `users` WHERE `username` = 'feature_admin'");
    }

    public function testPostCreationAndTaxonomySyncing(): void
    {
        // 1. Create a category
        $catId = static::$db->insert('taxonomies', [
            'name'       => 'Feature Test Category',
            'slug'       => 'test-cat-feature',
            'taxonomy'   => 'category',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // 2. Create a post
        $now = date('Y-m-d H:i:s');
        $postId = static::$db->insert('posts', [
            'title'        => 'Test Feature Post',
            'slug'         => 'test-feature-post',
            'content'      => 'This is the full content of the test feature post.',
            'excerpt'      => 'Short summary of the test feature post.',
            'status'       => 'published',
            'type'         => 'post',
            'author_id'    => static::$adminUserId,
            'published_at' => $now,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $post = Post::find($postId);
        $this->assertNotNull($post);
        $this->assertSame('Test Feature Post', $post->title);

        // 3. Sync category & tags
        $post->syncTaxonomies([$catId], 'category');
        $post->syncTags('php, test, cms');

        $cats = $post->getTaxonomies('category');
        $this->assertCount(1, $cats);
        $this->assertSame('Feature Test Category', $cats[0]->name);

        $tags = $post->getTaxonomies('tag');
        $this->assertGreaterThanOrEqual(1, count($tags));

        // 4. Save SEO meta
        $post->saveSeoMeta([
            'meta_title'       => 'Custom SEO Title',
            'meta_description' => 'Custom SEO description.',
        ]);

        $seo = $post->getSeoMeta();
        $this->assertNotNull($seo);
        $this->assertSame('Custom SEO Title', $seo->meta_title);
    }

    public function testPageCreationAndRetrieval(): void
    {
        $now = date('Y-m-d H:i:s');
        $pageId = static::$db->insert('pages', [
            'title'      => 'Test Feature Page',
            'slug'       => 'test-page-feature',
            'content'    => 'This is a static page content for test.',
            'status'     => 'published',
            'menu_order' => 5,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $page = Page::findBySlug('test-page-feature');
        $this->assertNotNull($page);
        $this->assertSame('Test Feature Page', $page->title);
        $this->assertSame(5, (int)$page->menu_order);
    }

    public function testCommentLifecycle(): void
    {
        $post = Post::findBySlug('test-feature-post');
        $this->assertNotNull($post);

        // Submit comment
        $commentId = static::$db->insert('comments', [
            'post_id'      => $post->id,
            'author_name'  => 'TestCommenter One',
            'author_email' => 'commenter@example.com',
            'content'      => 'Great post! Testing comments.',
            'status'       => 'approved',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $comment = Comment::find($commentId);
        $this->assertNotNull($comment);
        $this->assertSame('approved', $comment->status);

        $comments = $post->getComments('approved');
        $this->assertNotEmpty($comments);

        // Test comment counts
        $counts = Comment::countByStatus();
        $this->assertGreaterThan(0, $counts['approved']);
    }

    public function testThemeManager(): void
    {
        $manager = new ThemeManager(static::$app);
        $themes = $manager->getInstalledThemes();

        $this->assertArrayHasKey('default', $themes);
        $this->assertSame('default', $manager->getActiveTheme());
    }

    public function testPluginManager(): void
    {
        $manager = new PluginManager(static::$app);
        $plugins = $manager->getInstalledPlugins();

        $this->assertArrayHasKey('favorite-quick-notes', $plugins);

        // Test activation
        $manager->activatePlugin('favorite-quick-notes');
        $this->assertContains('favorite-quick-notes', $manager->getActivePlugins());

        // Test boot
        $manager->bootActivePlugins();
        $this->assertTrue(defined('FAVORITE_QUICK_NOTES_LOADED'));

        // Test deactivation
        $manager->deactivatePlugin('favorite-quick-notes');
        $this->assertNotContains('favorite-quick-notes', $manager->getActivePlugins());
    }

    public function testPublicFrontendRendering(): void
    {
        $kernel = new Kernel(static::$app);

        // 1. Homepage
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/']);
        $resp = $kernel->handle($req);
        $refContent = new \ReflectionProperty(Response::class, 'content');
        $refContent->setAccessible(true);
        $html = $refContent->getValue($resp);

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('Test Feature Post', $html);

        // 2. Single post
        $postReq = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/post/test-feature-post']);
        $postResp = $kernel->handle($postReq);
        $postHtml = $refContent->getValue($postResp);

        $this->assertStringContainsString('Test Feature Post', $postHtml);
        $this->assertStringContainsString('Great post! Testing comments', $postHtml);

        // 3. Static page
        $pageReq = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/page/test-page-feature']);
        $pageResp = $kernel->handle($pageReq);
        $pageHtml = $refContent->getValue($pageResp);

        $this->assertStringContainsString('Test Feature Page', $pageHtml);
        $this->assertStringContainsString('This is a static page content for test', $pageHtml);

        // 4. Category archive
        $catReq = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/category/test-cat-feature']);
        $catResp = $kernel->handle($catReq);
        $catHtml = $refContent->getValue($catResp);

        $this->assertStringContainsString('Category: Feature Test Category', $catHtml);
        $this->assertStringContainsString('Test Feature Post', $catHtml);

        // 5. Search
        $searchReq = new Request(['q' => 'Feature'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/search?q=Feature']);
        $searchResp = $kernel->handle($searchReq);
        $searchHtml = $refContent->getValue($searchResp);

        $this->assertStringContainsString('Search Results for: &quot;Feature&quot;', $searchHtml);
        $this->assertStringContainsString('Test Feature Post', $searchHtml);

        // 6. XML Sitemap
        $smReq = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/sitemap.xml']);
        $smResp = $kernel->handle($smReq);
        $smXml = $refContent->getValue($smResp);

        $this->assertStringContainsString('<?xml', $smXml);
        $this->assertStringContainsString('<urlset', $smXml);
        $this->assertStringContainsString('/post/test-feature-post', $smXml);

        // 7. Robots.txt
        $rbReq = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/robots.txt']);
        $rbResp = $kernel->handle($rbReq);
        $rbTxt = $refContent->getValue($rbResp);

        $this->assertStringContainsString('User-agent:', $rbTxt);

        // 8. 404 Not Found
        $nfReq = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/nonexistent-slug-xyz']);
        $nfResp = $kernel->handle($nfReq);
        $statusRef = new \ReflectionProperty(Response::class, 'status');
        $statusRef->setAccessible(true);

        $this->assertSame(404, $statusRef->getValue($nfResp));
        $nfHtml = $refContent->getValue($nfResp);
        $this->assertStringContainsString('404', $nfHtml);
    }
}
