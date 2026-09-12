<?php
require __DIR__ . '/header.php';

$posts       = is_array($posts ?? null) ? $posts : [];
$currentPage = max(1, (int)($currentPage ?? 1));
$totalPages  = max(1, (int)($totalPages ?? 1));
$totalPosts  = (int)($totalPosts ?? count($posts));
$archiveType = (string)($archiveType ?? 'archive');

$archiveEyebrow = match ($archiveType) {
    'category' => 'Category',
    'tag'      => 'Tag',
    default    => 'Archive',
};

$archiveMeta = null;
if ($totalPosts > 0) {
    $archiveMeta = number_format($totalPosts) . ($totalPosts === 1 ? ' article' : ' articles');
    if ($totalPages > 1) {
        $archiveMeta .= ' · Page ' . $currentPage . ' of ' . $totalPages;
    }
}
?>

<main class="site-main site-main--listing" id="main-content" tabindex="-1">
    <?php fcd_partial('page-header', [
        'eyebrow'     => $archiveEyebrow,
        'title'       => $archiveTitle ?? 'Archive',
        'description' => $archiveDescription ?? null,
        'meta'        => $archiveMeta,
    ]); ?>

    <?php fcd_partial('post-grid', [
        'posts'      => $posts,
        'eagerFirst' => true,
        'emptyState' => [
            'title'   => $currentPage > 1 && $totalPosts > 0 ? 'No articles on this page' : 'Nothing here yet',
            'message' => $currentPage > 1 && $totalPosts > 0
                ? 'This page is past the end of the archive.'
                : 'No articles have been published in this archive yet.',
            'actions' => [['label' => 'Return to Homepage', 'url' => '/']],
        ],
        'pagination' => ['currentPage' => $currentPage, 'totalPages' => $totalPages, 'label' => 'Archive pagination'],
    ]); ?>
</main>

<?php
require __DIR__ . '/sidebar.php';
require __DIR__ . '/footer.php';
