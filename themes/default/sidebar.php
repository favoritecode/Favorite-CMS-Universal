<?php
require_once __DIR__ . '/functions.php';

$siteLayout = fcd_site_layout();
if ($siteLayout === 'none') {
    return;
}
?>
<aside class="sidebar sidebar-<?php echo fcd_e($siteLayout); ?>" aria-label="Sidebar">
    <div class="sidebar__inner">
        <?php if (has_region_widgets('sidebar-primary')): ?>
            <?php echo render_region('sidebar-primary'); ?>
        <?php else: ?>
            <?php
            // Fallback when the region is empty: render the Core widgets themselves (no duplicated widget markup)
            $fallbackRegistry = \FavoriteCMS\Widgets\WidgetRegistry::getInstance();
            $fallbackRegistry->ensureBooted();
            $fallbackWidgets = [
                ['search', ['title' => 'Search Articles', 'placeholder' => 'Search keywords...']],
                ['recent_posts', ['title' => 'Recent Articles', 'number' => 5, 'show_date' => true, 'show_thumb' => false]],
                ['categories', ['title' => 'Categories', 'show_count' => true, 'hide_empty' => false]],
                ['tags', ['title' => 'Popular Tags', 'limit' => 15]],
            ];
            foreach ($fallbackWidgets as [$fallbackId, $fallbackSettings]) {
                $fallbackWidget = $fallbackRegistry->get($fallbackId);
                if ($fallbackWidget) {
                    echo $fallbackWidget->render($fallbackSettings);
                }
            }
            ?>
        <?php endif; ?>
    </div>
</aside>
