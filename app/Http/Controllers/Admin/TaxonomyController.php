<?php

declare(strict_types=1);

namespace FavoriteCMS\Http\Controllers\Admin;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Taxonomy;

class TaxonomyController
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function categories(Request $request): Response
    {
        return $this->renderTaxonomy($request, 'category', 'Categories', 'categories');
    }

    public function tags(Request $request): Response
    {
        return $this->renderTaxonomy($request, 'tag', 'Tags', 'tags');
    }

    /** Terms shown per list page. */
    protected const PER_PAGE = 12;

    protected function renderTaxonomy(Request $request, string $taxonomyType, string $title, string $activeMenu): Response
    {
        $db = $this->app->make(Database::class);

        $totalItems  = (int)($db->selectOne("SELECT COUNT(*) AS cnt FROM `taxonomies` WHERE `taxonomy` = ?", [$taxonomyType])->cnt ?? 0);
        $totalPages  = max(1, (int)ceil($totalItems / self::PER_PAGE));
        $currentPage = min(max(1, (int)$request->get('p', 1)), $totalPages);
        $pageSql     = "SELECT * FROM `taxonomies` WHERE `taxonomy` = ? ORDER BY `id` ASC LIMIT ? OFFSET ?";
        $pageParams  = [$taxonomyType, self::PER_PAGE, ($currentPage - 1) * self::PER_PAGE];

        // Update post counts for accuracy (only for the displayed, bounded page of terms)
        foreach ($db->select($pageSql, $pageParams) as $row) {
            (new Taxonomy((array)$row))->updatePostCount();
        }
        $items = array_map(fn($row) => new Taxonomy((array)$row), $db->select($pageSql, $pageParams));

        // Lightweight parent options (id and name only) for the "Parent Category" selector
        $parentOptions = $taxonomyType === 'category'
            ? array_map(fn($row) => new Taxonomy((array)$row), $db->select("SELECT `id`, `name` FROM `taxonomies` WHERE `taxonomy` = 'category' ORDER BY `name` ASC"))
            : [];

        $viewData = [
            'pageTitle'    => $title,
            'activeMenu'   => $activeMenu,
            'taxonomyType' => $taxonomyType,
            'items'        => $items,
            'parentOptions' => $parentOptions,
            'currentPage'  => $currentPage,
            'totalPages'   => $totalPages,
            'totalItems'   => $totalItems,
            'contentView'  => APP_ROOT . '/resources/views/admin/taxonomies/index.php',
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
    }

    public function store(Request $request): Response
    {
        $name        = trim((string)$request->post('name', ''));
        $slug        = trim((string)$request->post('slug', ''));
        $taxonomy    = (string)$request->post('taxonomy', 'category');
        $description = trim((string)$request->post('description', ''));
        $parentId    = (int)$request->post('parent_id', 0);

        if ($name === '') {
            $_SESSION['flash_error'] = 'Name is required.';
            return Response::redirect('/admin/taxonomies/' . ($taxonomy === 'tag' ? 'tags' : 'categories'));
        }

        $finalSlug = $slug !== '' ? str_slug($slug) : str_slug($name);
        if ($finalSlug === '') {
            $finalSlug = $taxonomy . '-' . bin2hex(random_bytes(2));
        }

        // Check unique slug
        $db = $this->app->make(Database::class);
        $existing = $db->selectOne("SELECT id FROM `taxonomies` WHERE `slug` = ? AND `taxonomy` = ?", [$finalSlug, $taxonomy]);
        if ($existing) {
            $finalSlug .= '-' . bin2hex(random_bytes(2));
        }

        $now = date('Y-m-d H:i:s');
        $db->insert('taxonomies', [
            'name'        => $name,
            'slug'        => $finalSlug,
            'taxonomy'    => $taxonomy,
            'description' => $description,
            'parent_id'   => $parentId > 0 ? $parentId : null,
            'post_count'  => 0,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $_SESSION['flash_success'] = ucfirst($taxonomy) . ' added successfully.';
        return Response::redirect('/admin/taxonomies/' . ($taxonomy === 'tag' ? 'tags' : 'categories'));
    }

    public function delete(Request $request): Response
    {
        $token = (string)($request->get('_token', $request->post('_token', '')));
        $sessionToken = (string)($_SESSION['_token'] ?? '');
        if ($token === '' || !hash_equals($sessionToken, $token)) {
            $_SESSION['flash_error'] = 'Security verification failed (invalid CSRF token). Please try again.';
            return Response::redirect('/admin/taxonomies/categories');
        }

        $id = (int)$request->get('id', 0);
        $tax = Taxonomy::find($id);
        if ($tax) {
            $taxonomyType = $tax->taxonomy;
            // Prevent deleting default uncategorized
            if ($tax->slug === 'uncategorized') {
                $_SESSION['flash_error'] = 'Default category cannot be deleted.';
                return Response::redirect('/admin/taxonomies/categories');
            }

            $db = $this->app->make(Database::class);
            $db->execute("DELETE FROM `post_taxonomies` WHERE `taxonomy_id` = ?", [$id]);
            $tax->delete();
            $_SESSION['flash_success'] = 'Item deleted successfully.';
            return Response::redirect('/admin/taxonomies/' . ($taxonomyType === 'tag' ? 'tags' : 'categories'));
        }

        return Response::redirect('/admin/taxonomies/categories');
    }
}

