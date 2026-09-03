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

        $menuItems = $selectedMenu ? $selectedMenu->getItems() : [];
        $pages = Page::published();

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
        $db->execute("DELETE FROM `menu_items` WHERE `id` = ?", [$itemId]);

        $_SESSION['flash_success'] = 'Menu item removed.';
        return Response::redirect('/admin/menus?menu=' . $menuId);
    }

    public function saveLocation(Request $request): Response
    {
        $menuId   = (int)$request->post('menu_id', 0);
        $location = trim((string)$request->post('location', ''));

        $db = $this->app->make(Database::class);
        if ($location !== '') {
            // Unassign location from others
            $db->execute("UPDATE `menus` SET `location` = NULL WHERE `location` = ?", [$location]);
        }
        $db->execute("UPDATE `menus` SET `location` = ? WHERE `id` = ?", [$location !== '' ? $location : null, $menuId]);

        $_SESSION['flash_success'] = 'Menu location updated.';
        return Response::redirect('/admin/menus?menu=' . $menuId);
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

