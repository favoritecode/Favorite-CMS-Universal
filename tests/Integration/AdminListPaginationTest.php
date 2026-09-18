<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Http\Controllers\Admin\CommentController;
use FavoriteCMS\Http\Controllers\Admin\DashboardController;
use FavoriteCMS\Http\Controllers\Admin\MediaController;
use FavoriteCMS\Http\Controllers\Admin\PageController;
use FavoriteCMS\Http\Controllers\Admin\PostController;
use FavoriteCMS\Http\Controllers\Admin\TaxonomyController;
use FavoriteCMS\Http\Controllers\Admin\UserController;
use PHPUnit\Framework\TestCase;

/**
 * Admin collection screens render a small bounded page and keep their filters.
 */
class AdminListPaginationTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;
    protected static string $token;
    protected static int $adminId;
    protected static int $postId;
    protected static array $pageIds = [];
    protected static array $mediaIds = [];

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db = static::$app->make(Database::class);
        static::$token = 'a3' . bin2hex(random_bytes(3));
        $now = date('Y-m-d H:i:s');

        static::$db->execute("INSERT IGNORE INTO `roles` (`name`, `slug`, `description`, `is_system`) VALUES ('Administrator', 'admin', 'Site administrator', 1)");
        static::$adminId = static::$db->insert('users', [
            'username' => 'a3admin_' . static::$token, 'name' => 'Admin ' . static::$token, 'email' => 'a3admin_' . static::$token . '@example.com',
            'password' => password_hash('Pass123!', PASSWORD_DEFAULT), 'status' => 'active', 'email_verified_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $role = static::$db->selectOne("SELECT `id` FROM `roles` WHERE `slug` = 'admin' LIMIT 1");
        static::$db->execute('INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES (?, ?)', [static::$adminId, $role->id]);

        for ($i = 1; $i <= 15; $i++) {
            static::$pageIds[] = (int)static::$db->insert('pages', [
                'title' => 'A3 Page ' . static::$token . ' ' . $i, 'slug' => 'a3-page-' . static::$token . '-' . $i, 'content' => '<p>Admin list page</p>',
                'status' => 'draft', 'author_id' => static::$adminId, 'menu_order' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        static::$postId = (int)static::$db->insert('posts', [
            'title' => 'A3 Post ' . static::$token, 'slug' => 'a3-post-' . static::$token, 'content' => '<p>Post</p>', 'status' => 'published', 'type' => 'post',
            'author_id' => static::$adminId, 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        for ($i = 1; $i <= 14; $i++) {
            static::$db->insert('comments', [
                'post_id' => static::$postId, 'author_name' => 'A3 Reader', 'author_email' => 'a3@example.com', 'content' => 'A3 comment ' . $i,
                'status' => 'spam', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        for ($i = 1; $i <= 17; $i++) {
            $isDocument = $i > 14;
            $filename = 'a3-' . static::$token . '-' . $i . ($isDocument ? '.pdf' : '.png');
            static::$mediaIds[] = (int)static::$db->insert('media', [
                'filename' => $filename, 'stored_filename' => $filename, 'path' => 'uploads/a3/' . $filename, 'url' => '/uploads/a3/' . $filename,
                'mime_type' => $isDocument ? 'application/pdf' : 'image/png', 'size' => 100, 'uploader_id' => static::$adminId, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public static function tearDownAfterClass(): void
    {
        static::$db->execute('DELETE FROM `comments` WHERE `post_id` = ?', [static::$postId ?? 0]);
        static::$db->execute('DELETE FROM `posts` WHERE `id` = ?', [static::$postId ?? 0]);
        if (static::$pageIds) {
            static::$db->execute('DELETE FROM `pages` WHERE `id` IN (' . implode(',', static::$pageIds) . ')');
        }
        if (static::$mediaIds) {
            static::$db->execute('DELETE FROM `media` WHERE `id` IN (' . implode(',', static::$mediaIds) . ')');
        }
        static::$db->execute('DELETE FROM `user_roles` WHERE `user_id` = ?', [static::$adminId ?? 0]);
        static::$db->execute('DELETE FROM `users` WHERE `id` = ?', [static::$adminId ?? 0]);
        $_GET = [];
    }

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = ['auth_user_id' => static::$adminId, '_token' => 'a3_token'];
        $_GET = [];
    }

    private function request(string $uri, array $get = []): Request
    {
        $_GET = $get;
        return new Request(get: $get, post: [], server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'HTTP_HOST' => 'favorite-cms.local']);
    }

    private function countRows(string $sql, array $params = []): int
    {
        return (int)(static::$db->selectOne($sql, $params)->cnt ?? 0);
    }

    public function testPagesListIsBoundedAndKeepsSearchAndStatusFilters(): void
    {
        $controller = new PageController(static::$app);

        $page1 = $controller->index($this->request('/admin/pages', ['status' => 'draft', 's' => static::$token]))->getContent();
        $this->assertSame(12, substr_count($page1, '<input type="checkbox" name="ids[]" value="'));
        $this->assertStringContainsString('15 items &bull; Page 1 of 2', $page1);
        $this->assertStringContainsString('status=draft', $page1);
        $this->assertStringContainsString('s=' . static::$token, $page1);

        $page2 = $controller->index($this->request('/admin/pages', ['status' => 'draft', 's' => static::$token, 'p' => '2']))->getContent();
        $this->assertSame(3, substr_count($page2, '<input type="checkbox" name="ids[]" value="'));

        $published = $controller->index($this->request('/admin/pages', ['status' => 'published', 's' => static::$token]))->getContent();
        $this->assertSame(0, substr_count($published, '<input type="checkbox" name="ids[]" value="'), 'Status filter is preserved');
    }

    public function testCommentsListIsBoundedPerStatus(): void
    {
        $total = $this->countRows("SELECT COUNT(*) AS cnt FROM `comments` WHERE `status` = 'spam'");
        $html = (new CommentController(static::$app))->index($this->request('/admin/comments', ['status' => 'spam']))->getContent();

        $this->assertSame(min(12, $total), substr_count($html, '<input type="checkbox" name="ids[]" value="'));
        $this->assertStringContainsString(number_format($total) . ' items', $html);
        $this->assertStringContainsString('comments-bulk-form', $html, 'Bulk actions remain available');
    }

    public function testUsersListIsBounded(): void
    {
        $total = $this->countRows('SELECT COUNT(*) AS cnt FROM `users`');
        $html = (new UserController(static::$app))->index($this->request('/admin/users'))->getContent();

        $this->assertSame(min(12, $total), substr_count($html, '<div class="row-actions">'));
        $this->assertStringContainsString(number_format($total) . ' items', $html);
    }

    public function testMediaLibraryFiltersAndPaginatesInSql(): void
    {
        $controller = new MediaController(static::$app);

        $page1 = $controller->index($this->request('/admin/media', ['s' => static::$token]))->getContent();
        $this->assertSame(12, substr_count($page1, 'class="copy-url-btn"'));
        $this->assertStringContainsString('17 items &bull; Page 1 of 2', $page1);

        $page2 = $controller->index($this->request('/admin/media', ['s' => static::$token, 'p' => '2']))->getContent();
        $this->assertSame(5, substr_count($page2, 'class="copy-url-btn"'));

        $documents = $controller->index($this->request('/admin/media', ['s' => static::$token, 'category' => 'document']))->getContent();
        $this->assertSame(3, substr_count($documents, 'class="copy-url-btn"'));

        $images = $controller->index($this->request('/admin/media', ['s' => static::$token, 'category' => 'image']))->getContent();
        $this->assertStringContainsString('14 items', $images);
    }

    public function testMediaLibraryJsonEndpointReturnsBoundedBatches(): void
    {
        $controller = new MediaController(static::$app);

        $first = json_decode($controller->library($this->request('/admin/media/library', ['s' => static::$token, 'per_page' => '5', 'page' => '1']))->getContent(), true);
        $this->assertTrue($first['success']);
        $this->assertCount(5, $first['items']);
        $this->assertSame(17, $first['total']);
        $this->assertTrue($first['has_more']);
        $this->assertArrayHasKey('formatted_size', $first['items'][0]);

        $last = json_decode($controller->library($this->request('/admin/media/library', ['s' => static::$token, 'per_page' => '5', 'page' => '4']))->getContent(), true);
        $this->assertCount(2, $last['items']);
        $this->assertFalse($last['has_more']);

        $capped = json_decode($controller->library($this->request('/admin/media/library', ['per_page' => '5000']))->getContent(), true);
        $this->assertLessThanOrEqual(60, count($capped['items']));
    }

    public function testTaxonomyListIsBounded(): void
    {
        $total = $this->countRows("SELECT COUNT(*) AS cnt FROM `taxonomies` WHERE `taxonomy` = 'category'");
        $html = (new TaxonomyController(static::$app))->categories($this->request('/admin/taxonomies/categories'))->getContent();

        $this->assertSame(min(12, $total), substr_count($html, '<div class="row-actions">'));
        $this->assertStringContainsString('name="parent_id"', $html, 'Parent category selector remains available');
    }

    public function testEditorMediaPickerLoadsABoundedFirstBatch(): void
    {
        $total = $this->countRows('SELECT COUNT(*) AS cnt FROM `media`');
        $html = (new PostController(static::$app))->create($this->request('/admin/posts/new'))->getContent();

        $this->assertSame(min(24, $total), substr_count($html, 'class="media-picker-card"'));
        $this->assertStringContainsString('id="modal-media-more-btn"', $html);
        $this->assertStringContainsString('/admin/media/library?page=', $html);

        $pageForm = (new PageController(static::$app))->create($this->request('/admin/pages/new'))->getContent();
        $this->assertStringContainsString('id="page-featured-image"', $pageForm);
    }

    public function testDashboardRendersWithCountQueries(): void
    {
        $response = (new DashboardController(static::$app))->index($this->request('/admin'));
        $this->assertSame(200, $response->getStatusCode());
    }
}
