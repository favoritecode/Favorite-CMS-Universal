<?php
/**
 * Homepage featured stories (first page only). The latest section skips these posts.
 *
 * @var array<int, object> $posts
 * @var int|null           $headingLevel Card title heading level
 */
$posts = is_array($posts ?? null) ? $posts : [];
if ($posts === []) {
    return;
}
$cardHeading = (int)($headingLevel ?? 3);
?>
<section class="home-section home-featured" aria-labelledby="home-featured-title">
    <div class="section-heading">
        <h2 class="section-heading__title" id="home-featured-title">Featured Stories</h2>
    </div>
    <div class="featured-grid">
        <?php foreach ($posts as $index => $featuredPost): ?>
            <?php fcd_partial('post-card', [
                'post'         => $featuredPost,
                'variant'      => $index === 0 ? 'lead' : 'default',
                'headingLevel' => $cardHeading,
                'eager'        => $index === 0,
            ]); ?>
        <?php endforeach; ?>
    </div>
</section>
