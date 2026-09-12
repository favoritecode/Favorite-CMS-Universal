<?php
/**
 * Older / newer article navigation. Uses the posts provided by the controller (no extra queries).
 *
 * @var object|null $previousPost Older article
 * @var object|null $nextPost     Newer article
 */
if (empty($previousPost) && empty($nextPost)) {
    return;
}
?>
<nav class="post-navigation" aria-label="Article navigation">
    <?php if (!empty($previousPost)): ?>
        <a class="post-navigation__link post-navigation__link--prev" href="<?php echo fcd_e(fcd_url('/post/' . $previousPost->slug)); ?>">
            <span class="post-navigation__label"><span aria-hidden="true">&larr;</span> Older Article</span>
            <span class="post-navigation__title"><?php echo fcd_e($previousPost->title); ?></span>
        </a>
    <?php endif; ?>
    <?php if (!empty($nextPost)): ?>
        <a class="post-navigation__link post-navigation__link--next" href="<?php echo fcd_e(fcd_url('/post/' . $nextPost->slug)); ?>">
            <span class="post-navigation__label">Newer Article <span aria-hidden="true">&rarr;</span></span>
            <span class="post-navigation__title"><?php echo fcd_e($nextPost->title); ?></span>
        </a>
    <?php endif; ?>
</nav>
