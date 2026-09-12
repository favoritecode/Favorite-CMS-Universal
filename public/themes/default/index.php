<?php
require __DIR__ . '/header.php';

$posts       = is_array($posts ?? null) ? $posts : [];
$currentPage = max(1, (int)($currentPage ?? 1));
$totalPages  = max(1, (int)($totalPages ?? 1));
$totalPosts  = (int)($totalPosts ?? count($posts));
$isHome      = !empty($isHome);
$isFirstPage = $currentPage === 1;

$enabledSections = [];
if ($isHome) {
    try {
        $layoutService = new \FavoriteCMS\Themes\ThemeLayoutService(\FavoriteCMS\Core\Application::getInstance());
        $enabledSections = array_values(array_filter($layoutService->getSections(), static fn(array $section): bool => !empty($section['enabled'])));
    } catch (\Throwable) {
        $enabledSections = [];
    }
}
$enabledSectionIds = array_column($enabledSections, 'id');

// Featured stories appear on the first page only, and the latest list never repeats them.
$featuredPosts = ($isHome && $isFirstPage && in_array('featured-posts', $enabledSectionIds, true)) ? array_slice($posts, 0, 2) : [];
$featuredIds   = array_map(static fn(object $post): int => (int)$post->id, $featuredPosts);
$latestPosts   = array_values(array_filter($posts, static fn(object $post): bool => !in_array((int)$post->id, $featuredIds, true)));
$heroVisible   = $isHome && $isFirstPage && in_array('hero', $enabledSectionIds, true);
?>

<main class="site-main site-main--listing" id="main-content" tabindex="-1">
    <?php if (!empty($archiveTitle)): ?>
        <?php fcd_partial('page-header', [
            'eyebrow'     => 'Browsing Archive',
            'title'       => $archiveTitle,
            'description' => $archiveDescription ?? null,
        ]); ?>
    <?php endif; ?>

    <?php if ($isHome && $enabledSections !== []): ?>
        <?php if (!$heroVisible && empty($archiveTitle)): ?>
            <?php if ($isFirstPage): ?>
                <h1 class="visually-hidden"><?php echo fcd_e($siteTitle); ?></h1>
            <?php else: ?>
                <?php fcd_partial('page-header', [
                    'eyebrow' => $siteTitle,
                    'title'   => 'Latest Articles',
                    'meta'    => 'Page ' . $currentPage . ' of ' . $totalPages,
                ]); ?>
            <?php endif; ?>
        <?php endif; ?>

        <?php foreach ($enabledSections as $section): ?>
            <?php if ($section['id'] === 'hero' && $heroVisible): ?>
                <?php fcd_partial('sections/hero', ['siteTitle' => $siteTitle, 'siteTagline' => $siteTagline]); ?>
            <?php elseif ($section['id'] === 'featured-posts'): ?>
                <?php fcd_partial('sections/featured', ['posts' => $featuredPosts, 'headingLevel' => 3]); ?>
            <?php elseif ($section['id'] === 'latest-posts'): ?>
                <?php fcd_partial('sections/latest', [
                    'posts'        => $latestPosts,
                    'currentPage'  => $currentPage,
                    'totalPages'   => $totalPages,
                    'hasAnyPosts'  => $totalPosts > 0,
                    'showHeading'  => $isFirstPage,
                    'headingLevel' => $isFirstPage ? 3 : 2,
                    'eagerFirst'   => $featuredPosts === [] && !$heroVisible,
                ]); ?>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php elseif ($isHome && empty($archiveTitle)): ?>
        <?php // Every homepage section is disabled in the Customizer: nothing else is rendered (existing behavior). ?>
        <h1 class="visually-hidden"><?php echo fcd_e($siteTitle); ?></h1>
    <?php else: ?>
        <?php if (empty($archiveTitle)): ?>
            <h1 class="visually-hidden"><?php echo fcd_e($siteTitle); ?></h1>
        <?php endif; ?>
        <?php fcd_partial('post-grid', [
            'posts'      => $posts,
            'eagerFirst' => true,
            'emptyState' => [
                'title'   => 'No Articles Published Yet',
                'message' => 'Welcome to your new site! Once articles are published, they will automatically appear here.',
                'actions' => !empty($_SESSION['auth_user_id']) ? [['label' => 'Write Your First Post', 'url' => '/admin/posts/new']] : [],
            ],
            'pagination' => ['currentPage' => $currentPage, 'totalPages' => $totalPages, 'label' => 'Posts pagination'],
        ]); ?>
    <?php endif; ?>
</main>

<?php
require __DIR__ . '/sidebar.php';
require __DIR__ . '/footer.php';
