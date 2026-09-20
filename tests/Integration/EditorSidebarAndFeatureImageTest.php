<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Exceptions\SecurityException;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Http\Controllers\Admin\MediaController;
use FavoriteCMS\Http\Controllers\Admin\PageController;
use FavoriteCMS\Http\Controllers\Admin\PostController;
use FavoriteCMS\Models\Media;
use FavoriteCMS\Models\Page;
use FavoriteCMS\Models\Post;
use FavoriteCMS\Models\User;
use FavoriteCMS\Services\MediaService;
use PHPUnit\Framework\TestCase;

class EditorSidebarAndFeatureImageTest extends TestCase
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

    public function testSsrfIsPublicIpDetection(): void
    {
        $service = new MediaService(static::$app);

        // IPv4 Loopback
        $this->assertFalse($service->isPublicIp('127.0.0.1'));
        $this->assertFalse($service->isPublicIp('127.0.0.2'));
        $this->assertFalse($service->isPublicIp('127.255.255.254'));

        // IPv4 Private RFC1918
        $this->assertFalse($service->isPublicIp('10.0.0.1'));
        $this->assertFalse($service->isPublicIp('10.254.1.1'));
        $this->assertFalse($service->isPublicIp('172.16.0.1'));
        $this->assertFalse($service->isPublicIp('172.31.255.254'));
        $this->assertFalse($service->isPublicIp('192.168.0.1'));
        $this->assertFalse($service->isPublicIp('192.168.1.100'));

        // IPv4 Link-Local / Cloud Metadata
        $this->assertFalse($service->isPublicIp('169.254.169.254'));
        $this->assertFalse($service->isPublicIp('169.254.1.1'));

        // Carrier Grade NAT RFC6598
        $this->assertFalse($service->isPublicIp('100.64.0.1'));
        $this->assertFalse($service->isPublicIp('100.127.255.254'));

        // IPv6 Loopback, Link-local, ULA
        $this->assertFalse($service->isPublicIp('::1'));
        $this->assertFalse($service->isPublicIp('fe80::1'));
        $this->assertFalse($service->isPublicIp('fc00::1'));
        $this->assertFalse($service->isPublicIp('fd00::1'));

        // Genuine Public IPv4
        $this->assertTrue($service->isPublicIp('8.8.8.8'));
        $this->assertTrue($service->isPublicIp('1.1.1.1'));
        $this->assertTrue($service->isPublicIp('93.184.216.34'));
    }

    public function testSsrfValidateUrlRejectsDisallowedSchemes(): void
    {
        $service = new MediaService(static::$app);

        $disallowed = [
            'file:///etc/passwd',
            'ftp://example.com/image.png',
            'gopher://example.com/',
            'javascript:alert(1)',
            'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            'dict://example.com/',
            'php://filter/read=convert.base64-encode/resource=index.php',
        ];

        foreach ($disallowed as $url) {
            try {
                $service->validateUrlForSsrf($url);
                $this->fail("Expected SecurityException for disallowed scheme: {$url}");
            } catch (SecurityException $e) {
                $this->assertStringContainsString('scheme', strtolower($e->getMessage()));
            }
        }
    }

    public function testSsrfValidateUrlRejectsDisallowedPorts(): void
    {
        $service = new MediaService(static::$app);

        $badPorts = [
            'http://example.com:22/test.jpg',
            'http://example.com:25/test.jpg',
            'http://example.com:3306/test.jpg',
            'http://example.com:6379/test.jpg',
            'https://example.com:9000/test.jpg',
        ];

        foreach ($badPorts as $url) {
            try {
                $service->validateUrlForSsrf($url);
                $this->fail("Expected SecurityException for disallowed port: {$url}");
            } catch (SecurityException $e) {
                $this->assertStringContainsString('port', strtolower($e->getMessage()));
            }
        }
    }

    public function testSsrfValidateUrlRejectsInternalAndMetadataHosts(): void
    {
        $service = new MediaService(static::$app);

        $blockedUrls = [
            'http://127.0.0.1/test.jpg',
            'http://localhost/test.jpg',
            'http://169.254.169.254/latest/meta-data/',
            'http://10.0.0.5/image.png',
            'http://192.168.1.1/router.jpg',
            'http://[::1]/image.png',
        ];

        foreach ($blockedUrls as $url) {
            try {
                $service->validateUrlForSsrf($url);
                $this->fail("Expected SecurityException for internal host: {$url}");
            } catch (SecurityException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function testImportUrlEndpointRequiresCsrfToken(): void
    {
        $controller = new MediaController(static::$app);

        // Invalid CSRF
        $request = new Request([], ['url' => 'https://example.com/test.jpg', '_token' => 'wrong_token'], [], [], [], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/admin/media/import-url',
        ]);

        $response = $controller->importUrl($request);
        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertFalse($body['success']);
        $this->assertStringContainsString('CSRF', $body['message']);
    }

    public function testImportUrlEndpointRejectsEmptyUrl(): void
    {
        $controller = new MediaController(static::$app);

        $request = new Request([], ['url' => '', '_token' => 'test_csrf_token_12345'], [], [], [], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/admin/media/import-url',
        ]);

        $response = $controller->importUrl($request);
        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertFalse($body['success']);
    }

    public function testImportUrlEndpointRejectsSsrfAttempt(): void
    {
        $controller = new MediaController(static::$app);

        $request = new Request([], [
            'url'    => 'http://169.254.169.254/latest/meta-data/',
            '_token' => 'test_csrf_token_12345',
        ], [], [], [], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/admin/media/import-url',
        ]);

        $response = $controller->importUrl($request);
        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertFalse($body['success']);
        $this->assertStringContainsString('Security Error', $body['message']);
    }

    public function testPostAndPageFeaturedImageAssociationAndRemoval(): void
    {
        // 1. Create a dummy media item
        $mediaId = static::$db->insert('media', [
            'filename'     => 'featured-test-sample.jpg',
            'path'         => 'uploads/featured-test-sample.jpg',
            'mime_type'    => 'image/jpeg',
            'size'         => 12345,
            'uploader_id'  => static::$adminUser->id,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        // 2. Create Post with featured image
        $postController = new PostController(static::$app);
        $postRequest = new Request([], [
            '_token'            => 'test_csrf_token_12345',
            'title'             => 'Test Post With Featured Image',
            'content'           => '<p>Post content with image</p>',
            'status'            => 'draft',
            'featured_image_id' => $mediaId,
        ], [], [], [], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/admin/posts/store',
        ]);

        $postController->store($postRequest);
        $postRow = static::$db->selectOne("SELECT * FROM `posts` WHERE `title` = 'Test Post With Featured Image' ORDER BY `id` DESC LIMIT 1");
        $this->assertNotNull($postRow);
        $this->assertSame((int)$mediaId, (int)$postRow->featured_image_id);

        $postModel = new Post((array)$postRow);
        $featImg = $postModel->getFeaturedImage();
        $this->assertNotNull($featImg);
        $this->assertSame((int)$mediaId, (int)$featImg->id);

        // 3. Create Page with featured image
        $pageController = new PageController(static::$app);
        $pageRequest = new Request([], [
            '_token'            => 'test_csrf_token_12345',
            'title'             => 'Test Page With Featured Image',
            'content'           => '<p>Page content with image</p>',
            'status'            => 'draft',
            'featured_image_id' => $mediaId,
        ], [], [], [], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/admin/pages/store',
        ]);

        $pageController->store($pageRequest);
        $pageRow = static::$db->selectOne("SELECT * FROM `pages` WHERE `title` = 'Test Page With Featured Image' ORDER BY `id` DESC LIMIT 1");
        $this->assertNotNull($pageRow);
        $this->assertSame((int)$mediaId, (int)$pageRow->featured_image_id);

        $pageModel = new Page((array)$pageRow);
        $featImgPage = $pageModel->getFeaturedImage();
        $this->assertNotNull($featImgPage);
        $this->assertSame((int)$mediaId, (int)$featImgPage->id);

        // 4. Update Post to remove featured image (set to 0)
        $updatePostRequest = new Request([], [
            '_token'            => 'test_csrf_token_12345',
            'id'                => $postRow->id,
            'title'             => 'Test Post With Featured Image',
            'content'           => '<p>Post content with image</p>',
            'status'            => 'draft',
            'featured_image_id' => '0',
        ], [], [], [], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/admin/posts/update',
        ]);

        $postController->update($updatePostRequest);
        $refreshedPost = static::$db->selectOne("SELECT * FROM `posts` WHERE `id` = ?", [$postRow->id]);
        $this->assertNull($refreshedPost->featured_image_id);

        // 5. Update Page to remove featured image (set to 0)
        $updatePageRequest = new Request([], [
            '_token'            => 'test_csrf_token_12345',
            'id'                => $pageRow->id,
            'title'             => 'Test Page With Featured Image',
            'content'           => '<p>Page content with image</p>',
            'status'            => 'draft',
            'featured_image_id' => '0',
        ], [], [], [], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/admin/pages/update',
        ]);

        $pageController->update($updatePageRequest);
        $refreshedPage = static::$db->selectOne("SELECT * FROM `pages` WHERE `id` = ?", [$pageRow->id]);
        $this->assertNull($refreshedPage->featured_image_id);
    }

    public function testPostAndPageViewsContainStickyClassesAndDualInputElements(): void
    {
        $postController = new PostController(static::$app);
        $postResponse = $postController->create(new Request([], [], [], [], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => '/admin/posts/new',
        ]));
        $postHtml = $postResponse->getContent();

        $this->assertStringContainsString('editor-layout-grid', $postHtml);
        $this->assertStringContainsString('editor-sidebar-sticky', $postHtml);
        $this->assertStringContainsString('id="upload-feat-img-btn"', $postHtml);
        $this->assertStringContainsString('id="toggle-url-import-btn"', $postHtml);
        $this->assertStringContainsString('id="feat-img-url-submit-btn"', $postHtml);
        $this->assertStringContainsString('id="media-modal"', $postHtml);

        $pageController = new PageController(static::$app);
        $pageResponse = $pageController->create(new Request([], [], [], [], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => '/admin/pages/new',
        ]));
        $pageHtml = $pageResponse->getContent();

        $this->assertStringContainsString('editor-layout-grid', $pageHtml);
        $this->assertStringContainsString('editor-sidebar-sticky', $pageHtml);
        $this->assertStringContainsString('id="upload-feat-img-btn"', $pageHtml);
        $this->assertStringContainsString('id="toggle-url-import-btn"', $pageHtml);
        $this->assertStringContainsString('id="feat-img-url-submit-btn"', $pageHtml);
        $this->assertStringContainsString('id="media-modal"', $pageHtml);
    }
}
