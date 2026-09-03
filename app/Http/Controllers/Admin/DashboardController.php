<?php

declare(strict_types=1);

namespace FavoriteCMS\Http\Controllers\Admin;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Post;
use FavoriteCMS\Models\Page;
use FavoriteCMS\Models\Comment;
use FavoriteCMS\Models\User;
use FavoriteCMS\Models\Media;

class DashboardController
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function index(Request $request): Response
    {
        $db = $this->app->make(Database::class);

        $postsCount = Post::countByStatus();
        $pagesCount = Page::countByStatus();
        $commentsCount = Comment::countByStatus();

        $userCount = count(User::all());
        $mediaCount = count(Media::all());

        $recentPosts = Post::published(5);
        $recentComments = $db->select("SELECT * FROM `comments` ORDER BY `created_at` DESC LIMIT 5");

        $viewData = [
            'pageTitle'      => 'Dashboard',
            'activeMenu'     => 'dashboard',
            'postsCount'     => $postsCount,
            'pagesCount'     => $pagesCount,
            'commentsCount'  => $commentsCount,
            'userCount'      => $userCount,
            'mediaCount'     => $mediaCount,
            'recentPosts'    => $recentPosts,
            'recentComments' => $recentComments,
            'contentView'    => APP_ROOT . '/resources/views/admin/dashboard.php',
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
    }
}

