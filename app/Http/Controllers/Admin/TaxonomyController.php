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

    protected function renderTaxonomy(Request $request, string $taxonomyType, string $title, string $activeMenu): Response
    {
        $db = $this->app->make(Database::class);
        $items = Taxonomy::getByTaxonomy($taxonomyType);

        // Update post counts for accuracy
        foreach ($items as $item) {
            $item->updatePostCount();
        }
        $items = Taxonomy::getByTaxonomy($taxonomyType);

        $viewData = [
            'pageTitle'    => $title,
            'activeMenu'   => $activeMenu,
            'taxonomyType' => $taxonomyType,
            'items'        => $items,
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

