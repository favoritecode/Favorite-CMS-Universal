<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use PHPUnit\Framework\TestCase;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Kernel;
use FavoriteCMS\Models\User;
use FavoriteCMS\Models\Role;
use FavoriteCMS\Models\Post;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Http\Controllers\Admin\PostController;
use FavoriteCMS\Http\Controllers\Admin\UserController;
use FavoriteCMS\Http\Controllers\Admin\MediaController;
use FavoriteCMS\Services\MediaService;

class UserSignupAndModerationTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db = static::$app->make(Database::class);

        static::$db->execute("INSERT IGNORE INTO `roles` (`name`, `slug`, `description`, `is_system`) VALUES ('Moderator', 'moderator', 'Can moderate comments and content', 1)");
        static::$db->execute("INSERT IGNORE INTO `permissions` (`name`, `slug`, `description`, `group_name`) VALUES ('Approve Posts', 'approve_posts', 'Review and approve submitted posts', 'content')");
        static::$db->execute("INSERT IGNORE INTO `permissions` (`name`, `slug`, `description`, `group_name`) VALUES ('Upload Moderator Media', 'upload_moderator_media', 'Allow uploading media files up to moderator limit (500 MB)', 'content')");
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
        Setting::set('general', 'allow_registration', 1, 'bool');
    }

    protected function createTestUser(string $prefix, string $roleSlug = 'subscriber', string $status = 'active'): User
    {
        $unique = $prefix . '_' . bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');
        $hash = password_hash('SecretPassword123!', PASSWORD_DEFAULT);

        $userId = static::$db->insert('users', [
            'username'          => $unique,
            'name'              => ucfirst($unique),
            'email'             => $unique . '@example.com',
            'password'          => $hash,
            'status'            => $status,
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

    public function testPublicUserRegistrationSuccessful(): void
    {
        $kernel = new Kernel(static::$app);
        $token = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $token;

        $username = 'newuser_' . bin2hex(random_bytes(4));
        $email = $username . '@example.com';

        $req = new Request(
            get: [],
            post: [
                '_token'                => $token,
                'username'              => $username,
                'name'                  => 'New User Test',
                'email'                 => $email,
                'password'              => 'Password123!',
                'password_confirmation' => 'Password123!',
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/register']
        );

        $response = $kernel->handle($req);
        $this->assertSame(302, $response->getStatusCode(), 'Expected 302 redirect, got: ' . $response->getStatusCode() . ' ' . $response->getContent());

        // Check user exists in database
        $createdUser = User::findByUsername($username);
        $this->assertNotNull($createdUser, 'User should be registered in database');
        $this->assertSame('active', $createdUser->status);
        $this->assertSame('Subscriber', $createdUser->getPrimaryRoleName());
        $this->assertTrue($createdUser->verifyPassword('Password123!'));

        // Check user was automatically logged in
        $this->assertSame($createdUser->id, $_SESSION['auth_user_id'] ?? null);

        // Clean up
        static::$db->execute("DELETE FROM `user_roles` WHERE `user_id` = ?", [$createdUser->id]);
        $createdUser->delete();
    }

    public function testPublicUserRegistrationDuplicatePrevented(): void
    {
        $existing = $this->createTestUser('dup');
        $kernel = new Kernel(static::$app);
        $token = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $token;

        // Try registering with same username
        $req = new Request(
            get: [],
            post: [
                '_token'                => $token,
                'username'              => $existing->username,
                'name'                  => 'Duplicate Attempt',
                'email'                 => 'another@example.com',
                'password'              => 'Password123!',
                'password_confirmation' => 'Password123!',
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/register']
        );

        $response = $kernel->handle($req);
        $body = $response->getContent();

        $this->assertStringContainsString('already exists', $body);

        // Clean up
        static::$db->execute("DELETE FROM `user_roles` WHERE `user_id` = ?", [$existing->id]);
        $existing->delete();
    }

    public function testPublicRegistrationDisabledEnforced(): void
    {
        Setting::set('general', 'allow_registration', 0, 'bool');
        $kernel = new Kernel(static::$app);
        $token = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $token;

        $req = new Request(
            get: [],
            post: [
                '_token'                => $token,
                'username'              => 'blocked_user',
                'email'                 => 'blocked@example.com',
                'password'              => 'Password123!',
                'password_confirmation' => 'Password123!',
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/register']
        );

        $response = $kernel->handle($req);
        $body = $response->getContent();

        $this->assertStringContainsString('registration is currently disabled', $body);
        $this->assertNull(User::findByUsername('blocked_user'));
    }

    public function testNormalUserPostCreationForcedToPending(): void
    {
        $normalUser = $this->createTestUser('author', 'subscriber');
        $_SESSION['auth_user_id'] = $normalUser->id;

        $postCtrl = new PostController(static::$app);

        // Even when status is explicitly sent as 'published', normal user cannot publish directly
        $req = new Request(
            get: [],
            post: [
                'title'       => 'Normal User Submission',
                'content'     => '<p>User content awaiting review</p>',
                'status'      => 'published', // Tamper attempt
                'action_type' => 'publish',   // Tamper attempt
            ],
            server: ['REQUEST_METHOD' => 'POST']
        );

        $resp = $postCtrl->store($req);

        $post = Post::findBySlug('normal-user-submission');
        $this->assertNotNull($post);
        $this->assertSame('pending', $post->status, 'Normal user posts must be forced to pending status');
        $this->assertTrue($post->isPending());
        $this->assertFalse($post->isPublished());
        $this->assertSame($normalUser->id, (int)$post->author_id);

        // Clean up
        $post->delete();
        static::$db->execute("DELETE FROM `user_roles` WHERE `user_id` = ?", [$normalUser->id]);
        $normalUser->delete();
    }

    public function testModeratorPostCreationPublishesDirectly(): void
    {
        $moderator = $this->createTestUser('mod', 'moderator');
        $_SESSION['auth_user_id'] = $moderator->id;

        $this->assertTrue($moderator->canDirectPublish(), 'Moderator must have direct publish rights');
        $this->assertTrue($moderator->canModeratePosts(), 'Moderator must have post moderation rights');

        $postCtrl = new PostController(static::$app);

        $req = new Request(
            get: [],
            post: [
                'title'       => 'Moderator Direct Post',
                'content'     => '<p>Moderator content published directly</p>',
                'status'      => 'published',
                'action_type' => 'publish',
            ],
            server: ['REQUEST_METHOD' => 'POST']
        );

        $resp = $postCtrl->store($req);

        $post = Post::findBySlug('moderator-direct-post');
        $this->assertNotNull($post);
        $this->assertSame('published', $post->status, 'Moderator posts must publish directly without pending moderation');
        $this->assertTrue($post->isPublished());
        $this->assertNotNull($post->published_at);

        // Clean up
        $post->delete();
        static::$db->execute("DELETE FROM `user_roles` WHERE `user_id` = ?", [$moderator->id]);
        $moderator->delete();
    }

    public function testModeratorCanApproveAndRejectPendingPost(): void
    {
        $normalUser = $this->createTestUser('usr', 'subscriber');
        $moderator = $this->createTestUser('mod', 'moderator');

        // Create a pending post
        $now = date('Y-m-d H:i:s');
        $postId = static::$db->insert('posts', [
            'title'        => 'Post To Approve',
            'slug'         => 'post-to-approve-' . bin2hex(random_bytes(4)),
            'content'      => '<p>Content for approval</p>',
            'status'       => 'pending',
            'type'         => 'post',
            'author_id'    => $normalUser->id,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        $post = Post::find($postId);
        $this->assertTrue($post->isPending());

        // 1. Normal user tries to approve -> rejected!
        $_SESSION['auth_user_id'] = $normalUser->id;
        $postCtrl = new PostController(static::$app);
        $reqApprove = new Request(get: ['id' => $postId]);
        $postCtrl->approve($reqApprove);

        $post = Post::find($postId);
        $this->assertSame('pending', $post->status, 'Normal user must not be able to approve posts');

        // 2. Moderator approves -> published!
        $_SESSION['auth_user_id'] = $moderator->id;
        $postCtrl->approve($reqApprove);

        $post = Post::find($postId);
        $this->assertSame('published', $post->status, 'Moderator should successfully approve post');
        $this->assertTrue($post->isPublished());
        $this->assertNotNull($post->published_at);

        // 3. Moderator rejects -> rejected!
        $reqReject = new Request(get: ['id' => $postId]);
        $postCtrl->reject($reqReject);

        $post = Post::find($postId);
        $this->assertSame('rejected', $post->status, 'Moderator should successfully reject post');
        $this->assertTrue($post->isRejected());

        // Clean up
        $post->delete();
        static::$db->execute("DELETE FROM `user_roles` WHERE `user_id` IN (?, ?)", [$normalUser->id, $moderator->id]);
        $normalUser->delete();
        $moderator->delete();
    }

    public function testSuspendedUserCannotCreatePostsOrUploadMedia(): void
    {
        $suspendedUser = $this->createTestUser('susp', 'subscriber', 'suspended');
        $this->assertTrue($suspendedUser->isSuspended());
        $this->assertFalse($suspendedUser->canCreatePosts());
        $this->assertFalse($suspendedUser->canUploadMedia());

        $_SESSION['auth_user_id'] = $suspendedUser->id;

        // Try creating post
        $postCtrl = new PostController(static::$app);
        $reqPost = new Request(
            get: [],
            post: [
                'title'   => 'Suspended Post Attempt',
                'content' => '<p>Suspended content</p>',
            ],
            server: ['REQUEST_METHOD' => 'POST']
        );

        $postCtrl->store($reqPost);
        $this->assertNull(Post::findBySlug('suspended-post-attempt'), 'Suspended user must not be able to create posts');
        $this->assertStringContainsString('suspended', $_SESSION['flash_error'] ?? '');

        // Try uploading media via controller
        $mediaCtrl = new MediaController(static::$app);
        $_FILES['file'] = [
            'name'     => 'test.jpg',
            'type'     => 'image/jpeg',
            'tmp_name' => '',
            'error'    => UPLOAD_ERR_NO_FILE,
            'size'     => 1024,
        ];
        $mediaCtrl->upload(new Request());
        $this->assertStringContainsString('suspended', $_SESSION['flash_error'] ?? '');

        // Clean up
        static::$db->execute("DELETE FROM `user_roles` WHERE `user_id` = ?", [$suspendedUser->id]);
        $suspendedUser->delete();
    }

    public function testBannedUserCannotLogIn(): void
    {
        $bannedUser = $this->createTestUser('banned', 'subscriber', 'banned');
        $this->assertTrue($bannedUser->isBanned());

        $kernel = new Kernel(static::$app);
        $token = bin2hex(random_bytes(32));
        $_SESSION['_token'] = $token;

        $req = new Request(
            get: [],
            post: [
                '_token'   => $token,
                'login'    => $bannedUser->username,
                'password' => 'SecretPassword123!',
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/login']
        );

        $resp = $kernel->handle($req);

        $this->assertNull($_SESSION['auth_user_id'] ?? null, 'Banned user must not be authenticated');

        $body = $resp->getContent();
        $this->assertStringContainsString('permanently banned', $body);

        // Clean up
        static::$db->execute("DELETE FROM `user_roles` WHERE `user_id` = ?", [$bannedUser->id]);
        $bannedUser->delete();
    }

    public function testAdminCanSuspendUnsuspendBanAndRestoreUser(): void
    {
        $admin = $this->createTestUser('admin', 'admin');
        $target = $this->createTestUser('target', 'subscriber');
        $_SESSION['auth_user_id'] = $admin->id;

        $userCtrl = new UserController(static::$app);

        // 1. Suspend target
        $userCtrl->changeStatus(new Request(get: ['id' => $target->id, 'status' => 'suspended']));
        $target = User::find($target->id);
        $this->assertSame('suspended', $target->status);
        $this->assertTrue($target->isSuspended());

        // 2. Unsuspend (Activate) target
        $userCtrl->changeStatus(new Request(get: ['id' => $target->id, 'status' => 'active']));
        $target = User::find($target->id);
        $this->assertSame('active', $target->status);
        $this->assertTrue($target->isActive());

        // 3. Ban target
        $userCtrl->changeStatus(new Request(get: ['id' => $target->id, 'status' => 'banned']));
        $target = User::find($target->id);
        $this->assertSame('banned', $target->status);
        $this->assertTrue($target->isBanned());

        // 4. Restore target
        $userCtrl->changeStatus(new Request(get: ['id' => $target->id, 'status' => 'active']));
        $target = User::find($target->id);
        $this->assertSame('active', $target->status);

        // Clean up
        static::$db->execute("DELETE FROM `user_roles` WHERE `user_id` IN (?, ?)", [$admin->id, $target->id]);
        $admin->delete();
        $target->delete();
    }

    public function testNormalUserPostUpdateForcesPendingOnPublishAttempt(): void
    {
        $normalUser = $this->createTestUser('writer', 'subscriber');
        $_SESSION['auth_user_id'] = $normalUser->id;

        // Create a draft post owned by normal user
        $now = date('Y-m-d H:i:s');
        $postId = static::$db->insert('posts', [
            'title'        => 'Draft Post To Update',
            'slug'         => 'draft-post-to-update-' . bin2hex(random_bytes(4)),
            'content'      => '<p>Draft content</p>',
            'status'       => 'draft',
            'type'         => 'post',
            'author_id'    => $normalUser->id,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        $post = Post::find($postId);

        $postCtrl = new PostController(static::$app);

        // Attempt to update and publish directly
        $req = new Request(
            get: [],
            post: [
                'id'          => $postId,
                'title'       => 'Updated Title',
                'content'     => '<p>Updated content</p>',
                'status'      => 'published', // Tamper attempt
                'action_type' => 'publish',   // Tamper attempt
            ],
            server: ['REQUEST_METHOD' => 'POST']
        );

        $postCtrl->update($req);
        $post = Post::find($postId);

        $this->assertSame('pending', $post->status, 'Normal user updating with publish intent must be forced to pending');
        $this->assertTrue($post->isPending());

        // Clean up
        $post->delete();
        static::$db->execute("DELETE FROM `user_roles` WHERE `user_id` = ?", [$normalUser->id]);
        $normalUser->delete();
    }

    public function testUnauthorizedUserCannotChangeStatusOrRole(): void
    {
        $normalUser = $this->createTestUser('normal_user', 'subscriber');
        $target = $this->createTestUser('target_user', 'subscriber');
        $_SESSION['auth_user_id'] = $normalUser->id;

        $userCtrl = new UserController(static::$app);

        // Normal user attempts to ban target user
        $resp = $userCtrl->changeStatus(new Request(get: ['id' => $target->id, 'status' => 'banned']));
        $this->assertSame(403, $resp->getStatusCode(), 'Normal user must be forbidden from changing user status');

        $target = User::find($target->id);
        $this->assertSame('active', $target->status, 'Target user status should remain unaffected');

        // Normal user attempts to change role
        $respRole = $userCtrl->changeRole(new Request(post: ['id' => $target->id, 'role_id' => 1]));
        $this->assertSame(403, $respRole->getStatusCode(), 'Normal user must be forbidden from changing user role');

        // Clean up
        static::$db->execute("DELETE FROM `user_roles` WHERE `user_id` IN (?, ?)", [$normalUser->id, $target->id]);
        $normalUser->delete();
        $target->delete();
    }

    public function testModeratorCannotAccessAdminOnlyRoutes(): void
    {
        $moderator = $this->createTestUser('mod_guard', 'moderator');
        $_SESSION['auth_user_id'] = $moderator->id;
        $_SESSION['auth_user_name'] = $moderator->username;

        $kernel = new Kernel(static::$app);

        // Try accessing /admin/settings
        $reqSettings = new Request(
            get: [],
            post: [],
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/settings']
        );
        $respSettings = $kernel->handle($reqSettings);
        $this->assertSame(403, $respSettings->getStatusCode(), 'Moderator should receive 403 on /admin/settings');

        // Try accessing /admin/themes
        $reqThemes = new Request(
            get: [],
            post: [],
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/themes']
        );
        $respThemes = $kernel->handle($reqThemes);
        $this->assertSame(403, $respThemes->getStatusCode(), 'Moderator should receive 403 on /admin/themes');

        // Try accessing /admin/plugins
        $reqPlugins = new Request(
            get: [],
            post: [],
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/plugins']
        );
        $respPlugins = $kernel->handle($reqPlugins);
        $this->assertSame(403, $respPlugins->getStatusCode(), 'Moderator should receive 403 on /admin/plugins');

        // Clean up
        static::$db->execute("DELETE FROM `user_roles` WHERE `user_id` = ?", [$moderator->id]);
        $moderator->delete();
    }

    public function testBannedSessionIsKickedOutImmediately(): void
    {
        $user = $this->createTestUser('logged_in_user', 'subscriber', 'active');
        $_SESSION['auth_user_id'] = $user->id;
        $_SESSION['auth_user_name'] = $user->username;

        $kernel = new Kernel(static::$app);

        // First request is allowed
        $req = new Request(
            get: [],
            post: [],
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/posts']
        );
        $resp = $kernel->handle($req);
        $this->assertNotSame(302, $resp->getStatusCode());

        // Now an admin bans this user
        $user->update(['status' => 'banned']);

        // User makes next request to admin
        $respAfterBan = $kernel->handle($req);
        $this->assertSame(302, $respAfterBan->getStatusCode());
        $this->assertSame('/admin/login', $respAfterBan->getHeaders()['Location'] ?? '');
        $this->assertNull($_SESSION['auth_user_id'] ?? null, 'Session auth_user_id must be cleared');
        $this->assertStringContainsString('permanently banned', $_SESSION['flash_error'] ?? '');

        // Clean up
        static::$db->execute("DELETE FROM `user_roles` WHERE `user_id` = ?", [$user->id]);
        $user->delete();
    }
}

