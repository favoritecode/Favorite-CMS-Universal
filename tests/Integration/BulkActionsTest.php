<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Kernel;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Http\Controllers\Admin\CommentController;
use FavoriteCMS\Http\Controllers\Admin\PageController;
use FavoriteCMS\Http\Controllers\Admin\PostController;
use FavoriteCMS\Http\Controllers\Admin\UserController;
use FavoriteCMS\Models\Comment;
use FavoriteCMS\Models\Page;
use FavoriteCMS\Models\Post;
use FavoriteCMS\Models\User;
use PHPUnit\Framework\TestCase;

class BulkActionsTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db = static::$app->make(Database::class);
    }

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_FILES = [];
    }

    protected function createTestAdmin(): User
    {
        $unique = 'admin_' . bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');
        $hash = password_hash('Pass12345!', PASSWORD_DEFAULT);

        $userId = static::$db->insert('users', [
            'username'          => $unique,
            'name'              => ucfirst($unique),
            'email'             => $unique . '@example.com',
            'password'          => $hash,
            'status'            => 'active',
            'email_verified_at' => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        $role = static::$db->selectOne("SELECT id FROM `roles` WHERE `slug` = 'admin' LIMIT 1");
        if ($role) {
            static::$db->execute("INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [$userId, $role->id]);
        }

        return User::find($userId);
    }

    public function testBulkPostsTrashRestoreDelete(): void
    {
        $admin = $this->createTestAdmin();
        $_SESSION['auth_user_id'] = $admin->id;
        $token = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $token;

        $post1Id = static::$db->insert('posts', [
            'title'      => 'Bulk Post 1 ' . bin2hex(random_bytes(3)),
            'slug'       => 'bulk-post-1-' . bin2hex(random_bytes(3)),
            'content'    => 'Content 1',
            'status'     => 'published',
            'type'       => 'post',
            'author_id'  => $admin->id,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $post2Id = static::$db->insert('posts', [
            'title'      => 'Bulk Post 2 ' . bin2hex(random_bytes(3)),
            'slug'       => 'bulk-post-2-' . bin2hex(random_bytes(3)),
            'content'    => 'Content 2',
            'status'     => 'published',
            'type'       => 'post',
            'author_id'  => $admin->id,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $controller = new PostController(static::$app);

        // 1. Bulk Trash
        $reqTrash = new Request([], [
            '_token'      => $token,
            'bulk_action' => 'trash',
            'ids'         => [$post1Id, $post2Id],
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $controller->bulkAction($reqTrash);
        $this->assertSame(302, $res->getStatusCode());

        $this->assertSame('trash', Post::find($post1Id)->status);
        $this->assertSame('trash', Post::find($post2Id)->status);

        // 2. Bulk Restore
        $reqRestore = new Request([], [
            '_token'      => $token,
            'bulk_action' => 'restore',
            'ids'         => [$post1Id, $post2Id],
        ], ['REQUEST_METHOD' => 'POST']);

        $controller->bulkAction($reqRestore);
        $this->assertSame('draft', Post::find($post1Id)->status);
        $this->assertSame('draft', Post::find($post2Id)->status);

        // 3. Bulk Delete
        $reqDelete = new Request([], [
            '_token'      => $token,
            'bulk_action' => 'delete',
            'ids'         => [$post1Id, $post2Id],
        ], ['REQUEST_METHOD' => 'POST']);

        $controller->bulkAction($reqDelete);
        $this->assertNull(Post::find($post1Id));
        $this->assertNull(Post::find($post2Id));
    }

    public function testBulkPagesTrashAndRestore(): void
    {
        $admin = $this->createTestAdmin();
        $_SESSION['auth_user_id'] = $admin->id;
        $token = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $token;

        $page1Id = static::$db->insert('pages', [
            'title'      => 'Bulk Page 1 ' . bin2hex(random_bytes(3)),
            'slug'       => 'bulk-page-1-' . bin2hex(random_bytes(3)),
            'content'    => 'Page Content 1',
            'status'     => 'published',
            'author_id'  => $admin->id,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $page2Id = static::$db->insert('pages', [
            'title'      => 'Bulk Page 2 ' . bin2hex(random_bytes(3)),
            'slug'       => 'bulk-page-2-' . bin2hex(random_bytes(3)),
            'content'    => 'Page Content 2',
            'status'     => 'published',
            'author_id'  => $admin->id,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $controller = new PageController(static::$app);

        // Bulk Trash
        $req = new Request([], [
            '_token'      => $token,
            'bulk_action' => 'trash',
            'ids'         => [$page1Id, $page2Id],
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $controller->bulkAction($req);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('trash', Page::find($page1Id)->status);
        $this->assertSame('trash', Page::find($page2Id)->status);

        // Bulk Restore
        $reqRestore = new Request([], [
            '_token'      => $token,
            'bulk_action' => 'restore',
            'ids'         => [$page1Id, $page2Id],
        ], ['REQUEST_METHOD' => 'POST']);

        $controller->bulkAction($reqRestore);
        $this->assertSame('draft', Page::find($page1Id)->status);
        $this->assertSame('draft', Page::find($page2Id)->status);

        // Clean up
        Page::find($page1Id)->delete();
        Page::find($page2Id)->delete();
    }

    public function testBulkCommentsApproveSpamTrash(): void
    {
        $admin = $this->createTestAdmin();
        $_SESSION['auth_user_id'] = $admin->id;
        $token = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $token;

        // Create a dummy post for the comments
        $postId = static::$db->insert('posts', [
            'title'      => 'Comment Target Post',
            'slug'       => 'comment-target-' . bin2hex(random_bytes(3)),
            'content'    => 'Target Content',
            'status'     => 'published',
            'type'       => 'post',
            'author_id'  => $admin->id,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $c1Id = static::$db->insert('comments', [
            'post_id'      => $postId,
            'author_name'  => 'User 1',
            'author_email' => 'user1@example.com',
            'content'      => 'Comment 1',
            'status'       => 'pending',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $c2Id = static::$db->insert('comments', [
            'post_id'      => $postId,
            'author_name'  => 'User 2',
            'author_email' => 'user2@example.com',
            'content'      => 'Comment 2',
            'status'       => 'pending',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $controller = new CommentController(static::$app);

        // Bulk Approve
        $reqApprove = new Request([], [
            '_token'      => $token,
            'bulk_action' => 'approve',
            'ids'         => [$c1Id, $c2Id],
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $controller->bulkAction($reqApprove);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('approved', Comment::find($c1Id)->status);
        $this->assertSame('approved', Comment::find($c2Id)->status);

        // Bulk Spam
        $reqSpam = new Request([], [
            '_token'      => $token,
            'bulk_action' => 'spam',
            'ids'         => [$c1Id, $c2Id],
        ], ['REQUEST_METHOD' => 'POST']);

        $controller->bulkAction($reqSpam);
        $this->assertSame('spam', Comment::find($c1Id)->status);
        $this->assertSame('spam', Comment::find($c2Id)->status);

        // Bulk Delete
        $reqDelete = new Request([], [
            '_token'      => $token,
            'bulk_action' => 'delete',
            'ids'         => [$c1Id, $c2Id],
        ], ['REQUEST_METHOD' => 'POST']);

        $controller->bulkAction($reqDelete);
        $this->assertNull(Comment::find($c1Id));
        $this->assertNull(Comment::find($c2Id));

        Post::find($postId)->delete();
    }

    public function testBulkUsersSuspendAndActivateWithSelfGuard(): void
    {
        $admin = $this->createTestAdmin();
        $_SESSION['auth_user_id'] = $admin->id;
        $token = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $token;

        $target1 = $this->createTestAdmin();
        $target2 = $this->createTestAdmin();

        $controller = new UserController(static::$app);

        // Bulk Suspend (including current admin ID to verify self-guard)
        $reqSuspend = new Request([], [
            '_token'      => $token,
            'bulk_action' => 'suspend',
            'ids'         => [$admin->id, $target1->id, $target2->id],
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $controller->bulkAction($reqSuspend);
        $this->assertSame(302, $res->getStatusCode());

        // Target users suspended, but admin remains active (self-guard)
        $this->assertSame('active', User::find($admin->id)->status);
        $this->assertSame('suspended', User::find($target1->id)->status);
        $this->assertSame('suspended', User::find($target2->id)->status);

        // Bulk Activate
        $reqActivate = new Request([], [
            '_token'      => $token,
            'bulk_action' => 'activate',
            'ids'         => [$target1->id, $target2->id],
        ], ['REQUEST_METHOD' => 'POST']);

        $controller->bulkAction($reqActivate);
        $this->assertSame('active', User::find($target1->id)->status);
        $this->assertSame('active', User::find($target2->id)->status);

        // Clean up
        $target1->delete();
        $target2->delete();
        $admin->delete();
    }

    protected function createTestUser(string $roleSlug = 'subscriber'): User
    {
        $unique = 'user_' . bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');
        $hash = password_hash('Pass12345!', PASSWORD_DEFAULT);

        $userId = static::$db->insert('users', [
            'username'          => $unique,
            'name'              => ucfirst($unique),
            'email'             => $unique . '@example.com',
            'password'          => $hash,
            'status'            => 'active',
            'email_verified_at' => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        $role = static::$db->selectOne("SELECT id FROM `roles` WHERE `slug` = ? LIMIT 1", [$roleSlug]);
        if ($role) {
            static::$db->execute("INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [$userId, $role->id]);
        }

        return User::find($userId);
    }

    public function testBulkActionFailsOnInvalidCsrfToken(): void
    {
        $admin = $this->createTestAdmin();
        $_SESSION['auth_user_id'] = $admin->id;
        $_SESSION['_token'] = 'valid_session_token';

        $controller = new PostController(static::$app);
        $request = new Request([], [
            '_token'      => 'invalid_token_123',
            'bulk_action' => 'trash',
            'ids'         => [1],
        ], ['REQUEST_METHOD' => 'POST']);

        $response = $controller->bulkAction($request);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('CSRF', $_SESSION['flash_error'] ?? '');

        $admin->delete();
    }

    public function testBulkActionFailsOnEmptySelection(): void
    {
        $admin = $this->createTestAdmin();
        $_SESSION['auth_user_id'] = $admin->id;
        $token = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $token;

        $controller = new PostController(static::$app);

        // 1. Empty IDs
        $requestNoIds = new Request([], [
            '_token'      => $token,
            'bulk_action' => 'trash',
            'ids'         => [],
        ], ['REQUEST_METHOD' => 'POST']);

        $response = $controller->bulkAction($requestNoIds);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('select at least one', $_SESSION['flash_error'] ?? '');

        // 2. Empty action
        $requestNoAction = new Request([], [
            '_token'      => $token,
            'bulk_action' => '',
            'ids'         => [1],
        ], ['REQUEST_METHOD' => 'POST']);

        $response = $controller->bulkAction($requestNoAction);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('select at least one', $_SESSION['flash_error'] ?? '');

        $admin->delete();
    }

    public function testBulkActionIgnoresUnauthorizedPostForNormalUser(): void
    {
        $admin = $this->createTestAdmin();
        $normalUser = $this->createTestUser('subscriber');
        $this->assertFalse($normalUser->canModeratePosts());

        // Create a post authored by admin
        $postId = static::$db->insert('posts', [
            'title'      => 'Admin Protected Post ' . bin2hex(random_bytes(3)),
            'slug'       => 'admin-protected-' . bin2hex(random_bytes(3)),
            'content'    => 'Protected Content',
            'status'     => 'published',
            'type'       => 'post',
            'author_id'  => $admin->id,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $_SESSION['auth_user_id'] = $normalUser->id;
        $token = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $token;

        $controller = new PostController(static::$app);
        $request = new Request([], [
            '_token'      => $token,
            'bulk_action' => 'trash',
            'ids'         => [$postId],
        ], ['REQUEST_METHOD' => 'POST']);

        $response = $controller->bulkAction($request);
        $this->assertSame(302, $response->getStatusCode());

        // The post must NOT be trashed because normalUser is not author and cannot moderate
        $post = Post::find($postId);
        $this->assertSame('published', $post->status);

        // Clean up
        $post->delete();
        $admin->delete();
        $normalUser->delete();
    }

    public function testBulkActionSafelyHandlesNonExistentIds(): void
    {
        $admin = $this->createTestAdmin();
        $_SESSION['auth_user_id'] = $admin->id;
        $token = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $token;

        $controller = new PostController(static::$app);
        $request = new Request([], [
            '_token'      => $token,
            'bulk_action' => 'trash',
            'ids'         => [99999991, 99999992],
        ], ['REQUEST_METHOD' => 'POST']);

        $response = $controller->bulkAction($request);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('No posts were updated.', $_SESSION['flash_error'] ?? '');

        $admin->delete();
    }
}
