<?php
$searchQuery  = trim((string)($searchQuery ?? (is_string($_GET['q'] ?? null) ? $_GET['q'] : '')));
// Raw (unescaped) title: output helpers and the SEO service escape it exactly once
$archiveTitle = $searchQuery !== '' ? 'Search: ' . $searchQuery : 'Search';

require __DIR__ . '/header.php';

$posts       = is_array($posts ?? null) ? $posts : [];
$currentPage = max(1, (int)($currentPage ?? 1));
$totalPages  = max(1, (int)($totalPages ?? 1));
$totalPosts  = (int)($totalPosts ?? count($posts));

if ($searchQuery === '') {
    $searchMeta = 'Enter a keyword to search the articles on this site.';
} elseif ($totalPosts === 0) {
    $searchMeta = sprintf('No articles matching "%s"', $searchQuery);
} else {
    $searchMeta = sprintf('Found %s matching %s for "%s"', number_format($totalPosts), $totalPosts === 1 ? 'article' : 'articles', $searchQuery);
    if ($totalPages > 1) {
        $searchMeta .= ' · Page ' . $currentPage . ' of ' . $totalPages;
    }
}
?>

<main class="site-main site-main--listing" id="main-content" tabindex="-1">
    <?php fcd_partial('page-header', [
        'eyebrow' => 'Search Results',
        'title'   => $searchQuery !== '' ? 'Search Results for: "' . $searchQuery . '"' : 'Search',
        'meta'    => $searchMeta,
        'search'  => ['inputId' => 'search-page-input', 'formClass' => 'search-form--page', 'query' => $searchQuery, 'placeholder' => 'Search again…', 'required' => true],
    ]); ?>

    <?php if ($searchQuery !== ''): ?>
        <?php fcd_partial('post-grid', [
            'posts'      => $posts,
            'eagerFirst' => true,
            'emptyState' => [
                'title'   => $currentPage > 1 && $totalPosts > 0 ? 'No results on this page' : 'No Results Found',
                'message' => $currentPage > 1 && $totalPosts > 0
                    ? 'This page is past the end of the search results.'
                    : "We couldn't find any articles matching your search terms. Try searching with different keywords or browse our recent posts.",
                'actions' => [['label' => 'Return to Homepage', 'url' => '/', 'variant' => 'secondary']],
            ],
            'pagination' => ['currentPage' => $currentPage, 'totalPages' => $totalPages, 'label' => 'Search results pagination'],
        ]); ?>
    <?php endif; ?>
</main>

<?php
require __DIR__ . '/sidebar.php';
require __DIR__ . '/footer.php';
