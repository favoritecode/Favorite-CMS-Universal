<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use PHPUnit\Framework\TestCase;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Kernel;
use FavoriteCMS\Models\Menu;
use FavoriteCMS\Models\User;

class AdminMenuManagementTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;
    private static array $createdUserIds = [];
    private static array $createdMenuIds = [];

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db = static::$app->make(Database::class);

        static::$db->execute("INSERT IGNORE INTO `roles` (`name`, `slug`, `description`, `is_system`) VALUES ('Administrator', 'admin', 'Site administrator', 1)");
    }

    public static function tearDownAfterClass(): void
    {
        if (!empty(static::$createdMenuIds)) {
            $inMenus = implode(',', array_map('intval', static::$createdMenuIds));
            static::$db->execute("DELETE FROM `menu_items` WHERE `menu_id` IN ({$inMenus})");
            static::$db->execute("DELETE FROM `menus` WHERE `id` IN ({$inMenus})");
        }

        if (!empty(static::$createdUserIds)) {
            $inUsers = implode(',', array_map('intval', static::$createdUserIds));
            static::$db->execute("DELETE FROM `user_roles` WHERE `user_id` IN ({$inUsers})");
            static::$db->execute("DELETE FROM `users` WHERE `id` IN ({$inUsers})");
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
        $_SESSION['_token'] = bin2hex(random_bytes(16));
    }

    protected function createAdminUser(): User
    {
        $unique = 'admin_menu_' . bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');
        $hash = password_hash('SecretPassword123!', PASSWORD_DEFAULT);

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

        static::$createdUserIds[] = (int)$userId;

        $role = static::$db->selectOne("SELECT id FROM `roles` WHERE `slug` = 'admin' LIMIT 1");
        if ($role) {
            static::$db->execute("INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [$userId, $role->id]);
        }

        return User::find((int)$userId);
    }

    protected function createTestMenu(string $name, array $itemTitles = []): Menu
    {
        $slug = 'test-menu-' . bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');
        $menuId = static::$db->insert('menus', [
            'name'       => $name,
            'slug'       => $slug,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        static::$createdMenuIds[] = (int)$menuId;

        foreach ($itemTitles as $order => $title) {
            static::$db->insert('menu_items', [
                'menu_id'    => $menuId,
                'title'      => $title,
                'url'        => '/' . strtolower(str_replace(' ', '-', $title)),
                'type'       => 'custom',
                'sort_order' => $order + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return Menu::find((int)$menuId);
    }

    public function testAdminMenuIndexRendersControlsAndHierarchy(): void
    {
        $admin = $this->createAdminUser();
        $_SESSION['auth_user_id'] = $admin->id;

        $menu = $this->createTestMenu('Primary Nav', ['Home', 'Tutorial', 'Documentation']);

        $kernel = new Kernel(static::$app);
        $req = Request::create('GET', '/admin/menus?menu=' . $menu->id);
        $resp = $kernel->handle($req);

        $this->assertSame(200, $resp->getStatusCode());
        $html = $resp->getContent();

        // Must contain drag handles
        $this->assertStringContainsString('☰', $html, 'Must render drag handles ☰');
        // Must contain Up and Down buttons
        $this->assertStringContainsString('↑', $html, 'Must render Up button ↑');
        $this->assertStringContainsString('↓', $html, 'Must render Down button ↓');
        // Must contain Edit and Remove buttons
        $this->assertStringContainsString('Edit', $html, 'Must render Edit button');
        $this->assertStringContainsString('Remove', $html, 'Must render Remove button');
        // Must contain action target /admin/menus/save
        $this->assertStringContainsString('/admin/menus/save', $html, 'Must post to /admin/menus/save');
        // Must contain menu item titles
        $this->assertStringContainsString('Home', $html);
        $this->assertStringContainsString('Tutorial', $html);
        $this->assertStringContainsString('Documentation', $html);
    }

    public function testSaveMenuReordersItemsVertically(): void
    {
        $admin = $this->createAdminUser();
        $_SESSION['auth_user_id'] = $admin->id;

        $menu = $this->createTestMenu('Reorder Test', ['Home', 'Tutorial', 'Documentation']);
        $items = static::$db->select("SELECT * FROM menu_items WHERE menu_id = ? ORDER BY id ASC", [$menu->id]);
        $homeId = (int)$items[0]->id;
        $tutorialId = (int)$items[1]->id;
        $docId = (int)$items[2]->id;

        // Move Tutorial to the top, then Documentation, then Home
        $postData = [
            '_token'   => $_SESSION['_token'],
            'menu_id'  => (string)$menu->id,
            'items'    => [
                0 => ['id' => (string)$tutorialId, 'parent_id' => '0', 'sort_order' => '1', 'title' => 'Tutorial', 'url' => '/tutorial'],
                1 => ['id' => (string)$docId,      'parent_id' => '0', 'sort_order' => '2', 'title' => 'Documentation', 'url' => '/doc'],
                2 => ['id' => (string)$homeId,     'parent_id' => '0', 'sort_order' => '3', 'title' => 'Home', 'url' => '/'],
            ],
        ];

        $kernel = new Kernel(static::$app);
        $req = Request::create('POST', '/admin/menus/save', $postData);
        $resp = $kernel->handle($req);

        $this->assertTrue($resp->isRedirect(), 'Must redirect after saving menu');

        $tree = $menu->getItems();
        $this->assertCount(3, $tree);
        $this->assertSame('Tutorial', $tree[0]->title);
        $this->assertSame('Documentation', $tree[1]->title);
        $this->assertSame('Home', $tree[2]->title);
    }

    public function testSaveMenuNestsItemsUnderParent(): void
    {
        $admin = $this->createAdminUser();
        $_SESSION['auth_user_id'] = $admin->id;

        $menu = $this->createTestMenu('Nesting Test', ['Tutorial', 'PC', 'Mobile', 'Coding', 'Documentation']);
        $items = static::$db->select("SELECT * FROM menu_items WHERE menu_id = ? ORDER BY id ASC", [$menu->id]);
        $tutId    = (int)$items[0]->id;
        $pcId     = (int)$items[1]->id;
        $mobileId = (int)$items[2]->id;
        $codingId = (int)$items[3]->id;
        $docId    = (int)$items[4]->id;

        // Nest PC, Mobile, Coding under Tutorial (parent_id = tutId)
        $postData = [
            '_token'   => $_SESSION['_token'],
            'menu_id'  => (string)$menu->id,
            'items'    => [
                0 => ['id' => (string)$tutId,    'parent_id' => '0',           'sort_order' => '1', 'title' => 'Tutorial',      'url' => '/tutorial'],
                1 => ['id' => (string)$pcId,     'parent_id' => (string)$tutId, 'sort_order' => '2', 'title' => 'PC',            'url' => '/tutorial/pc'],
                2 => ['id' => (string)$mobileId, 'parent_id' => (string)$tutId, 'sort_order' => '3', 'title' => 'Mobile',        'url' => '/tutorial/mobile'],
                3 => ['id' => (string)$codingId, 'parent_id' => (string)$tutId, 'sort_order' => '4', 'title' => 'Coding',        'url' => '/tutorial/coding'],
                4 => ['id' => (string)$docId,    'parent_id' => '0',           'sort_order' => '5', 'title' => 'Documentation', 'url' => '/docs'],
            ],
        ];

        $kernel = new Kernel(static::$app);
        $req = Request::create('POST', '/admin/menus/save', $postData);
        $resp = $kernel->handle($req);

        $this->assertTrue($resp->isRedirect());

        // Verify tree structure
        $tree = $menu->getItems();
        $this->assertCount(2, $tree, 'Top level must contain 2 items: Tutorial and Documentation');
        $this->assertSame('Tutorial', $tree[0]->title);
        $this->assertSame('Documentation', $tree[1]->title);

        $this->assertCount(3, $tree[0]->children, 'Tutorial must have 3 children');
        $this->assertSame('PC', $tree[0]->children[0]->title);
        $this->assertSame('Mobile', $tree[0]->children[1]->title);
        $this->assertSame('Coding', $tree[0]->children[2]->title);

        // Verify getFlatTree preserves depth
        $flat = $menu->getFlatTree();
        $this->assertCount(5, $flat);
        $this->assertSame(0, $flat[0]->depth); // Tutorial
        $this->assertSame(1, $flat[1]->depth); // PC
        $this->assertSame(1, $flat[2]->depth); // Mobile
        $this->assertSame(1, $flat[3]->depth); // Coding
        $this->assertSame(0, $flat[4]->depth); // Documentation
    }

    public function testSaveMenuOutdentsItemBackToTopLevel(): void
    {
        $admin = $this->createAdminUser();
        $_SESSION['auth_user_id'] = $admin->id;

        $menu = $this->createTestMenu('Outdent Test', ['Tutorial', 'PC']);
        $items = static::$db->select("SELECT * FROM menu_items WHERE menu_id = ? ORDER BY id ASC", [$menu->id]);
        $tutId = (int)$items[0]->id;
        $pcId  = (int)$items[1]->id;

        // First nest PC under Tutorial
        static::$db->execute("UPDATE menu_items SET parent_id = ? WHERE id = ?", [$tutId, $pcId]);

        $this->assertCount(1, $menu->getItems(), 'Initially only Tutorial at top level');

        // Now outdent PC back to top level (parent_id = 0)
        $postData = [
            '_token'  => $_SESSION['_token'],
            'menu_id' => (string)$menu->id,
            'items'   => [
                0 => ['id' => (string)$tutId, 'parent_id' => '0', 'sort_order' => '1', 'title' => 'Tutorial', 'url' => '/tutorial'],
                1 => ['id' => (string)$pcId,  'parent_id' => '0', 'sort_order' => '2', 'title' => 'PC',       'url' => '/pc'],
            ],
        ];

        $kernel = new Kernel(static::$app);
        $req = Request::create('POST', '/admin/menus/save', $postData);
        $resp = $kernel->handle($req);

        $this->assertTrue($resp->isRedirect());

        $tree = $menu->getItems();
        $this->assertCount(2, $tree, 'Both items must now be at top level');
        $this->assertSame('Tutorial', $tree[0]->title);
        $this->assertSame('PC', $tree[1]->title);
    }

    public function testSaveMenuUpdatesTitleAndUrl(): void
    {
        $admin = $this->createAdminUser();
        $_SESSION['auth_user_id'] = $admin->id;

        $menu = $this->createTestMenu('Edit Test', ['Original Title']);
        $items = static::$db->select("SELECT * FROM menu_items WHERE menu_id = ? ORDER BY id ASC", [$menu->id]);
        $itemId = (int)$items[0]->id;

        $postData = [
            '_token'  => $_SESSION['_token'],
            'menu_id' => (string)$menu->id,
            'items'   => [
                0 => [
                    'id'         => (string)$itemId,
                    'parent_id'  => '0',
                    'sort_order' => '1',
                    'title'      => 'Updated Navigation Label',
                    'url'        => 'https://example.com/new-path',
                    'target'     => '_blank',
                ],
            ],
        ];

        $kernel = new Kernel(static::$app);
        $req = Request::create('POST', '/admin/menus/save', $postData);
        $resp = $kernel->handle($req);

        $this->assertTrue($resp->isRedirect());

        $item = static::$db->selectOne("SELECT * FROM menu_items WHERE id = ?", [$itemId]);
        $this->assertSame('Updated Navigation Label', $item->title);
        $this->assertSame('https://example.com/new-path', $item->url);
        $this->assertSame('_blank', $item->target);
    }

    public function testSelfParentingIsRejected(): void
    {
        $admin = $this->createAdminUser();
        $_SESSION['auth_user_id'] = $admin->id;

        $menu = $this->createTestMenu('Self Parent Test', ['Self Referencing Item']);
        $items = static::$db->select("SELECT * FROM menu_items WHERE menu_id = ? ORDER BY id ASC", [$menu->id]);
        $itemId = (int)$items[0]->id;

        // Malicious or corrupted input: setting parent_id = itemId
        $postData = [
            '_token'  => $_SESSION['_token'],
            'menu_id' => (string)$menu->id,
            'items'   => [
                0 => [
                    'id'         => (string)$itemId,
                    'parent_id'  => (string)$itemId, // Self-parenting attempt!
                    'sort_order' => '1',
                    'title'      => 'Self Referencing Item',
                    'url'        => '/self',
                ],
            ],
        ];

        $kernel = new Kernel(static::$app);
        $req = Request::create('POST', '/admin/menus/save', $postData);
        $resp = $kernel->handle($req);

        $this->assertTrue($resp->isRedirect());

        $item = static::$db->selectOne("SELECT * FROM menu_items WHERE id = ?", [$itemId]);
        $this->assertNull($item->parent_id, 'Self-parenting must be neutralized to NULL');
    }

    public function testCircularHierarchyIsRejected(): void
    {
        $admin = $this->createAdminUser();
        $_SESSION['auth_user_id'] = $admin->id;

        $menu = $this->createTestMenu('Cycle Test', ['Item A', 'Item B']);
        $items = static::$db->select("SELECT * FROM menu_items WHERE menu_id = ? ORDER BY id ASC", [$menu->id]);
        $idA = (int)$items[0]->id;
        $idB = (int)$items[1]->id;

        // Circular parent attempt: A's parent is B, B's parent is A
        $postData = [
            '_token'  => $_SESSION['_token'],
            'menu_id' => (string)$menu->id,
            'items'   => [
                0 => ['id' => (string)$idA, 'parent_id' => (string)$idB, 'sort_order' => '1', 'title' => 'Item A', 'url' => '/a'],
                1 => ['id' => (string)$idB, 'parent_id' => (string)$idA, 'sort_order' => '2', 'title' => 'Item B', 'url' => '/b'],
            ],
        ];

        $kernel = new Kernel(static::$app);
        $req = Request::create('POST', '/admin/menus/save', $postData);
        $resp = $kernel->handle($req);

        $this->assertTrue($resp->isRedirect());

        // Cycle must be broken
        $itemA = static::$db->selectOne("SELECT * FROM menu_items WHERE id = ?", [$idA]);
        $itemB = static::$db->selectOne("SELECT * FROM menu_items WHERE id = ?", [$idB]);

        // At least one parent_id must have been reset to NULL
        $hasNull = ($itemA->parent_id === null || $itemB->parent_id === null);
        $this->assertTrue($hasNull, 'Circular relationship must be broken');

        // Menu tree must build without hanging or crashing
        $tree = $menu->getItems();
        $this->assertNotEmpty($tree);
    }

    public function testLocationAssignmentIsPersisted(): void
    {
        $admin = $this->createAdminUser();
        $_SESSION['auth_user_id'] = $admin->id;

        $menu = $this->createTestMenu('Location Test', ['Home']);

        $postData = [
            '_token'   => $_SESSION['_token'],
            'menu_id'  => (string)$menu->id,
            'location' => 'primary',
            'items'    => [
                0 => ['id' => (string)$menu->getItems()[0]->id, 'parent_id' => '0', 'sort_order' => '1', 'title' => 'Home', 'url' => '/'],
            ],
        ];

        $kernel = new Kernel(static::$app);
        $req = Request::create('POST', '/admin/menus/save', $postData);
        $resp = $kernel->handle($req);

        $this->assertTrue($resp->isRedirect());

        $updated = Menu::find((int)$menu->id);
        $this->assertSame('primary', $updated->location);
    }

    public function testDeleteItemPromotesChildren(): void
    {
        $admin = $this->createAdminUser();
        $_SESSION['auth_user_id'] = $admin->id;

        $menu = $this->createTestMenu('Delete Parent Test', ['Parent Item', 'Child Item']);
        $items = static::$db->select("SELECT * FROM menu_items WHERE menu_id = ? ORDER BY id ASC", [$menu->id]);
        $parentId = (int)$items[0]->id;
        $childId  = (int)$items[1]->id;

        // Set Child's parent to Parent
        static::$db->execute("UPDATE menu_items SET parent_id = ? WHERE id = ?", [$parentId, $childId]);

        // Delete parent via /admin/menus/item/delete
        $kernel = new Kernel(static::$app);
        $req = Request::create('POST', '/admin/menus/item/delete?id=' . $parentId . '&menu=' . $menu->id, ['_token' => $_SESSION['_token']]);
        $resp = $kernel->handle($req);

        $this->assertTrue($resp->isRedirect());

        // Parent deleted
        $parentInDb = static::$db->selectOne("SELECT * FROM menu_items WHERE id = ?", [$parentId]);
        $this->assertNull($parentInDb);

        // Child promoted to top-level (parent_id is null)
        $childInDb = static::$db->selectOne("SELECT * FROM menu_items WHERE id = ?", [$childId]);
        $this->assertNotNull($childInDb);
        $this->assertNull($childInDb->parent_id);
    }

    public function testFrontendNestedSubmenuRendering(): void
    {
        $menu = $this->createTestMenu('Frontend Nav Test', ['Tutorial', 'PC', 'Mobile', 'Coding', 'Documentation']);
        $items = static::$db->select("SELECT * FROM menu_items WHERE menu_id = ? ORDER BY id ASC", [$menu->id]);
        $tutId    = (int)$items[0]->id;
        $pcId     = (int)$items[1]->id;
        $mobileId = (int)$items[2]->id;
        $codingId = (int)$items[3]->id;
        $docId    = (int)$items[4]->id;

        // Nest PC, Mobile, Coding under Tutorial
        static::$db->execute("UPDATE menu_items SET parent_id = ? WHERE id IN (?, ?, ?)", [$tutId, $pcId, $mobileId, $codingId]);

        // Assign to primary location
        static::$db->execute("UPDATE menus SET location = 'primary' WHERE id = ?", [$menu->id]);

        $tree = $menu->getItems();
        $this->assertCount(2, $tree);
        $this->assertSame('Tutorial', $tree[0]->title);
        $this->assertCount(3, $tree[0]->children);
        $this->assertSame('PC', $tree[0]->children[0]->title);
        $this->assertSame('Mobile', $tree[0]->children[1]->title);
        $this->assertSame('Coding', $tree[0]->children[2]->title);
        $this->assertSame('Documentation', $tree[1]->title);

        // Test theme partial rendering if available
        $themeFunctions = APP_ROOT . '/themes/favorite-web/functions.php';
        if (file_exists($themeFunctions)) {
            require_once $themeFunctions;
            if (function_exists('fw_partial')) {
                ob_start();
                fw_partial('nav-menu', [
                    'items'       => $tree,
                    'menuClass'   => 'menu main-menu',
                    'prependHome' => false,
                ]);
                $html = ob_get_clean();

                $this->assertStringContainsString('class="menu-item menu-item-has-children"', $html);
                $this->assertStringContainsString('class="sub-menu"', $html);
                $this->assertStringContainsString('PC', $html);
                $this->assertStringContainsString('Mobile', $html);
                $this->assertStringContainsString('Coding', $html);
            }
        }
    }
}

