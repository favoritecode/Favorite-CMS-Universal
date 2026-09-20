<?php
require_once __DIR__ . '/functions.php';

$footerMenu    = fcd_menu_items('footer', 3);
$footerItems   = $footerMenu['items'];
$footerUser    = $currentUser ?? (function_exists('current_user') ? current_user() : null);
$footerRegions = array_values(array_filter(['footer-1', 'footer-2', 'footer-3'], static fn(string $region): bool => has_region_widgets($region)));
$customCopyright = get_theme_mod('footer_copyright');
$customCopyright = is_string($customCopyright) ? trim($customCopyright) : '';

$footerLinks = $footerItems;
$footerLinks[] = (object)['title' => 'Sitemap', 'url' => '/sitemap.xml', 'target' => '', 'children' => []];
if ($footerUser) {
    $footerLinks[] = (object)['title' => 'Admin Area', 'url' => '/admin', 'target' => '', 'children' => []];
}
$scriptUrl = function_exists('theme_asset_url') ? theme_asset_url('assets/js/main.js') : fcd_url('/themes/default/assets/js/main.js');
?>
    </div><!-- /.layout -->
</div><!-- /.site-content -->

<footer class="site-footer" role="contentinfo">
    <?php if ($footerRegions !== []): ?>
        <div class="container footer-widgets footer-widgets--<?php echo count($footerRegions); ?>">
            <?php foreach ($footerRegions as $region): ?>
                <div class="footer-widget-col footer-col-<?php echo fcd_e(substr($region, -1)); ?>">
                    <?php echo render_region($region); ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="container footer-bottom">
        <div class="footer-brand-block">
            <a class="footer-brand" href="<?php echo fcd_e(fcd_url('/')); ?>"><?php echo fcd_e(\FavoriteCMS\Models\Setting::get('general', 'site_name', 'Favorite CMS')); ?></a>
            <p class="footer-copyright">
                &copy; <?php echo date('Y'); ?> <?php echo $customCopyright !== '' ? fcd_e($customCopyright) : 'All rights reserved.'; ?> Powered by <strong>Favorite CMS</strong>.
            </p>
        </div>

        <nav class="footer-nav" aria-label="Footer navigation">
            <?php fcd_partial('nav-menu', [
                'items'       => $footerLinks,
                'menuClass'   => 'menu footer-menu',
                'prependHome' => !fcd_menu_links_home($footerItems),
            ]); ?>
        </nav>
    </div>
</footer>

<script src="<?php echo fcd_e($scriptUrl); ?>" defer></script>
</body>
</html>
