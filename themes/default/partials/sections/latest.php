<?php
/**
 * Homepage latest articles with pagination.
 *
 * @var array<int, object> $posts        Posts for this page (featured posts already removed)
 * @var int                $currentPage
 * @var int                $totalPages
 * @var bool               $hasAnyPosts  Whether the site has published posts at all
 * @var bool|null          $showHeading
 * @var int|null           $headingLevel Card title heading level
 * @var bool|null          $eagerFirst
 */
$posts = is_array($posts ?? null) ? $posts : [];
$showHeading = $showHeading ?? true;
$emptyState = null;
if ($posts === [] && empty($hasAnyPosts)) {
    $emptyState = [
        'title'   => 'No Articles Published Yet',
        'message' => 'Welcome to your new site! Once articles are published, they will automatically appear here.',
        'actions' => !empty($_SESSION['auth_user_id']) ? [['label' => 'Write Your First Post', 'url' => '/admin/posts/new']] : [],
    ];
} elseif ($posts === [] && (int)($currentPage ?? 1) > 1) {
    $emptyState = [
        'title'   => 'No articles on this page',
        'message' => 'This page is past the end of the article list.',
        'actions' => [['label' => 'Back to the first page', 'url' => '/']],
    ];
}
?>
<section class="home-section home-latest"<?php echo $showHeading ? ' aria-labelledby="home-latest-title"' : ' aria-label="Latest articles"'; ?>>
    <?php if ($showHeading && $posts !== []): ?>
        <div class="section-heading">
            <h2 class="section-heading__title" id="home-latest-title">Latest Articles</h2>
        </div>
    <?php endif; ?>
    <?php fcd_partial('post-grid', [
        'posts'        => $posts,
        'headingLevel' => $headingLevel ?? 3,
        'eagerFirst'   => $eagerFirst ?? false,
        'emptyState'   => $emptyState,
        'pagination'   => ['currentPage' => $currentPage ?? 1, 'totalPages' => $totalPages ?? 1, 'label' => 'Posts pagination'],
    ]); ?>
</section>
