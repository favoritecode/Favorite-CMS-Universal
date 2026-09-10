<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Kernel;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Http\Controllers\Admin\CommentController;
use FavoriteCMS\Models\Comment;
use FavoriteCMS\Models\Setting;
use PHPUnit\Framework\TestCase;

class CommentSubmissionAndRedirectTest extends TestCase
{
    private const TOKEN = 'test_valid_csrf_token_abc123';

    protected static Application $app;
    protected static Database $db;
    protected static Kernel $kernel;
    protected static int $postId;
    protected static string $postSlug;
    protected static int $authorId;
    protected static int $memberId;
    protected static int $suspendedUserId;
    protected static mixed $originalAllowRegistration = null;

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db = static::$app->make(Database::class);
        static::$kernel = new Kernel(static::$app);

        // Ensure roles exist
        static::$db->execute("INSERT IGNORE INTO `roles` (`name`, `slug`, `description`, `is_system`) VALUES ('Administrator', 'admin', 'Site administrator', 1)");
        static::$db->execute("INSERT IGNORE INTO `roles` (`name`, `slug`, `description`, `is_system`) VALUES ('Subscriber', 'subscriber', 'Regular registered user', 1)");

        static::$authorId = static::ensureUser('comment_author_test', 'Comment Author', 'comment_author@example.com', 'active');
        static::$memberId = static::ensureUser('comment_member_test', 'Comment Member', 'comment_member@example.com', 'active');
        static::$suspendedUserId = static::ensureUser('comment_suspended_test', 'Suspended Person', 'suspended_commenter@example.com', 'suspended');

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

