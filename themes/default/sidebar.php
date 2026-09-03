<?php
use FavoriteCMS\Models\Post;
use FavoriteCMS\Models\Taxonomy;

$recentPosts = Post::published(5);
$categories  = Taxonomy::getByTaxonomy('category');
$tags        = Taxonomy::getByTaxonomy('tag');
?>

<aside class="sidebar" role="complementary" aria-label="Sidebar">
    <!-- Search Widget -->
    <section class="widget">
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
        <section class="widget">
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
        <section class="widget">
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

    <!-- Tags Cloud Widget -->
    <?php if (!empty($tags)): ?>
        <section class="widget">
            <h3 class="widget-title">Popular Tags</h3>
            <div class="tags-cloud-flex">
                <?php foreach ($tags as $t): ?>
                    <a href="/tag/<?php echo htmlspecialchars($t->slug, ENT_QUOTES, 'UTF-8'); ?>" class="tag-cloud-chip">
                        #<?php echo htmlspecialchars($t->name, ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Meta / Quick Links Widget -->
    <section class="widget">
        <h3 class="widget-title">Site Links</h3>
        <ul class="widget-list">
            <li><a href="/sitemap.xml" style="color: var(--color-body);">Sitemap (XML)</a></li>
            <li><a href="/rss.xml" style="color: var(--color-body);">RSS Feed</a></li>
            <?php if (empty($_SESSION['auth_user_id'])): ?>
                <li><a href="/admin/login" style="color: var(--color-muted);">Admin Login &rarr;</a></li>
            <?php else: ?>
                <li><a href="/admin" style="color: var(--color-primary); font-weight: 600;">Admin Dashboard &rarr;</a></li>
            <?php endif; ?>
        </ul>
    </section>
</aside>
