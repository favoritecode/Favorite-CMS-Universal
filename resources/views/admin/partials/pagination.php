<?php
/**
 * Shared admin list pagination (same controls as the Posts list).
 *
 * @var int    $currentPage
 * @var int    $totalPages
 * @var int    $totalItems
 * @var string $paginationBase Base admin URL, e.g. /admin/pages
 */
$paginationCurrent = max(1, (int)($currentPage ?? 1));
$paginationTotal   = max(1, (int)($totalPages ?? 1));
$paginationItems   = (int)($totalItems ?? 0);
$paginationParams  = $_GET;
$paginationBase    = (string)($paginationBase ?? '');
?>
<div class="admin-list-pagination" style="display: flex; justify-content: flex-end; align-items: center; gap: 6px; margin-top: 16px; flex-wrap: wrap;">
    <span style="font-size: 12px; color: var(--wp-text-muted); margin-right: 8px;">
        <?php echo number_format($paginationItems); ?> <?php echo $paginationItems === 1 ? 'item' : 'items'; ?><?php if ($paginationTotal > 1): ?> &bull; Page <?php echo $paginationCurrent; ?> of <?php echo $paginationTotal; ?><?php endif; ?>
    </span>
    <?php if ($paginationTotal > 1): ?>
        <?php if ($paginationCurrent > 1): ?>
            <?php $paginationParams['p'] = $paginationCurrent - 1; ?>
            <a href="<?php echo htmlspecialchars($paginationBase . '?' . http_build_query($paginationParams), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary" style="padding: 4px 8px; font-size: 12px;">&laquo; Prev</a>
        <?php endif; ?>
        <?php if ($paginationCurrent < $paginationTotal): ?>
            <?php $paginationParams['p'] = $paginationCurrent + 1; ?>
            <a href="<?php echo htmlspecialchars($paginationBase . '?' . http_build_query($paginationParams), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary" style="padding: 4px 8px; font-size: 12px;">Next &raquo;</a>
        <?php endif; ?>
    <?php endif; ?>
</div>
