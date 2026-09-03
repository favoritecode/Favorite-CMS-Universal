<?php
$searchQuery = trim((string)($searchQuery ?? $_GET['q'] ?? ''));
$archiveTitle = 'Search: ' . htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8');
$archiveDescription = !empty($posts) 
    ? sprintf('Found %d matching %s for "%s"', count($posts), count($posts) === 1 ? 'article' : 'articles', htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'))
    : sprintf('No articles matching "%s"', htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'));

require __DIR__ . '/header.php';
?>

<main class="main-content" role="main">
    <header class="page-header-banner">
        <div class="page-header-label">Search Results</div>
        <h1 class="page-header-title"><?php echo htmlspecialchars('Search Results for: "' . $searchQuery . '"', ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="page-header-desc"><?php echo $archiveDescription; ?></p>
    </header>

    <?php if (empty($posts)): ?>
        <div class="empty-box">
            <div class="empty-icon-wrap" aria-hidden="true">&#128269;</div>
            <h2 class="empty-heading">No Results Found</h2>
            <p class="empty-message">We couldn't find any articles matching your search terms. Try searching with different keywords or browse our recent posts.</p>
            
            <form method="GET" action="/search" style="max-width: 400px; margin: 0 auto;" role="search">
                <div style="display: flex; gap: 8px;">
                    <input type="search" name="q" class="form-input" placeholder="Search again..." value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>" required>
                    <button type="submit" class="btn-primary">Search</button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <div class="posts-feed">
            <?php foreach ($posts as $post): ?>
                <?php
                $featImg   = $post->getFeaturedImage();
                $author    = $post->getAuthor();
                $cats      = $post->getTaxonomies('category');
                $wordCount = str_word_count(strip_tags($post->content ?? ''));
                $readTime  = max(1, (int)ceil($wordCount / 200));
                $postDate  = $post->published_at ?? $post->created_at;
                ?>
                <article class="post-card">
                    <?php if ($featImg && !empty($featImg->url)): ?>
                        <div class="post-card-thumb">
                            <a href="/post/<?php echo htmlspecialchars($post->slug, ENT_QUOTES, 'UTF-8'); ?>" tabindex="-1" aria-hidden="true">
                                <img src="<?php echo htmlspecialchars($featImg->url, ENT_QUOTES, 'UTF-8'); ?>" 
                                     alt="<?php echo htmlspecialchars($featImg->alt_text ?: $post->title, ENT_QUOTES, 'UTF-8'); ?>"
                                     loading="lazy">
                            </a>
                        </div>
                    <?php endif; ?>

                    <div class="post-card-body">
                        <div class="post-card-meta-top">
                            <?php if (!empty($cats)): ?>
                                <?php foreach (array_slice($cats, 0, 2) as $cat): ?>
                                    <a href="/category/<?php echo htmlspecialchars($cat->slug, ENT_QUOTES, 'UTF-8'); ?>" class="category-pill">
                                        <?php echo htmlspecialchars($cat->name, ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                <?php endforeach; ?>
                                <span class="meta-dot">&bull;</span>
                            <?php endif; ?>
                            <time class="post-card-date" datetime="<?php echo date('c', strtotime($postDate)); ?>">
                                <?php echo date('M j, Y', strtotime($postDate)); ?>
                            </time>
                            <span class="meta-dot">&bull;</span>
                            <span class="post-card-date"><?php echo $readTime; ?> min read</span>
                        </div>

                        <h2 class="post-card-title">
                            <a href="/post/<?php echo htmlspecialchars($post->slug, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($post->title, ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </h2>

                        <div class="post-card-excerpt">
                            <?php
                            if (!empty($post->excerpt)) {
                                echo '<p>' . htmlspecialchars($post->excerpt, ENT_QUOTES, 'UTF-8') . '</p>';
                            } else {
                                $plain = strip_tags($post->content ?? '');
                                echo '<p>' . htmlspecialchars(mb_substr($plain, 0, 170), ENT_QUOTES, 'UTF-8') . (mb_strlen($plain) > 170 ? '...' : '') . '</p>';
                            }
                            ?>
                        </div>

                        <div class="post-card-meta-bottom">
                            <div class="card-author-info">
                                <span class="author-avatar-sm" aria-hidden="true">
                                    <?php echo strtoupper(substr($author?->name ?? $author?->username ?? 'A', 0, 1)); ?>
                                </span>
                                <span><?php echo htmlspecialchars($author?->name ?? $author?->username ?? 'Admin', ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>

                            <a href="/post/<?php echo htmlspecialchars($post->slug, ENT_QUOTES, 'UTF-8'); ?>" class="read-more-link">
                                Read Article &rarr;
                            </a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<?php
require __DIR__ . '/sidebar.php';
require __DIR__ . '/footer.php';
