<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Http\Controllers\Admin\MediaController;
use FavoriteCMS\Models\Media;
use FavoriteCMS\Models\Page;
use FavoriteCMS\Models\Post;
use FavoriteCMS\Models\User;
use PHPUnit\Framework\TestCase;

class MediaLibraryMultiUploadAndBulkTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;
    protected static User $adminUser;

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db  = static::$app->make(Database::class);

        $admin = User::findByUsername('admin');
        if (!$admin) {
            $adminId = static::$db->insert('users', [
                'username'      => 'admin',
                'email'         => 'admin@test.local',
                'password_hash' => password_hash('Admin123!', PASSWORD_BCRYPT),
                'role'          => 'administrator',
                'status'        => 'active',
                'created_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
            $admin = User::find($adminId);
        }
        static::$adminUser = $admin;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [
            'auth_user_id' => static::$adminUser->id,
            '_token'       => 'test_csrf_token_12345',
        ];
    }

    public function testNormalizeFilesHandlesSingleAndMultipleFiles(): void
    {
        $controller = new MediaController(static::$app);
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('normalizeFiles');
        $method->setAccessible(true);

        // 1. Single file: $_FILES['file']
        $singleFiles = [
            'file' => [
                'name'     => 'photo.jpg',
                'type'     => 'image/jpeg',
                'tmp_name' => '/tmp/php123',
                'error'    => UPLOAD_ERR_OK,
                'size'     => 1024,
            ],
        ];
        $result = $method->invoke($controller, $singleFiles);
        $this->assertCount(1, $result);
        $this->assertSame('photo.jpg', $result[0]['name']);

        // 2. Multiple files: $_FILES['files']
        $multiFiles = [
            'files' => [
                'name'     => ['pic1.jpg', 'pic2.png'],
                'type'     => ['image/jpeg', 'image/png'],
                'tmp_name' => ['/tmp/p1', '/tmp/p2'],
                'error'    => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                'size'     => [1024, 2048],
            ],
        ];
        $resultMulti = $method->invoke($controller, $multiFiles);
        $this->assertCount(2, $resultMulti);
        $this->assertSame('pic1.jpg', $resultMulti[0]['name']);
        $this->assertSame('pic2.png', $resultMulti[1]['name']);

        // 3. Empty files
        $this->assertEmpty($method->invoke($controller, []));
    }

    public function testBulkDeleteRequiresCsrfToken(): void
    {
        $controller = new MediaController(static::$app);

        $request = new Request([], [
            'ids'    => [1, 2],
            '_token' => 'invalid_csrf_token',
        ], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/admin/media/bulk-delete',
            'HTTP_ACCEPT'    => 'application/json',
        ]);

        $response = $controller->bulkDelete($request);
        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertFalse($body['success']);
        $this->assertStringContainsString('CSRF', $body['message']);
    }

    public function testBulkDeleteRequiresNonEmptyIds(): void
    {
        $controller = new MediaController(static::$app);

        $request = new Request([], [
            'ids'    => [],
            '_token' => 'test_csrf_token_12345',
        ], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/admin/media/bulk-delete',
            'HTTP_ACCEPT'    => 'application/json',
        ]);

        $response = $controller->bulkDelete($request);
        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertFalse($body['success']);
    }

    public function testBulkDeleteDeletesMediaAndSafelyNullifiesReferences(): void
    {
        // 1. Create two test media items on disk and in database
        $uploadsDir = sys_get_temp_dir();
        $fileA = $uploadsDir . '/file_a_' . uniqid() . '.jpg';
        $fileB = $uploadsDir . '/file_b_' . uniqid() . '.jpg';
        file_put_contents($fileA, 'fake image a');
        file_put_contents($fileB, 'fake image b');

        $now = date('Y-m-d H:i:s');
        $mediaAId = static::$db->insert('media', [
            'filename'     => basename($fileA),
            'path'         => $fileA,
            'mime_type'    => 'image/jpeg',
            'size'         => filesize($fileA),
            'uploader_id'  => static::$adminUser->id,
            'created_at'   => $now,
        ]);
        $mediaBId = static::$db->insert('media', [
            'filename'     => basename($fileB),
            'path'         => $fileB,
            'mime_type'    => 'image/jpeg',
            'size'         => filesize($fileB),
            'uploader_id'  => static::$adminUser->id,
            'created_at'   => $now,
        ]);

        // 2. Associate mediaA with a Post, mediaB with a Page
        $postId = static::$db->insert('posts', [
            'title'             => 'Post For Bulk Media Delete Test',
            'slug'              => 'post-bulk-media-' . uniqid(),
            'content'           => '<p>Test</p>',
            'status'            => 'draft',
            'featured_image_id' => $mediaAId,
            'author_id'         => static::$adminUser->id,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        $pageId = static::$db->insert('pages', [
            'title'             => 'Page For Bulk Media Delete Test',
            'slug'              => 'page-bulk-media-' . uniqid(),
            'content'           => '<p>Test</p>',
            'status'            => 'draft',
            'featured_image_id' => $mediaBId,
            'author_id'         => static::$adminUser->id,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        // Verify pre-conditions
        $this->assertNotNull(Media::find($mediaAId));
        $this->assertNotNull(Media::find($mediaBId));
        $this->assertSame((int)$mediaAId, (int)Post::find($postId)->featured_image_id);
        $this->assertSame((int)$mediaBId, (int)Page::find($pageId)->featured_image_id);

        // 3. Execute bulk delete via MediaController
        $controller = new MediaController(static::$app);
        $request = new Request([], [
            'ids'    => [$mediaAId, $mediaBId],
            '_token' => 'test_csrf_token_12345',
        ], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/admin/media/bulk-delete',
            'HTTP_ACCEPT'    => 'application/json',
        ]);

        $response = $controller->bulkDelete($request);
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertTrue($body['success']);
        $this->assertSame(2, $body['deleted']);

        // 4. Verify media records are gone
        $this->assertNull(Media::find($mediaAId));
        $this->assertNull(Media::find($mediaBId));

        // 5. Verify post and page featured_image_id are safely nullified
        $refreshedPost = Post::find($postId);
        $this->assertNotNull($refreshedPost);
        $this->assertNull($refreshedPost->featured_image_id);

        $refreshedPage = Page::find($pageId);
        $this->assertNotNull($refreshedPage);
        $this->assertNull($refreshedPage->featured_image_id);

        // 6. Verify files on disk removed
        $this->assertFileDoesNotExist($fileA);
        $this->assertFileDoesNotExist($fileB);
    }

    public function testSingleDeleteAlsoSafelyNullifiesReferences(): void
    {
        $now = date('Y-m-d H:i:s');
        $mediaId = static::$db->insert('media', [
            'filename'     => 'single-del-sample.jpg',
            'path'         => sys_get_temp_dir() . '/single-del-sample.jpg',
            'mime_type'    => 'image/jpeg',
            'size'         => 1234,
            'uploader_id'  => static::$adminUser->id,
            'created_at'   => $now,
        ]);

        $postId = static::$db->insert('posts', [
            'title'             => 'Post For Single Media Delete Test',
            'slug'              => 'post-single-media-' . uniqid(),
            'content'           => '<p>Test</p>',
            'status'            => 'draft',
            'featured_image_id' => $mediaId,
            'author_id'         => static::$adminUser->id,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        $controller = new MediaController(static::$app);
        $request = new Request(['id' => $mediaId], [
            '_token' => 'test_csrf_token_12345',
        ], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/admin/media/delete',
        ]);

        $response = $controller->delete($request);
        $this->assertSame(302, $response->getStatusCode());

        $this->assertNull(Media::find($mediaId));
        $refreshedPost = Post::find($postId);
        $this->assertNotNull($refreshedPost);
        $this->assertNull($refreshedPost->featured_image_id);
    }

    public function testMediaIndexViewContainsMultiUploadAndBulkToolbar(): void
    {
        $controller = new MediaController(static::$app);
        $response = $controller->index(new Request([], [], [], [], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => '/admin/media',
        ]));
        $html = $response->getContent();

        $this->assertStringContainsString('name="files[]"', $html);
        $this->assertStringContainsString('multiple', $html);
        $this->assertStringContainsString('id="upload-results-list"', $html);
        $this->assertStringContainsString('id="media-bulk-bar"', $html);
        $this->assertStringContainsString('id="bulk-select-all"', $html);
        $this->assertStringContainsString('id="bulk-action-select"', $html);
        $this->assertStringContainsString('id="bulk-apply-btn"', $html);
    }
}
