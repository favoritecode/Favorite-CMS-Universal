<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Core;

use FavoriteCMS\Core\AccountMenu;
use FavoriteCMS\Core\Hook;
use PHPUnit\Framework\TestCase;

class AccountMenuTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AccountMenu::reset();
        Hook::removeAction('account_menu_init');
        Hook::removeFilter('account_menu_items');
        Hook::removeFilter('render_account_menu');
        Hook::removeAction('plugin.deactivated');
        Hook::removeAction('plugin.uninstalled');
    }

    protected function tearDown(): void
    {
        AccountMenu::reset();
        Hook::removeAction('account_menu_init');
        Hook::removeFilter('account_menu_items');
        Hook::removeFilter('render_account_menu');
        Hook::removeAction('plugin.deactivated');
        Hook::removeAction('plugin.uninstalled');
        parent::tearDown();
    }

    /**
     * Create a mock user object with customizable permissions and roles.
     */
    protected function createMockUser(
        int $id = 1,
        string $name = 'Test User',
        string $username = 'testuser',
        array $permissions = ['manage_options', 'publish_posts'],
        ?string $avatar = null
    ): object {
        return new class($id, $name, $username, $permissions, $avatar) {
            public int $id;
            public string $name;
            public string $username;
            public ?string $avatar;
            protected array $permissions;

            public function __construct(int $id, string $name, string $username, array $permissions, ?string $avatar)
            {
                $this->id = $id;
                $this->name = $name;
                $this->username = $username;
                $this->permissions = $permissions;
                $this->avatar = $avatar;
            }

            public function hasPermission(string $slug): bool
            {
                return in_array($slug, $this->permissions, true);
            }

            public function getRoles(): array
            {
                $role = new \stdClass();
                $role->name = 'Administrator';
                return [$role];
            }
        };
    }

    // -------------------------------------------------------------------------
    // 1. Core Default Items & Authenticated Access
    // -------------------------------------------------------------------------

    public function testCoreDefaultItemsRegisteredForAuthenticatedUser(): void
    {
        $user = $this->createMockUser();
        $items = AccountMenu::getItems($user);

        $this->assertNotEmpty($items);
        $this->assertArrayHasKey('profile', $items);
        $this->assertArrayHasKey('account-settings', $items);
        $this->assertArrayHasKey('dashboard', $items);
        $this->assertArrayHasKey('logout', $items);

        $this->assertSame('Profile', $items['profile']['label']);
        $this->assertSame('/admin/users/profile', $items['profile']['url']);
        $this->assertSame('core', $items['profile']['plugin']);

        $this->assertSame('Log Out', $items['logout']['label']);
        $this->assertSame('/admin/logout', $items['logout']['url']);
        $this->assertSame(100, $items['logout']['order']);
    }

    // -------------------------------------------------------------------------
    // 2. Guest User Isolation (Security)
    // -------------------------------------------------------------------------

    public function testGuestUsersDoNotReceiveAccountActions(): void
    {
        // Null user = unauthenticated guest
        $items = AccountMenu::getItems(null);
        $this->assertSame([], $items);
        $this->assertFalse(has_account_menu_items(null));

        $rendered = AccountMenu::render(['user' => null]);
        $this->assertSame('', $rendered);
    }

    // -------------------------------------------------------------------------
    // 3. Plugin Registration API
    // -------------------------------------------------------------------------

    public function testPluginCanRegisterAccountMenuItem(): void
    {
        $registered = AccountMenu::registerItem([
            'id'         => 'my-custom-item',
            'label'      => 'Custom Feature',
            'url'        => '/my-feature',
            'icon'       => 'star',
            'order'      => 45,
            'capability' => 'publish_posts',
            'plugin'     => 'test-plugin',
        ]);

        $this->assertTrue($registered);
        $this->assertTrue(AccountMenu::hasItem('my-custom-item'));

        $item = AccountMenu::getItem('my-custom-item');
        $this->assertNotNull($item);
        $this->assertSame('my-custom-item', $item['id']);
        $this->assertSame('Custom Feature', $item['label']);
        $this->assertSame('/my-feature', $item['url']);
        $this->assertSame('star', $item['icon']);
        $this->assertSame(45, $item['order']);
        $this->assertSame('publish_posts', $item['capability']);
        $this->assertSame('test-plugin', $item['plugin']);
    }

    public function testHelperFunctionRegisterAccountMenuItem(): void
    {
        $registered = register_account_menu_item([
            'id'     => 'helper-item',
            'label'  => 'Helper Item',
            'url'    => '/helper',
            'plugin' => 'sample-plugin',
        ]);

        $this->assertTrue($registered);
        $this->assertTrue(AccountMenu::hasItem('helper-item'));
    }

    // -------------------------------------------------------------------------
    // 4. Ordering & Placement in Menu
    // -------------------------------------------------------------------------

    public function testItemsAreSortedByOrderAscending(): void
    {
        $user = $this->createMockUser();

        AccountMenu::registerItem([
            'id'     => 'early-item',
            'label'  => 'Early Action',
            'url'    => '/early',
            'order'  => 5,
            'plugin' => 'early-plugin',
        ]);

        AccountMenu::registerItem([
            'id'     => 'middle-item',
            'label'  => 'Middle Action',
            'url'    => '/middle',
            'order'  => 15,
            'plugin' => 'mid-plugin',
        ]);

        $items = AccountMenu::getItems($user);
        $keys = array_keys($items);

        // 'early-item' (order 5) should appear before 'profile' (order 10)
        $this->assertSame('early-item', $keys[0]);
        // 'middle-item' (order 15) should appear between 'profile' (10) and 'account-settings' (20)
        $this->assertSame('profile', $keys[1]);
        $this->assertSame('middle-item', $keys[2]);
        $this->assertSame('account-settings', $keys[3]);
    }

    // -------------------------------------------------------------------------
    // 5. Unregister & Plugin Deactivation Purging
    // -------------------------------------------------------------------------

    public function testPluginCanUnregisterItem(): void
    {
        AccountMenu::registerItem([
            'id'     => 'to-remove',
            'label'  => 'Temporary Item',
            'url'    => '/temp',
            'plugin' => 'temp-plugin',
        ]);

        $this->assertTrue(AccountMenu::hasItem('to-remove'));
        $removed = unregister_account_menu_item('to-remove');
        $this->assertTrue($removed);
        $this->assertFalse(AccountMenu::hasItem('to-remove'));
    }

    public function testDeactivatingPluginPurgesAllItsMenuItems(): void
    {
        AccountMenu::registerItem([
            'id'     => 'plugin-item-1',
            'label'  => 'Plugin Item 1',
            'url'    => '/item-1',
            'plugin' => 'ext-plugin',
        ]);

        AccountMenu::registerItem([
            'id'     => 'plugin-item-2',
            'label'  => 'Plugin Item 2',
            'url'    => '/item-2',
            'plugin' => 'ext-plugin',
        ]);

        AccountMenu::registerItem([
            'id'     => 'other-plugin-item',
            'label'  => 'Other Plugin Item',
            'url'    => '/other',
            'plugin' => 'other-plugin',
        ]);

        $this->assertTrue(AccountMenu::hasItem('plugin-item-1'));
        $this->assertTrue(AccountMenu::hasItem('plugin-item-2'));
        $this->assertTrue(AccountMenu::hasItem('other-plugin-item'));

        // Fire deactivation hook
        Hook::doAction('plugin.deactivated', 'ext-plugin');

        $this->assertFalse(AccountMenu::hasItem('plugin-item-1'));
        $this->assertFalse(AccountMenu::hasItem('plugin-item-2'));
        $this->assertTrue(AccountMenu::hasItem('other-plugin-item'));
    }

    public function testUninstallingPluginPurgesAllItsMenuItems(): void
    {
        AccountMenu::registerItem([
            'id'     => 'to-uninstall',
            'label'  => 'Uninstall Me',
            'url'    => '/uninstall',
            'plugin' => 'uninstalled-plugin',
        ]);

        $this->assertTrue(AccountMenu::hasItem('to-uninstall'));

        Hook::doAction('plugin.uninstalled', 'uninstalled-plugin');

        $this->assertFalse(AccountMenu::hasItem('to-uninstall'));
    }

    // -------------------------------------------------------------------------
    // 6. Capability Restrictions
    // -------------------------------------------------------------------------

    public function testCapabilityRestrictionsHideItemsFromUnauthorizedUsers(): void
    {
        AccountMenu::registerItem([
            'id'         => 'restricted-item',
            'label'      => 'Admin Only',
            'url'        => '/secret',
            'capability' => 'manage_options',
            'plugin'     => 'security-plugin',
        ]);

        // User without manage_options
        $regularUser = $this->createMockUser(2, 'Regular User', 'regular', ['read_only']);
        $itemsForRegular = AccountMenu::getItems($regularUser);
        $this->assertArrayNotHasKey('restricted-item', $itemsForRegular);
        $this->assertArrayNotHasKey('dashboard', $itemsForRegular); // dashboard also requires manage_options

        // User with manage_options
        $adminUser = $this->createMockUser(1, 'Admin', 'admin', ['manage_options']);
        $itemsForAdmin = AccountMenu::getItems($adminUser);
        $this->assertArrayHasKey('restricted-item', $itemsForAdmin);
        $this->assertArrayHasKey('dashboard', $itemsForAdmin);
    }

    // -------------------------------------------------------------------------
    // 7. Dynamic Visibility Conditions
    // -------------------------------------------------------------------------

    public function testCallableConditionFiltersItem(): void
    {
        $user = $this->createMockUser(10, 'Special User');

        AccountMenu::registerItem([
            'id'        => 'conditional-pass',
            'label'     => 'Allowed Condition',
            'url'       => '/pass',
            'condition' => fn($u) => $u->id === 10,
        ]);

        AccountMenu::registerItem([
            'id'        => 'conditional-fail',
            'label'     => 'Denied Condition',
            'url'       => '/fail',
            'condition' => fn($u) => $u->id === 999,
        ]);

        $items = AccountMenu::getItems($user);
        $this->assertArrayHasKey('conditional-pass', $items);
        $this->assertArrayNotHasKey('conditional-fail', $items);
    }

    public function testBooleanConditionFiltersItem(): void
    {
        $user = $this->createMockUser();

        AccountMenu::registerItem([
            'id'        => 'flag-disabled',
            'label'     => 'Disabled Feature',
            'url'       => '/disabled',
            'condition' => false,
        ]);

        $items = AccountMenu::getItems($user);
        $this->assertArrayNotHasKey('flag-disabled', $items);
    }

    // -------------------------------------------------------------------------
    // 8. Security & Input Validation (XSS Prevention, Dangerous URLs)
    // -------------------------------------------------------------------------

    public function testEmptyOrInvalidIdRejected(): void
    {
        $this->assertFalse(AccountMenu::registerItem([
            'id'    => '',
            'label' => 'No ID',
            'url'   => '/test',
        ]));

        $this->assertFalse(AccountMenu::registerItem([
            'id'    => 'invalid id with spaces!',
            'label' => 'Invalid ID',
            'url'   => '/test',
        ]));
    }

    public function testEmptyLabelRejected(): void
    {
        $this->assertFalse(AccountMenu::registerItem([
            'id'    => 'no-label',
            'label' => '   ',
            'url'   => '/test',
        ]));
    }

    public function testXssInLabelIsSanitized(): void
    {
        AccountMenu::registerItem([
            'id'    => 'xss-label',
            'label' => '<script>alert("xss")</script>Clean Label<b>Bold</b>',
            'url'   => '/clean',
        ]);

        $item = AccountMenu::getItem('xss-label');
        $this->assertNotNull($item);
        $this->assertSame('alert("xss")Clean LabelBold', $item['label']);
        $this->assertStringNotContainsString('<script>', $item['label']);
    }

    public function testDangerousUrlsAreStrictlyRejected(): void
    {
        // javascript: scheme
        $this->assertFalse(AccountMenu::registerItem([
            'id'    => 'bad-js',
            'label' => 'Malicious JS',
            'url'   => 'javascript:alert(document.cookie)',
        ]));

        // data: scheme
        $this->assertFalse(AccountMenu::registerItem([
            'id'    => 'bad-data',
            'label' => 'Malicious Data',
            'url'   => 'data:text/html,<script>alert(1)</script>',
        ]));

        // vbscript: scheme
        $this->assertFalse(AccountMenu::registerItem([
            'id'    => 'bad-vb',
            'label' => 'Malicious VBScript',
            'url'   => 'vbscript:msgbox(1)',
        ]));

        // file: scheme
        $this->assertFalse(AccountMenu::registerItem([
            'id'    => 'bad-file',
            'label' => 'Local File',
            'url'   => 'file:///etc/passwd',
        ]));

        // Protocol-relative URL
        $this->assertFalse(AccountMenu::registerItem([
            'id'    => 'bad-protocol-relative',
            'label' => 'Protocol Relative',
            'url'   => '//attacker.com/evil',
        ]));
    }

    public function testSafeUrlsAreAccepted(): void
    {
        $this->assertTrue(AccountMenu::registerItem([
            'id'    => 'safe-relative',
            'label' => 'Relative Link',
            'url'   => '/my/account/path',
        ]));

        $this->assertTrue(AccountMenu::registerItem([
            'id'    => 'safe-https',
            'label' => 'External HTTPS',
            'url'   => 'https://example.com/portal',
        ]));

        $this->assertTrue(AccountMenu::registerItem([
            'id'    => 'safe-http',
            'label' => 'External HTTP',
            'url'   => 'http://example.com/help',
        ]));
    }

    // -------------------------------------------------------------------------
    // 9. Filter and Action Hooks
    // -------------------------------------------------------------------------

    public function testFilterHookCanModifyAccountMenuItems(): void
    {
        $user = $this->createMockUser();

        Hook::addFilter('account_menu_items', function (array $items, $u = null) {
            $items['filtered-item'] = [
                'id'         => 'filtered-item',
                'label'      => 'Injected via Filter',
                'url'        => '/filtered',
                'icon'       => 'filter',
                'order'      => 99,
                'capability' => null,
                'plugin'     => 'hook-plugin',
                'condition'  => null,
            ];
            return $items;
        }, 10, 2);

        $items = AccountMenu::getItems($user);
        $this->assertArrayHasKey('filtered-item', $items);
        $this->assertSame('Injected via Filter', $items['filtered-item']['label']);
    }

    public function testActionHookTriggersOnInit(): void
    {
        $user = $this->createMockUser();
        $triggered = false;

        Hook::addAction('account_menu_init', function ($u) use (&$triggered) {
            $triggered = true;
            register_account_menu_item([
                'id'     => 'hook-registered',
                'label'  => 'Registered On Hook',
                'url'    => '/hook-page',
                'plugin' => 'hook-plugin',
            ]);
        });

        $items = AccountMenu::getItems($user);
        $this->assertTrue($triggered);
        $this->assertArrayHasKey('hook-registered', $items);
    }

    // -------------------------------------------------------------------------
    // 10. Markup Rendering & Accessibility
    // -------------------------------------------------------------------------

    public function testRenderOutputsAccessibleMarkupForAuthenticatedUser(): void
    {
        $user = $this->createMockUser(1, 'Jane Doe', 'janedoe');
        $html = AccountMenu::render(['user' => $user]);

        $this->assertStringContainsString('class="cms-account-menu"', $html);
        $this->assertStringContainsString('class="cms-account-trigger"', $html);
        $this->assertStringContainsString('aria-haspopup="true"', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);
        $this->assertStringContainsString('role="menu"', $html);
        $this->assertStringContainsString('role="menuitem"', $html);
        $this->assertStringContainsString('Jane Doe', $html);
        $this->assertStringContainsString('Profile', $html);
        $this->assertStringContainsString('/admin/users/profile', $html);
        $this->assertStringContainsString('Log Out', $html);
        $this->assertStringContainsString('/admin/logout', $html);
    }

    public function testUserHelperFunctions(): void
    {
        $user = $this->createMockUser(5, 'Alex Smith', 'alex', [], '/uploads/avatars/alex.jpg');

        $this->assertSame('Alex Smith', get_user_display_name($user));
        $this->assertSame('/uploads/avatars/alex.jpg', get_user_avatar_url($user));
        $this->assertSame('Guest', get_user_display_name(null));
        $this->assertNull(get_user_avatar_url(null));
    }
}
