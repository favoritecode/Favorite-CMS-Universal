</div><!-- /.site-content -->

<footer class="site-footer" role="contentinfo">
    <?php if (has_region_widgets('footer-1') || has_region_widgets('footer-2') || has_region_widgets('footer-3')): ?>
        <div class="footer-widgets-container" style="max-width: 1200px; margin: 0 auto 32px; padding: 0 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 32px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 32px;">
            <?php if (has_region_widgets('footer-1')): ?>
                <div class="footer-widget-col footer-col-1">
                    <?php echo render_region('footer-1'); ?>
                </div>
            <?php endif; ?>
            <?php if (has_region_widgets('footer-2')): ?>
                <div class="footer-widget-col footer-col-2">
                    <?php echo render_region('footer-2'); ?>
                </div>
            <?php endif; ?>
            <?php if (has_region_widgets('footer-3')): ?>
                <div class="footer-widget-col footer-col-3">
                    <?php echo render_region('footer-3'); ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="footer-container">
        <div class="footer-left">
            <span class="footer-brand"><?php echo htmlspecialchars(\FavoriteCMS\Models\Setting::get('general', 'site_name', 'Favorite CMS'), ENT_QUOTES, 'UTF-8'); ?></span>
            <?php
            $customCopyright = get_theme_mod('footer_copyright');
            if (!empty($customCopyright)): ?>
                <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($customCopyright, ENT_QUOTES, 'UTF-8'); ?> Powered by <strong style="color: #cbd5e1;">Favorite CMS</strong>.</p>
            <?php else: ?>
                <p>&copy; <?php echo date('Y'); ?> All rights reserved. Powered by <strong style="color: #cbd5e1;">Favorite CMS</strong>.</p>
            <?php endif; ?>
        </div>

        <nav class="footer-nav" aria-label="Footer Navigation">
            <ul>
                <li><a href="/">Home</a></li>
                <?php
                $footerMenu = \FavoriteCMS\Models\Menu::findByLocation('footer');
                if ($footerMenu) {
                    foreach ($footerMenu->getItems() as $item) {
                        echo '<li><a href="' . htmlspecialchars($item->url ?? '#', ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($item->title, ENT_QUOTES, 'UTF-8') . '</a></li>';
                    }
                } else {
                    $pages = \FavoriteCMS\Models\Page::published();
                    foreach (array_slice($pages, 0, 3) as $p) {
                        echo '<li><a href="/page/' . $p->slug . '">' . htmlspecialchars($p->title, ENT_QUOTES, 'UTF-8') . '</a></li>';
                    }
                }
                ?>
                <li><a href="/sitemap.xml">Sitemap</a></li>
                <li><a href="/admin">Admin Area</a></li>
            </ul>
        </nav>
    </div>
</footer>

<script src="/themes/default/assets/js/main.js" defer></script>
</body>
</html>
