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
                <time datetime="<?php echo format_date($postDate, 'c'); ?>">
                    <?php echo format_date($postDate, 'F j, Y'); ?>
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
        <?php elseif (!empty($commentNotice)): ?>
            <div class="alert-box alert-success" role="status">
                &#10003; <?php echo htmlspecialchars($commentNotice, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['comment_error'])): ?>
            <div class="alert-box alert-danger" role="alert" style="background: #fee2e2; border: 1px solid #ef4444; color: #b91c1c; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px;">
                &#9888; <?php echo htmlspecialchars($_SESSION['comment_error'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['comment_error']); ?>
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
                                <time class="comment-timestamp" datetime="<?php echo format_date($comment->created_at, 'c'); ?>">
                                    <?php echo format_date($comment->created_at, 'M j, Y \a\t g:i a'); ?>
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
        <?php
        $commentUser = function_exists('current_user') ? current_user() : null;
        $commentBasePath = (string)($GLOBALS['favorite_cms_base_path'] ?? '');
        $commentReturnPath = '/post/' . $post->slug . '#comments';
        ?>
        <div class="comment-form-wrap">
            <?php if ($commentUser): ?>
                <h3 class="form-heading">Leave a Reply</h3>
                <p class="form-subtext">Commenting as <strong><?php echo htmlspecialchars((string)($commentUser->name ?: $commentUser->username), ENT_QUOTES, 'UTF-8'); ?></strong></p>

                <form action="<?php echo htmlspecialchars($commentBasePath . '/post/' . $post->slug . '/comment', ENT_QUOTES, 'UTF-8'); ?>" method="POST">
                    <input type="hidden" name="_token" value="<?php echo htmlspecialchars(function_exists('csrf_token') ? csrf_token() : ($_SESSION['_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="post_id" value="<?php echo (int)$post->id; ?>">
                    <input type="hidden" name="post_slug" value="<?php echo htmlspecialchars($post->slug, ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="form-group">
                        <label for="comment_content" class="form-label">Comment <span class="req">*</span></label>
                        <textarea id="comment_content" name="content" class="form-textarea" rows="4" required placeholder="Share your thoughts..."></textarea>
                    </div>

                    <button type="submit" class="btn-primary">Post Comment</button>
                </form>
            <?php else: ?>
                <h3 class="form-heading">Join the Discussion</h3>
                <p class="form-subtext">You need an account to comment on this article.</p>

                <div class="comment-auth-actions" style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
                    <a href="<?php echo htmlspecialchars($commentBasePath . '/admin/login?redirect=' . rawurlencode($commentReturnPath), ENT_QUOTES, 'UTF-8'); ?>" class="btn-primary">Log in to comment</a>
                    <?php if ((int)\FavoriteCMS\Models\Setting::get('general', 'allow_registration', 1)): ?>
                        <a href="<?php echo htmlspecialchars($commentBasePath . '/register?redirect=' . rawurlencode($commentReturnPath), ENT_QUOTES, 'UTF-8'); ?>" class="btn-secondary">Create an account</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>

<?php
require __DIR__ . '/sidebar.php';
require __DIR__ . '/footer.php';
