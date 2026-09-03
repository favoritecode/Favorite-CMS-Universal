<?php if (empty($posts)): ?>
    <div class="empty-box">
        <div class="empty-icon-wrap" aria-hidden="true">&#128196;</div>
        <h2 class="empty-heading">No Articles Published Yet</h2>
        <p class="empty-message">Welcome to your new site! Once articles are published, they will automatically appear here.</p>
        <?php if (!empty($_SESSION['auth_user_id'])): ?>
            <a href="/admin/posts/new" class="btn-primary">Write Your First Post</a>
        <?php endif; ?>
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
                                 loading="lazy"
                                 onerror="this.parentElement.parentElement.style.display='none';">
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

                        <a href="/post/<?php echo htmlspecialchars($post->slug, ENT_QUOTES, 'UTF-8'); ?>" class="read-more-link" aria-label="Read full post: <?php echo htmlspecialchars($post->title, ENT_QUOTES, 'UTF-8'); ?>">
                            Read Article &rarr;
                        </a>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <!-- Pagination Controls -->
    <?php if ($totalPages > 1): ?>
        <nav class="pagination-container" aria-label="Posts pagination">
            <?php $queryParams = $_GET; ?>
            <?php if ($currentPage > 1): ?>
                <?php $queryParams['page'] = $currentPage - 1; ?>
                <a href="?<?php echo http_build_query($queryParams); ?>" class="pag-btn" aria-label="Previous page">&larr; Prev</a>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <?php $queryParams['page'] = $i; ?>
                <?php if ($i === $currentPage): ?>
                    <span class="pag-current" aria-current="page"><?php echo $i; ?></span>
                <?php else: ?>
                    <a href="?<?php echo http_build_query($queryParams); ?>" class="pag-num"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($currentPage < $totalPages): ?>
                <?php $queryParams['page'] = $currentPage + 1; ?>
                <a href="?<?php echo http_build_query($queryParams); ?>" class="pag-btn" aria-label="Next page">Next &rarr;</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

