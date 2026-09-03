<?php
require __DIR__ . '/header.php';

$author    = $post->getAuthor();
$cats      = $post->getTaxonomies('category');
$tags      = $post->getTaxonomies('tag');
$featImg   = $post->getFeaturedImage();
$comments  = $post->getComments('approved');
$wordCount = str_word_count(strip_tags($post->content ?? ''));
$readTime  = max(1, (int)ceil($wordCount / 200));
$postDate  = $post->published_at ?? $post->created_at;
$prevPost  = $post->getPrevious();
$nextPost  = $post->getNext();
?>

<main class="main-content" role="main">
    <article class="single-article">
        <header class="single-header">
            <?php if (!empty($cats)): ?>
                <div class="single-categories">
                    <?php foreach ($cats as $cat): ?>
                        <a href="/category/<?php echo htmlspecialchars($cat->slug, ENT_QUOTES, 'UTF-8'); ?>" class="category-pill">
                            <?php echo htmlspecialchars($cat->name, ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h1 class="single-title"><?php echo htmlspecialchars($post->title, ENT_QUOTES, 'UTF-8'); ?></h1>

            <div class="single-meta-row">
                <span class="single-author-badge">
                    <span class="author-avatar-sm" aria-hidden="true">
                        <?php echo strtoupper(substr($author?->name ?? $author?->username ?? 'A', 0, 1)); ?>
                    </span>
                    <span><?php echo htmlspecialchars($author?->name ?? $author?->username ?? 'Admin', ENT_QUOTES, 'UTF-8'); ?></span>
                </span>
                <span class="meta-dot">&bull;</span>
                <time datetime="<?php echo date('c', strtotime($postDate)); ?>">
                    <?php echo date('F j, Y', strtotime($postDate)); ?>
                </time>
                <span class="meta-dot">&bull;</span>
                <span><?php echo $readTime; ?> min read</span>
                <?php if (count($comments) > 0): ?>
                    <span class="meta-dot">&bull;</span>
                    <a href="#comments" style="color: var(--color-muted);"><?php echo count($comments); ?> <?php echo count($comments) === 1 ? 'Comment' : 'Comments'; ?></a>
                <?php endif; ?>
            </div>
        </header>

        <?php if ($featImg && !empty($featImg->url)): ?>
            <div class="single-featured-media">
                <img src="<?php echo htmlspecialchars($featImg->url, ENT_QUOTES, 'UTF-8'); ?>" 
                     alt="<?php echo htmlspecialchars($featImg->alt_text ?: $post->title, ENT_QUOTES, 'UTF-8'); ?>"
                     loading="eager"
                     onerror="this.parentElement.style.display='none';">
            </div>
        <?php endif; ?>

        <!-- Clean Formatted Content -->
        <div class="entry-content">
            <?php echo clean_post_content($post->content ?? ''); ?>
        </div>

        <!-- Tags Footer -->
        <?php if (!empty($tags)): ?>
            <footer class="single-footer">
                <div class="tags-wrap">
                    <span class="tags-heading">Tags:</span>
                    <?php foreach ($tags as $tag): ?>
                        <a href="/tag/<?php echo htmlspecialchars($tag->slug, ENT_QUOTES, 'UTF-8'); ?>" class="tag-badge">
                            #<?php echo htmlspecialchars($tag->name, ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </footer>
        <?php endif; ?>
    </article>

    <!-- Post Previous / Next Navigation -->
    <?php if ($prevPost || $nextPost): ?>
        <nav class="post-navigation" aria-label="Article navigation">
            <div class="nav-grid">
                <?php if ($prevPost): ?>
                    <a href="/post/<?php echo htmlspecialchars($prevPost->slug, ENT_QUOTES, 'UTF-8'); ?>" class="nav-card">
                        <span class="nav-direction">&larr; Older Article</span>
                        <span class="nav-article-title"><?php echo htmlspecialchars($prevPost->title, ENT_QUOTES, 'UTF-8'); ?></span>
                    </a>
                <?php else: ?>
                    <div></div>
                <?php endif; ?>

                <?php if ($nextPost): ?>
                    <a href="/post/<?php echo htmlspecialchars($nextPost->slug, ENT_QUOTES, 'UTF-8'); ?>" class="nav-card" style="text-align: right;">
                        <span class="nav-direction">Newer Article &rarr;</span>
                        <span class="nav-article-title"><?php echo htmlspecialchars($nextPost->title, ENT_QUOTES, 'UTF-8'); ?></span>
                    </a>
                <?php endif; ?>
            </div>
        </nav>
    <?php endif; ?>

    <!-- Comments Section -->
    <section class="comments-section" id="comments" aria-label="Comments">
        <h2 class="comments-header-title">
            <span>&#128172;</span>
            <span>Discussion (<?php echo count($comments); ?>)</span>
        </h2>

        <?php if (!empty($_SESSION['flash_comment_success'])): ?>
            <div class="alert-box alert-success" role="status">
                &#10003; <?php echo htmlspecialchars($_SESSION['flash_comment_success'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['flash_comment_success']); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($comments)): ?>
            <div class="comments-list">
                <?php foreach ($comments as $comment): ?>
                    <div class="comment-card">
                        <div class="comment-avatar" aria-hidden="true">
                            <?php echo strtoupper(substr($comment->author_name ?? 'A', 0, 1)); ?>
                        </div>
                        <div class="comment-main">
                            <div class="comment-meta">
                                <span class="comment-author-name"><?php echo htmlspecialchars($comment->author_name ?? 'Anonymous', ENT_QUOTES, 'UTF-8'); ?></span>
                                <time class="comment-timestamp" datetime="<?php echo date('c', strtotime($comment->created_at)); ?>">
                                    <?php echo date('M j, Y \a\t g:i a', strtotime($comment->created_at)); ?>
                                </time>
                            </div>
                            <div class="comment-text">
                                <?php echo nl2br(htmlspecialchars($comment->content ?? '', ENT_QUOTES, 'UTF-8')); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Comment Reply Form -->
        <div class="comment-form-wrap">
            <h3 class="form-heading">Leave a Reply</h3>
            <p class="form-subtext">Your email address will not be published. Required fields are marked <span class="req">*</span></p>

            <form action="/post/<?php echo htmlspecialchars($post->slug, ENT_QUOTES, 'UTF-8'); ?>/comment" method="POST">
                <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

                <div class="form-grid-2">
                    <div class="form-group">
                        <label for="author_name" class="form-label">Your Name <span class="req">*</span></label>
                        <input type="text" id="author_name" name="author_name" class="form-input" required placeholder="Jane Doe">
                    </div>
                    <div class="form-group">
                        <label for="author_email" class="form-label">Your Email <span class="req">*</span></label>
                        <input type="email" id="author_email" name="author_email" class="form-input" required placeholder="jane@example.com">
                    </div>
                </div>

                <div class="form-group">
                    <label for="comment_content" class="form-label">Comment <span class="req">*</span></label>
                    <textarea id="comment_content" name="content" class="form-textarea" rows="4" required placeholder="Share your thoughts..."></textarea>
                </div>

                <button type="submit" class="btn-primary">Post Comment</button>
            </form>
        </div>
    </section>
</main>

<?php
require __DIR__ . '/sidebar.php';
require __DIR__ . '/footer.php';
