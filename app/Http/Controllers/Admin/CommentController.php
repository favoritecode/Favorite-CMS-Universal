<?php

declare(strict_types=1);

namespace FavoriteCMS\Http\Controllers\Admin;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Comment;

class CommentController
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

        $query = "SELECT * FROM `comments` WHERE 1=1";
        $params = [];

        if ($status !== 'all') {
            $query .= " AND `status` = ?";
            $params[] = $status;
        } else {
            $query .= " AND `status` != 'trash'";
        }

        $query .= " ORDER BY `created_at` DESC";
        $comments = array_map(fn($row) => new Comment((array)$row), $db->select($query, $params));
        $counts = Comment::countByStatus();

        $viewData = [
            'pageTitle'   => 'Comments',
            'activeMenu'  => 'comments',
            'comments'    => $comments,
            'counts'      => $counts,
            'status'      => $status,
            'contentView' => APP_ROOT . '/resources/views/admin/comments/index.php',
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
    }

    public function approve(Request $request): Response
    {
        $id = (int)$request->get('id', 0);
        $comment = Comment::find($id);
        if ($comment) {
            $comment->update(['status' => 'approved', 'updated_at' => date('Y-m-d H:i:s')]);
            $_SESSION['flash_success'] = 'Comment approved.';
        }
        return Response::redirect('/admin/comments');
    }

    public function unapprove(Request $request): Response
    {
        $id = (int)$request->get('id', 0);
        $comment = Comment::find($id);
        if ($comment) {
            $comment->update(['status' => 'pending', 'updated_at' => date('Y-m-d H:i:s')]);
            $_SESSION['flash_success'] = 'Comment unapproved.';
        }
        return Response::redirect('/admin/comments');
    }

    public function spam(Request $request): Response
    {
        $id = (int)$request->get('id', 0);
        $comment = Comment::find($id);
        if ($comment) {
            $comment->update(['status' => 'spam', 'updated_at' => date('Y-m-d H:i:s')]);
            $_SESSION['flash_success'] = 'Comment marked as spam.';
        }
        return Response::redirect('/admin/comments');
    }

    public function trash(Request $request): Response
    {
        $id = (int)$request->get('id', 0);
        $comment = Comment::find($id);
        if ($comment) {
            $comment->update(['status' => 'trash', 'updated_at' => date('Y-m-d H:i:s')]);
            $_SESSION['flash_success'] = 'Comment moved to trash.';
        }
        return Response::redirect('/admin/comments');
    }

    public function delete(Request $request): Response
    {
        $id = (int)$request->get('id', 0);
        $comment = Comment::find($id);
        if ($comment) {
            $comment->delete();
            $_SESSION['flash_success'] = 'Comment permanently deleted.';
        }
        return Response::redirect('/admin/comments?status=trash');
    }
}

