<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use PHPUnit\Framework\TestCase;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Kernel;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Models\User;
use FavoriteCMS\Models\Role;
use FavoriteCMS\Models\Post;
use FavoriteCMS\Models\Comment;

class RolePermissionMatrixTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;
    protected static Kernel $kernel;
    private static array $createdUserIds = [];
    private static array $createdPostIds = [];
    private static array $createdCommentIds = [];

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db = static::$app->make(Database::class);
        static::$kernel = new Kernel(static::$app);

        // Ensure roles exist
        static::$db->execute("INSERT IGNORE INTO `roles` (`id`, `name`, `slug`, `description`, `is_system`) VALUES (1, 'Super Admin', 'super-admin', 'Full system access', 1)");
        static::$db->execute("INSERT IGNORE INTO `roles` (`id`, `name`, `slug`, `description`, `is_system`) VALUES (2, 'Admin', 'admin', 'Administrative access', 1)");
        static::$db->execute("INSERT IGNORE INTO `roles` (`id`, `name`, `slug`, `description`, `is_system`) VALUES (3, 'Editor', 'editor', 'Can publish and manage posts and pages', 1)");
        static::$db->execute("INSERT IGNORE INTO `roles` (`id`, `name`, `slug`, `description`, `is_system`) VALUES (4, 'Author', 'author', 'Can publish and manage own posts', 1)");
        static::$db->execute("INSERT IGNORE INTO `roles` (`id`, `name`, `slug`, `description`, `is_system`) VALUES (5, 'Subscriber', 'subscriber', 'Basic subscriber role', 1)");
        static::$db->execute("INSERT IGNORE INTO `roles` (`id`, `name`, `slug`, `description`, `is_system`) VALUES (6, 'Moderator', 'moderator', 'Can moderate comments and content', 1)");

        // Ensure user 1 exists with super-admin role
        $u1 = static::$db->selectOne("SELECT id FROM `users` WHERE `id` = 1");
        $now = date('Y-m-d H:i:s');
        if (!$u1) {
            static::$db->execute("INSERT INTO `users` (`id`, `username`, `name`, `email`, `password`, `status`, `email_verified_at`, `created_at`, `updated_at`) VALUES (1, 'admin', 'Administrator', 'admin@example.com', ?, 'active', ?, ?, ?)", [
                password_hash('AdminPassword123!', PASSWORD_DEFAULT),
                $now,
                $now,
                $now,
            ]);
        }
        static::$db->execute("INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`) VALUES (1, 1)");
    }

    public static function tearDownAfterClass(): void
    {
        if (!empty(static::$createdCommentIds)) {
            $in = implode(',', array_map('intval', static::$createdCommentIds));
            static::$db->execute("DELETE FROM `comments` WHERE `id` IN ({$in})");
        }
        if (!empty(static::$createdPostIds)) {
            $in = implode(',', array_map('intval', static::$createdPostIds));
            static::$db->execute("DELETE FROM `post_taxonomies` WHERE `post_id` IN ({$in})");
            static::$db->execute("DELETE FROM `seo_meta` WHERE `object_type` = 'post' AND `object_id` IN ({$in})");
            static::$db->execute("DELETE FROM `posts` WHERE `id` IN ({$in})");
        }
        if (!empty(static::$createdUserIds)) {
            $in = implode(',', array_map('intval', static::$createdUserIds));
            static::$db->execute("DELETE FROM `user_roles` WHERE `user_id` IN ({$in})");
            static::$db->execute("DELETE FROM `users` WHERE `id` IN ({$in})");
        }
    }

    protected function setUp(): void
    {
        static::$app->setInstalled(true);
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        $_SESSION = [
            '_token' => 'test-token-12345',
        ];
        $_POST = [];
        $_GET = [];
    }

    protected function createTestUser(string $roleSlug, string $prefix = 'user'): User
    {
        $unique = $prefix . '_' . bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');
        $hash = password_hash('TestPassword123!', PASSWORD_DEFAULT);

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

        static::$createdUserIds[] = $userId;

        $role = static::$db->selectOne("SELECT id FROM `roles` WHERE `slug` = ? LIMIT 1", [$roleSlug]);
        if ($role) {
            static::$db->execute("INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [$userId, $role->id]);
        }

        return User::find($userId);
    }

    protected function createTestPost(int $authorId, string $status = 'pending'): Post
    {
        $unique = 'post_' . bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        $postId = static::$db->insert('posts', [
            'title'        => 'Test Post ' . $unique,
            'slug'         => $unique,
            'content'      => '<p>Original Content for ' . $unique . '</p>',
            'excerpt'      => 'Test excerpt',
            'status'       => $status,
            'type'         => 'post',
            'author_id'    => $authorId,
            'created_at'   => $now,
            'updated_at'   => $now,
            'published_at' => $status === 'published' ? $now : null,
        ]);

        static::$createdPostIds[] = $postId;
        return Post::find($postId);
    }

    protected function createTestComment(int $postId, int $userId, string $status = 'pending'): Comment
    {
        $now = date('Y-m-d H:i:s');
        $commentId = static::$db->insert('comments', [
            'post_id'         => $postId,
            'user_id'         => $userId,
            'author_name'     => 'Commenter',
            'author_email'    => 'commenter@example.com',
            'content'         => 'This is a test comment.',
            'status'          => $status,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        static::$createdCommentIds[] = $commentId;
        return Comment::find($commentId);
    }

    // =========================================================================
    // SCENARIOS A - AC: FINAL ROLE PERMISSION MATRIX SPECIFICATION
    // =========================================================================

    public function testScenarioA_SuperAdminPermissionsAndActions(): void
    {
        $superAdmin = User::find(1);
        $this->assertNotNull($superAdmin);
        $this->assertTrue($superAdmin->isSuperAdmin());
        $this->assertTrue($superAdmin->canCreatePosts());
        $this->assertTrue($superAdmin->canUpdatePosts());
        $this->assertTrue($superAdmin->canEditOwnPosts());
        $this->assertTrue($superAdmin->canEditOtherPosts());
        $this->assertTrue($superAdmin->canDeleteOwnPosts());
        $this->assertTrue($superAdmin->canDeleteOtherPosts());
        $this->assertTrue($superAdmin->canRestoreOwnPosts());
        $this->assertTrue($superAdmin->canPublishPost());
        $this->assertTrue($superAdmin->canModeratePosts());
        $this->assertTrue($superAdmin->canModerateComments());
        $this->assertTrue($superAdmin->canEditOwnContentSeo());
        $this->assertTrue($superAdmin->canEditOtherContentSeo());
        $this->assertTrue($superAdmin->canUploadMedia());
        $this->assertTrue($superAdmin->canManageMedia());
        $this->assertTrue($superAdmin->canManageUsers());
        $this->assertTrue($superAdmin->canManagePages());
        $this->assertTrue($superAdmin->canManageTaxonomies());
        $this->assertTrue($superAdmin->canManageMenus());
        $this->assertTrue($superAdmin->canManagePlugins());
    }

    public function testScenarioB_AdminPermissionsAndActions(): void
    {
        $admin = $this->createTestUser('admin', 'scen_b_adm');
        $this->assertFalse($admin->isSuperAdmin());
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue($admin->canCreatePosts());
        $this->assertTrue($admin->canUpdatePosts());
        $this->assertTrue($admin->canEditOwnPosts());
        $this->assertTrue($admin->canEditOtherPosts());
        $this->assertTrue($admin->canDeleteOwnPosts());
        $this->assertTrue($admin->canDeleteOtherPosts());
        $this->assertTrue($admin->canRestoreOwnPosts());
        $this->assertTrue($admin->canPublishPost());
        $this->assertTrue($admin->canModeratePosts());
        $this->assertTrue($admin->canModerateComments());
        $this->assertTrue($admin->canEditOwnContentSeo());
        $this->assertTrue($admin->canEditOtherContentSeo());
        $this->assertTrue($admin->canUploadMedia());
        $this->assertTrue($admin->canManageMedia());
        $this->assertTrue($admin->canManageUsers());
        $this->assertTrue($admin->canManagePages());
        $this->assertTrue($admin->canManageTaxonomies());
        $this->assertTrue($admin->canManageMenus());
        $this->assertTrue($admin->canManagePlugins());
    }

    public function testScenarioC_EditorPermissionsAndActions(): void
    {
        $editor = $this->createTestUser('editor', 'scen_c_ed');
        $this->assertTrue($editor->hasRole('editor'));
        $this->assertTrue($editor->canCreatePosts());
        $this->assertTrue($editor->canUpdatePosts());
        $this->assertTrue($editor->canEditOwnPosts());
        $this->assertTrue($editor->canEditOtherPosts());
        $this->assertTrue($editor->canDeleteOwnPosts());
        $this->assertFalse($editor->canDeleteOtherPosts());
        $this->assertTrue($editor->canRestoreOwnPosts());
        $this->assertTrue($editor->canPublishPost());
        $this->assertFalse($editor->canModeratePosts());
        $this->assertTrue($editor->canModerateComments());
        $this->assertTrue($editor->canEditOwnContentSeo());
        $this->assertTrue($editor->canEditOtherContentSeo());
        $this->assertTrue($editor->canUploadMedia());
        $this->assertTrue($editor->canManageMedia());
        $this->assertTrue($editor->canManagePages());
        $this->assertTrue($editor->canManageTaxonomies());
        $this->assertTrue($editor->canManageMenus());
        $this->assertFalse($editor->canManageUsers());
        $this->assertFalse($editor->canManagePlugins());
    }

    public function testScenarioD_ModeratorPermissionsAndActions(): void
    {
        $moderator = $this->createTestUser('moderator', 'scen_d_mod');
        $this->assertTrue($moderator->hasRole('moderator'));
        $this->assertTrue($moderator->canCreatePosts());
        $this->assertTrue($moderator->canUpdatePosts());
        $this->assertTrue($moderator->canEditOwnPosts());
        $this->assertTrue($moderator->canEditOtherPosts());
        $this->assertTrue($moderator->canDeleteOwnPosts());
        $this->assertFalse($moderator->canDeleteOtherPosts());
        $this->assertTrue($moderator->canRestoreOwnPosts());
        $this->assertTrue($moderator->canPublishPost());
        $this->assertTrue($moderator->canModeratePosts());
        $this->assertTrue($moderator->canModerateComments());
        $this->assertTrue($moderator->canEditOwnContentSeo());
        $this->assertFalse($moderator->canEditOtherContentSeo());
        $this->assertTrue($moderator->canUploadMedia());
        $this->assertFalse($moderator->canManageMedia());
        $this->assertFalse($moderator->canManagePages());
        $this->assertFalse($moderator->canManageTaxonomies());
        $this->assertFalse($moderator->canManageMenus());
        $this->assertFalse($moderator->canManageUsers());
        $this->assertFalse($moderator->canManagePlugins());
    }

    public function testScenarioE_AuthorPermissionsAndActions(): void
    {
        $author = $this->createTestUser('author', 'scen_e_aut');
        $this->assertTrue($author->hasRole('author'));
        $this->assertTrue($author->canCreatePosts());
        $this->assertTrue($author->canUpdatePosts());
        $this->assertTrue($author->canEditOwnPosts());
        $this->assertFalse($author->canEditOtherPosts());
        $this->assertTrue($author->canDeleteOwnPosts());
        $this->assertFalse($author->canDeleteOtherPosts());
        $this->assertTrue($author->canRestoreOwnPosts());
        $this->assertFalse($author->canPublishPost());
        $this->assertFalse($author->canModeratePosts());
        $this->assertFalse($author->canModerateComments());
        $this->assertTrue($author->canEditOwnContentSeo());
        $this->assertFalse($author->canEditOtherContentSeo());
        $this->assertTrue($author->canUploadMedia());
        $this->assertFalse($author->canManageMedia());
        $this->assertFalse($author->canManagePages());
        $this->assertFalse($author->canManageTaxonomies());
        $this->assertFalse($author->canManageMenus());
        $this->assertFalse($author->canManageUsers());
        $this->assertFalse($author->canManagePlugins());
    }

    public function testScenarioF_SubscriberPermissionsAndActions(): void
    {
        $subscriber = $this->createTestUser('subscriber', 'scen_f_sub');
        $this->assertTrue($subscriber->hasRole('subscriber'));
        $this->assertFalse($subscriber->canCreatePosts());
        $this->assertFalse($subscriber->canUpdatePosts());
        $this->assertFalse($subscriber->canEditOwnPosts());
        $this->assertFalse($subscriber->canEditOtherPosts());
        $this->assertFalse($subscriber->canDeleteOwnPosts());
        $this->assertFalse($subscriber->canDeleteOtherPosts());
        $this->assertFalse($subscriber->canRestoreOwnPosts());
        $this->assertFalse($subscriber->canPublishPost());
        $this->assertFalse($subscriber->canModeratePosts());
        $this->assertFalse($subscriber->canModerateComments());
        $this->assertFalse($subscriber->canEditOwnContentSeo());
        $this->assertFalse($subscriber->canEditOtherContentSeo());
        $this->assertFalse($subscriber->canUploadMedia());
        $this->assertFalse($subscriber->canManageMedia());
        $this->assertFalse($subscriber->canManagePages());
        $this->assertFalse($subscriber->canManageTaxonomies());
        $this->assertFalse($subscriber->canManageMenus());
        $this->assertFalse($subscriber->canManageUsers());
        $this->assertFalse($subscriber->canManagePlugins());
        $this->assertTrue($subscriber->hasPermission('view_admin'));
    }

    public function testScenarioG_SuperAdminCreatesPost(): void
    {
        $superAdmin = User::find(1);
        $_SESSION['auth_user_id'] = $superAdmin->id;
        $title = 'Super Admin Created ' . bin2hex(random_bytes(4));
        $req = Request::create('POST', '/admin/posts/store', [
            '_token'      => 'test-token-12345',
            'title'       => $title,
            'content'     => '<p>Super admin content</p>',
            'status'      => 'published',
            'action_type' => 'publish',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $row = static::$db->selectOne("SELECT * FROM `posts` WHERE `title` = ?", [$title]);
        $this->assertNotNull($row);
        static::$createdPostIds[] = (int)$row->id;
        $this->assertEquals('published', $row->status);
    }

    public function testScenarioH_AdminCreatesPost(): void
    {
        $admin = $this->createTestUser('admin', 'scen_h_adm');
        $_SESSION['auth_user_id'] = $admin->id;
        $title = 'Admin Created ' . bin2hex(random_bytes(4));
        $req = Request::create('POST', '/admin/posts/store', [
            '_token'      => 'test-token-12345',
            'title'       => $title,
            'content'     => '<p>Admin content</p>',
            'status'      => 'published',
            'action_type' => 'publish',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $row = static::$db->selectOne("SELECT * FROM `posts` WHERE `title` = ?", [$title]);
        $this->assertNotNull($row);
        static::$createdPostIds[] = (int)$row->id;
        $this->assertEquals('published', $row->status);
    }

    public function testScenarioI_EditorCreatesPost(): void
    {
        $editor = $this->createTestUser('editor', 'scen_i_ed');
        $_SESSION['auth_user_id'] = $editor->id;
        $title = 'Editor Created ' . bin2hex(random_bytes(4));
        $req = Request::create('POST', '/admin/posts/store', [
            '_token'      => 'test-token-12345',
            'title'       => $title,
            'content'     => '<p>Editor content</p>',
            'status'      => 'published',
            'action_type' => 'publish',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $row = static::$db->selectOne("SELECT * FROM `posts` WHERE `title` = ?", [$title]);
        $this->assertNotNull($row);
        static::$createdPostIds[] = (int)$row->id;
        $this->assertEquals('published', $row->status);
    }

    public function testScenarioJ_ModeratorCreatesPost(): void
    {
        $moderator = $this->createTestUser('moderator', 'scen_j_mod');
        $_SESSION['auth_user_id'] = $moderator->id;
        $title = 'Moderator Created ' . bin2hex(random_bytes(4));
        $req = Request::create('POST', '/admin/posts/store', [
            '_token'      => 'test-token-12345',
            'title'       => $title,
            'content'     => '<p>Moderator content</p>',
            'status'      => 'published',
            'action_type' => 'publish',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $row = static::$db->selectOne("SELECT * FROM `posts` WHERE `title` = ?", [$title]);
        $this->assertNotNull($row);
        static::$createdPostIds[] = (int)$row->id;
        $this->assertEquals('published', $row->status);
    }

    public function testScenarioK_AuthorCreatesPost(): void
    {
        $author = $this->createTestUser('author', 'scen_k_aut');
        $_SESSION['auth_user_id'] = $author->id;
        $title = 'Author Created ' . bin2hex(random_bytes(4));
        $req = Request::create('POST', '/admin/posts/store', [
            '_token'      => 'test-token-12345',
            'title'       => $title,
            'content'     => '<p>Author content</p>',
            'status'      => 'published',
            'action_type' => 'publish',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $row = static::$db->selectOne("SELECT * FROM `posts` WHERE `title` = ?", [$title]);
        $this->assertNotNull($row);
        static::$createdPostIds[] = (int)$row->id;
        // Author posts require moderation, forced to pending
        $this->assertEquals('pending', $row->status);
    }

    public function testScenarioL_SubscriberBlockedFromCreatingPost(): void
    {
        $subscriber = $this->createTestUser('subscriber', 'scen_l_sub');
        $_SESSION['auth_user_id'] = $subscriber->id;
        $title = 'Subscriber Post ' . bin2hex(random_bytes(4));

        // GET /admin/posts/new
        $req = Request::create('GET', '/admin/posts/new');
        $resp = static::$kernel->handle($req);
        $this->assertEquals(403, $resp->getStatusCode());

        // POST /admin/posts/store
        $req = Request::create('POST', '/admin/posts/store', [
            '_token' => 'test-token-12345',
            'title'  => $title,
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(403, $resp->getStatusCode());

        $row = static::$db->selectOne("SELECT id FROM `posts` WHERE `title` = ?", [$title]);
        $this->assertNull($row);
    }

    public function testScenarioM_SuperAdminEditsOtherUsersPosts(): void
    {
        $author = $this->createTestUser('author', 'scen_m_aut');
        $post = $this->createTestPost($author->id, 'published');
        $superAdmin = User::find(1);

        $this->assertTrue($superAdmin->canEditPost($post));

        $_SESSION['auth_user_id'] = $superAdmin->id;
        $newTitle = 'Title Edited by Super Admin ' . bin2hex(random_bytes(3));
        $req = Request::create('POST', '/admin/posts/update?id=' . $post->id, [
            'id'      => $post->id,
            '_token'  => 'test-token-12345',
            'title'   => $newTitle,
            'content' => '<p>Updated content</p>',
            'status'  => 'published',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals($newTitle, Post::find($post->id)->title);
    }

    public function testScenarioN_AdminEditsOtherUsersPosts(): void
    {
        $author = $this->createTestUser('author', 'scen_n_aut');
        $post = $this->createTestPost($author->id, 'published');
        $admin = $this->createTestUser('admin', 'scen_n_adm');

        $this->assertTrue($admin->canEditPost($post));

        $_SESSION['auth_user_id'] = $admin->id;
        $newTitle = 'Title Edited by Admin ' . bin2hex(random_bytes(3));
        $req = Request::create('POST', '/admin/posts/update?id=' . $post->id, [
            'id'      => $post->id,
            '_token'  => 'test-token-12345',
            'title'   => $newTitle,
            'content' => '<p>Updated content</p>',
            'status'  => 'published',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals($newTitle, Post::find($post->id)->title);
    }

    public function testScenarioO_EditorEditsOtherUsersPosts(): void
    {
        $author = $this->createTestUser('author', 'scen_o_aut');
        $post = $this->createTestPost($author->id, 'published');
        $editor = $this->createTestUser('editor', 'scen_o_ed');

        $this->assertTrue($editor->canEditPost($post));

        $_SESSION['auth_user_id'] = $editor->id;
        $newTitle = 'Title Edited by Editor ' . bin2hex(random_bytes(3));
        $req = Request::create('POST', '/admin/posts/update?id=' . $post->id, [
            'id'      => $post->id,
            '_token'  => 'test-token-12345',
            'title'   => $newTitle,
            'content' => '<p>Updated content</p>',
            'status'  => 'published',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals($newTitle, Post::find($post->id)->title);
    }

    public function testScenarioP_ModeratorEditsOtherUsersPosts(): void
    {
        $author = $this->createTestUser('author', 'scen_p_aut');
        $post = $this->createTestPost($author->id, 'published');
        $moderator = $this->createTestUser('moderator', 'scen_p_mod');

        $this->assertTrue($moderator->canEditPost($post));

        $_SESSION['auth_user_id'] = $moderator->id;
        $newTitle = 'Title Edited by Moderator ' . bin2hex(random_bytes(3));
        $req = Request::create('POST', '/admin/posts/update?id=' . $post->id, [
            'id'      => $post->id,
            '_token'  => 'test-token-12345',
            'title'   => $newTitle,
            'content' => '<p>Updated content</p>',
            'status'  => 'published',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals($newTitle, Post::find($post->id)->title);
    }

    public function testScenarioQ_AuthorBlockedFromEditingOtherUsersPosts(): void
    {
        $author1 = $this->createTestUser('author', 'scen_q_aut1');
        $author2 = $this->createTestUser('author', 'scen_q_aut2');
        $post1 = $this->createTestPost($author1->id, 'published');

        $this->assertFalse($author2->canEditPost($post1));

        $_SESSION['auth_user_id'] = $author2->id;

        // GET edit form
        $req = Request::create('GET', '/admin/posts/edit?id=' . $post1->id, ['id' => $post1->id]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());

        // POST update
        $req = Request::create('POST', '/admin/posts/update?id=' . $post1->id, [
            'id'     => $post1->id,
            '_token' => 'test-token-12345',
            'title'  => 'Hacked by Author 2',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());

        $this->assertNotEquals('Hacked by Author 2', Post::find($post1->id)->title);
    }

    public function testScenarioR_SubscriberBlockedFromEditingAnyPosts(): void
    {
        $author = $this->createTestUser('author', 'scen_r_aut');
        $post = $this->createTestPost($author->id, 'published');
        $subscriber = $this->createTestUser('subscriber', 'scen_r_sub');

        $this->assertFalse($subscriber->canEditPost($post));

        $_SESSION['auth_user_id'] = $subscriber->id;

        // GET edit
        $req = Request::create('GET', '/admin/posts/edit?id=' . $post->id, ['id' => $post->id]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(403, $resp->getStatusCode());

        // POST update
        $req = Request::create('POST', '/admin/posts/update?id=' . $post->id, [
            'id'     => $post->id,
            '_token' => 'test-token-12345',
            'title'  => 'Hacked by Subscriber',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(403, $resp->getStatusCode());

        $this->assertNotEquals('Hacked by Subscriber', Post::find($post->id)->title);
    }

    public function testScenarioS_SuperAdminTrashesDeletesRestoresOtherUsersPosts(): void
    {
        $author = $this->createTestUser('author', 'scen_s_aut');
        $post = $this->createTestPost($author->id, 'published');
        $superAdmin = User::find(1);

        $this->assertTrue($superAdmin->canDeletePost($post));
        $this->assertTrue($superAdmin->canRestorePost($post));

        $_SESSION['auth_user_id'] = $superAdmin->id;

        // Trash
        $req = Request::create('POST', '/admin/posts/trash?id=' . $post->id, ['id' => $post->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals('trash', Post::find($post->id)->status);

        // Restore
        $req = Request::create('POST', '/admin/posts/restore?id=' . $post->id, ['id' => $post->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals('draft', Post::find($post->id)->status);

        // Delete
        $req = Request::create('POST', '/admin/posts/delete?id=' . $post->id, ['id' => $post->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertNull(Post::find($post->id));
    }

    public function testScenarioT_AdminTrashesDeletesRestoresOtherUsersPosts(): void
    {
        $author = $this->createTestUser('author', 'scen_t_aut');
        $post = $this->createTestPost($author->id, 'published');
        $admin = $this->createTestUser('admin', 'scen_t_adm');

        $this->assertTrue($admin->canDeletePost($post));
        $this->assertTrue($admin->canRestorePost($post));

        $_SESSION['auth_user_id'] = $admin->id;

        // Trash
        $req = Request::create('POST', '/admin/posts/trash?id=' . $post->id, ['id' => $post->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals('trash', Post::find($post->id)->status);

        // Restore
        $req = Request::create('POST', '/admin/posts/restore?id=' . $post->id, ['id' => $post->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals('draft', Post::find($post->id)->status);

        // Delete
        $req = Request::create('POST', '/admin/posts/delete?id=' . $post->id, ['id' => $post->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertNull(Post::find($post->id));
    }

    public function testScenarioU_EditorBlockedFromTrashingDeletingRestoringOtherUsersPosts(): void
    {
        $author = $this->createTestUser('author', 'scen_u_aut');
        $editor = $this->createTestUser('editor', 'scen_u_ed');
        $authorPost = $this->createTestPost($author->id, 'published');
        $editorPost = $this->createTestPost($editor->id, 'published');

        // Editor cannot delete other's post
        $this->assertFalse($editor->canDeletePost($authorPost));
        $this->assertFalse($editor->canRestorePost($authorPost));
        // Editor CAN delete own post
        $this->assertTrue($editor->canDeletePost($editorPost));
        $this->assertTrue($editor->canRestorePost($editorPost));

        $_SESSION['auth_user_id'] = $editor->id;

        // Trash other user's post -> blocked
        $req = Request::create('POST', '/admin/posts/trash?id=' . $authorPost->id, ['id' => $authorPost->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals('published', Post::find($authorPost->id)->status);

        // Put in trash, restore -> blocked
        static::$db->execute("UPDATE `posts` SET `status` = 'trash' WHERE `id` = ?", [$authorPost->id]);
        $req = Request::create('POST', '/admin/posts/restore?id=' . $authorPost->id, ['id' => $authorPost->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals('trash', Post::find($authorPost->id)->status);

        // Delete other user's post -> blocked
        $req = Request::create('POST', '/admin/posts/delete?id=' . $authorPost->id, ['id' => $authorPost->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertNotNull(Post::find($authorPost->id));

        // Editor trashing own post -> allowed
        $req = Request::create('POST', '/admin/posts/trash?id=' . $editorPost->id, ['id' => $editorPost->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals('trash', Post::find($editorPost->id)->status);
    }

    public function testScenarioV_ModeratorBlockedFromTrashingDeletingRestoringOtherUsersPosts(): void
    {
        $author = $this->createTestUser('author', 'scen_v_aut');
        $moderator = $this->createTestUser('moderator', 'scen_v_mod');
        $authorPost = $this->createTestPost($author->id, 'published');
        $modPost = $this->createTestPost($moderator->id, 'published');

        $this->assertFalse($moderator->canDeletePost($authorPost));
        $this->assertFalse($moderator->canRestorePost($authorPost));
        $this->assertTrue($moderator->canDeletePost($modPost));
        $this->assertTrue($moderator->canRestorePost($modPost));

        $_SESSION['auth_user_id'] = $moderator->id;

        // Trash other user's post -> blocked
        $req = Request::create('POST', '/admin/posts/trash?id=' . $authorPost->id, ['id' => $authorPost->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals('published', Post::find($authorPost->id)->status);

        // Put in trash, restore -> blocked
        static::$db->execute("UPDATE `posts` SET `status` = 'trash' WHERE `id` = ?", [$authorPost->id]);
        $req = Request::create('POST', '/admin/posts/restore?id=' . $authorPost->id, ['id' => $authorPost->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals('trash', Post::find($authorPost->id)->status);

        // Delete other user's post -> blocked
        $req = Request::create('POST', '/admin/posts/delete?id=' . $authorPost->id, ['id' => $authorPost->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertNotNull(Post::find($authorPost->id));

        // Moderator trashing own post -> allowed
        $req = Request::create('POST', '/admin/posts/trash?id=' . $modPost->id, ['id' => $modPost->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals('trash', Post::find($modPost->id)->status);
    }

    public function testScenarioW_AuthorBlockedFromTrashingDeletingRestoringOtherUsersPosts(): void
    {
        $author1 = $this->createTestUser('author', 'scen_w_aut1');
        $author2 = $this->createTestUser('author', 'scen_w_aut2');
        $post1 = $this->createTestPost($author1->id, 'published');
        $post2 = $this->createTestPost($author2->id, 'published');

        $this->assertFalse($author2->canDeletePost($post1));
        $this->assertFalse($author2->canRestorePost($post1));
        $this->assertTrue($author2->canDeletePost($post2));
        $this->assertTrue($author2->canRestorePost($post2));

        $_SESSION['auth_user_id'] = $author2->id;

        // Trash other user's post -> blocked
        $req = Request::create('POST', '/admin/posts/trash?id=' . $post1->id, ['id' => $post1->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals('published', Post::find($post1->id)->status);

        // Put in trash, restore other user's post -> blocked
        static::$db->execute("UPDATE `posts` SET `status` = 'trash' WHERE `id` = ?", [$post1->id]);
        $req = Request::create('POST', '/admin/posts/restore?id=' . $post1->id, ['id' => $post1->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals('trash', Post::find($post1->id)->status);

        // Delete other user's post -> blocked
        $req = Request::create('POST', '/admin/posts/delete?id=' . $post1->id, ['id' => $post1->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertNotNull(Post::find($post1->id));

        // Author trashing own post -> allowed
        $req = Request::create('POST', '/admin/posts/trash?id=' . $post2->id, ['id' => $post2->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals('trash', Post::find($post2->id)->status);
    }

    public function testScenarioX_SubscriberBlockedFromTrashingDeletingRestoringAnyPosts(): void
    {
        $author = $this->createTestUser('author', 'scen_x_aut');
        $post = $this->createTestPost($author->id, 'published');
        $subscriber = $this->createTestUser('subscriber', 'scen_x_sub');

        $this->assertFalse($subscriber->canDeletePost($post));
        $this->assertFalse($subscriber->canRestorePost($post));

        $_SESSION['auth_user_id'] = $subscriber->id;

        // Trash -> blocked
        $req = Request::create('POST', '/admin/posts/trash?id=' . $post->id, ['id' => $post->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertContains($resp->getStatusCode(), [302, 403]);
        $this->assertEquals('published', Post::find($post->id)->status);

        // Restore -> blocked
        static::$db->execute("UPDATE `posts` SET `status` = 'trash' WHERE `id` = ?", [$post->id]);
        $req = Request::create('POST', '/admin/posts/restore?id=' . $post->id, ['id' => $post->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertContains($resp->getStatusCode(), [302, 403]);
        $this->assertEquals('trash', Post::find($post->id)->status);

        // Delete -> blocked
        $req = Request::create('POST', '/admin/posts/delete?id=' . $post->id, ['id' => $post->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertContains($resp->getStatusCode(), [302, 403]);
        $this->assertNotNull(Post::find($post->id));
    }

    public function testScenarioY_BulkOperationsOwnershipAwareCheck(): void
    {
        // 1. Editor bulk trash: mix of own post and author post
        $editor = $this->createTestUser('editor', 'scen_y_ed');
        $author = $this->createTestUser('author', 'scen_y_aut');
        $edPost = $this->createTestPost($editor->id, 'published');
        $autPost = $this->createTestPost($author->id, 'published');

        $_SESSION['auth_user_id'] = $editor->id;
        $req = Request::create('POST', '/admin/posts/bulk', [
            'bulk_action' => 'trash',
            'ids'         => [$edPost->id, $autPost->id],
            '_token'      => 'test-token-12345',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());

        // Editor's own post trashed
        $this->assertEquals('trash', Post::find($edPost->id)->status);
        // Author's post was skipped and remains published
        $this->assertEquals('published', Post::find($autPost->id)->status);

        // 2. Moderator bulk restore: mix of own post and author post
        $moderator = $this->createTestUser('moderator', 'scen_y_mod');
        $modPost = $this->createTestPost($moderator->id, 'trash');
        $autPost2 = $this->createTestPost($author->id, 'trash');

        $_SESSION['auth_user_id'] = $moderator->id;
        $req = Request::create('POST', '/admin/posts/bulk', [
            'bulk_action' => 'restore',
            'ids'         => [$modPost->id, $autPost2->id],
            '_token'      => 'test-token-12345',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());

        // Moderator's own post restored to draft
        $this->assertEquals('draft', Post::find($modPost->id)->status);
        // Author's post was skipped and remains trash
        $this->assertEquals('trash', Post::find($autPost2->id)->status);
    }

    public function testScenarioZ_ModeratorEditingAnotherUserPostCannotAlterOrOverwriteSeoMetadata(): void
    {
        $author = $this->createTestUser('author', 'scen_z_aut');
        $moderator = $this->createTestUser('moderator', 'scen_z_mod');
        $post = $this->createTestPost($author->id, 'published');

        // Author sets SEO metadata
        $post->saveSeoMeta([
            'meta_title'       => 'Original Author Meta Title',
            'meta_description' => 'Original Author Meta Description',
            'og_title'         => 'Original Author OG Title',
            'og_description'   => 'Original Author OG Description',
            'canonical_url'    => 'https://example.com/original',
            'robots'           => 'index,follow',
        ]);

        $this->assertFalse($moderator->canEditContentSeo($post));
        $this->assertTrue($moderator->canEditPost($post));

        $_SESSION['auth_user_id'] = $moderator->id;

        // Moderator updates post title & content and attempts to inject changed SEO metadata
        $updatedTitle = 'Moderator Revised Title ' . bin2hex(random_bytes(3));
        $req = Request::create('POST', '/admin/posts/update?id=' . $post->id, [
            'id'               => $post->id,
            '_token'           => 'test-token-12345',
            'title'            => $updatedTitle,
            'content'          => '<p>Revised content</p>',
            'status'           => 'published',
            'meta_title'       => 'Malicious Moderator Hijacked SEO Title',
            'meta_description' => 'Malicious Moderator Hijacked SEO Description',
            'og_title'         => 'Malicious Moderator OG Title',
            'og_description'   => 'Malicious Moderator OG Description',
            'canonical_url'    => 'https://example.com/hijacked',
            'robots'           => 'noindex,nofollow',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());

        // Content updated
        $fresh = Post::find($post->id);
        $this->assertEquals($updatedTitle, $fresh->title);

        // SEO metadata MUST NOT be altered or overwritten!
        $seo = $fresh->getSeoMeta();
        $this->assertEquals('Original Author Meta Title', $seo->meta_title);
        $this->assertEquals('Original Author Meta Description', $seo->meta_description);
        $this->assertEquals('Original Author OG Title', $seo->og_title);
        $this->assertEquals('Original Author OG Description', $seo->og_description);
        $this->assertEquals('https://example.com/original', $seo->canonical_url);
        $this->assertEquals('index,follow', $seo->robots);

        // In contrast, Moderator editing their OWN post CAN update SEO metadata
        $modPost = $this->createTestPost($moderator->id, 'published');
        $this->assertTrue($moderator->canEditContentSeo($modPost));

        $req = Request::create('POST', '/admin/posts/update?id=' . $modPost->id, [
            'id'               => $modPost->id,
            '_token'           => 'test-token-12345',
            'title'            => 'Moderator Own Post',
            'content'          => '<p>Content</p>',
            'status'           => 'published',
            'meta_title'       => 'Moderator Own SEO Title',
            'meta_description' => 'Moderator Own SEO Description',
        ]);
        static::$kernel->handle($req);
        $freshMod = Post::find($modPost->id);
        $seoMod = $freshMod->getSeoMeta();
        $this->assertEquals('Moderator Own SEO Title', $seoMod->meta_title);
    }

    public function testScenarioAA_MediaUploadPermissions(): void
    {
        $superAdmin = User::find(1);
        $admin = $this->createTestUser('admin', 'scen_aa_adm');
        $editor = $this->createTestUser('editor', 'scen_aa_ed');
        $moderator = $this->createTestUser('moderator', 'scen_aa_mod');
        $author = $this->createTestUser('author', 'scen_aa_aut');
        $subscriber = $this->createTestUser('subscriber', 'scen_aa_sub');

        // Capability checks
        $this->assertTrue($superAdmin->canUploadMedia());
        $this->assertTrue($admin->canUploadMedia());
        $this->assertTrue($editor->canUploadMedia());
        $this->assertTrue($moderator->canUploadMedia());
        $this->assertTrue($author->canUploadMedia());
        $this->assertFalse($subscriber->canUploadMedia());

        // Kernel route access for upload endpoints
        // Allowed roles access /admin/media/capabilities -> 200
        $_SESSION['auth_user_id'] = $moderator->id;
        $req = Request::create('GET', '/admin/media/capabilities');
        $resp = static::$kernel->handle($req);
        $this->assertEquals(200, $resp->getStatusCode());

        $_SESSION['auth_user_id'] = $author->id;
        $req = Request::create('GET', '/admin/media/capabilities');
        $resp = static::$kernel->handle($req);
        $this->assertEquals(200, $resp->getStatusCode());

        // Subscriber is denied -> 403
        $_SESSION['auth_user_id'] = $subscriber->id;
        $req = Request::create('GET', '/admin/media/capabilities');
        $resp = static::$kernel->handle($req);
        $this->assertEquals(403, $resp->getStatusCode());

        $req = Request::create('POST', '/admin/media/upload', ['_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(403, $resp->getStatusCode());

        $req = Request::create('POST', '/admin/media/upload-ajax', ['_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(403, $resp->getStatusCode());
    }

    public function testScenarioAB_MediaManagePermissions(): void
    {
        $superAdmin = User::find(1);
        $admin = $this->createTestUser('admin', 'scen_ab_adm');
        $editor = $this->createTestUser('editor', 'scen_ab_ed');
        $moderator = $this->createTestUser('moderator', 'scen_ab_mod');
        $author = $this->createTestUser('author', 'scen_ab_aut');
        $subscriber = $this->createTestUser('subscriber', 'scen_ab_sub');

        // Capability checks
        $this->assertTrue($superAdmin->canManageMedia());
        $this->assertTrue($admin->canManageMedia());
        $this->assertTrue($editor->canManageMedia());
        $this->assertFalse($moderator->canManageMedia());
        $this->assertFalse($author->canManageMedia());
        $this->assertFalse($subscriber->canManageMedia());

        $_SESSION['auth_user_id'] = $superAdmin->id;
        $req = Request::create('GET', '/admin/media');
        $resp = static::$kernel->handle($req);
        $this->assertEquals(200, $resp->getStatusCode());

        $_SESSION['auth_user_id'] = $admin->id;
        $req = Request::create('GET', '/admin/media');
        $this->assertEquals(200, static::$kernel->handle($req)->getStatusCode());

        $_SESSION['auth_user_id'] = $editor->id;
        $req = Request::create('GET', '/admin/media');
        $this->assertEquals(200, static::$kernel->handle($req)->getStatusCode());

        // Moderator, Author, Subscriber are denied /admin/media (403)
        $_SESSION['auth_user_id'] = $moderator->id;
        $req = Request::create('GET', '/admin/media');
        $this->assertEquals(403, static::$kernel->handle($req)->getStatusCode());

        $_SESSION['auth_user_id'] = $author->id;
        $req = Request::create('GET', '/admin/media');
        $this->assertEquals(403, static::$kernel->handle($req)->getStatusCode());

        $_SESSION['auth_user_id'] = $subscriber->id;
        $req = Request::create('GET', '/admin/media');
        $this->assertEquals(403, static::$kernel->handle($req)->getStatusCode());

        // Route /admin/media/update and /admin/media/delete blocked for Moderator
        $_SESSION['auth_user_id'] = $moderator->id;
        $req = Request::create('POST', '/admin/media/update', ['id' => 1, '_token' => 'test-token-12345']);
        $this->assertEquals(403, static::$kernel->handle($req)->getStatusCode());

        $req = Request::create('POST', '/admin/media/delete?id=1', ['id' => 1, '_token' => 'test-token-12345']);
        $this->assertEquals(403, static::$kernel->handle($req)->getStatusCode());
    }

    public function testScenarioAC_SubscriberAccess(): void
    {
        $subscriber = $this->createTestUser('subscriber', 'scen_ac_sub');
        $author = $this->createTestUser('author', 'scen_ac_aut');
        $post = $this->createTestPost($author->id, 'published');

        $_SESSION['auth_user_id'] = $subscriber->id;

        // 1. Dashboard access allowed (200 OK)
        $req = Request::create('GET', '/admin');
        $resp = static::$kernel->handle($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $body = (string)$resp->getContent();
        $this->assertStringContainsString('Dashboard', $body);
        $this->assertStringContainsString('My Account', $body);
        $this->assertStringNotContainsString('+ Write Your First Post', $body);
        $this->assertStringNotContainsString('+ Add an About Page', $body);
        $this->assertStringNotContainsString('Quick Draft', $body);

        // 2. Profile access allowed (200 OK)
        $req = Request::create('GET', '/admin/users/profile');
        $resp = static::$kernel->handle($req);
        $this->assertEquals(200, $resp->getStatusCode());

        // 3. Posts view allowed (200 OK, read-only)
        $req = Request::create('GET', '/admin/posts');
        $resp = static::$kernel->handle($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $postListBody = (string)$resp->getContent();
        $this->assertStringContainsString('Posts', $postListBody);
        // Subscriber must not see "Add New Post" or bulk actions or edit/trash links
        $this->assertStringNotContainsString('Add New Post', $postListBody);
        $this->assertStringNotContainsString('Bulk Actions', $postListBody);
        $this->assertStringNotContainsString('/admin/posts/trash?id=', $postListBody);
        $this->assertStringNotContainsString('/admin/posts/delete?id=', $postListBody);

        // 4. Post mutations blocked (403)
        $blockedRoutes = [
            ['GET', '/admin/posts/new'],
            ['POST', '/admin/posts/store', ['title' => 'Sub Post']],
            ['GET', '/admin/posts/edit?id=' . $post->id],
            ['POST', '/admin/posts/update?id=' . $post->id, ['id' => $post->id, 'title' => 'Sub Updated']],
            ['POST', '/admin/posts/trash?id=' . $post->id, ['id' => $post->id]],
            ['POST', '/admin/posts/restore?id=' . $post->id, ['id' => $post->id]],
            ['POST', '/admin/posts/delete?id=' . $post->id, ['id' => $post->id]],
            ['POST', '/admin/posts/approve?id=' . $post->id, ['id' => $post->id]],
            ['POST', '/admin/posts/reject?id=' . $post->id, ['id' => $post->id]],
        ];

        foreach ($blockedRoutes as $item) {
            $m = $item[0];
            $uri = $item[1];
            $data = $item[2] ?? [];
            if ($m === 'POST') {
                $data['_token'] = 'test-token-12345';
            }
            $req = Request::create($m, $uri, $data);
            $resp = static::$kernel->handle($req);
            $this->assertEquals(403, $resp->getStatusCode(), "Subscriber must receive 403 on {$m} {$uri}");
        }
    }

    // =========================================================================
    // REGRESSION & SPECIFIC WORKFLOW INTEGRATION TESTS
    // =========================================================================

    /**
     * Regression 1: Moderator can approve a pending post
     */
    public function testModeratorCanApprovePendingPost(): void
    {
        $moderator = $this->createTestUser('moderator', 'mod_appr');
        $author = $this->createTestUser('author', 'aut_appr');
        $post = $this->createTestPost($author->id, 'pending');

        $this->assertTrue($moderator->canModeratePosts());

        $_SESSION['auth_user_id'] = $moderator->id;
        $req = Request::create('POST', '/admin/posts/approve?id=' . $post->id, ['id' => $post->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);

        $this->assertEquals(302, $resp->getStatusCode());
        $fresh = Post::find($post->id);
        $this->assertEquals('published', $fresh->status);
    }

    /**
     * Regression 2: Moderator can reject a pending post
     */
    public function testModeratorCanRejectPendingPost(): void
    {
        $moderator = $this->createTestUser('moderator', 'mod_rej');
        $author = $this->createTestUser('author', 'aut_rej');
        $post = $this->createTestPost($author->id, 'pending');

        $_SESSION['auth_user_id'] = $moderator->id;
        $req = Request::create('POST', '/admin/posts/reject?id=' . $post->id, ['id' => $post->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);

        $this->assertEquals(302, $resp->getStatusCode());
        $fresh = Post::find($post->id);
        $this->assertEquals('rejected', $fresh->status);
    }

    /**
     * Regression 3: Editor CANNOT approve or reject a pending post
     */
    public function testEditorCannotApproveOrRejectPendingPost(): void
    {
        $editor = $this->createTestUser('editor', 'ed_no_appr');
        $author = $this->createTestUser('author', 'aut_no_appr');
        $post = $this->createTestPost($author->id, 'pending');

        $this->assertFalse($editor->canModeratePosts());

        $_SESSION['auth_user_id'] = $editor->id;
        $req = Request::create('POST', '/admin/posts/approve?id=' . $post->id, ['id' => $post->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);

        $this->assertEquals(302, $resp->getStatusCode());
        $fresh = Post::find($post->id);
        $this->assertEquals('pending', $fresh->status);
    }

    /**
     * Regression 4: Editor can moderate comments (approve, spam, trash)
     */
    public function testEditorCanModerateComments(): void
    {
        $editor = $this->createTestUser('editor', 'ed_comm');
        $author = $this->createTestUser('author', 'aut_comm');
        $post = $this->createTestPost($author->id, 'published');
        $comment = $this->createTestComment($post->id, $author->id, 'pending');

        $this->assertTrue($editor->canModerateComments());

        $_SESSION['auth_user_id'] = $editor->id;

        // Approve comment
        $req = Request::create('POST', '/admin/comments/approve?id=' . $comment->id, ['id' => $comment->id, '_token' => 'test-token-12345']);
        static::$kernel->handle($req);
        $fresh = Comment::find($comment->id);
        $this->assertEquals('approved', $fresh->status);

        // Spam comment
        $req = Request::create('POST', '/admin/comments/spam?id=' . $comment->id, ['id' => $comment->id, '_token' => 'test-token-12345']);
        static::$kernel->handle($req);
        $fresh = Comment::find($comment->id);
        $this->assertEquals('spam', $fresh->status);

        // Trash comment
        $req = Request::create('POST', '/admin/comments/trash?id=' . $comment->id, ['id' => $comment->id, '_token' => 'test-token-12345']);
        static::$kernel->handle($req);
        $fresh = Comment::find($comment->id);
        $this->assertEquals('trash', $fresh->status);
    }

    /**
     * Regression 5: Existing sole Super Admin protections work
     */
    public function testExistingSoleSuperAdminProtectionsWork(): void
    {
        $superAdmin = User::find(1);
        $this->assertNotNull($superAdmin);
        $count = User::getActiveSuperAdminCount(static::$db);
        $this->assertGreaterThanOrEqual(1, $count);

        if ($count === 1) {
            $this->assertFalse($superAdmin->canSelfDelete());
            $this->expectException(\RuntimeException::class);
            $superAdmin->deleteAccount(1);
        }
    }

    /**
     * Regression 6: Existing Super Admin emergency recovery works
     */
    public function testExistingSuperAdminEmergencyRecoveryWorks(): void
    {
        $user1 = User::find(1);
        $this->assertNotNull($user1);

        // When Super Admin count is >= 1, recovery is not eligible
        $this->assertFalse($user1->isEligibleForSuperAdminRecovery());
    }

    /**
     * Regression 7: Posts list view hides destructive actions for Moderator on other users' posts
     */
    public function testPostsListViewHidesDestructiveActionsForModeratorOnOtherPosts(): void
    {
        $moderator = $this->createTestUser('moderator', 'mod_list');
        $author = $this->createTestUser('author', 'aut_list');
        $authorPost = $this->createTestPost($author->id, 'published');
        $modPost = $this->createTestPost($moderator->id, 'published');

        $_SESSION['auth_user_id'] = $moderator->id;

        $req = Request::create('GET', '/admin/posts');
        $resp = static::$kernel->handle($req);
        $this->assertEquals(200, $resp->getStatusCode());
        $body = (string)$resp->getContent();

        // Moderator sees Trash option for their own post
        $this->assertStringContainsString('action="/admin/posts/trash?id=' . $modPost->id . '"', $body);

        // Moderator does NOT see Trash option for author's post
        $this->assertStringNotContainsString('action="/admin/posts/trash?id=' . $authorPost->id . '"', $body);
    }

    /**
     * Regression 8: Author dashboard access returns 200 and displays scoped view
     */
    public function testAuthorDashboardAccessAndRestrictedView(): void
    {
        $author = $this->createTestUser('author', 'aut_dash');
        $authorPost = $this->createTestPost($author->id, 'published');

        $_SESSION['auth_user_id'] = $author->id;

        $req = Request::create('GET', '/admin');
        $resp = static::$kernel->handle($req);

        $this->assertEquals(200, $resp->getStatusCode());
        $body = (string)$resp->getContent();

        $this->assertStringContainsString('Dashboard', $body);
        $this->assertStringContainsString('My Posts', $body);
        $this->assertStringContainsString('My Account', $body);

        // Author dashboard MUST NOT contain unauthorized management cards or links
        $this->assertStringNotContainsString('href="/admin/pages"', $body);
        $this->assertStringNotContainsString('href="/admin/comments"', $body);
        $this->assertStringNotContainsString('href="/admin/users"', $body);
        $this->assertStringNotContainsString('href="/admin/media"', $body);
        $this->assertStringNotContainsString('+ Add an About Page', $body);
        $this->assertStringNotContainsString('Customize Theme', $body);

        // Quick Draft form is available for author
        $this->assertStringContainsString('action="/admin/posts/quick-draft"', $body);
    }

    /**
     * Regression 9: Author cannot access unpermitted administrative areas
     */
    public function testAuthorCannotAccessAdminPagesCommentsTaxonomiesMenus(): void
    {
        $author = $this->createTestUser('author', 'aut_nopages');
        $_SESSION['auth_user_id'] = $author->id;

        // Pages
        $req = Request::create('GET', '/admin/pages');
        $resp = static::$kernel->handle($req);
        $this->assertEquals(403, $resp->getStatusCode());

        // Comments
        $req = Request::create('GET', '/admin/comments');
        $resp = static::$kernel->handle($req);
        $this->assertEquals(403, $resp->getStatusCode());

        // Taxonomies
        $req = Request::create('GET', '/admin/taxonomies/categories');
        $resp = static::$kernel->handle($req);
        $this->assertEquals(403, $resp->getStatusCode());

        // Menus
        $req = Request::create('GET', '/admin/menus');
        $resp = static::$kernel->handle($req);
        $this->assertEquals(403, $resp->getStatusCode());

        // Users
        $req = Request::create('GET', '/admin/users');
        $resp = static::$kernel->handle($req);
        $this->assertEquals(403, $resp->getStatusCode());
    }

    /**
     * Explicit Verification: Editor Bulk & Individual Delete/Trash/Restore Permissions
     */
    public function testExplicitEditorBulkOperationsAndIndividualPermissions(): void
    {
        $editor = $this->createTestUser('editor', 'ex_ed');
        $author = $this->createTestUser('author', 'ex_ed_aut');

        $edPost = $this->createTestPost($editor->id, 'published');
        $otherPost = $this->createTestPost($author->id, 'published');

        // Capability checks & verify NO fallback from edit permission
        $this->assertTrue($editor->canEditPost($otherPost), 'Editor CAN edit other user post');
        $this->assertFalse($editor->canDeleteOtherPosts(), 'Editor CANNOT delete other posts');
        $this->assertFalse($editor->canDeletePost($otherPost), 'Editor CANNOT delete other user post');
        $this->assertFalse($editor->canRestorePost($otherPost), 'Editor CANNOT restore other user post');
        $this->assertTrue($editor->canDeletePost($edPost), 'Editor CAN delete own post');
        $this->assertTrue($editor->canRestorePost($edPost), 'Editor CAN restore own post');

        $_SESSION['auth_user_id'] = $editor->id;

        // 1. Bulk Trash mixed selection
        $req = Request::create('POST', '/admin/posts/bulk', [
            'bulk_action' => 'trash',
            'ids'         => [$edPost->id, $otherPost->id],
            '_token'      => 'test-token-12345',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals('trash', Post::find($edPost->id)->status, 'Editor post must be trashed');
        $this->assertEquals('published', Post::find($otherPost->id)->status, 'Other user post MUST NEVER be trashed');
        $this->assertStringContainsString('1 post(s) successfully moved to trash', $_SESSION['flash_success'] ?? '');
        $this->assertStringContainsString('1 post(s) skipped due to insufficient permissions', $_SESSION['flash_success'] ?? '');

        // 2. Bulk Restore mixed selection
        static::$db->execute("UPDATE `posts` SET `status` = 'trash' WHERE `id` = ?", [$otherPost->id]);
        $req = Request::create('POST', '/admin/posts/bulk', [
            'bulk_action' => 'restore',
            'ids'         => [$edPost->id, $otherPost->id],
            '_token'      => 'test-token-12345',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals('draft', Post::find($edPost->id)->status, 'Editor post must be restored to draft');
        $this->assertEquals('trash', Post::find($otherPost->id)->status, 'Other user post MUST NEVER be restored');
        $this->assertStringContainsString('1 post(s) successfully restored from trash', $_SESSION['flash_success'] ?? '');
        $this->assertStringContainsString('1 post(s) skipped due to insufficient permissions', $_SESSION['flash_success'] ?? '');

        // 3. Bulk Delete Permanently mixed selection
        static::$db->execute("UPDATE `posts` SET `status` = 'trash' WHERE `id` = ?", [$edPost->id]);
        $req = Request::create('POST', '/admin/posts/bulk', [
            'bulk_action' => 'delete',
            'ids'         => [$edPost->id, $otherPost->id],
            '_token'      => 'test-token-12345',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertNull(Post::find($edPost->id), 'Editor post must be permanently deleted');
        $this->assertNotNull(Post::find($otherPost->id), 'Other user post MUST NEVER be deleted');
        $this->assertEquals('trash', Post::find($otherPost->id)->status);
        $this->assertStringContainsString('1 post(s) successfully permanently deleted', $_SESSION['flash_success'] ?? '');
        $this->assertStringContainsString('1 post(s) skipped due to insufficient permissions', $_SESSION['flash_success'] ?? '');

        // 4. Individual operations on other user post (all must be rejected)
        static::$db->execute("UPDATE `posts` SET `status` = 'published' WHERE `id` = ?", [$otherPost->id]);
        $req = Request::create('POST', '/admin/posts/trash?id=' . $otherPost->id, ['id' => $otherPost->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertStringContainsString('permission', strtolower($_SESSION['flash_error'] ?? ''));
        $this->assertEquals('published', Post::find($otherPost->id)->status);

        static::$db->execute("UPDATE `posts` SET `status` = 'trash' WHERE `id` = ?", [$otherPost->id]);
        $req = Request::create('POST', '/admin/posts/restore?id=' . $otherPost->id, ['id' => $otherPost->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertStringContainsString('permission', strtolower($_SESSION['flash_error'] ?? ''));
        $this->assertEquals('trash', Post::find($otherPost->id)->status);

        $req = Request::create('POST', '/admin/posts/delete?id=' . $otherPost->id, ['id' => $otherPost->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertStringContainsString('permission', strtolower($_SESSION['flash_error'] ?? ''));
        $this->assertNotNull(Post::find($otherPost->id));

        // 5. Individual operations on own post (all must succeed)
        $newEdPost = $this->createTestPost($editor->id, 'published');
        $req = Request::create('POST', '/admin/posts/trash?id=' . $newEdPost->id, ['id' => $newEdPost->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals('trash', Post::find($newEdPost->id)->status);

        $req = Request::create('POST', '/admin/posts/restore?id=' . $newEdPost->id, ['id' => $newEdPost->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals('draft', Post::find($newEdPost->id)->status);

        $req = Request::create('POST', '/admin/posts/delete?id=' . $newEdPost->id, ['id' => $newEdPost->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertNull(Post::find($newEdPost->id));
    }

    /**
     * Explicit Verification: Moderator Bulk & Individual Delete/Trash/Restore Permissions
     */
    public function testExplicitModeratorBulkOperationsAndIndividualPermissions(): void
    {
        $moderator = $this->createTestUser('moderator', 'ex_mod');
        $author = $this->createTestUser('author', 'ex_mod_aut');

        $modPost = $this->createTestPost($moderator->id, 'published');
        $otherPost = $this->createTestPost($author->id, 'published');

        // Capability checks & verify NO fallback from edit permission
        $this->assertTrue($moderator->canEditPost($otherPost), 'Moderator CAN edit other user post');
        $this->assertFalse($moderator->canDeleteOtherPosts(), 'Moderator CANNOT delete other posts');
        $this->assertFalse($moderator->canDeletePost($otherPost), 'Moderator CANNOT delete other user post');
        $this->assertFalse($moderator->canRestorePost($otherPost), 'Moderator CANNOT restore other user post');
        $this->assertTrue($moderator->canDeletePost($modPost), 'Moderator CAN delete own post');
        $this->assertTrue($moderator->canRestorePost($modPost), 'Moderator CAN restore own post');

        $_SESSION['auth_user_id'] = $moderator->id;

        // 1. Bulk Trash mixed selection
        $req = Request::create('POST', '/admin/posts/bulk', [
            'bulk_action' => 'trash',
            'ids'         => [$modPost->id, $otherPost->id],
            '_token'      => 'test-token-12345',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals('trash', Post::find($modPost->id)->status, 'Moderator post must be trashed');
        $this->assertEquals('published', Post::find($otherPost->id)->status, 'Other user post MUST NEVER be trashed');
        $this->assertStringContainsString('1 post(s) successfully moved to trash', $_SESSION['flash_success'] ?? '');
        $this->assertStringContainsString('1 post(s) skipped due to insufficient permissions', $_SESSION['flash_success'] ?? '');

        // 2. Bulk Restore mixed selection
        static::$db->execute("UPDATE `posts` SET `status` = 'trash' WHERE `id` = ?", [$otherPost->id]);
        $req = Request::create('POST', '/admin/posts/bulk', [
            'bulk_action' => 'restore',
            'ids'         => [$modPost->id, $otherPost->id],
            '_token'      => 'test-token-12345',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals('draft', Post::find($modPost->id)->status, 'Moderator post must be restored to draft');
        $this->assertEquals('trash', Post::find($otherPost->id)->status, 'Other user post MUST NEVER be restored');
        $this->assertStringContainsString('1 post(s) successfully restored from trash', $_SESSION['flash_success'] ?? '');
        $this->assertStringContainsString('1 post(s) skipped due to insufficient permissions', $_SESSION['flash_success'] ?? '');

        // 3. Bulk Delete Permanently mixed selection
        static::$db->execute("UPDATE `posts` SET `status` = 'trash' WHERE `id` = ?", [$modPost->id]);
        $req = Request::create('POST', '/admin/posts/bulk', [
            'bulk_action' => 'delete',
            'ids'         => [$modPost->id, $otherPost->id],
            '_token'      => 'test-token-12345',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertNull(Post::find($modPost->id), 'Moderator post must be permanently deleted');
        $this->assertNotNull(Post::find($otherPost->id), 'Other user post MUST NEVER be deleted');
        $this->assertEquals('trash', Post::find($otherPost->id)->status);
        $this->assertStringContainsString('1 post(s) successfully permanently deleted', $_SESSION['flash_success'] ?? '');
        $this->assertStringContainsString('1 post(s) skipped due to insufficient permissions', $_SESSION['flash_success'] ?? '');

        // 4. Individual operations on other user post (all must be rejected)
        static::$db->execute("UPDATE `posts` SET `status` = 'published' WHERE `id` = ?", [$otherPost->id]);
        $req = Request::create('POST', '/admin/posts/trash?id=' . $otherPost->id, ['id' => $otherPost->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertStringContainsString('permission', strtolower($_SESSION['flash_error'] ?? ''));
        $this->assertEquals('published', Post::find($otherPost->id)->status);

        static::$db->execute("UPDATE `posts` SET `status` = 'trash' WHERE `id` = ?", [$otherPost->id]);
        $req = Request::create('POST', '/admin/posts/restore?id=' . $otherPost->id, ['id' => $otherPost->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertStringContainsString('permission', strtolower($_SESSION['flash_error'] ?? ''));
        $this->assertEquals('trash', Post::find($otherPost->id)->status);

        $req = Request::create('POST', '/admin/posts/delete?id=' . $otherPost->id, ['id' => $otherPost->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertStringContainsString('permission', strtolower($_SESSION['flash_error'] ?? ''));
        $this->assertNotNull(Post::find($otherPost->id));

        // 5. Individual operations on own post (all must succeed)
        $newModPost = $this->createTestPost($moderator->id, 'published');
        $req = Request::create('POST', '/admin/posts/trash?id=' . $newModPost->id, ['id' => $newModPost->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals('trash', Post::find($newModPost->id)->status);

        $req = Request::create('POST', '/admin/posts/restore?id=' . $newModPost->id, ['id' => $newModPost->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals('draft', Post::find($newModPost->id)->status);

        $req = Request::create('POST', '/admin/posts/delete?id=' . $newModPost->id, ['id' => $newModPost->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertNull(Post::find($newModPost->id));
    }

    /**
     * Explicit Verification: Author Bulk & Individual Delete/Trash/Restore Permissions
     */
    public function testExplicitAuthorBulkOperationsAndIndividualPermissions(): void
    {
        $author = $this->createTestUser('author', 'ex_aut1');
        $otherAuthor = $this->createTestUser('author', 'ex_aut2');

        $autPost = $this->createTestPost($author->id, 'published');
        $otherPost = $this->createTestPost($otherAuthor->id, 'published');

        // Capability checks & verify NO fallback from edit permission
        $this->assertFalse($author->canEditPost($otherPost), 'Author CANNOT edit other user post');
        $this->assertFalse($author->canDeleteOtherPosts(), 'Author CANNOT delete other posts');
        $this->assertFalse($author->canDeletePost($otherPost), 'Author CANNOT delete other user post');
        $this->assertFalse($author->canRestorePost($otherPost), 'Author CANNOT restore other user post');
        $this->assertTrue($author->canDeletePost($autPost), 'Author CAN delete own post');
        $this->assertTrue($author->canRestorePost($autPost), 'Author CAN restore own post');

        $_SESSION['auth_user_id'] = $author->id;

        // 1. Bulk Trash mixed selection
        $req = Request::create('POST', '/admin/posts/bulk', [
            'bulk_action' => 'trash',
            'ids'         => [$autPost->id, $otherPost->id],
            '_token'      => 'test-token-12345',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals('trash', Post::find($autPost->id)->status, 'Author post must be trashed');
        $this->assertEquals('published', Post::find($otherPost->id)->status, 'Other user post MUST NEVER be trashed');
        $this->assertStringContainsString('1 post(s) successfully moved to trash', $_SESSION['flash_success'] ?? '');
        $this->assertStringContainsString('1 post(s) skipped due to insufficient permissions', $_SESSION['flash_success'] ?? '');

        // 2. Bulk Restore mixed selection
        static::$db->execute("UPDATE `posts` SET `status` = 'trash' WHERE `id` = ?", [$otherPost->id]);
        $req = Request::create('POST', '/admin/posts/bulk', [
            'bulk_action' => 'restore',
            'ids'         => [$autPost->id, $otherPost->id],
            '_token'      => 'test-token-12345',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals('draft', Post::find($autPost->id)->status, 'Author post must be restored to draft');
        $this->assertEquals('trash', Post::find($otherPost->id)->status, 'Other user post MUST NEVER be restored');
        $this->assertStringContainsString('1 post(s) successfully restored from trash', $_SESSION['flash_success'] ?? '');
        $this->assertStringContainsString('1 post(s) skipped due to insufficient permissions', $_SESSION['flash_success'] ?? '');

        // 3. Bulk Delete Permanently mixed selection
        static::$db->execute("UPDATE `posts` SET `status` = 'trash' WHERE `id` = ?", [$autPost->id]);
        $req = Request::create('POST', '/admin/posts/bulk', [
            'bulk_action' => 'delete',
            'ids'         => [$autPost->id, $otherPost->id],
            '_token'      => 'test-token-12345',
        ]);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertNull(Post::find($autPost->id), 'Author post must be permanently deleted');
        $this->assertNotNull(Post::find($otherPost->id), 'Other user post MUST NEVER be deleted');
        $this->assertEquals('trash', Post::find($otherPost->id)->status);
        $this->assertStringContainsString('1 post(s) successfully permanently deleted', $_SESSION['flash_success'] ?? '');
        $this->assertStringContainsString('1 post(s) skipped due to insufficient permissions', $_SESSION['flash_success'] ?? '');

        // 4. Individual operations on other user post (all must be rejected)
        static::$db->execute("UPDATE `posts` SET `status` = 'published' WHERE `id` = ?", [$otherPost->id]);
        $req = Request::create('POST', '/admin/posts/trash?id=' . $otherPost->id, ['id' => $otherPost->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertStringContainsString('permission', strtolower($_SESSION['flash_error'] ?? ''));
        $this->assertEquals('published', Post::find($otherPost->id)->status);

        static::$db->execute("UPDATE `posts` SET `status` = 'trash' WHERE `id` = ?", [$otherPost->id]);
        $req = Request::create('POST', '/admin/posts/restore?id=' . $otherPost->id, ['id' => $otherPost->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertStringContainsString('permission', strtolower($_SESSION['flash_error'] ?? ''));
        $this->assertEquals('trash', Post::find($otherPost->id)->status);

        $req = Request::create('POST', '/admin/posts/delete?id=' . $otherPost->id, ['id' => $otherPost->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertStringContainsString('permission', strtolower($_SESSION['flash_error'] ?? ''));
        $this->assertNotNull(Post::find($otherPost->id));

        // 5. Individual operations on own post (all must succeed)
        $newAutPost = $this->createTestPost($author->id, 'published');
        $req = Request::create('POST', '/admin/posts/trash?id=' . $newAutPost->id, ['id' => $newAutPost->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals('trash', Post::find($newAutPost->id)->status);

        $req = Request::create('POST', '/admin/posts/restore?id=' . $newAutPost->id, ['id' => $newAutPost->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertEquals('draft', Post::find($newAutPost->id)->status);

        $req = Request::create('POST', '/admin/posts/delete?id=' . $newAutPost->id, ['id' => $newAutPost->id, '_token' => 'test-token-12345']);
        $resp = static::$kernel->handle($req);
        $this->assertEquals(302, $resp->getStatusCode());
        $this->assertNull(Post::find($newAutPost->id));
    }
}
