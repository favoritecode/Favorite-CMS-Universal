<?php
use FavoriteCMS\Models\Post;
use FavoriteCMS\Models\Taxonomy;

$siteLayout = get_theme_mod('site_layout', 'right');
if ($siteLayout === 'none') {
    return;
}
?>

<aside class="sidebar sidebar-<?php echo htmlspecialchars($siteLayout); ?>" role="complementary" aria-label="Sidebar">
    <?php if (has_region_widgets('sidebar-primary')): ?>
        <?php echo render_region('sidebar-primary'); ?>
    <?php else: ?>
        <!-- Default Fallback Widgets -->
        <?php
        $recentPosts = Post::published(5);
        $categories  = Taxonomy::getByTaxonomy('category');
        $tags        = Taxonomy::getByTaxonomy('tag');
        ?>

        <!-- Search Widget -->
        <section class="widget widget_search">
            <h3 class="widget-title">Search Articles</h3>
            <form method="GET" action="/search" class="widget-search-form" role="search">
                <input type="search" name="q" class="widget-search-input" placeholder="Search keywords..." value="<?php echo htmlspecialchars($_GET['q'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                <button type="submit" class="widget-search-btn" aria-label="Search">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                </button>
            </form>
        </section>

        <!-- Recent Posts Widget -->
        <?php if (!empty($recentPosts)): ?>
            <section class="widget widget_recent_posts">
                <h3 class="widget-title">Recent Articles</h3>
                <ul class="widget-list">
                    <?php foreach ($recentPosts as $rp): ?>
                        <li>
                            <a href="/post/<?php echo htmlspecialchars($rp->slug, ENT_QUOTES, 'UTF-8'); ?>" class="recent-post-link">
                                <?php echo htmlspecialchars($rp->title, ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                            <div class="recent-post-meta">
                                <?php echo date('M j, Y', strtotime($rp->published_at ?? $rp->created_at)); ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <!-- Categories Widget -->
        <?php if (!empty($categories)): ?>
            <section class="widget widget_categories">
                <h3 class="widget-title">Categories</h3>
                <ul class="widget-list">
                    <?php foreach ($categories as $cat): ?>
                        <li>
                            <a href="/category/<?php echo htmlspecialchars($cat->slug, ENT_QUOTES, 'UTF-8'); ?>" class="category-row">
                                <span><?php echo htmlspecialchars($cat->name, ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="category-badge-count"><?php echo (int)($cat->count ?? 0); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <!-- Tags Widget -->
        <?php if (!empty($tags)): ?>
            <section class="widget widget_tags">
                <h3 class="widget-title">Popular Tags</h3>
                <div class="widget-tags">
                    <?php foreach (array_slice($tags, 0, 15) as $t): ?>
                        <a href="/tag/<?php echo htmlspecialchars($t->slug, ENT_QUOTES, 'UTF-8'); ?>" class="tag-pill">
                            #<?php echo htmlspecialchars($t->name, ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</aside>
