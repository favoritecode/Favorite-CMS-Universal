<?php

declare(strict_types=1);

namespace FavoriteCMS\Http\Controllers\Admin;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Menu;
use FavoriteCMS\Models\Page;

class MenuController
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function index(Request $request): Response
    {
        $menus = Menu::all();
        $selectedMenuId = (int)$request->get('menu', 0);

        $selectedMenu = null;
        if ($selectedMenuId > 0) {
            $selectedMenu = Menu::find($selectedMenuId);
        }
        if (!$selectedMenu && !empty($menus)) {
            $selectedMenu = $menus[0];
        }

        $menuItems = $selectedMenu ? $selectedMenu->getFlatTree() : [];
        // Lightweight rows (title/slug only) instead of full page content for the "Add from Pages" list
        $pages = Page::summaries('published');

        $viewData = [
            'pageTitle'    => 'Menus',
            'activeMenu'   => 'menus',
            'menus'        => $menus,
            'selectedMenu' => $selectedMenu,
            'menuItems'    => $menuItems,
            'pages'        => $pages,
            'contentView'  => APP_ROOT . '/resources/views/admin/menus/index.php',
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
    }

    public function createMenu(Request $request): Response
    {
        $name = trim((string)$request->post('name', ''));
        if ($name === '') {
            $_SESSION['flash_error'] = 'Menu name is required.';
            return Response::redirect('/admin/menus');
        }

        $slug = str_slug($name);
        $db = $this->app->make(Database::class);

        $now = date('Y-m-d H:i:s');
        $id = $db->insert('menus', [
            'name'       => $name,
            'slug'       => $slug,
            'location'   => $request->post('location', null),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $_SESSION['flash_success'] = 'Menu created.';
        return Response::redirect('/admin/menus?menu=' . $id);
    }

    public function addItem(Request $request): Response
    {
        $menuId = (int)$request->post('menu_id', 0);
        $title  = trim((string)$request->post('title', ''));
        $url    = trim((string)$request->post('url', ''));

        if ($menuId <= 0 || $title === '') {
            $_SESSION['flash_error'] = 'Title and menu are required.';
            return Response::redirect('/admin/menus?menu=' . $menuId);
        }

        $db = $this->app->make(Database::class);
        $maxOrder = (int)($db->selectOne("SELECT MAX(sort_order) as m FROM menu_items WHERE menu_id = ?", [$menuId])->m ?? 0);

        $now = date('Y-m-d H:i:s');
        $db->insert('menu_items', [
            'menu_id'    => $menuId,
            'title'      => $title,
            'url'        => $url !== '' ? $url : '#',
            'type'       => 'custom',
            'sort_order' => $maxOrder + 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $_SESSION['flash_success'] = 'Menu item added.';
        return Response::redirect('/admin/menus?menu=' . $menuId);
    }

    public function deleteItem(Request $request): Response
    {
        $itemId = (int)$request->get('id', 0);
        $menuId = (int)$request->get('menu', 0);

        $db = $this->app->make(Database::class);
        // Promote any direct children to top-level so they are not orphaned
        $db->execute("UPDATE `menu_items` SET `parent_id` = NULL WHERE `parent_id` = ?", [$itemId]);
        $db->execute("DELETE FROM `menu_items` WHERE `id` = ?", [$itemId]);

        $_SESSION['flash_success'] = 'Menu item removed.';
        return Response::redirect('/admin/menus?menu=' . $menuId);
    }

    public function saveMenu(Request $request): Response
    {
        $menuId = (int)$request->post('menu_id', 0);
        if ($menuId <= 0) {
            $_SESSION['flash_error'] = 'Invalid menu.';
            return Response::redirect('/admin/menus');
        }

        $db = $this->app->make(Database::class);
        $menu = Menu::find($menuId);
        if (!$menu) {
            $_SESSION['flash_error'] = 'Menu not found.';
            return Response::redirect('/admin/menus');
        }

        // Save menu location
        $location = trim((string)$request->post('location', ''));
        if ($location !== '') {
            $db->execute("UPDATE `menus` SET `location` = NULL WHERE `location` = ?", [$location]);
            $db->execute("UPDATE `menus` SET `location` = ? WHERE `id` = ?", [$location, $menuId]);
        } else {
            $db->execute("UPDATE `menus` SET `location` = NULL WHERE `id` = ?", [$menuId]);
        }

        // Fetch all existing items belonging to this menu
        $existingItems = $db->select("SELECT id FROM `menu_items` WHERE `menu_id` = ?", [$menuId]);
        $validItemIds = array_flip(array_map(fn($r) => (int)$r->id, $existingItems));

        // Process items hierarchy and attribute updates
        $incomingItems = $request->post('items', []);
        if (is_array($incomingItems) && !empty($incomingItems)) {
            $sanitized = [];
            $parentMap = [];

            foreach ($incomingItems as $index => $itemData) {
                $itemId = (int)($itemData['id'] ?? 0);
                if ($itemId <= 0 || !isset($validItemIds[$itemId])) {
                    continue;
                }

                $parentId = (int)($itemData['parent_id'] ?? 0);
                // Self-parenting check & valid parent check
                if ($parentId <= 0 || $parentId === $itemId || !isset($validItemIds[$parentId])) {
                    $parentId = null;
                }

                $title = trim((string)($itemData['title'] ?? ''));
                if ($title === '') {
                    $title = 'Menu Item';
                }

                $url = trim((string)($itemData['url'] ?? ''));
                if ($url === '') {
                    $url = '#';
                }

                $sortOrder = isset($itemData['sort_order']) ? (int)$itemData['sort_order'] : ((int)$index + 1);
                $target = in_array($itemData['target'] ?? '', ['_blank', '_self', '_parent', '_top'], true) ? $itemData['target'] : '_self';

                $sanitized[$itemId] = [
                    'id'         => $itemId,
                    'parent_id'  => $parentId,
                    'title'      => $title,
                    'url'        => $url,
                    'sort_order' => $sortOrder,
                    'target'     => $target,
                ];

                $parentMap[$itemId] = $parentId;
            }

            // Cycle detection pass: ensure no circular parent relationships (e.g. A -> B -> A)
            foreach ($sanitized as $itemId => &$item) {
                $curr = $item['parent_id'];
                $visited = [$itemId => true];
                while ($curr !== null && $curr > 0) {
                    if (isset($visited[$curr])) {
                        // Cycle detected! Break cycle by setting parent_id to null
                        $item['parent_id'] = null;
                        $parentMap[$itemId] = null;
                        break;
                    }
                    $visited[$curr] = true;
                    $curr = $parentMap[$curr] ?? null;
                }
            }
            unset($item);

            // Persist updated records
            $now = date('Y-m-d H:i:s');
            foreach ($sanitized as $item) {
                $db->execute(
                    "UPDATE `menu_items` SET `title` = ?, `url` = ?, `target` = ?, `parent_id` = ?, `sort_order` = ?, `updated_at` = ? WHERE `id` = ? AND `menu_id` = ?",
                    [
                        $item['title'],
                        $item['url'],
                        $item['target'],
                        $item['parent_id'],
                        $item['sort_order'],
                        $now,
                        $item['id'],
                        $menuId
                    ]
                );
            }

            // Remove any items that were removed in the UI
            $deletedItems = $request->post('deleted_items', []);
            if (is_array($deletedItems) && !empty($deletedItems)) {
                foreach ($deletedItems as $delId) {
                    $delId = (int)$delId;
                    if ($delId > 0 && isset($validItemIds[$delId])) {
                        $db->execute("UPDATE `menu_items` SET `parent_id` = NULL WHERE `parent_id` = ?", [$delId]);
                        $db->execute("DELETE FROM `menu_items` WHERE `id` = ? AND `menu_id` = ?", [$delId, $menuId]);
                    }
                }
            }
        }

        $_SESSION['flash_success'] = 'Menu updated.';
        return Response::redirect('/admin/menus?menu=' . $menuId);
    }

    public function saveLocation(Request $request): Response
    {
        return $this->saveMenu($request);
    }

    public function deleteMenu(Request $request): Response
    {
        $menuId = (int)$request->get('menu', 0);
        $db = $this->app->make(Database::class);
        $db->execute("DELETE FROM `menu_items` WHERE `menu_id` = ?", [$menuId]);
        $db->execute("DELETE FROM `menus` WHERE `id` = ?", [$menuId]);

        $_SESSION['flash_success'] = 'Menu deleted.';
        return Response::redirect('/admin/menus');
    }
}

