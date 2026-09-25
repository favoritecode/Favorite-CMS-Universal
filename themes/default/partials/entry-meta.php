<?php
/**
 * Post metadata: author, date, reading time and (on single posts) comment count.
 *
 * @var object      $post
 * @var string|null $context      'card' or 'single'
 * @var int|null    $commentCount Approved comment count (single posts)
 */
$context    = ($context ?? 'card') === 'single' ? 'single' : 'card';
$author     = method_exists($post, 'getAuthor') ? $post->getAuthor() : null;
$authorName = fcd_display_name($author);
$avatarUrl  = ($author && method_exists($author, 'getAvatarUrl')) ? $author->getAvatarUrl() : null;
$postDate   = $post->published_at ?? $post->created_at;
$readTime   = fcd_read_time((string)($post->content ?? ''));
?>
<ul class="entry-meta entry-meta--<?php echo $context; ?>">
    <li class="entry-meta__author">
        <span class="avatar" aria-hidden="true">
            <?php if ($avatarUrl): ?>
                <img src="<?php echo fcd_e(fcd_url($avatarUrl)); ?>" alt="" width="28" height="28" loading="lazy" decoding="async">
            <?php else: ?>
                <?php echo fcd_e(fcd_initial($authorName)); ?>
            <?php endif; ?>
        </span>
        <span class="entry-meta__author-name"><?php echo fcd_e($authorName); ?></span>
    </li>
    <li><time datetime="<?php echo fcd_e(format_date($postDate, 'c')); ?>"><?php echo fcd_e(format_date($postDate, $context === 'single' ? 'F j, Y' : 'M j, Y')); ?></time></li>
    <li><?php echo $readTime; ?> min read</li>
    <?php if ($context === 'single' && !empty($commentCount)): ?>
        <li><a href="#comments"><?php echo (int)$commentCount; ?> <?php echo (int)$commentCount === 1 ? 'Comment' : 'Comments'; ?></a></li>
    <?php endif; ?>
</ul>
