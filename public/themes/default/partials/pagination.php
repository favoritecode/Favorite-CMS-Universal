<?php
/**
 * Windowed pagination that preserves the current query string (e.g. ?q=) and the base path.
 *
 * @var int         $currentPage
 * @var int         $totalPages
 * @var string|null $label       Accessible navigation label
 */
$current = max(1, (int)($currentPage ?? 1));
$total   = max(1, (int)($totalPages ?? 1));
if ($total <= 1) {
    return;
}

$params = $_GET;
unset($params['page']);
$basePathUrl = fcd_url(function_exists('site_request_path') ? site_request_path() : '/');
$pageUrl = static function (int $page) use ($params, $basePathUrl): string {
    $query = $params;
    if ($page > 1) {
        $query['page'] = $page;
    }
    $queryString = http_build_query($query);
    return $basePathUrl . ($queryString !== '' ? '?' . $queryString : '');
};

$pages = [1, $total];
for ($i = $current - 1; $i <= $current + 1; $i++) {
    if ($i >= 1 && $i <= $total) {
        $pages[] = $i;
    }
}
$pages = array_values(array_unique($pages));
sort($pages);
?>
<nav class="pagination" aria-label="<?php echo fcd_e($label ?? 'Pagination'); ?>">
    <p class="pagination__status">Page <?php echo $current; ?> of <?php echo $total; ?></p>
    <ul class="pagination__list">
        <li class="pagination__item pagination__item--prev">
            <?php if ($current > 1): ?>
                <a class="pagination__link" href="<?php echo fcd_e($pageUrl(min($current - 1, $total))); ?>"><span aria-hidden="true">&larr;</span> Previous</a>
            <?php else: ?>
                <span class="pagination__link is-disabled" aria-disabled="true"><span aria-hidden="true">&larr;</span> Previous</span>
            <?php endif; ?>
        </li>
        <?php $previousPage = 0; ?>
        <?php foreach ($pages as $page): ?>
            <?php if ($previousPage > 0 && $page - $previousPage === 2): ?>
                <li class="pagination__item pagination__item--number"><a class="pagination__link" href="<?php echo fcd_e($pageUrl($previousPage + 1)); ?>" aria-label="Page <?php echo $previousPage + 1; ?>"><?php echo $previousPage + 1; ?></a></li>
            <?php elseif ($previousPage > 0 && $page - $previousPage > 2): ?>
                <li class="pagination__item pagination__item--gap" aria-hidden="true">&hellip;</li>
            <?php endif; ?>
            <li class="pagination__item pagination__item--number">
                <?php if ($page === $current): ?>
                    <span class="pagination__link is-current" aria-current="page"><?php echo $page; ?></span>
                <?php else: ?>
                    <a class="pagination__link" href="<?php echo fcd_e($pageUrl($page)); ?>" aria-label="Page <?php echo $page; ?>"><?php echo $page; ?></a>
                <?php endif; ?>
            </li>
            <?php $previousPage = $page; ?>
        <?php endforeach; ?>
        <li class="pagination__item pagination__item--next">
            <?php if ($current < $total): ?>
                <a class="pagination__link" href="<?php echo fcd_e($pageUrl($current + 1)); ?>">Next <span aria-hidden="true">&rarr;</span></a>
            <?php else: ?>
                <span class="pagination__link is-disabled" aria-disabled="true">Next <span aria-hidden="true">&rarr;</span></span>
            <?php endif; ?>
        </li>
    </ul>
</nav>