        static::$originalAllowRegistration = Setting::get('general', 'allow_registration', null);
    }

    public static function tearDownAfterClass(): void
    {
        if (!empty(static::$postId)) {
            static::$db->execute("DELETE FROM `comments` WHERE `post_id` = ?", [static::$postId]);
            static::$db->execute("DELETE FROM `posts` WHERE `id` = ?", [static::$postId]);
        }
        foreach ([static::$authorId ?? 0, static::$memberId ?? 0, static::$suspendedUserId ?? 0] as $userId) {
            if ($userId > 0) {
                static::$db->execute("DELETE FROM `users` WHERE `id` = ?", [$userId]);
            }
        }
        Setting::set('general', 'allow_registration', static::$originalAllowRegistration ?? 1, 'bool');
        unset($GLOBALS['favorite_cms_base_path']);
    }

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [
            '_token' => self::TOKEN,
        ];
        unset($GLOBALS['favorite_cms_base_path']);
        Setting::set('general', 'allow_registration', 1, 'bool');
    }

    private static function ensureUser(string $username, string $name, string $email, string $status): int
    {
        $existing = static::$db->selectOne("SELECT id FROM `users` WHERE `username` = ? LIMIT 1", [$username]);
        if ($existing) {
            return (int)$existing->id;
        }

        $now = date('Y-m-d H:i:s');
        return static::$db->insert('users', [
            'username'          => $username,
            'name'              => $name,
            'email'             => $email,
            'password'          => password_hash('Pass123!', PASSWORD_DEFAULT),
            'status'            => $status,
            'email_verified_at' => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
    }

    private function requestPage(string $uri, array $get = [], array $server = []): Response
    {
        return static::$kernel->handle(new Request(
            get: $get,
            post: [],
            server: array_merge([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI'    => $uri,
                'HTTP_HOST'      => 'favorite-cms.local',
            ], $server)
        ));
    }

    private function submitComment(array $fields, ?string $uri = null, array $server = []): Response
    {
        $payload = array_merge([
            '_token'    => self::TOKEN,
            'post_id'   => static::$postId,
            'post_slug' => static::$postSlug,
        ], $fields);

        return static::$kernel->handle(new Request(
            get: [],
            post: $payload,
            server: array_merge([
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI'    => $uri ?? '/post/' . static::$postSlug . '/comment',
                'HTTP_HOST'      => 'favorite-cms.local',
            ], $server)
        ));
    }

    private function countComments(string $where, array $bindings): int
    {
        $row = static::$db->selectOne("SELECT COUNT(*) AS cnt FROM `comments` WHERE {$where}", $bindings);
        return (int)($row->cnt ?? 0);
    }

    /**
     * Logged-in users see a comment form without manual name/email fields.
     */
    public function testLoggedInUserSeesCommentFormWithoutNameOrEmailFields(): void
    {
        $_SESSION['auth_user_id'] = static::$memberId;

        $response = $this->requestPage('/post/' . static::$postSlug);
        $this->assertSame(200, $response->getStatusCode());
        $html = $response->getContent();

        $this->assertStringContainsString('action="/post/' . static::$postSlug . '/comment"', $html);
        $this->assertStringContainsString('name="post_id" value="' . static::$postId . '"', $html);
        $this->assertStringContainsString('name="post_slug" value="' . static::$postSlug . '"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('name="content"', $html);
        $this->assertStringContainsString('Commenting as <strong>Comment Member</strong>', $html);
        $this->assertStringNotContainsString('name="author_name"', $html);
        $this->assertStringNotContainsString('name="author_email"', $html);
    }

    /**
     * Logged-out visitors see a login prompt (and signup link) instead of the comment form.
     */
    public function testLoggedOutVisitorSeesLoginPromptInsteadOfForm(): void
    {
        $html = $this->requestPage('/post/' . static::$postSlug)->getContent();
        $returnParam = rawurlencode('/post/' . static::$postSlug . '#comments');

        $this->assertStringContainsString('Log in to comment', $html);
        $this->assertStringContainsString('href="/admin/login?redirect=' . $returnParam . '"', $html);
        $this->assertStringContainsString('Create an account', $html);
        $this->assertStringContainsString('href="/register?redirect=' . $returnParam . '"', $html);

        $this->assertStringNotContainsString('action="/post/' . static::$postSlug . '/comment"', $html);
        $this->assertStringNotContainsString('id="comment_content"', $html);
        $this->assertStringNotContainsString('name="author_name"', $html);
        $this->assertStringNotContainsString('name="author_email"', $html);
    }

    public function testSignupLinkIsHiddenWhenRegistrationIsDisabled(): void
    {
        Setting::set('general', 'allow_registration', 0, 'bool');

        $html = $this->requestPage('/post/' . static::$postSlug)->getContent();

        $this->assertStringContainsString('Log in to comment', $html);
        $this->assertStringNotContainsString('Create an account', $html);
        $this->assertStringNotContainsString('/register?redirect=', $html);
    }

    public function testLoggedOutPromptLinksIncludeSubdirectoryBasePath(): void
    {
        $html = $this->requestPage('/cms/post/' . static::$postSlug, [], ['SCRIPT_NAME' => '/cms/index.php'])->getContent();
        $returnParam = rawurlencode('/post/' . static::$postSlug . '#comments');

        $this->assertStringContainsString('href="/cms/admin/login?redirect=' . $returnParam . '"', $html);
        $this->assertStringContainsString('href="/cms/register?redirect=' . $returnParam . '"', $html);
    }

    /**
     * Authenticated comments store the account's user_id and identity, ignoring submitted name/email.
     */
    public function testAuthenticatedCommentStoresUserIdAndAccountIdentity(): void
    {
        $_SESSION['auth_user_id'] = static::$memberId;

        $response = $this->submitComment([
            'author_name'  => 'Spoofed Display Name',
            'author_email' => 'spoofed-identity@example.com',
            'content'      => 'This is a genuine positive comment!',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $location = $response->getHeader('Location');
        $this->assertSame('/post/' . static::$postSlug . '?comment=submitted#comments', $location);

        $row = static::$db->selectOne(
            "SELECT * FROM `comments` WHERE `post_id` = ? AND `content` = ? ORDER BY `id` DESC LIMIT 1",
            [static::$postId, 'This is a genuine positive comment!']
        );
        $this->assertNotNull($row);
        $this->assertSame(static::$memberId, (int)$row->user_id);
        $this->assertSame('Comment Member', $row->author_name);
        $this->assertSame('comment_member@example.com', $row->author_email);
        $this->assertSame('approved', $row->status);

        $this->assertSame(0, $this->countComments('`author_email` = ? OR `author_name` = ?', ['spoofed-identity@example.com', 'Spoofed Display Name']));

        // Follow redirect to destination URL: comment renders with the account identity
        $targetPath = (string)preg_replace('/[?#].*$/', '', (string)$location);
        $followResponse = $this->requestPage($targetPath, ['comment' => 'submitted']);
        $this->assertSame(200, $followResponse->getStatusCode(), 'Redirect target must return HTTP 200, not 404!');
        $this->assertStringNotContainsString('404 Page Not Found', $followResponse->getContent());
        $this->assertStringContainsString('Comment Member', $followResponse->getContent());
        $this->assertStringContainsString('This is a genuine positive comment!', $followResponse->getContent());
        $this->assertStringNotContainsString('Spoofed Display Name', $followResponse->getContent());
    }

    public function testLoggedOutSubmissionIsRejected(): void
    {
        $response = $this->submitComment([
            'author_name'  => 'Anonymous Visitor',
            'author_email' => 'anonymous-visitor@example.com',
            'content'      => 'Logged-out comment attempt.',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/post/' . static::$postSlug . '#comments', $response->getHeader('Location'));
        $this->assertStringContainsString('log in', strtolower($_SESSION['comment_error'] ?? ''));
        $this->assertSame(0, $this->countComments('`content` = ?', ['Logged-out comment attempt.']));
    }

    public function testLoggedOutSubmissionWithoutAnySessionTokenIsRejected(): void
    {
        $_SESSION = [];

        $response = $this->submitComment([
            '_token'       => '',
            'author_name'  => 'Tokenless Visitor',
            'author_email' => 'tokenless-visitor@example.com',
            'content'      => 'Tokenless logged-out comment attempt.',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(0, $this->countComments('`content` = ?', ['Tokenless logged-out comment attempt.']));
    }

    public function testCsrfFailureIsRejectedForAuthenticatedUser(): void
    {
        $_SESSION['auth_user_id'] = static::$memberId;

        $response = $this->submitComment([
            '_token'  => 'invalid_spoofed_token_xyz',
            'content' => 'CSRF attack payload',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/post/' . static::$postSlug . '#comments', $response->getHeader('Location'));
        $this->assertStringContainsString('Security verification failed', $_SESSION['comment_error'] ?? '');
        $this->assertSame(0, $this->countComments('`content` = ?', ['CSRF attack payload']));
    }

    public function testMissingSessionTokenIsRejectedForAuthenticatedUser(): void
    {
        $_SESSION = ['auth_user_id' => static::$memberId];

        foreach (['', 'any_submitted_token'] as $submittedToken) {
            $response = $this->submitComment([
                '_token'  => $submittedToken,
                'content' => 'Comment without a session CSRF token',
            ]);

            $this->assertSame(302, $response->getStatusCode());
            $this->assertStringContainsString('Security verification failed', $_SESSION['comment_error'] ?? '');
            unset($_SESSION['comment_error']);
        }

        $this->assertSame(0, $this->countComments('`content` = ?', ['Comment without a session CSRF token']));
    }

    /**
     * Subdirectory deployment preserves base path and does NOT 404 upon redirect.
     */
    public function testSubdirectoryCommentSubmissionAndRedirectDoesNot404(): void
    {
        $_SESSION['auth_user_id'] = static::$memberId;
        $GLOBALS['favorite_cms_base_path'] = '/cms';

        $subPostResp = $this->submitComment(
            ['content' => 'Subdirectory comment test.'],
            '/cms/post/' . static::$postSlug . '/comment',
            ['SCRIPT_NAME' => '/cms/index.php']
        );
        $this->assertSame(302, $subPostResp->getStatusCode());

        $location = $subPostResp->getHeader('Location');
        $this->assertSame('/cms/post/' . static::$postSlug . '?comment=submitted#comments', $location);

        $targetPath = (string)preg_replace('/[?#].*$/', '', (string)$location);
        $subFollowResp = $this->requestPage($targetPath, ['comment' => 'submitted'], ['SCRIPT_NAME' => '/cms/index.php']);
        $this->assertSame(200, $subFollowResp->getStatusCode(), 'Subdirectory post URL must resolve to 200, not 404!');
        $this->assertStringNotContainsString('404 Page Not Found', $subFollowResp->getContent());
        $this->assertStringContainsString('Subdirectory comment test.', $subFollowResp->getContent());

        unset($GLOBALS['favorite_cms_base_path']);
    }

    /**
     * Empty comment content fails validation and redirects safely without 404.
     */
    public function testEmptyCommentValidationFailureRedirectsSafelyWithout404(): void
    {
        $_SESSION['auth_user_id'] = static::$memberId;

        $response = $this->submitComment(['content' => '   ']);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/post/' . static::$postSlug . '#comments', $response->getHeader('Location'));
        $this->assertNotEmpty($_SESSION['comment_error']);

        $followResponse = $this->requestPage('/post/' . static::$postSlug);
        $this->assertSame(200, $followResponse->getStatusCode());
        $this->assertStringContainsString('Please write a comment before submitting.', $followResponse->getContent());
    }

    /**
     * Open redirect parameters are ignored; redirect is strictly the canonical post URL.
     */
    public function testOpenRedirectIsPrevented(): void
    {
        $_SESSION['auth_user_id'] = static::$memberId;

        $response = $this->submitComment([
            'content'     => 'Open redirect exploit attempt.',
            'redirect_to' => 'https://attacker.example/malicious-login',
            'return_url'  => '//attacker.example/phishing',
            'redirect'    => 'https://attacker.example/phishing',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $location = $response->getHeader('Location');
        $this->assertStringNotContainsString('attacker.example', (string)$location);
        $this->assertSame('/post/' . static::$postSlug . '?comment=submitted#comments', $location);
    }

    /**
     * Alternative endpoint /comment/submit works for authenticated users.
     */
    public function testAlternativeEndpointCommentSubmitWorksEquallyWell(): void
    {
        $_SESSION['auth_user_id'] = static::$memberId;

        $response = $this->submitComment(['content' => 'Alternative route submission test.'], '/comment/submit');
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/post/' . static::$postSlug . '?comment=submitted#comments', $response->getHeader('Location'));
        $this->assertSame(1, $this->countComments('`content` = ? AND `user_id` = ?', ['Alternative route submission test.', static::$memberId]));
    }

    public function testSuspendedUserCommentSubmissionIsBlocked(): void
    {
        $_SESSION['auth_user_id'] = static::$suspendedUserId;

        $response = $this->submitComment(['content' => 'Attempt by suspended user.']);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/post/' . static::$postSlug . '#comments', $response->getHeader('Location'));
        $this->assertStringContainsString('suspended', strtolower($_SESSION['comment_error'] ?? ''));
        $this->assertSame(0, $this->countComments('`content` = ?', ['Attempt by suspended user.']));
    }

    /**
     * Historical comments stored before accounts were required (user_id NULL) still render unchanged.
     */
    public function testHistoricalAnonymousCommentsStillRenderCorrectly(): void
    {
        $now = date('Y-m-d H:i:s');
        $historicalId = static::$db->insert('comments', [
            'post_id'      => static::$postId,
            'author_name'  => 'Historical Guest Reader',
            'author_email' => 'historical-guest@example.com',
            'author_ip'    => '203.0.113.5',
            'content'      => 'A historical anonymous comment from before accounts were required.',
            'status'       => 'approved',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $loggedOutHtml = $this->requestPage('/post/' . static::$postSlug)->getContent();
        $this->assertStringContainsString('Historical Guest Reader', $loggedOutHtml);
        $this->assertStringContainsString('A historical anonymous comment from before accounts were required.', $loggedOutHtml);

        $_SESSION['auth_user_id'] = static::$memberId;
        $loggedInHtml = $this->requestPage('/post/' . static::$postSlug)->getContent();
        $this->assertStringContainsString('Historical Guest Reader', $loggedInHtml);

        $row = static::$db->selectOne("SELECT * FROM `comments` WHERE `id` = ?", [$historicalId]);
        $this->assertNull($row->user_id);
        $this->assertSame('Historical Guest Reader', $row->author_name);
        $this->assertSame('historical-guest@example.com', $row->author_email);
        $this->assertSame('approved', $row->status);
    }

    /**
     * Admin moderation (unapprove / approve / spam / trash) still controls public visibility.
     */
    public function testExistingModerationBehaviorRemainsIntact(): void
    {
        $_SESSION['auth_user_id'] = static::$memberId;
        $content = 'Moderation lifecycle comment ' . bin2hex(random_bytes(3));

        $this->submitComment(['content' => $content]);
        $row = static::$db->selectOne("SELECT * FROM `comments` WHERE `post_id` = ? AND `content` = ?", [static::$postId, $content]);
        $this->assertNotNull($row);
        $this->assertSame('approved', $row->status, 'Comments remain auto-approved');
        $commentId = (int)$row->id;

        $controller = new CommentController(static::$app);
        $moderate = function (string $action) use ($controller, $commentId): void {
            $response = $controller->{$action}(new Request(['id' => $commentId, '_token' => self::TOKEN], [], ['REQUEST_METHOD' => 'GET']));
            $this->assertSame(302, $response->getStatusCode());
        };

        $moderate('unapprove');
        $this->assertSame('pending', Comment::find($commentId)->status);
        $this->assertStringNotContainsString($content, $this->requestPage('/post/' . static::$postSlug)->getContent());

        $moderate('approve');
        $this->assertSame('approved', Comment::find($commentId)->status);
        $this->assertStringContainsString($content, $this->requestPage('/post/' . static::$postSlug)->getContent());

        $moderate('spam');
        $this->assertSame('spam', Comment::find($commentId)->status);
        $this->assertStringNotContainsString($content, $this->requestPage('/post/' . static::$postSlug)->getContent());

        $moderate('trash');
        $this->assertSame('trash', Comment::find($commentId)->status);
        $this->assertStringNotContainsString($content, $this->requestPage('/post/' . static::$postSlug)->getContent());
    }
}
