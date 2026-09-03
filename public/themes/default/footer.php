</div><!-- /.site-content -->

<footer class="site-footer" role="contentinfo">
    <div class="footer-container">
        <div class="footer-left">
            <span class="footer-brand"><?php echo htmlspecialchars(\FavoriteCMS\Models\Setting::get('general', 'site_name', 'Favorite CMS'), ENT_QUOTES, 'UTF-8'); ?></span>
            <p>&copy; <?php echo date('Y'); ?> All rights reserved. Powered by <strong style="color: #cbd5e1;">Favorite CMS</strong>.</p>
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
