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
        $currentUser = function_exists('current_user') ? current_user() : null;
        if (!$currentUser && !empty($_SESSION['auth_user_id'])) {
            $currentUser = User::find((int)$_SESSION['auth_user_id']);
        }

        if (!$currentUser || !$currentUser->isActive()) {
            return Response::redirect('/admin/login');
        }

        if (!$currentUser->hasPermission('view_admin') && !$currentUser->canCreatePosts() && !$currentUser->canUpdatePosts() && !$currentUser->hasRole('subscriber')) {
            return Response::redirect('/admin/users/profile');
        }

        $db = $this->app->make(Database::class);

        if ($currentUser->canModeratePosts()) {
            $postsCount = Post::countByStatus();
        } else {
            // Author / restricted roles: count author's own posts
            $postsCount = [
                'all'       => 0,
                'published' => 0,
                'draft'     => 0,
                'pending'   => 0,
                'rejected'  => 0,
                'trash'     => 0,
                'scheduled' => 0,
            ];
            $authorRows = $db->select(
                "SELECT `status`, COUNT(*) as cnt FROM `posts` WHERE `author_id` = ? AND `type` = 'post' GROUP BY `status`",
                [(int)$currentUser->id]
            );
            foreach ($authorRows as $row) {
                $statusKey = (string)$row->status;
                $cnt = (int)$row->cnt;
                $postsCount[$statusKey] = $cnt;
                if ($statusKey !== 'trash') {
                    $postsCount['all'] += $cnt;
                }
            }
        }

        $pagesCount = $currentUser->canManagePages() ? Page::countByStatus() : null;
        $commentsCount = $currentUser->canModerateComments() ? Comment::countByStatus() : null;

        // COUNT queries scoped by capabilities
        $userCount = $currentUser->canManageUsers()
            ? (int)($db->selectOne("SELECT COUNT(*) AS cnt FROM `users`")->cnt ?? 0)
            : null;

        $mediaCount = $currentUser->canManageMedia()
            ? (int)($db->selectOne("SELECT COUNT(*) AS cnt FROM `media`")->cnt ?? 0)
            : 0;

        $recentPosts = Post::published(5);
        $recentComments = $currentUser->canModerateComments()
            ? $db->select("SELECT * FROM `comments` ORDER BY `created_at` DESC LIMIT 5")
            : [];

        $viewData = [
            'pageTitle'      => 'Dashboard',
            'activeMenu'     => 'dashboard',
            'currentUser'    => $currentUser,
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

