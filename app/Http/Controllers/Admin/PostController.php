<?php

declare(strict_types=1);

namespace FavoriteCMS\Http\Controllers\Admin;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Post;
use FavoriteCMS\Models\Taxonomy;
use FavoriteCMS\Models\Media;
use FavoriteCMS\Models\User;
use FavoriteCMS\Services\ContentSanitizer;

class PostController
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
        $page = max(1, (int)$request->get('p', 1));
        $perPage = 15;
        $offset = ($page - 1) * $perPage;

        $whereClause = "WHERE `type` = 'post'";
        $params = [];

        if ($status !== 'all') {
            $whereClause .= " AND `status` = ?";
            $params[] = $status;
        } else {
            $whereClause .= " AND `status` != 'trash'";
        }

        if ($search !== '') {
            $whereClause .= " AND (`title` LIKE ? OR `content` LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        // Count total for pagination
        $countSql = "SELECT COUNT(*) as cnt FROM `posts` {$whereClause}";
        $totalRow = $db->selectOne($countSql, $params);
        $totalItems = (int)($totalRow->cnt ?? 0);
        $totalPages = max(1, (int)ceil($totalItems / $perPage));

        // Fetch paginated posts
        $query = "SELECT * FROM `posts` {$whereClause} ORDER BY `created_at` DESC LIMIT ? OFFSET ?";
        $queryParams = array_merge($params, [$perPage, $offset]);
        $posts = array_map(fn($row) => new Post((array)$row), $db->select($query, $queryParams));
        $counts = Post::countByStatus();
        $currentUser = isset($_SESSION['auth_user_id']) ? User::find((int)$_SESSION['auth_user_id']) : null;

        $viewData = [
            'pageTitle'    => 'Posts',
            'activeMenu'   => 'posts',
            'posts'        => $posts,
            'counts'       => $counts,
            'status'       => $status,
            'search'       => $search,
            'currentPage'  => $page,
            'totalPages'   => $totalPages,
            'totalItems'   => $totalItems,
            'currentUser'  => $currentUser,
            'contentView'  => APP_ROOT . '/resources/views/admin/posts/index.php',
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
    }

    public function create(Request $request): Response
    {
        $currentUser = isset($_SESSION['auth_user_id']) ? User::find((int)$_SESSION['auth_user_id']) : null;
        if (!$currentUser || !$currentUser->canCreatePosts()) {
            $_SESSION['flash_error'] = 'Your account is suspended and cannot create new posts.';
            return Response::redirect('/admin/posts');
        }

        $categories = Taxonomy::getByTaxonomy('category');
        $mediaItems = Media::all();

        $viewData = [
            'pageTitle'    => 'Add New Post',
            'activeMenu'   => 'posts-new',
            'post'         => null,
            'categories'   => $categories,
            'selectedCats' => [],
            'tagsString'   => '',
            'mediaItems'   => $mediaItems,
            'seo'          => null,
            'currentUser'  => $currentUser,
            'contentView'  => APP_ROOT . '/resources/views/admin/posts/edit.php',
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
    }

    public function store(Request $request): Response
    {
        $currentUser = isset($_SESSION['auth_user_id']) ? User::find((int)$_SESSION['auth_user_id']) : null;
        if (!$currentUser || !$currentUser->canCreatePosts()) {
            $_SESSION['flash_error'] = 'Your account is suspended and cannot create new posts.';
            return Response::redirect('/admin/posts');
        }

        $title       = trim((string)$request->post('title', ''));
        $content     = (string)$request->post('content', '');
        $excerpt     = trim((string)$request->post('excerpt', ''));
        $status      = (string)$request->post('status', 'draft');
        $actionType  = (string)$request->post('action_type', '');
        $slug        = trim((string)$request->post('slug', ''));
        $featImg     = (int)$request->post('featured_image_id', 0);
        $catIds      = (array)$request->post('categories', []);
        $tagsStr     = trim((string)$request->post('tags', ''));

        if ($actionType === 'draft') {
            $status = 'draft';
        } elseif ($actionType === 'publish') {
            $status = 'published';
        }

        // Enforce moderation workflow for non-moderator/admin normal users
        $canDirectPublish = $currentUser->canDirectPublish();
        if (!$canDirectPublish && ($status === 'published' || $actionType === 'publish')) {
            $status = 'pending';
        }

        if ($title === '') {
            $_SESSION['flash_error'] = 'Post title cannot be empty.';
            return Response::redirect('/admin/posts/new');
        }

        $postModel = new Post();
        $finalSlug = $postModel->generateSlug($slug !== '' ? $slug : $title);

        $now = date('Y-m-d H:i:s');
        $publishedAt = ($status === 'published') ? $now : null;
        $authorId = (int)$currentUser->id;
        $content = ContentSanitizer::clean($content, $authorId);

        $db = $this->app->make(Database::class);
        $postId = $db->insert('posts', [
            'title'             => $title,
            'slug'              => $finalSlug,
            'content'           => $content,
            'excerpt'           => $excerpt,
            'status'            => $status,
            'type'              => 'post',
            'author_id'         => $authorId,
            'featured_image_id' => $featImg > 0 ? $featImg : null,
            'published_at'      => $publishedAt,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        $post = Post::find($postId);
        $post->syncTaxonomies($catIds, 'category');
        if ($tagsStr !== '') {
            $post->syncTags($tagsStr);
        }

        $post->saveSeoMeta([
            'meta_title'       => trim((string)$request->post('meta_title', '')),
            'meta_description' => trim((string)$request->post('meta_description', '')),
            'og_title'         => trim((string)$request->post('og_title', '')),
            'og_description'   => trim((string)$request->post('og_description', '')),
        ]);

        if ($status === 'pending') {
            $_SESSION['flash_success'] = 'Post submitted successfully and is awaiting review by a moderator.';
        } elseif ($status === 'published') {
            $_SESSION['flash_success'] = 'Post published successfully!';
        } else {
            $_SESSION['flash_success'] = 'Draft saved successfully.';
        }
        return Response::redirect('/admin/posts/edit?id=' . $postId);
    }

    public function edit(Request $request): Response
    {
        $currentUser = isset($_SESSION['auth_user_id']) ? User::find((int)$_SESSION['auth_user_id']) : null;
        if (!$currentUser || !$currentUser->isActive()) {
            $_SESSION['flash_error'] = 'Your account is inactive or suspended.';
            return Response::redirect('/admin/posts');
        }

        $id = (int)$request->get('id', 0);
        $post = Post::find($id);
        if (!$post) {
            $_SESSION['flash_error'] = 'Post not found.';
            return Response::redirect('/admin/posts');
        }

        if (!$currentUser->canModeratePosts() && (int)$post->author_id !== (int)$currentUser->id) {
            $_SESSION['flash_error'] = 'You do not have permission to edit this post.';
            return Response::redirect('/admin/posts');
        }

        $categories = Taxonomy::getByTaxonomy('category');
        $mediaItems = Media::all();
        $seo = $post->getSeoMeta();

        $selectedCats = array_map(fn($c) => (int)$c->id, $post->getTaxonomies('category'));
        $tagObjects = $post->getTaxonomies('tag');
        $tagsString = implode(', ', array_map(fn($t) => $t->name, $tagObjects));

        $viewData = [
            'pageTitle'    => 'Edit Post',
            'activeMenu'   => 'posts',
            'post'         => $post,
            'categories'   => $categories,
            'selectedCats' => $selectedCats,
            'tagsString'   => $tagsString,
            'mediaItems'   => $mediaItems,
            'seo'          => $seo,
            'currentUser'  => $currentUser,
            'contentView'  => APP_ROOT . '/resources/views/admin/posts/edit.php',
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
    }

    public function update(Request $request): Response
    {
        $currentUser = isset($_SESSION['auth_user_id']) ? User::find((int)$_SESSION['auth_user_id']) : null;
        if (!$currentUser || !$currentUser->isActive()) {
            $_SESSION['flash_error'] = 'Your account is inactive or suspended.';
            return Response::redirect('/admin/posts');
        }

        $id = (int)$request->post('id', 0);
        $post = Post::find($id);
        if (!$post) {
            $_SESSION['flash_error'] = 'Post not found.';
            return Response::redirect('/admin/posts');
        }

        if (!$currentUser->canModeratePosts() && (int)$post->author_id !== (int)$currentUser->id) {
            $_SESSION['flash_error'] = 'You do not have permission to edit this post.';
            return Response::redirect('/admin/posts');
        }

        $title      = trim((string)$request->post('title', ''));
        $content    = (string)$request->post('content', '');
        $excerpt    = trim((string)$request->post('excerpt', ''));
        $status     = (string)$request->post('status', 'draft');
        $actionType = (string)$request->post('action_type', '');
        $slug       = trim((string)$request->post('slug', ''));
        $featImg    = (int)$request->post('featured_image_id', 0);
        $catIds     = (array)$request->post('categories', []);
        $tagsStr    = trim((string)$request->post('tags', ''));

        if ($actionType === 'draft') {
            $status = 'draft';
        } elseif ($actionType === 'publish') {
            $status = 'published';
        }

        // Enforce moderation workflow for non-moderator/admin normal users
        $canDirectPublish = $currentUser->canDirectPublish();
        if (!$canDirectPublish && ($status === 'published' || $actionType === 'publish')) {
            $status = 'pending';
        }

        if ($title === '') {
            $_SESSION['flash_error'] = 'Post title cannot be empty.';
            return Response::redirect('/admin/posts/edit?id=' . $id);
        }

        $finalSlug = $post->generateSlug($slug !== '' ? $slug : $title, $id);
        $now = date('Y-m-d H:i:s');
        $publishedAt = $post->published_at;
        if ($status === 'published' && empty($publishedAt)) {
            $publishedAt = $now;
        }

        $authorId = (int)($post->author_id ?: $currentUser->id);
        $content = ContentSanitizer::clean($content, $authorId);

        $post->update([
            'title'             => $title,
            'slug'              => $finalSlug,
            'content'           => $content,
            'excerpt'           => $excerpt,
            'status'            => $status,
            'featured_image_id' => $featImg > 0 ? $featImg : null,
            'published_at'      => $publishedAt,
            'updated_at'        => $now,
        ]);

        $post->syncTaxonomies($catIds, 'category');
        $post->syncTags($tagsStr);

        $post->saveSeoMeta([
            'meta_title'       => trim((string)$request->post('meta_title', '')),
            'meta_description' => trim((string)$request->post('meta_description', '')),
            'og_title'         => trim((string)$request->post('og_title', '')),
            'og_description'   => trim((string)$request->post('og_description', '')),
        ]);

        if ($status === 'pending') {
            $_SESSION['flash_success'] = 'Post submitted successfully and is awaiting review by a moderator.';
        } elseif ($status === 'published') {
            $_SESSION['flash_success'] = 'Post published successfully!';
        } else {
            $_SESSION['flash_success'] = 'Post updated successfully.';
        }
        return Response::redirect('/admin/posts/edit?id=' . $id);
    }

    public function approve(Request $request): Response
    {
        $currentUser = isset($_SESSION['auth_user_id']) ? User::find((int)$_SESSION['auth_user_id']) : null;
        if (!$currentUser || !$currentUser->canModeratePosts()) {
            $_SESSION['flash_error'] = 'You do not have permission to approve posts.';
            return Response::redirect('/admin/posts');
        }

        $id = (int)$request->get('id', $request->post('id', 0));
        $post = Post::find($id);
        if (!$post) {
            $_SESSION['flash_error'] = 'Post not found.';
            return Response::redirect('/admin/posts');
        }

        $post->approve();
        $_SESSION['flash_success'] = 'Post "' . htmlspecialchars($post->title, ENT_QUOTES, 'UTF-8') . '" approved and published successfully!';
        return Response::redirect('/admin/posts?status=pending');
    }

    public function reject(Request $request): Response
    {
        $currentUser = isset($_SESSION['auth_user_id']) ? User::find((int)$_SESSION['auth_user_id']) : null;
        if (!$currentUser || !$currentUser->canModeratePosts()) {
            $_SESSION['flash_error'] = 'You do not have permission to reject posts.';
            return Response::redirect('/admin/posts');
        }

        $id = (int)$request->get('id', $request->post('id', 0));
        $post = Post::find($id);
        if (!$post) {
            $_SESSION['flash_error'] = 'Post not found.';
            return Response::redirect('/admin/posts');
        }

        $post->reject();
        $_SESSION['flash_success'] = 'Post "' . htmlspecialchars($post->title, ENT_QUOTES, 'UTF-8') . '" has been rejected.';
        return Response::redirect('/admin/posts?status=pending');
    }

    public function preview(Request $request): Response
    {
        $title   = trim((string)$request->post('title', 'Untitled Preview'));
        $content = (string)$request->post('content', '');
        $featImgId = (int)$request->post('featured_image_id', 0);
        $featImg = $featImgId > 0 ? Media::find($featImgId) : null;

        $authorId = (int)($_SESSION['auth_user_id'] ?? 1);
        $cleanedContent = ContentSanitizer::clean($content, $authorId);

        $fakePost = new Post([
            'id'                => 0,
            'title'             => $title ?: 'Preview',
            'slug'              => 'preview',
            'content'           => $cleanedContent,
            'excerpt'           => '',
            'status'            => 'draft',
            'type'              => 'post',
            'author_id'         => $authorId,
            'featured_image_id' => $featImgId > 0 ? $featImgId : null,
            'published_at'      => date('Y-m-d H:i:s'),
            'created_at'        => date('Y-m-d H:i:s'),
        ]);

        $engine = new \FavoriteCMS\Rendering\Engine($this->app);
        $html = $engine->render('single', [
            'post'            => $fakePost,
            'previousPost'    => null,
            'nextPost'        => null,
            'metaTitle'       => 'Preview: ' . $title,
            'metaDescription' => '',
            'commentNotice'   => null,
            'isPreview'       => true,
        ]);

        return Response::make($html, 200);
    }

    public function trash(Request $request): Response
    {
        $currentUser = isset($_SESSION['auth_user_id']) ? User::find((int)$_SESSION['auth_user_id']) : null;
        $id = (int)$request->get('id', 0);
        $post = Post::find($id);
        if ($post) {
            if (!$currentUser || (!$currentUser->canModeratePosts() && (int)$post->author_id !== (int)$currentUser->id)) {
                $_SESSION['flash_error'] = 'You do not have permission to modify this post.';
                return Response::redirect('/admin/posts');
            }
            $post->update(['status' => 'trash', 'updated_at' => date('Y-m-d H:i:s')]);
            $_SESSION['flash_success'] = 'Post moved to trash.';
        }
        return Response::redirect('/admin/posts');
    }

    public function restore(Request $request): Response
    {
        $currentUser = isset($_SESSION['auth_user_id']) ? User::find((int)$_SESSION['auth_user_id']) : null;
        $id = (int)$request->get('id', 0);
        $post = Post::find($id);
        if ($post) {
            if (!$currentUser || (!$currentUser->canModeratePosts() && (int)$post->author_id !== (int)$currentUser->id)) {
                $_SESSION['flash_error'] = 'You do not have permission to modify this post.';
                return Response::redirect('/admin/posts');
            }
            $post->update(['status' => 'draft', 'updated_at' => date('Y-m-d H:i:s')]);
            $_SESSION['flash_success'] = 'Post restored from trash.';
        }
        return Response::redirect('/admin/posts?status=trash');
    }

    public function delete(Request $request): Response
    {
        $currentUser = isset($_SESSION['auth_user_id']) ? User::find((int)$_SESSION['auth_user_id']) : null;
        $id = (int)$request->get('id', 0);
        $post = Post::find($id);
        if ($post) {
            if (!$currentUser || (!$currentUser->canModeratePosts() && (int)$post->author_id !== (int)$currentUser->id)) {
                $_SESSION['flash_error'] = 'You do not have permission to delete this post.';
                return Response::redirect('/admin/posts');
            }
            $db = $this->app->make(Database::class);
            $db->execute("DELETE FROM `post_taxonomies` WHERE `post_id` = ?", [$id]);
            $db->execute("DELETE FROM `seo_meta` WHERE `object_type` = 'post' AND `object_id` = ?", [$id]);
            $db->execute("DELETE FROM `comments` WHERE `post_id` = ?", [$id]);
            $post->delete();
            $_SESSION['flash_success'] = 'Post permanently deleted.';
        }
        return Response::redirect('/admin/posts?status=trash');
    }

    public function quickDraft(Request $request): Response
    {
        $currentUser = isset($_SESSION['auth_user_id']) ? User::find((int)$_SESSION['auth_user_id']) : null;
        if (!$currentUser || !$currentUser->canCreatePosts()) {
            $_SESSION['flash_error'] = 'Your account is suspended and cannot create posts.';
            return Response::redirect('/admin');
        }

        $title = trim((string)$request->post('title', ''));
        $content = (string)$request->post('content', '');

        if ($title !== '') {
            $postModel = new Post();
            $slug = $postModel->generateSlug($title);
            $authorId = (int)$currentUser->id;

            $db = $this->app->make(Database::class);
            $db->insert('posts', [
                'title'      => $title,
                'slug'       => $slug,
                'content'    => $content,
                'status'     => 'draft',
                'type'       => 'post',
                'author_id'  => $authorId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $_SESSION['flash_success'] = 'Draft saved successfully.';
        }

        return Response::redirect('/admin');
    }
}
