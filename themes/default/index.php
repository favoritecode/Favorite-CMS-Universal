<?php
require __DIR__ . '/header.php';

$currentPage = $currentPage ?? 1;
$totalPages  = $totalPages ?? 1;
$totalPosts  = $totalPosts ?? count($posts ?? []);
$isHome      = $isHome ?? false;

$layoutService = new \FavoriteCMS\Themes\ThemeLayoutService(\FavoriteCMS\Core\Application::getInstance());
$sections = $isHome ? $layoutService->getSections() : [];
?>

<main class="main-content" role="main">
    <?php if (!empty($archiveTitle)): ?>
        <!-- Archive Header -->
        <header class="page-header-banner">
            <div class="page-header-label">Browsing Archive</div>
            <h1 class="page-header-title"><?php echo htmlspecialchars($archiveTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
            <?php if (!empty($archiveDescription)): ?>
                <p class="page-header-desc"><?php echo htmlspecialchars($archiveDescription, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
        </header>
    <?php endif; ?>

    <?php if ($isHome && !empty($sections)): ?>
        <?php foreach ($sections as $section): ?>
            <?php if (empty($section['enabled'])) continue; ?>

            <?php if ($section['id'] === 'hero'): ?>
                <!-- Homepage Welcome Hero -->
                <section class="home-intro" aria-label="Welcome section">
                    <span class="intro-badge">&#9679; Welcome</span>
                    <h1 class="intro-title"><?php echo htmlspecialchars($siteTitle ?? 'Favorite CMS', ENT_QUOTES, 'UTF-8'); ?></h1>
                    <p class="intro-tagline">
                        <?php echo htmlspecialchars(!empty($siteTagline) ? $siteTagline : 'A fast, modern, and lightweight content management experience built for performance.', ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                </section>
            <?php elseif ($section['id'] === 'featured-posts'): ?>
                <?php
                // Display up to 2 featured posts if available
                $featuredPosts = array_slice($posts ?? [], 0, 2);
                if (!empty($featuredPosts)): ?>
                    <section class="featured-section" aria-label="Featured Stories" style="margin-bottom: 2rem;">
                        <h3 style="font-size: 1.1rem; font-weight: 700; margin-bottom: 1rem; color: var(--color-ink); display: flex; align-items: center; gap: 6px;">
                            <span>⭐</span> Featured Stories
                        </h3>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem;">
                            <?php foreach ($featuredPosts as $fPost): ?>
                                <div style="background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 1.25rem; box-shadow: var(--shadow-xs);">
                                    <h4 style="font-size: 1rem; font-weight: 700; margin-bottom: 0.5rem; line-height: 1.3;">
                                        <a href="/post/<?php echo htmlspecialchars($fPost->slug, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($fPost->title, ENT_QUOTES, 'UTF-8'); ?></a>
                                    </h4>
                                    <p style="font-size: 0.875rem; color: var(--color-muted); line-height: 1.5; margin-bottom: 0.75rem;">
                                        <?php echo htmlspecialchars(mb_substr(strip_tags($fPost->content ?? ''), 0, 110)); ?>...
                                    </p>
                                    <a href="/post/<?php echo htmlspecialchars($fPost->slug, ENT_QUOTES, 'UTF-8'); ?>" style="font-size: 0.8125rem; font-weight: 600;">Read Story &rarr;</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>
            <?php elseif ($section['id'] === 'latest-posts'): ?>
                <!-- Posts Feed -->
                <?php include __DIR__ . '/_posts_feed.php'; ?>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php else: ?>
        <!-- Standard Archive / Single Feed -->
        <?php include __DIR__ . '/_posts_feed.php'; ?>
    <?php endif; ?>
</main>

<?php
require __DIR__ . '/sidebar.php';
require __DIR__ . '/footer.php';
