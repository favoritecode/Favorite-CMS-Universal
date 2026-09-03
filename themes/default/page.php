<?php
require __DIR__ . '/header.php';
$featImg = $page->getFeaturedImage();
?>

<main class="main-content" role="main">
    <article class="single-article">
        <header class="single-header" style="border-bottom: none; margin-bottom: 1.5rem; padding-bottom: 0;">
            <h1 class="single-title" style="margin-bottom: 0;"><?php echo htmlspecialchars($page->title, ENT_QUOTES, 'UTF-8'); ?></h1>
        </header>

        <?php if ($featImg && !empty($featImg->url)): ?>
            <div class="single-featured-media">
                <img src="<?php echo htmlspecialchars($featImg->url, ENT_QUOTES, 'UTF-8'); ?>" 
                     alt="<?php echo htmlspecialchars($featImg->alt_text ?: $page->title, ENT_QUOTES, 'UTF-8'); ?>"
                     loading="eager"
                     onerror="this.parentElement.style.display='none';">
            </div>
        <?php endif; ?>

        <div class="entry-content">
            <?php echo clean_post_content($page->content ?? ''); ?>
        </div>
    </article>
</main>

<?php
require __DIR__ . '/sidebar.php';
require __DIR__ . '/footer.php';
