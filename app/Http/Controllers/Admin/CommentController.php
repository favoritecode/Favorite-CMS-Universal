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
        $this->validateCsrf($request);
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
        $this->validateCsrf($request);
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
        $this->validateCsrf($request);
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
        $this->validateCsrf($request);
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
        $this->validateCsrf($request);
        $id = (int)$request->get('id', 0);
        $comment = Comment::find($id);
        if ($comment) {
            $comment->delete();
            $_SESSION['flash_success'] = 'Comment permanently deleted.';
        }
        return Response::redirect('/admin/comments?status=trash');
    }

    protected function validateCsrf(Request $request): void
    {
        $token = (string)($request->get('_token', $request->post('_token', '')));
        $sessionToken = (string)($_SESSION['_token'] ?? '');
        if ($token === '' || !hash_equals($sessionToken, $token)) {
            $_SESSION['flash_error'] = 'Security verification failed (invalid CSRF token). Please try again.';
            Response::redirect('/admin/comments')->send();
            exit;
        }
    }

    public function bulkAction(Request $request): Response
    {
        $token = (string)($request->get('_token', $request->post('_token', '')));
        $sessionToken = (string)($_SESSION['_token'] ?? '');
        if ($token === '' || !hash_equals($sessionToken, $token)) {
            $_SESSION['flash_error'] = 'Security verification failed (invalid CSRF token).';
            return Response::redirect('/admin/comments');
        }

        $action = trim((string)$request->post('bulk_action', ''));
        $rawIds = (array)$request->post('ids', []);
        $ids = array_filter(array_map('intval', $rawIds), fn($id) => $id > 0);
        $redirectStatus = trim((string)$request->post('status', ''));
        $redirectUrl = '/admin/comments' . ($redirectStatus !== '' ? '?status=' . urlencode($redirectStatus) : '');

        if (empty($action) || empty($ids)) {
            $_SESSION['flash_error'] = 'Please select at least one comment and a bulk action.';
            return Response::redirect($redirectUrl);
        }

        $count = 0;
        $now = date('Y-m-d H:i:s');

        foreach ($ids as $id) {
            $comment = Comment::find($id);
            if (!$comment) {
                continue;
            }

            switch ($action) {
                case 'approve':
                    $comment->update(['status' => 'approved', 'updated_at' => $now]);
                    $count++;
                    break;
                case 'unapprove':
                    $comment->update(['status' => 'pending', 'updated_at' => $now]);
                    $count++;
                    break;
                case 'spam':
                    $comment->update(['status' => 'spam', 'updated_at' => $now]);
                    $count++;
                    break;
                case 'trash':
                    $comment->update(['status' => 'trash', 'updated_at' => $now]);
                    $count++;
                    break;
                case 'delete':
                    $comment->delete();
                    $count++;
                    break;
            }
        }

        if ($count > 0) {
            $label = match ($action) {
                'approve'   => 'approved',
                'unapprove' => 'unapproved',
                'spam'      => 'marked as spam',
                'trash'     => 'moved to trash',
                'delete'    => 'permanently deleted',
                default     => 'processed',
            };
            $_SESSION['flash_success'] = "{$count} comment(s) successfully {$label}.";
        } else {
            $_SESSION['flash_error'] = 'No comments were updated.';
        }

        return Response::redirect($redirectUrl);
    }
}

