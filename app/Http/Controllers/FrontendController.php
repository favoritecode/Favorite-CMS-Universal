<?php

declare(strict_types=1);

namespace FavoriteCMS\Http\Controllers;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Post;
use FavoriteCMS\Models\Page;
use FavoriteCMS\Models\Taxonomy;
use FavoriteCMS\Models\Comment;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Rendering\Engine;

class FrontendController
{
    protected Application $app;
    protected Engine $engine;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->engine = new Engine($app);
    }

    public function home(Request $request): Response
    {
        $frontPageType = Setting::get('reading', 'front_page_type', 'posts');
        $frontPageId   = (int)Setting::get('reading', 'front_page_id', 0);

        if ($frontPageType === 'page' && $frontPageId > 0) {
            $page = Page::find($frontPageId);
            if ($page && $page->status === 'published') {
                return $this->renderPage($page);
            }
        }

        // Show latest posts with pagination
        $perPage     = max(1, (int)Setting::get('reading', 'posts_per_page', 10));
        $currentPage = max(1, (int)$request->get('page', 1));
        $totalPosts  = (int)(Post::countByStatus()['published'] ?? 0);
        $totalPages  = max(1, (int)ceil($totalPosts / $perPage));
        $offset      = ($currentPage - 1) * $perPage;

        $posts = Post::published($perPage, $offset);

        $html = $this->engine->render('index', [
            'posts'        => $posts,
            'archiveTitle' => null,
            'isHome'       => true,
            'currentPage'  => $currentPage,
            'totalPages'   => $totalPages,
            'totalPosts'   => $totalPosts,
        ]);

        return Response::make($html, 200);
    }

    public function post(Request $request, string $slug): Response
    {
        $post = Post::findBySlug($slug);
        if (!$post || $post->status !== 'published') {
            return $this->notFound();
        }

        $seo = $post->getSeoMeta();
        $siteTitle = Setting::get('general', 'site_name', 'Favorite CMS');
        $sep = Setting::get('seo', 'title_separator', '—');
        $metaTitle = ($seo && $seo->meta_title) ? $seo->meta_title : "{$post->title} {$sep} {$siteTitle}";
        $metaDesc = ($seo && $seo->meta_description) ? $seo->meta_description : $post->excerpt;

        $commentNotice = $request->get('comment') === 'submitted'
            ? 'Thank you! Your comment has been submitted.'
            : null;

        $previousPost = $post->getPrevious();
        $nextPost = $post->getNext();

        $html = $this->engine->render('single', [
            'post'            => $post,
            'previousPost'    => $previousPost,
            'nextPost'        => $nextPost,
            'metaTitle'       => $metaTitle,
            'metaDescription' => $metaDesc,
            'commentNotice'   => $commentNotice,
        ]);

        return Response::make($html, 200);
    }

    public function page(Request $request, string $slug): Response
    {
        $page = Page::findBySlug($slug);
        if (!$page || $page->status !== 'published') {
            return $this->notFound();
        }

        return $this->renderPage($page);
    }

    protected function renderPage(Page $page): Response
    {
        $seo = $page->getSeoMeta();
        $siteTitle = Setting::get('general', 'site_name', 'Favorite CMS');
        $sep = Setting::get('seo', 'title_separator', '—');
        $metaTitle = ($seo && $seo->meta_title) ? $seo->meta_title : "{$page->title} {$sep} {$siteTitle}";
        $metaDesc = ($seo && $seo->meta_description) ? $seo->meta_description : '';

        $html = $this->engine->render('page', [
            'page'            => $page,
            'metaTitle'       => $metaTitle,
            'metaDescription' => $metaDesc,
        ]);

        return Response::make($html, 200);
    }

    public function category(Request $request, string $slug): Response
    {
        $cat = Taxonomy::findBySlug($slug, 'category');
        if (!$cat) {
            return $this->notFound();
        }

        $posts = $cat->getPosts();
        $html = $this->engine->render('archive', [
            'posts'              => $posts,
            'archiveTitle'       => "Category: {$cat->name}",
            'archiveDescription' => $cat->description,
        ]);

        return Response::make($html, 200);
    }

    public function tag(Request $request, string $slug): Response
    {
        $tag = Taxonomy::findBySlug($slug, 'tag');
        if (!$tag) {
            return $this->notFound();
        }

        $posts = $tag->getPosts();
        $html = $this->engine->render('archive', [
            'posts'              => $posts,
            'archiveTitle'       => "Tag: #{$tag->name}",
            'archiveDescription' => $tag->description,
        ]);

        return Response::make($html, 200);
    }

    public function search(Request $request): Response
    {
        $query = trim((string)$request->get('q', ''));
        $posts = [];

        if ($query !== '') {
            $db = $this->app->make(Database::class);
            $rows = $db->select(
                "SELECT * FROM `posts` WHERE `status` = 'published' AND `type` = 'post' AND (`title` LIKE ? OR `content` LIKE ?) ORDER BY `published_at` DESC LIMIT 20",
                ["%{$query}%", "%{$query}%"]
            );
            $posts = array_map(fn($r) => new Post((array)$r), $rows);
        }

        $html = $this->engine->render('search', [
            'posts'       => $posts,
            'searchQuery' => $query,
        ]);

        return Response::make($html, 200);
    }

    public function submitComment(Request $request): Response
    {
        $postId = (int)$request->post('post_id', 0);
        $name   = trim((string)$request->post('author_name', ''));
        $email  = trim((string)$request->post('author_email', ''));
        $text   = trim((string)$request->post('content', ''));

        $post = Post::find($postId);
        if (!$post || $post->status !== 'published') {
            return Response::redirect('/');
        }

        if ($name === '' || $email === '' || $text === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['comment_error'] = 'Please provide your name, valid email, and comment.';
            return Response::redirect('/post/' . $post->slug);
        }

        $db = $this->app->make(Database::class);
        $now = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $db->insert('comments', [
            'post_id'      => $postId,
            'author_name'  => $name,
            'author_email' => $email,
            'author_ip'    => $ip,
            'content'      => $text,
            'status'       => 'approved', // auto-approve default comment for smooth workflow
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        return Response::redirect('/post/' . $post->slug . '?comment=submitted#comments');
    }

    public function sitemap(Request $request): Response
    {
        $baseUrl = rtrim(config('app.url', 'http://favorite-cms.local'), '/');
        $posts = Post::published(500);
        $pages = Page::published();
        $categories = Taxonomy::getByTaxonomy('category');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        // Home
        $xml .= "  <url>\n    <loc>{$baseUrl}/</loc>\n    <changefreq>daily</changefreq>\n    <priority>1.0</priority>\n  </url>\n";

        // Posts
        foreach ($posts as $p) {
            $lastmod = date('c', strtotime($p->updated_at ?? $p->created_at));
            $xml .= "  <url>\n    <loc>{$baseUrl}/post/" . htmlspecialchars($p->slug, ENT_QUOTES, 'UTF-8') . "</loc>\n    <lastmod>{$lastmod}</lastmod>\n    <changefreq>weekly</changefreq>\n    <priority>0.8</priority>\n  </url>\n";
        }

        // Pages
        foreach ($pages as $p) {
            $lastmod = date('c', strtotime($p->updated_at ?? $p->created_at));
            $xml .= "  <url>\n    <loc>{$baseUrl}/page/" . htmlspecialchars($p->slug, ENT_QUOTES, 'UTF-8') . "</loc>\n    <lastmod>{$lastmod}</lastmod>\n    <changefreq>monthly</changefreq>\n    <priority>0.6</priority>\n  </url>\n";
        }

        // Categories
        foreach ($categories as $c) {
            $xml .= "  <url>\n    <loc>{$baseUrl}/category/" . htmlspecialchars($c->slug, ENT_QUOTES, 'UTF-8') . "</loc>\n    <changefreq>weekly</changefreq>\n    <priority>0.5</priority>\n  </url>\n";
        }

        $xml .= '</urlset>';

        $res = Response::make($xml, 200);
        $res->header('Content-Type', 'application/xml; charset=utf-8');
        return $res;
    }

    public function robots(Request $request): Response
    {
        $default = "User-agent: *\nAllow: /\nDisallow: /admin/\nSitemap: " . config('app.url', 'http://favorite-cms.local') . "/sitemap.xml\n";
        $content = Setting::get('seo', 'robots_txt', $default);

        $res = Response::make((string)$content, 200);
        $res->header('Content-Type', 'text/plain; charset=utf-8');
        return $res;
    }

    protected function notFound(): Response
    {
        try {
            $html = $this->engine->render('404');
            return Response::make($html, 404);
        } catch (\Throwable) {
            return Response::make('<h1>404 Not Found</h1>', 404);
        }
    }
}

