<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Kernel;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Models\Comment;
use FavoriteCMS\Models\Post;
use FavoriteCMS\Models\User;
use PHPUnit\Framework\TestCase;

class CommentSubmissionAndRedirectTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;
    protected static Kernel $kernel;
    protected static int $postId;
    protected static string $postSlug;
    protected static int $authorId;
    protected static int $suspendedUserId;

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db = static::$app->make(Database::class);
        static::$kernel = new Kernel(static::$app);

        // Ensure roles exist
        static::$db->execute("INSERT IGNORE INTO `roles` (`name`, `slug`, `description`, `is_system`) VALUES ('Administrator', 'admin', 'Site administrator', 1)");
        static::$db->execute("INSERT IGNORE INTO `roles` (`name`, `slug`, `description`, `is_system`) VALUES ('Subscriber', 'subscriber', 'Regular registered user', 1)");

        // Create author
        $user = static::$db->selectOne("SELECT id FROM `users` WHERE `username` = 'comment_author_test' LIMIT 1");
        if (!$user) {
            static::$authorId = static::$db->insert('users', [
                'username'   => 'comment_author_test',
                'name'       => 'Comment Author',
                'email'      => 'comment_author@example.com',
                'password'   => password_hash('Pass123!', PASSWORD_DEFAULT),
                'status'     => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            static::$authorId = (int)$user->id;
        }

        // Create suspended user
        $suspUser = static::$db->selectOne("SELECT id FROM `users` WHERE `username` = 'comment_suspended_test' LIMIT 1");
        if (!$suspUser) {
            static::$suspendedUserId = static::$db->insert('users', [
                'username'   => 'comment_suspended_test',
                'name'       => 'Suspended Person',
                'email'      => 'suspended_commenter@example.com',
                'password'   => password_hash('Pass123!', PASSWORD_DEFAULT),
                'status'     => 'suspended',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            static::$suspendedUserId = (int)$suspUser->id;
        }

        // Create published test post
        static::$postSlug = 'comment-regression-post-' . bin2hex(random_bytes(4));
        static::$postId = static::$db->insert('posts', [
            'title'        => 'Comment Regression Article',
            'slug'         => static::$postSlug,
            'content'      => '<p>Article body for comment regression testing.</p>',
            'status'       => 'published',
            'type'         => 'post',
            'author_id'    => static::$authorId,
            'published_at' => date('Y-m-d H:i:s'),
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        if (!empty(static::$postId)) {
            static::$db->execute("DELETE FROM `comments` WHERE `post_id` = ?", [static::$postId]);
            static::$db->execute("DELETE FROM `posts` WHERE `id` = ?", [static::$postId]);
        }
        if (!empty(static::$authorId)) {
            static::$db->execute("DELETE FROM `users` WHERE `id` = ?", [static::$authorId]);
        }
        if (!empty(static::$suspendedUserId)) {
            static::$db->execute("DELETE FROM `users` WHERE `id` = ?", [static::$suspendedUserId]);
        }
        unset($GLOBALS['favorite_cms_base_path']);
    }

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [
            '_token' => 'test_valid_csrf_token_abc123',
        ];
        unset($GLOBALS['favorite_cms_base_path']);
    }

    /**
     * Test 1: Public Post comment form renders action and required hidden fields.
     */
    public function testCommentFormRendersCorrectlyOnPublicPost(): void
    {
        $request = new Request(
            get: [],
            post: [],
            server: [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI'    => '/post/' . static::$postSlug,
                'HTTP_HOST'      => 'favorite-cms.local',
            ]
        );

        $response = static::$kernel->handle($request);
        $this->assertSame(200, $response->getStatusCode());
        $html = $response->getContent();

        $this->assertStringContainsString('action="/post/' . static::$postSlug . '/comment"', $html);
        $this->assertStringContainsString('name="post_id" value="' . static::$postId . '"', $html);
        $this->assertStringContainsString('name="post_slug" value="' . static::$postSlug . '"', $html);
        $this->assertStringContainsString('name="_token"', $html);
    }

    /**
     * Test 2: Submitting a valid comment redirects to the canonical post URL and does NOT 404.
     */
    public function testValidCommentSubmissionRedirectsToCanonicalPostUrlAndDoesNot404(): void
    {
        $postData = [
            '_token'       => 'test_valid_csrf_token_abc123',
            'post_id'      => static::$postId,
            'post_slug'    => static::$postSlug,
            'author_name'  => 'Alice Wonder',
            'author_email' => 'alice@example.com',
            'content'      => 'This is a genuine positive comment!',
        ];

        $postRequest = new Request(
            get: [],
            post: $postData,
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI'    => '/post/' . static::$postSlug . '/comment',
                'HTTP_HOST'      => 'favorite-cms.local',
            ]
        );

        $postResponse = static::$kernel->handle($postRequest);
        $this->assertSame(302, $postResponse->getStatusCode());

        $location = $postResponse->getHeader('Location');
        $this->assertNotNull($location);
        $this->assertSame('/post/' . static::$postSlug . '?comment=submitted#comments', $location);

        // Verify comment inserted in DB
        $commentRow = static::$db->selectOne(
            "SELECT * FROM `comments` WHERE `post_id` = ? AND `author_email` = ? ORDER BY `id` DESC LIMIT 1",
            [static::$postId, 'alice@example.com']
        );
        $this->assertNotNull($commentRow);
        $this->assertSame('Alice Wonder', $commentRow->author_name);
        $this->assertSame('approved', $commentRow->status);

        // Follow redirect to destination URL
        $targetPath = preg_replace('/#.*$/', '', $location);
        $followRequest = new Request(
            get: ['comment' => 'submitted'],
            post: [],
            server: [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI'    => $targetPath,
                'HTTP_HOST'      => 'favorite-cms.local',
            ]
        );

        $followResponse = static::$kernel->handle($followRequest);
        $this->assertSame(200, $followResponse->getStatusCode(), 'Redirect target must return HTTP 200, not 404!');
        $this->assertStringNotContainsString('404 Page Not Found', $followResponse->getContent());
        $this->assertStringContainsString('Alice Wonder', $followResponse->getContent());
        $this->assertStringContainsString('This is a genuine positive comment!', $followResponse->getContent());
    }

    /**
     * Test 3: Subdirectory deployment preserves base path and does NOT 404 upon redirect.
     */
    public function testSubdirectoryCommentSubmissionAndRedirectDoesNot404(): void
    {
        $GLOBALS['favorite_cms_base_path'] = '/cms';

        $postData = [
            '_token'       => 'test_valid_csrf_token_abc123',
            'post_id'      => static::$postId,
            'post_slug'    => static::$postSlug,
            'author_name'  => 'Bob Subdir',
            'author_email' => 'bob@example.com',
            'content'      => 'Subdirectory comment test.',
        ];

        $subPostReq = new Request(
            get: [],
            post: $postData,
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI'    => '/cms/post/' . static::$postSlug . '/comment',
                'SCRIPT_NAME'    => '/cms/index.php',
                'HTTP_HOST'      => 'favorite-cms.local',
            ]
        );

        $subPostResp = static::$kernel->handle($subPostReq);
        $this->assertSame(302, $subPostResp->getStatusCode());

        $location = $subPostResp->getHeader('Location');
        $this->assertNotNull($location);
        $this->assertSame('/cms/post/' . static::$postSlug . '?comment=submitted#comments', $location);

        // Follow redirect inside subdirectory
        $targetPath = preg_replace('/#.*$/', '', $location);
        $subFollowReq = new Request(
            get: ['comment' => 'submitted'],
            post: [],
            server: [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI'    => $targetPath,
                'SCRIPT_NAME'    => '/cms/index.php',
                'HTTP_HOST'      => 'favorite-cms.local',
            ]
        );

        $subFollowResp = static::$kernel->handle($subFollowReq);
        $this->assertSame(200, $subFollowResp->getStatusCode(), 'Subdirectory post URL must resolve to 200, not 404!');
        $this->assertStringNotContainsString('404 Page Not Found', $subFollowResp->getContent());
        $this->assertStringContainsString('Bob Subdir', $subFollowResp->getContent());

        unset($GLOBALS['favorite_cms_base_path']);
    }

    /**
     * Test 4: Validation failure redirects safely to post URL with error session and does not 404.
     */
    public function testEmptyCommentValidationFailureRedirectsSafelyWithout404(): void
    {
        $postData = [
            '_token'       => 'test_valid_csrf_token_abc123',
            'post_id'      => static::$postId,
            'author_name'  => '',
            'author_email' => 'invalid-email',
            'content'      => '',
        ];

        $postRequest = new Request(
            get: [],
            post: $postData,
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI'    => '/post/' . static::$postSlug . '/comment',
                'HTTP_HOST'      => 'favorite-cms.local',
            ]
        );

        $response = static::$kernel->handle($postRequest);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/post/' . static::$postSlug . '#comments', $response->getHeader('Location'));
        $this->assertNotEmpty($_SESSION['comment_error']);

        // Follow redirect to see error feedback on post page
        $targetPath = preg_replace('/#.*$/', '', (string)$response->getHeader('Location'));
        $followRequest = new Request(
            get: [],
            post: [],
            server: [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI'    => $targetPath,
                'HTTP_HOST'      => 'favorite-cms.local',
            ]
        );
        $followResponse = static::$kernel->handle($followRequest);
        $this->assertSame(200, $followResponse->getStatusCode());
        $this->assertStringContainsString($_SESSION['comment_error'] ?? '', $followResponse->getContent());
    }

    /**
     * Test 5: Invalid CSRF token is rejected and redirects back safely without 404.
     */
    public function testInvalidCsrfTokenFailureRedirectsSafelyWithout404(): void
    {
        $postData = [
            '_token'       => 'invalid_spoofed_token_xyz',
            'post_id'      => static::$postId,
            'author_name'  => 'Eve Malicious',
            'author_email' => 'eve@example.com',
            'content'      => 'CSRF attack payload',
        ];

        $postRequest = new Request(
            get: [],
            post: $postData,
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI'    => '/post/' . static::$postSlug . '/comment',
                'HTTP_HOST'      => 'favorite-cms.local',
            ]
        );

        $response = static::$kernel->handle($postRequest);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/post/' . static::$postSlug . '#comments', $response->getHeader('Location'));
        $this->assertStringContainsString('Security verification failed', $_SESSION['comment_error'] ?? '');

        // Verify comment was NOT inserted
        $count = static::$db->selectOne("SELECT COUNT(*) as cnt FROM `comments` WHERE `author_email` = 'eve@example.com'");
        $this->assertSame(0, (int)($count->cnt ?? 0));
    }

    /**
     * Test 6: Open redirect parameters are ignored; redirect is strictly canonical post URL.
     */
    public function testOpenRedirectIsPrevented(): void
    {
        $postData = [
            '_token'       => 'test_valid_csrf_token_abc123',
            'post_id'      => static::$postId,
            'author_name'  => 'Frank Attacker',
            'author_email' => 'frank@example.com',
            'content'      => 'Open redirect exploit attempt.',
            'redirect_to'  => 'https://attacker.example/malicious-login',
            'return_url'   => '//attacker.example/phishing',
        ];

        $postRequest = new Request(
            get: [],
            post: $postData,
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI'    => '/post/' . static::$postSlug . '/comment',
                'HTTP_HOST'      => 'favorite-cms.local',
            ]
        );

        $response = static::$kernel->handle($postRequest);
        $this->assertSame(302, $response->getStatusCode());
        $location = $response->getHeader('Location');

        $this->assertStringNotContainsString('attacker.example', (string)$location);
        $this->assertSame('/post/' . static::$postSlug . '?comment=submitted#comments', $location);
    }

    /**
     * Test 7: Alternative endpoint /comment/submit works seamlessly and redirects correctly.
     */
    public function testAlternativeEndpointCommentSubmitWorksEquallyWell(): void
    {
        $postData = [
            '_token'       => 'test_valid_csrf_token_abc123',
            'post_id'      => static::$postId,
            'author_name'  => 'Grace Hopper',
            'author_email' => 'grace@example.com',
            'content'      => 'Alternative route submission test.',
        ];

        $postRequest = new Request(
            get: [],
            post: $postData,
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI'    => '/comment/submit',
                'HTTP_HOST'      => 'favorite-cms.local',
            ]
        );

        $response = static::$kernel->handle($postRequest);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/post/' . static::$postSlug . '?comment=submitted#comments', $response->getHeader('Location'));
    }

    /**
     * Test 8: Suspended user comment submission is blocked.
     */
    public function testSuspendedUserCommentSubmissionIsBlocked(): void
    {
        $_SESSION['auth_user_id'] = static::$suspendedUserId;

        $postData = [
            '_token'       => 'test_valid_csrf_token_abc123',
            'post_id'      => static::$postId,
            'author_name'  => 'Suspended Person',
            'author_email' => 'suspended_commenter@example.com',
            'content'      => 'Attempt by suspended user.',
        ];

        $postRequest = new Request(
            get: [],
            post: $postData,
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI'    => '/post/' . static::$postSlug . '/comment',
                'HTTP_HOST'      => 'favorite-cms.local',
            ]
        );

        $response = static::$kernel->handle($postRequest);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/post/' . static::$postSlug . '#comments', $response->getHeader('Location'));
        $this->assertStringContainsString('suspended', strtolower($_SESSION['comment_error'] ?? ''));
    }
}
