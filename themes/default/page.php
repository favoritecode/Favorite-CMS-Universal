<?php
require __DIR__ . '/header.php';
$featImg = $page->getFeaturedImage();
?>

<main class="site-main site-main--singular" id="main-content" tabindex="-1">
    <article class="entry entry--page" id="page-<?php echo (int)$page->id; ?>">
        <header class="entry-header">
            <h1 class="entry-title"><?php echo fcd_e($page->title); ?></h1>
        </header>

        <?php if ($featImg && !empty($featImg->url)): ?>
            <figure class="entry-media">
                <img src="<?php echo fcd_e(fcd_url((string)$featImg->url)); ?>"
                     alt="<?php echo fcd_e($featImg->alt_text ?: $page->title); ?>"<?php echo fcd_image_dimensions($featImg); ?>
                     loading="eager" fetchpriority="high" decoding="async">
            </figure>
        <?php endif; ?>

        <div class="entry-content">
            <?php echo fcd_prepare_content(clean_post_content($page->content ?? '')); ?>
        </div>
    </article>
</main>

<?php
require __DIR__ . '/sidebar.php';
require __DIR__ . '/footer.php';
