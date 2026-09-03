<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Exceptions\SecurityException;
use FavoriteCMS\Models\Media;
use FavoriteCMS\Models\Post;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Services\ContentSanitizer;
use FavoriteCMS\Services\MediaService;
use FavoriteCMS\Services\UploadCapabilityService;
use PHPUnit\Framework\TestCase;

class PostEditorAndMediaSystemTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db  = static::$app->make(Database::class);

        // Seed settings and permissions if needed
        static::$db->execute(
            "INSERT IGNORE INTO `permissions` (`name`, `slug`, `description`, `group_name`) VALUES 
             ('Upload Large Media', 'upload_large_media', 'Allow uploading large media files up to server max', 'content'),
             ('Unfiltered HTML', 'unfiltered_html', 'Publish raw unrestricted HTML in posts and pages', 'system')"
        );

        static::$db->execute(
            "INSERT IGNORE INTO `settings` (`group_name`, `setting_key`, `value`, `type`, `label`, `is_public`) VALUES 
             ('media', 'max_upload_size_admin', '0', 'int', 'Admin Max Upload Size', 0),
             ('media', 'max_upload_size_user', '52428800', 'int', 'User Max Upload Size', 0),
             ('media', 'allowed_categories', 'images,videos,audio,documents,archives', 'string', 'Allowed Categories', 0)"
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        Setting::clearCache();
    }

    public function testPostStoresLongContentWithoutTruncation(): void
    {
        // Generate a 150KB structured HTML post with headings, paragraphs, lists, and tables
        $chunk = '<h2>Episode Section</h2><p>This is a detailed paragraph with <strong>bold</strong> and <em>emphasis</em>. ' . str_repeat('Content data block with comprehensive text details. ', 20) . '</p><table><tr><td>Cell A</td><td>Cell B</td></tr></table>';
        $longContent = str_repeat($chunk, 100); // ~150 KB

        $this->assertGreaterThan(100000, strlen($longContent));

        $slug = 'large-content-test-' . bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        $postId = static::$db->insert('posts', [
            'title'        => 'Large Post Test',
            'slug'         => $slug,
            'content'      => $longContent,
            'excerpt'      => 'Test excerpt',
            'status'       => 'draft',
            'type'         => 'post',
            'author_id'    => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $post = Post::find($postId);
        $this->assertNotNull($post);
        $this->assertSame(strlen($longContent), strlen($post->content));
        $this->assertSame($longContent, $post->content);

        // Clean up
        static::$db->delete('posts', ['id' => $postId]);
    }

    public function testHelperCleanPostContentSanitizesMarkup(): void
    {
        $input = '<p>Normal text</p><script>alert("hacked")</script>';
        $output = clean_post_content($input);

        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringContainsString('<p>Normal text</p>', $output);
    }

    public function testMediaModelCategorizesMimeTypesCorrectly(): void
    {
        $img = new Media(['mime_type' => 'image/png']);
        $this->assertTrue($img->isImage());
        $this->assertFalse($img->isVideo());
        $this->assertSame('image', $img->getTypeCategory());

        $vid = new Media(['mime_type' => 'video/mp4']);
        $this->assertTrue($vid->isVideo());
        $this->assertFalse($vid->isImage());
        $this->assertSame('video', $vid->getTypeCategory());

        $aud = new Media(['mime_type' => 'audio/mpeg']);
        $this->assertTrue($aud->isAudio());
        $this->assertSame('audio', $aud->getTypeCategory());

        $doc = new Media(['mime_type' => 'application/pdf']);
        $this->assertTrue($doc->isDocument());
        $this->assertSame('document', $doc->getTypeCategory());

        $zip = new Media(['mime_type' => 'application/zip']);
        $this->assertTrue($zip->isArchive());
        $this->assertSame('archive', $zip->getTypeCategory());
    }

    public function testUploadCapabilityCalculatesRoleLimits(): void
    {
        $service = new UploadCapabilityService(static::$app);
        $serverMax = $service->getServerLimits()['effective_server_bytes'];

        // Normal user should be capped by user limit (default 50MB) or server limit, whichever is lower
        $normalLimit = $service->getEffectiveUserLimit(null);
        $this->assertLessThanOrEqual($serverMax, $normalLimit);
        $this->assertLessThanOrEqual(52428800, $normalLimit);
    }

    public function testMediaServiceBlocksDangerousDoubleExtensions(): void
    {
        $service = new MediaService(static::$app);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('multi-extension');

        $tempFile = tempnam(sys_get_temp_dir(), 'test_fake');
        file_put_contents($tempFile, 'dummy image content');

        $fakeFile = [
            'name'     => 'exploit.php.png',
            'type'     => 'image/png',
            'tmp_name' => $tempFile,
            'error'    => UPLOAD_ERR_OK,
            'size'     => 1024,
        ];

        try {
            $service->upload($fakeFile);
        } finally {
            @unlink($tempFile);
        }
    }

    public function testMediaServiceRejectsOversizedFileForRole(): void
    {
        $service = new MediaService(static::$app);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds your maximum allowed upload limit');

        // Set user limit to a tiny 100 bytes for testing
        Setting::set('media', 'max_upload_size_user', 100, 'int');

        $tempFile = tempnam(sys_get_temp_dir(), 'test_large');
        file_put_contents($tempFile, 'small content');

        $fakeFile = [
            'name'     => 'large_video.mp4',
            'type'     => 'video/mp4',
            'tmp_name' => $tempFile,
            'error'    => UPLOAD_ERR_OK,
            'size'     => 5000, // 5000 > 100
        ];

        try {
            $service->upload($fakeFile);
        } finally {
            @unlink($tempFile);
            Setting::set('media', 'max_upload_size_user', 52428800, 'int');
        }
    }

    public function testSuperAdminCanPublishRawHtmlWhileNormalUserIsSanitized(): void
    {
        $dirty = '<p>Good text</p><script>console.log("admin code");</script><iframe src="https://player.vimeo.com/video/123"></iframe>';

        // 1. As super-admin user (ID 1)
        $adminCleaned = ContentSanitizer::clean($dirty, 1);
        $this->assertStringContainsString('<script>', $adminCleaned);
        $this->assertStringContainsString('<iframe', $adminCleaned);

        // 2. As unauthenticated or standard subscriber (ID 0 or null)
        $normalCleaned = ContentSanitizer::clean($dirty, null);
        $this->assertStringNotContainsString('<script>', $normalCleaned);
        $this->assertStringNotContainsString('<iframe', $normalCleaned);
        $this->assertStringContainsString('<p>Good text</p>', $normalCleaned);
    }

    public function testSettingControllerMediaSettingsRoundtrip(): void
    {
        $controller = new \FavoriteCMS\Http\Controllers\Admin\SettingController(static::$app);
        
        // Update media upload limits
        $request = new \FavoriteCMS\Core\Request([], [
            'site_name'                => 'Test CMS Title',
            'site_description'         => 'Test tagline',
            'site_url'                 => 'http://favorite-cms.local',
            'admin_email'              => 'admin@test.com',
            'timezone'                 => 'UTC',
            'posts_per_page'           => 10,
            'front_page_type'          => 'posts',
            'front_page_id'            => 0,
            'default_category'         => 1,
            'max_upload_size_admin_mb' => 250,
            'max_upload_size_user_mb'  => 35,
        ]);

        $response = $controller->update($request);
        $this->assertSame(302, $response->getStatusCode());

        $this->assertSame(250 * 1024 * 1024, (int)Setting::get('media', 'max_upload_size_admin'));
        $this->assertSame(35 * 1024 * 1024, (int)Setting::get('media', 'max_upload_size_user'));
    }

    public function testMediaControllerCapabilitiesEndpointReturnsJson(): void
    {
        $controller = new \FavoriteCMS\Http\Controllers\Admin\MediaController(static::$app);
        $request = new \FavoriteCMS\Core\Request();

        $response = $controller->capabilities($request);
        $this->assertSame(200, $response->getStatusCode());
        
        $json = json_decode($response->getContent(), true);
        $this->assertTrue($json['success']);
        $this->assertArrayHasKey('capabilities', $json);
        $this->assertArrayHasKey('server', $json['capabilities']);
        $this->assertArrayHasKey('user', $json['capabilities']);
    }
}
