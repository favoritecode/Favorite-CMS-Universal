<?php
/**
 * Backward-compatible posts feed include.
 * Theme templates now render listings through partials/post-grid.php; this wrapper keeps any
 * existing include of _posts_feed.php working with the same data ($posts, $currentPage, $totalPages).
 */
require_once __DIR__ . '/functions.php';

fcd_partial('post-grid', [
    'posts'      => $posts ?? [],
    'emptyState' => [
        'title'   => 'No Articles Published Yet',
        'message' => 'Welcome to your new site! Once articles are published, they will automatically appear here.',
        'actions' => !empty($_SESSION['auth_user_id']) ? [['label' => 'Write Your First Post', 'url' => '/admin/posts/new']] : [],
    ],
    'pagination' => ['currentPage' => $currentPage ?? 1, 'totalPages' => $totalPages ?? 1, 'label' => 'Posts pagination'],
]);
