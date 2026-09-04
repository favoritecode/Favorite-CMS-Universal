<?php

declare(strict_types=1);

namespace FavoriteCMS\Http\Controllers\Admin;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Page;
use FavoriteCMS\Models\Media;
use FavoriteCMS\Models\User;
use FavoriteCMS\Services\ContentSanitizer;

class PageController
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function index(Request $request): Response
    {
        $db = $this->app->make(Database::class);
        $status = $request->get('status', 'all');
        $search = trim((string)$request->get('s', ''));

        $query = "SELECT * FROM `pages` WHERE 1=1";
        $params = [];

        if ($status !== 'all') {
            $query .= " AND `status` = ?";
            $params[] = $status;
        } else {
            $query .= " AND `status` != 'trash'";
        }

        if ($search !== '') {
            $query .= " AND (`title` LIKE ? OR `content` LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $query .= " ORDER BY `menu_order` ASC, `created_at` DESC";
        $pages = array_map(fn($row) => new Page((array)$row), $db->select($query, $params));
        $counts = Page::countByStatus();

        $viewData = [
            'pageTitle'   => 'Pages',
            'activeMenu'  => 'pages',
            'pages'       => $pages,
            'counts'      => $counts,
            'status'      => $status,
            'search'      => $search,
            'contentView' => APP_ROOT . '/resources/views/admin/pages/index.php',
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
    }

    public function create(Request $request): Response
    {
        $allPages = Page::all();
        $mediaItems = Media::all();

        $viewData = [
            'pageTitle'   => 'Add New Page',
            'activeMenu'  => 'pages-new',
            'page'        => null,
            'allPages'    => $allPages,
            'mediaItems'  => $mediaItems,
            'contentView' => APP_ROOT . '/resources/views/admin/pages/edit.php',
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
    }

    public function store(Request $request): Response
    {
        $title    = trim((string)$request->post('title', ''));
        $content  = (string)$request->post('content', '');
        $status   = (string)$request->post('status', 'draft');
        $slug     = trim((string)$request->post('slug', ''));
        $parentId = (int)$request->post('parent_id', 0);
        $featImg  = (int)$request->post('featured_image_id', 0);
        $order    = (int)$request->post('menu_order', 0);

        if ($title === '') {
            $_SESSION['flash_error'] = 'Page title cannot be empty.';
            return Response::redirect('/admin/pages/new');
        }

        $pageModel = new Page();
        $finalSlug = $slug !== '' ? str_slug($slug) : $pageModel->generateSlug($title);
        $authorId = (int)($_SESSION['auth_user_id'] ?? 1);
        $content  = ContentSanitizer::clean($content, $authorId);
        $now = date('Y-m-d H:i:s');

        $db = $this->app->make(Database::class);
        $pageId = $db->insert('pages', [
            'title'             => $title,
            'slug'              => $finalSlug,
            'content'           => $content,
            'status'            => $status,
            'parent_id'         => $parentId > 0 ? $parentId : null,
            'author_id'         => $authorId,
            'featured_image_id' => $featImg > 0 ? $featImg : null,
            'menu_order'        => $order,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        $page = Page::find($pageId);
        $page->saveSeoMeta([
            'meta_title'       => trim((string)$request->post('meta_title', '')),
            'meta_description' => trim((string)$request->post('meta_description', '')),
            'og_title'         => trim((string)$request->post('og_title', '')),
            'og_description'   => trim((string)$request->post('og_description', '')),
        ]);

        $_SESSION['flash_success'] = 'Page created successfully.';
        return Response::redirect('/admin/pages/edit?id=' . $pageId);
    }

    public function edit(Request $request): Response
    {
        $id = (int)$request->get('id', 0);
        $page = Page::find($id);
        if (!$page) {
            $_SESSION['flash_error'] = 'Page not found.';
            return Response::redirect('/admin/pages');
        }

        $allPages = Page::all();
        $mediaItems = Media::all();
        $seo = $page->getSeoMeta();

        $viewData = [
            'pageTitle'   => 'Edit Page',
            'activeMenu'  => 'pages',
            'page'        => $page,
            'allPages'    => $allPages,
            'mediaItems'  => $mediaItems,
            'seo'         => $seo,
            'contentView' => APP_ROOT . '/resources/views/admin/pages/edit.php',
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
    }

    public function update(Request $request): Response
    {
        $id = (int)$request->post('id', 0);
        $page = Page::find($id);
        if (!$page) {
            $_SESSION['flash_error'] = 'Page not found.';
            return Response::redirect('/admin/pages');
        }

        $title    = trim((string)$request->post('title', ''));
        $content  = (string)$request->post('content', '');
        $status   = (string)$request->post('status', 'draft');
        $slug     = trim((string)$request->post('slug', ''));
        $parentId = (int)$request->post('parent_id', 0);
        $featImg  = (int)$request->post('featured_image_id', 0);
        $order    = (int)$request->post('menu_order', 0);

        if ($title === '') {
            $_SESSION['flash_error'] = 'Page title cannot be empty.';
            return Response::redirect('/admin/pages/edit?id=' . $id);
        }

        $finalSlug = $slug !== '' ? str_slug($slug) : $page->slug;
        $authorId = (int)($_SESSION['auth_user_id'] ?? $page->author_id ?? 1);
        $content  = ContentSanitizer::clean($content, $authorId);

        $page->update([
            'title'             => $title,
            'slug'              => $finalSlug,
            'content'           => $content,
            'status'            => $status,
            'parent_id'         => $parentId > 0 && $parentId !== $id ? $parentId : null,
            'featured_image_id' => $featImg > 0 ? $featImg : null,
            'menu_order'        => $order,
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);

        $page->saveSeoMeta([
            'meta_title'       => trim((string)$request->post('meta_title', '')),
            'meta_description' => trim((string)$request->post('meta_description', '')),
            'og_title'         => trim((string)$request->post('og_title', '')),
            'og_description'   => trim((string)$request->post('og_description', '')),
        ]);

        $_SESSION['flash_success'] = 'Page updated successfully.';
        return Response::redirect('/admin/pages/edit?id=' . $id);
    }

    public function preview(Request $request): Response
    {
        $title   = trim((string)$request->post('title', 'Untitled Preview'));
        $content = (string)$request->post('content', '');
        $featImgId = (int)$request->post('featured_image_id', 0);

        $authorId = (int)($_SESSION['auth_user_id'] ?? 1);
        $cleanedContent = ContentSanitizer::clean($content, $authorId);

        $fakePage = new Page([
            'id'                => 0,
            'title'             => $title ?: 'Preview',
            'slug'              => 'preview',
            'content'           => $cleanedContent,
            'status'            => 'draft',
            'author_id'         => $authorId,
            'featured_image_id' => $featImgId > 0 ? $featImgId : null,
            'created_at'        => date('Y-m-d H:i:s'),
        ]);

        $engine = new \FavoriteCMS\Rendering\Engine($this->app);
        $html = $engine->render('page', [
            'page'            => $fakePage,
            'metaTitle'       => 'Preview: ' . $title,
            'metaDescription' => '',
            'isPreview'       => true,
        ]);

        return Response::make($html, 200);
    }

    public function trash(Request $request): Response
    {
        $id = (int)$request->get('id', 0);
        $page = Page::find($id);
        if ($page) {
            $page->update(['status' => 'trash', 'updated_at' => date('Y-m-d H:i:s')]);
            $_SESSION['flash_success'] = 'Page moved to trash.';
        }
        return Response::redirect('/admin/pages');
    }

    public function restore(Request $request): Response
    {
        $id = (int)$request->get('id', 0);
        $page = Page::find($id);
        if ($page) {
            $page->update(['status' => 'draft', 'updated_at' => date('Y-m-d H:i:s')]);
            $_SESSION['flash_success'] = 'Page restored from trash.';
        }
        return Response::redirect('/admin/pages?status=trash');
    }

    public function delete(Request $request): Response
    {
        $id = (int)$request->get('id', 0);
        $page = Page::find($id);
        if ($page) {
            $db = $this->app->make(Database::class);
            $db->execute("DELETE FROM `seo_meta` WHERE `object_type` = 'page' AND `object_id` = ?", [$id]);
            $page->delete();
            $_SESSION['flash_success'] = 'Page permanently deleted.';
        }
        return Response::redirect('/admin/pages?status=trash');
    }

    public function bulkAction(Request $request): Response
    {
        $token = (string)$request->post('_token', '');
        if (empty($_SESSION['_token']) || !hash_equals($_SESSION['_token'], $token)) {
            $_SESSION['flash_error'] = 'Security verification failed (invalid CSRF token).';
            return Response::redirect('/admin/pages');
        }

        $currentUser = isset($_SESSION['auth_user_id']) ? User::find((int)$_SESSION['auth_user_id']) : null;
        if (!$currentUser || !$currentUser->isActive()) {
            $_SESSION['flash_error'] = 'Your account is inactive or suspended.';
            return Response::redirect('/admin/pages');
        }

        if (!$currentUser->canManagePages()) {
            $_SESSION['flash_error'] = 'You do not have permission to manage pages.';
            return Response::redirect('/admin/pages');
        }

        $action = trim((string)$request->post('bulk_action', ''));
        $rawIds = (array)$request->post('ids', []);
        $ids = array_filter(array_map('intval', $rawIds), fn($id) => $id > 0);
        $redirectStatus = trim((string)$request->post('status', ''));
        $redirectUrl = '/admin/pages' . ($redirectStatus !== '' ? '?status=' . urlencode($redirectStatus) : '');

        if (empty($action) || empty($ids)) {
            $_SESSION['flash_error'] = 'Please select at least one page and a bulk action.';
            return Response::redirect($redirectUrl);
        }

        $db = $this->app->make(Database::class);
        $count = 0;

        foreach ($ids as $id) {
            $page = Page::find($id);
            if (!$page) {
                continue;
            }

            switch ($action) {
                case 'trash':
                    $page->update(['status' => 'trash', 'updated_at' => date('Y-m-d H:i:s')]);
                    $count++;
                    break;
                case 'restore':
                    $page->update(['status' => 'draft', 'updated_at' => date('Y-m-d H:i:s')]);
                    $count++;
                    break;
                case 'delete':
                    $db->execute("DELETE FROM `seo_meta` WHERE `object_type` = 'page' AND `object_id` = ?", [$id]);
                    $page->delete();
                    $count++;
                    break;
            }
        }

        if ($count > 0) {
            $label = match ($action) {
                'trash'   => 'moved to trash',
                'restore' => 'restored from trash',
                'delete'  => 'permanently deleted',
                default   => 'processed',
            };
            $_SESSION['flash_success'] = "{$count} page(s) successfully {$label}.";
        } else {
            $_SESSION['flash_error'] = 'No pages were updated.';
        }

        return Response::redirect($redirectUrl);
    }
}

