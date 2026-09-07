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
use FavoriteCMS\Models\Media;
use FavoriteCMS\Http\Controllers\Admin\UserController;
use FavoriteCMS\Http\Controllers\Admin\PostController;
use FavoriteCMS\Http\Controllers\Admin\MediaController;
use FavoriteCMS\Http\Controllers\FrontendController;

class UserProfileAndAccountTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;
    private static array $createdUserIds = [];

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db = static::$app->make(Database::class);

        static::$db->execute("INSERT IGNORE INTO `roles` (`name`, `slug`, `description`, `is_system`) VALUES ('Moderator', 'moderator', 'Can moderate comments and content', 1)");
        static::$db->execute("INSERT IGNORE INTO `roles` (`name`, `slug`, `description`, `is_system`) VALUES ('Subscriber', 'subscriber', 'Regular registered user', 1)");
        static::$db->execute("INSERT IGNORE INTO `roles` (`name`, `slug`, `description`, `is_system`) VALUES ('Administrator', 'admin', 'Site administrator', 1)");
        static::$db->execute("INSERT IGNORE INTO `permissions` (`name`, `slug`, `description`, `group_name`) VALUES ('Approve Posts', 'approve_posts', 'Review and approve submitted posts', 'content')");
        static::$db->execute("INSERT IGNORE INTO `permissions` (`name`, `slug`, `description`, `group_name`) VALUES ('Publish Direct', 'publish_direct', 'Directly publish posts without review', 'content')");
    }

    public static function tearDownAfterClass(): void
    {
        if (!empty(static::$createdUserIds)) {
            $inClause = implode(',', array_map('intval', static::$createdUserIds));
            static::$db->execute("DELETE FROM `user_roles` WHERE `user_id` IN ({$inClause})");
            static::$db->execute("DELETE FROM `posts` WHERE `author_id` IN ({$inClause})");
            static::$db->execute("DELETE FROM `users` WHERE `id` IN ({$inClause})");
        }
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
        \FavoriteCMS\Models\Setting::set('general', 'allow_registration', 1, 'bool');
        \FavoriteCMS\Models\Setting::set('general', 'require_email_verification', 1, 'bool');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        \FavoriteCMS\Models\Setting::set('general', 'allow_registration', 1, 'bool');
        \FavoriteCMS\Models\Setting::set('general', 'require_email_verification', 1, 'bool');
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

        static::$createdUserIds[] = $userId;

        $role = static::$db->selectOne("SELECT id FROM `roles` WHERE `slug` = ? LIMIT 1", [$roleSlug]);
        if ($role) {
            static::$db->execute("INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [$userId, $role->id]);
        }

        return User::find($userId);
    }

    public function testProfileViewRendersForAuthenticatedUser(): void
    {
        $user = $this->createTestUser('prof_view', 'admin', 'active');
        $_SESSION['auth_user_id'] = $user->id;

        $controller = new UserController(static::$app);
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/users/profile']);

        $response = $controller->profile($request);
        $this->assertSame(200, $response->getStatusCode());

        $content = $response->getContent();
        $this->assertStringContainsString('Profile &amp; Account Settings', $content);
        $this->assertStringContainsString(htmlspecialchars($user->username), $content);
        $this->assertStringContainsString(htmlspecialchars($user->name), $content);
        $this->assertStringContainsString(htmlspecialchars($user->email), $content);
        $this->assertStringContainsString('Active', $content);
    }

    public function testProfileViewDisplaysSuspendedNoticeWhenSuspended(): void
    {
        $user = $this->createTestUser('prof_susp', 'subscriber', 'suspended');
        $_SESSION['auth_user_id'] = $user->id;

        $controller = new UserController(static::$app);
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/users/profile']);

        $response = $controller->profile($request);
        $this->assertSame(200, $response->getStatusCode());

        $content = $response->getContent();
        $this->assertStringContainsString('Account Suspended', $content);
        $this->assertStringContainsString('Suspended', $content);
    }

    public function testProfileUpdateRejectsInvalidCsrfToken(): void
    {
        $user = $this->createTestUser('prof_csrf', 'subscriber', 'active');
        $_SESSION['auth_user_id'] = $user->id;
        $_SESSION['_token'] = 'valid-csrf-token';

        $controller = new UserController(static::$app);
        $request = new Request(
            get: [],
            post: [
                '_token' => 'invalid-token',
                'name'   => 'Hacked Name',
                'email'  => 'hacked@example.com',
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/users/profile/update']
        );

        $response = $controller->updateProfile($request);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('Invalid security token', $_SESSION['flash_error'] ?? '');

        // User data must remain unmodified
        $freshUser = User::find($user->id);
        $this->assertSame($user->name, $freshUser->name);
    }

    public function testProfileUpdateSuccessfullyUpdatesDetails(): void
    {
        $user = $this->createTestUser('prof_upd', 'subscriber', 'active');
        $_SESSION['auth_user_id'] = $user->id;
        $token = bin2hex(random_bytes(16));
        $_SESSION['_token'] = $token;

        $controller = new UserController(static::$app);
        $request = new Request(
            get: [],
            post: [
                '_token'                => $token,
                'name'                  => 'Updated Full Name',
                'email'                 => $user->email, // keep current email to test direct details update
                'bio'                   => 'I am an updated author with a new bio.',
                'password'              => 'NewSecurePass123!',
                'password_confirmation' => 'NewSecurePass123!',
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/users/profile/update']
        );

        $response = $controller->updateProfile($request);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('Profile updated successfully', $_SESSION['flash_success'] ?? '');

        $fresh = User::find($user->id);
        $this->assertSame('Updated Full Name', $fresh->name);
        $this->assertSame($user->email, $fresh->email);
        $this->assertSame('I am an updated author with a new bio.', $fresh->bio);
        $this->assertTrue($fresh->verifyPassword('NewSecurePass123!'));
    }

    public function testProfileUpdateEnforcesEmailUniqueness(): void
    {
        $user1 = $this->createTestUser('prof_uniq1', 'subscriber', 'active');
        $user2 = $this->createTestUser('prof_uniq2', 'subscriber', 'active');

        $_SESSION['auth_user_id'] = $user2->id;
        $token = bin2hex(random_bytes(16));
        $_SESSION['_token'] = $token;

        $controller = new UserController(static::$app);
        $request = new Request(
            get: [],
            post: [
                '_token' => $token,
                'name'   => 'User Two',
                'email'  => $user1->email, // attempt to steal user1's email
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/users/profile/update']
        );

        $response = $controller->updateProfile($request);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('already in use', $_SESSION['flash_error'] ?? '');

        // user2's email must NOT have changed
        $freshUser2 = User::find($user2->id);
        $this->assertSame($user2->email, $freshUser2->email);
    }

    public function testProfileUpdateIgnoresTamperingWithRoleAndStatus(): void
    {
        $user = $this->createTestUser('prof_tamper', 'subscriber', 'active');
        $_SESSION['auth_user_id'] = $user->id;
        $token = bin2hex(random_bytes(16));
        $_SESSION['_token'] = $token;

        $controller = new UserController(static::$app);
        $request = new Request(
            get: [],
            post: [
                '_token'   => $token,
                'name'     => 'Legit Name',
                'email'    => $user->email,
                'role'     => 'admin',     // Tamper attempt
                'role_id'  => 1,           // Tamper attempt
                'status'   => 'suspended', // Tamper attempt
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/users/profile/update']
        );

        $response = $controller->updateProfile($request);
        $this->assertSame(302, $response->getStatusCode());

        $fresh = User::find($user->id);
        $this->assertSame('active', $fresh->status, 'User status must not be modified via profile');
        $this->assertSame('Subscriber', $fresh->getPrimaryRoleName(), 'User role must not be modified via profile');
    }

    public function testProfileUpdateSetsExternalAvatarUrlAndRemovesIt(): void
    {
        $user = $this->createTestUser('prof_av', 'subscriber', 'active');
        $_SESSION['auth_user_id'] = $user->id;
        $token = bin2hex(random_bytes(16));
        $_SESSION['_token'] = $token;

        $controller = new UserController(static::$app);

        // 1. Set external avatar URL
        $avatarUrl = 'https://example.com/avatars/user_pic.jpg';
        $request = new Request(
            get: [],
            post: [
                '_token'            => $token,
                'avatar_action'     => 'set_url',
                'avatar_url'        => $avatarUrl,
                'avatar_url_submit' => '1',
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/users/profile/update']
        );

        $response = $controller->updateProfile($request);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('URL updated successfully', $_SESSION['flash_success'] ?? '');

        $fresh = User::find($user->id);
        $this->assertSame($avatarUrl, $fresh->avatar);
        $this->assertSame($avatarUrl, $fresh->getAvatarUrl());

        // 2. Remove avatar
        $removeRequest = new Request(
            get: [],
            post: [
                '_token'        => $token,
                'avatar_action' => 'remove',
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/users/profile/update']
        );

        $controller->updateProfile($removeRequest);
        $freshAfterRemove = User::find($user->id);
        $this->assertNull($freshAfterRemove->avatar);
        $this->assertNull($freshAfterRemove->getAvatarUrl());
    }

    public function testSuspendedUserCannotCreateOrUpdatePosts(): void
    {
        $suspendedUser = $this->createTestUser('susp_post', 'subscriber', 'suspended');
        $_SESSION['auth_user_id'] = $suspendedUser->id;

        $controller = new PostController(static::$app);

        // 1. Create page should redirect with flash notice
        $createReq = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/posts/new']);
        $createResp = $controller->create($createReq);
        $this->assertSame(302, $createResp->getStatusCode());
        $this->assertStringContainsString('suspended and cannot create', $_SESSION['flash_error'] ?? '');

        // 2. Store should redirect with flash notice
        $storeReq = new Request(
            get: [],
            post: [
                'title'       => 'Suspended Post Attempt',
                'content'     => 'Some content',
                'action_type' => 'publish',
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/posts/new']
        );
        $storeResp = $controller->store($storeReq);
        $this->assertSame(302, $storeResp->getStatusCode());
        $this->assertStringContainsString('suspended and cannot create', $_SESSION['flash_error'] ?? '');

        // Verify post was NOT created in DB
        $post = static::$db->selectOne("SELECT * FROM `posts` WHERE `title` = 'Suspended Post Attempt'");
        $this->assertNull($post);
    }

    public function testSuspendedUserCannotUploadMedia(): void
    {
        $suspendedUser = $this->createTestUser('susp_med', 'subscriber', 'suspended');
        $_SESSION['auth_user_id'] = $suspendedUser->id;

        $controller = new MediaController(static::$app);
        $req = new Request([], [], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/media/upload']);

        $response = $controller->upload($req);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('suspended and cannot upload media', $_SESSION['flash_error'] ?? '');
    }

    public function testSuspendedUserCannotSubmitComments(): void
    {
        // Create an active author and post
        $author = $this->createTestUser('comm_author', 'admin', 'active');
        $postId = static::$db->insert('posts', [
            'title'        => 'Comment Target Post',
            'slug'         => 'comment-target-post-' . bin2hex(random_bytes(3)),
            'content'      => 'Hello world',
            'status'       => 'published',
            'type'         => 'post',
            'author_id'    => $author->id,
            'published_at' => date('Y-m-d H:i:s'),
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $suspendedUser = $this->createTestUser('susp_comm', 'subscriber', 'suspended');
        $_SESSION['auth_user_id'] = $suspendedUser->id;

        $frontend = new FrontendController(static::$app);

        // 1. Submit comment while logged in as suspended user
        $reqLoggedIn = new Request(
            get: [],
            post: [
                'post_id'      => $postId,
                'author_name'  => 'Suspended Person',
                'author_email' => 'other@example.com',
                'content'      => 'A comment that should be blocked',
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/comments/submit']
        );
        $respLoggedIn = $frontend->submitComment($reqLoggedIn);
        $this->assertSame(302, $respLoggedIn->getStatusCode());
        $this->assertStringContainsString('account is suspended', $_SESSION['comment_error'] ?? '');

        // 2. Submit comment logged out, but using suspended user's email
        $_SESSION = [];
        $reqEmail = new Request(
            get: [],
            post: [
                'post_id'      => $postId,
                'author_name'  => 'Anonymous Suspended',
                'author_email' => $suspendedUser->email,
                'content'      => 'Another blocked comment',
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/comments/submit']
        );
        $respEmail = $frontend->submitComment($reqEmail);
        $this->assertSame(302, $respEmail->getStatusCode());
        $this->assertStringContainsString('account is suspended', $_SESSION['comment_error'] ?? '');

        // Verify zero comments exist in DB for this post
        $countRow = static::$db->selectOne("SELECT COUNT(*) as cnt FROM `comments` WHERE `post_id` = ?", [$postId]);
        $this->assertSame(0, (int)($countRow->cnt ?? 0));

        // Cleanup post
        static::$db->execute("DELETE FROM `posts` WHERE `id` = ?", [$postId]);
    }

    public function testBannedUserCannotLoginAndSessionTerminated(): void
    {
        $bannedUser = $this->createTestUser('banned_u', 'subscriber', 'banned');
        $kernel = new Kernel(static::$app);
        $token = bin2hex(random_bytes(16));
        $_SESSION['_token'] = $token;

        // 1. Attempt login with banned credentials
        $loginReq = new Request(
            get: [],
            post: [
                '_token'   => $token,
                'login'    => $bannedUser->username,
                'password' => 'SecretPassword123!',
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/login']
        );
        $resp = $kernel->handle($loginReq);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertStringContainsString('permanently banned', $resp->getContent());
        $this->assertEmpty($_SESSION['auth_user_id'] ?? null);

        // 2. If session existed prior to ban, next request clears session
        $_SESSION['auth_user_id'] = $bannedUser->id;
        $_SESSION['user_id'] = $bannedUser->id;
        $activeReq = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin']);
        $respActive = $kernel->handle($activeReq);

        $this->assertSame(302, $respActive->getStatusCode());
        $this->assertEmpty($_SESSION['auth_user_id'] ?? null);
        $this->assertStringContainsString('permanently banned', $_SESSION['flash_error'] ?? '');
    }

    public function testPostModerationEnforcement(): void
    {
        $subscriber = $this->createTestUser('sub_author', 'subscriber', 'active');
        $moderator  = $this->createTestUser('mod_author', 'moderator', 'active');

        $controller = new PostController(static::$app);

        // 1. Subscriber attempts direct publish: forced to 'pending'
        $_SESSION['auth_user_id'] = $subscriber->id;
        $subReq = new Request(
            get: [],
            post: [
                'title'       => 'Subscriber Submission Test',
                'content'     => 'Article content by subscriber',
                'status'      => 'published', // attempts to bypass
                'action_type' => 'publish',   // attempts to bypass
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/posts/new']
        );
        $controller->store($subReq);

        $subPost = static::$db->selectOne("SELECT * FROM `posts` WHERE `title` = 'Subscriber Submission Test'");
        $this->assertNotNull($subPost);
        $this->assertSame('pending', $subPost->status, 'Subscriber post MUST be forced to pending status');
        $this->assertStringContainsString('awaiting review', $_SESSION['flash_success'] ?? '');

        // 2. Moderator publishes: auto-published
        $_SESSION['auth_user_id'] = $moderator->id;
        $modReq = new Request(
            get: [],
            post: [
                'title'       => 'Moderator Submission Test',
                'content'     => 'Article content by moderator',
                'status'      => 'published',
                'action_type' => 'publish',
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/posts/new']
        );
        $controller->store($modReq);

        $modPost = static::$db->selectOne("SELECT * FROM `posts` WHERE `title` = 'Moderator Submission Test'");
        $this->assertNotNull($modPost);
        $this->assertSame('published', $modPost->status, 'Moderator post must be auto-published');
        $this->assertStringContainsString('published successfully', $_SESSION['flash_success'] ?? '');

        // Cleanup test posts
        static::$db->execute("DELETE FROM `posts` WHERE `id` IN (?, ?)", [$subPost->id, $modPost->id]);
    }
    public function testProfileUpdateWithEmailChangeSendsVerificationAndPreservesOldEmailUntilConfirmed(): void
    {
        $user = $this->createTestUser('prof_emchange', 'subscriber', 'active');
        $oldEmail = $user->email;
        $newEmail = 'pending_change_' . bin2hex(random_bytes(4)) . '@example.com';

        $_SESSION['auth_user_id'] = $user->id;
        $token = bin2hex(random_bytes(16));
        $_SESSION['_token'] = $token;

        $controller = new UserController(static::$app);
        $request = new Request(
            get: [],
            post: [
                '_token' => $token,
                'name'   => $user->name,
                'email'  => $newEmail,
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/users/profile/update']
        );

        $response = $controller->updateProfile($request);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('confirmation link has been sent', $_SESSION['flash_success'] ?? '');

        // Primary email in database must NOT be changed yet
        $fresh = User::find($user->id);
        $this->assertSame($oldEmail, $fresh->email, 'Old email must remain active until new email is confirmed');

        // Verification record exists for the new email
        $verif = static::$db->selectOne("SELECT * FROM `email_verifications` WHERE `user_id` = ?", [$user->id]);
        $this->assertNotNull($verif);
        $this->assertSame(strtolower($newEmail), $verif->email);

        // Verification via service completes the email change
        $verifService = new \FavoriteCMS\Services\EmailVerificationService(static::$db);
        $rawToken = $verifService->createVerificationToken($user, $newEmail);
        $res = $verifService->verifyToken($rawToken);
        $this->assertTrue($res['success']);
        $this->assertTrue($res['isEmailChange']);

        // Now user's email is updated to new address
        $confirmedUser = User::find($user->id);
        $this->assertSame(strtolower($newEmail), strtolower((string)$confirmedUser->email));
        $this->assertTrue($confirmedUser->isEmailVerified());
    }

    public function testRegistrationCreatesUnverifiedAccountAndSendsToken(): void
    {
        $kernel = new Kernel(static::$app);
        $token = bin2hex(random_bytes(16));
        $_SESSION['_token'] = $token;

        $unique = 'new_reg_' . bin2hex(random_bytes(4));
        $email = $unique . '@example.com';

        $req = new Request(
            get: [],
            post: [
                '_token'                => $token,
                'username'              => $unique,
                'email'                 => $email,
                'password'              => 'StrongPassword123!',
                'password_confirmation' => 'StrongPassword123!',
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/register']
        );

        $response = $kernel->handle($req);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/login', $response->getHeader('Location'));
        $this->assertStringContainsString('verification email has been sent', $_SESSION['flash_info'] ?? '');
        $this->assertEmpty($_SESSION['auth_user_id'] ?? null, 'New user should NOT be auto-logged in prior to verification');

        // Verify user in database is unverified
        $userRow = static::$db->selectOne("SELECT * FROM `users` WHERE `username` = ?", [$unique]);
        $this->assertNotNull($userRow);
        $this->assertNull($userRow->email_verified_at, 'email_verified_at must be null for new unverified accounts');
        static::$createdUserIds[] = (int)$userRow->id;

        // Verify verification token was stored
        $tokenRow = static::$db->selectOne("SELECT * FROM `email_verifications` WHERE `user_id` = ?", [$userRow->id]);
        $this->assertNotNull($tokenRow);
        $this->assertSame(strtolower($email), $tokenRow->email);
    }

    public function testUnverifiedUserBlockedFromLogin(): void
    {
        $unverifiedUser = $this->createTestUser('unverif_login', 'subscriber', 'active');
        static::$db->execute("UPDATE `users` SET `email_verified_at` = NULL WHERE `id` = ?", [$unverifiedUser->id]);

        $kernel = new Kernel(static::$app);
        $token = bin2hex(random_bytes(16));
        $_SESSION['_token'] = $token;

        $req = new Request(
            get: [],
            post: [
                '_token'   => $token,
                'login'    => $unverifiedUser->username,
                'password' => 'SecretPassword123!',
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/login']
        );

        $response = $kernel->handle($req);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('not yet verified', $response->getContent());
        $this->assertEmpty($_SESSION['auth_user_id'] ?? null, 'Unverified user must not be logged in');
    }

    public function testEmailVerificationEndpointActivatesUser(): void
    {
        $user = $this->createTestUser('verif_endpoint', 'subscriber', 'active');
        static::$db->execute("UPDATE `users` SET `email_verified_at` = NULL WHERE `id` = ?", [$user->id]);

        $service = new \FavoriteCMS\Services\EmailVerificationService(static::$db);
        $rawToken = $service->createVerificationToken($user, $user->email);

        $kernel = new Kernel(static::$app);
        $req = new Request(
            get: ['token' => $rawToken],
            post: [],
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/verify-email']
        );

        $response = $kernel->handle($req);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/login', $response->getHeader('Location'));
        $this->assertStringContainsString('successfully verified', $_SESSION['flash_success'] ?? '');

        // Check user is now verified
        $fresh = User::find($user->id);
        $this->assertTrue($fresh->isEmailVerified());
    }

    public function testResendVerificationEnforcesCooldownAndAntiEnumeration(): void
    {
        $user = $this->createTestUser('resend_u', 'subscriber', 'active');
        static::$db->execute("UPDATE `users` SET `email_verified_at` = NULL WHERE `id` = ?", [$user->id]);

        $kernel = new Kernel(static::$app);
        $token = bin2hex(random_bytes(16));
        $_SESSION['_token'] = $token;

        // First resend request succeeds
        $req1 = new Request(
            get: [],
            post: [
                '_token' => $token,
                'email'  => $user->email,
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/resend-verification']
        );
        $resp1 = $kernel->handle($req1);
        $this->assertSame(200, $resp1->getStatusCode());
        $this->assertStringContainsString('verification link has been sent', $resp1->getContent());

        // Second immediate resend request triggers cooldown
        $_SESSION['_token'] = $token;
        $req2 = new Request(
            get: [],
            post: [
                '_token' => $token,
                'email'  => $user->email,
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/resend-verification']
        );
        $resp2 = $kernel->handle($req2);
        $this->assertSame(200, $resp2->getStatusCode());
        $this->assertStringContainsString('Please wait', $resp2->getContent());
        $this->assertStringContainsString('seconds', $resp2->getContent());

        // Anti-enumeration test: non-existent email returns neutral message, does not leak existence
        $fakeEmail = 'nonexistent_' . bin2hex(random_bytes(4)) . '@example.com';
        $_SESSION['_token'] = $token;
        $reqFake = new Request(
            get: [],
            post: [
                '_token' => $token,
                'email'  => $fakeEmail,
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/resend-verification']
        );
        $respFake = $kernel->handle($reqFake);
        $this->assertSame(200, $respFake->getStatusCode());
        $this->assertStringContainsString('unverified account', $respFake->getContent());
    }

    public function testActiveUserCanSelfDeleteAndPostsReassignedToAdmin(): void
    {
        // 1. Ensure at least one active administrator exists as fallback
        $admin = $this->createTestUser('fallback_adm', 'admin', 'active');
        $user = $this->createTestUser('self_del_u', 'subscriber', 'active');

        // Create authored post by user
        $postId = static::$db->insert('posts', [
            'author_id'  => $user->id,
            'title'      => 'Authored By Self-Deleting User',
            'slug'       => 'authored-by-self-deleting-user-' . bin2hex(random_bytes(3)),
            'content'    => 'Preserved content test',
            'type'       => 'post',
            'status'     => 'published',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $_SESSION['auth_user_id'] = $user->id;
        $token = bin2hex(random_bytes(16));
        $_SESSION['_token'] = $token;

        $controller = new UserController(static::$app);
        $req = new Request(
            get: [],
            post: [
                '_token'         => $token,
                'password'       => 'SecretPassword123!',
                'confirm_delete' => '1',
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/users/profile/delete-account']
        );

        $response = $controller->deleteOwnAccount($req);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/', $response->getHeader('Location'));
        $this->assertStringContainsString('permanently deleted', $_SESSION['flash_success'] ?? '');
        $this->assertEmpty($_SESSION['auth_user_id'] ?? null, 'Session must be terminated');

        // User record is removed
        $deletedUser = User::find($user->id);
        $this->assertNull($deletedUser);

        // Authored post is PRESERVED and reassigned to fallback active admin
        $postRow = static::$db->selectOne("SELECT * FROM `posts` WHERE `id` = ?", [$postId]);
        $this->assertNotNull($postRow, 'Post must NOT be deleted');
        $fallbackAdmin = User::find((int)$postRow->author_id);
        $this->assertNotNull($fallbackAdmin, 'Post author must be a valid user');
        $this->assertTrue($fallbackAdmin->hasRole('admin') || $fallbackAdmin->hasRole('super-admin'), 'Post must be reassigned to an administrator');
        $this->assertTrue($fallbackAdmin->isActive(), 'Post must be reassigned to an active administrator');

        // Cleanup test post
        static::$db->execute("DELETE FROM `posts` WHERE `id` = ?", [$postId]);
    }

    public function testSuspendedOrBannedUserCannotSelfDelete(): void
    {
        $suspendedUser = $this->createTestUser('susp_del_u', 'subscriber', 'suspended');
        $_SESSION['auth_user_id'] = $suspendedUser->id;
        $token = bin2hex(random_bytes(16));
        $_SESSION['_token'] = $token;

        $controller = new UserController(static::$app);
        $req = new Request(
            get: [],
            post: [
                '_token'         => $token,
                'password'       => 'SecretPassword123!',
                'confirm_delete' => '1',
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/users/profile/delete-account']
        );

        $response = $controller->deleteOwnAccount($req);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('not eligible for self-deletion', $_SESSION['flash_error'] ?? '');

        // Account remains in database
        $stillExists = User::find($suspendedUser->id);
        $this->assertNotNull($stillExists);
    }

    public function testAntiBanRegistrationBypassRejectsRegistrationWithSuspendedOrBannedEmail(): void
    {
        $suspendedUser = $this->createTestUser('susp_reg_check', 'subscriber', 'suspended');
        $bannedUser = $this->createTestUser('ban_reg_check', 'subscriber', 'banned');

        $kernel = new Kernel(static::$app);
        $token = bin2hex(random_bytes(16));

        // 1. Attempt registering with suspended user's email
        $_SESSION['_token'] = $token;
        $reqSusp = new Request(
            get: [],
            post: [
                '_token'                => $token,
                'username'              => 'attempt_susp_' . bin2hex(random_bytes(3)),
                'email'                 => $suspendedUser->email,
                'password'              => 'NewPass12345!',
                'password_confirmation' => 'NewPass12345!',
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/register']
        );
        $respSusp = $kernel->handle($reqSusp);
        $this->assertSame(200, $respSusp->getStatusCode());
        $this->assertStringContainsString('unavailable for registration', $respSusp->getContent());

        // 2. Attempt registering with banned user's email
        $_SESSION['_token'] = $token;
        $reqBan = new Request(
            get: [],
            post: [
                '_token'                => $token,
                'username'              => 'attempt_ban_' . bin2hex(random_bytes(3)),
                'email'                 => $bannedUser->email,
                'password'              => 'NewPass12345!',
                'password_confirmation' => 'NewPass12345!',
            ],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/register']
        );
        $respBan = $kernel->handle($reqBan);
        $this->assertSame(200, $respBan->getStatusCode());
        $this->assertStringContainsString('unavailable for registration', $respBan->getContent());
    }
}

