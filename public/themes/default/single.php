<?php
require __DIR__ . '/header.php';

$author       = $post->getAuthor();
$cats         = $post->getTaxonomies('category');
$tags         = $post->getTaxonomies('tag');
$featImg      = $post->getFeaturedImage();
$comments     = $post->getComments('approved');
$previousPost = $previousPost ?? null;
$nextPost     = $nextPost ?? null;
$authorBio    = $author ? trim((string)($author->bio ?? '')) : '';
?>

<main class="site-main site-main--singular" id="main-content" tabindex="-1">
    <article class="entry entry--post" id="post-<?php echo (int)$post->id; ?>">
        <header class="entry-header">
            <?php if (!empty($cats)): ?>
                <p class="entry-categories">
                    <?php foreach ($cats as $cat): ?>
                        <a class="category-pill" href="<?php echo fcd_e(fcd_url('/category/' . $cat->slug)); ?>"><?php echo fcd_e($cat->name); ?></a>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>

            <h1 class="entry-title"><?php echo fcd_e($post->title); ?></h1>

            <?php fcd_partial('entry-meta', ['post' => $post, 'context' => 'single', 'commentCount' => count($comments)]); ?>
        </header>

        <?php if ($featImg && !empty($featImg->url)): ?>
            <figure class="entry-media">
                <img src="<?php echo fcd_e(fcd_url((string)$featImg->url)); ?>"
                     alt="<?php echo fcd_e($featImg->alt_text ?: $post->title); ?>"<?php echo fcd_image_dimensions($featImg); ?>
                     loading="eager" fetchpriority="high" decoding="async">
            </figure>
        <?php endif; ?>

        <div class="entry-content">
            <?php echo fcd_prepare_content(clean_post_content($post->content ?? '')); ?>
        </div>

        <?php if (!empty($tags)): ?>
            <footer class="entry-footer">
                <p class="entry-tags">
                    <span class="entry-tags__label">Tags:</span>
                    <?php foreach ($tags as $tag): ?>
                        <a class="tag-badge" href="<?php echo fcd_e(fcd_url('/tag/' . $tag->slug)); ?>">#<?php echo fcd_e($tag->name); ?></a>
                    <?php endforeach; ?>
                </p>
            </footer>
        <?php endif; ?>

        <?php if ($author): ?>
            <?php $authorAvatar = method_exists($author, 'getAvatarUrl') ? $author->getAvatarUrl() : null; ?>
            <section class="author-box" aria-label="About the author">
                <span class="avatar avatar--large" aria-hidden="true">
                    <?php if ($authorAvatar): ?>
                        <img src="<?php echo fcd_e(fcd_url($authorAvatar)); ?>" alt="" width="56" height="56" loading="lazy" decoding="async">
                    <?php else: ?>
                        <?php echo fcd_e(fcd_initial(fcd_display_name($author))); ?>
                    <?php endif; ?>
                </span>
                <div class="author-box__body">
                    <p class="author-box__label">Written by</p>
                    <p class="author-box__name"><?php echo fcd_e(fcd_display_name($author)); ?></p>
                    <?php if ($authorBio !== ''): ?>
                        <p class="author-box__bio"><?php echo nl2br(fcd_e($authorBio)); ?></p>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    </article>

    <?php fcd_partial('post-navigation', ['previousPost' => $previousPost, 'nextPost' => $nextPost]); ?>

    <?php fcd_partial('comments', ['post' => $post, 'comments' => $comments, 'commentNotice' => $commentNotice ?? null]); ?>
</main>

<?php
require __DIR__ . '/sidebar.php';
require __DIR__ . '/footer.php';
