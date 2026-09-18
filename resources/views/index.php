<?php
/**
 * Favorite CMS Universal — Standalone Core Fallback Index View
 *
 * Rendered when no external theme is active or when themes are decoupled.
 *
 * @var array $posts
 * @var string|null $archiveTitle
 * @var bool $isHome
 * @var int $currentPage
 * @var int $totalPages
 * @var int $totalPosts
 */

$siteName = (string)\FavoriteCMS\Models\Setting::get('general', 'site_name', 'Favorite CMS Universal');
$siteDesc = (string)\FavoriteCMS\Models\Setting::get('general', 'site_description', 'One CMS. Any Website.');
$base = (string)($GLOBALS['favorite_cms_base_path'] ?? '');
$currentVersion = defined('APP_VERSION') ? APP_VERSION : '1.0.0';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($siteName); ?><?php if ($siteDesc): ?> &mdash; <?php echo htmlspecialchars($siteDesc); ?><?php endif; ?></title>
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --text: #0f172a;
            --text-muted: #64748b;
            --bg: #f8fafc;
            --card-bg: #ffffff;
            --border: #e2e8f0;
            --font: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }
        *, *::before, *::after { box-sizing: border-box; }
        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text);
            margin: 0;
            padding: 0;
            line-height: 1.6;
        }
        .container {
            max-width: 880px;
            margin: 0 auto;
            padding: 0 20px;
        }
        header {
            background: var(--card-bg);
            border-bottom: 1px solid var(--border);
            padding: 24px 0;
            margin-bottom: 40px;
        }
        .header-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }
        .site-branding h1 {
            font-size: 22px;
            font-weight: 700;
            margin: 0 0 4px 0;
        }
        .site-branding h1 a {
            color: var(--text);
            text-decoration: none;
        }
        .site-branding p {
            color: var(--text-muted);
            font-size: 13px;
            margin: 0;
        }
        .main-nav a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            margin-right: 16px;
        }
        .main-nav a:hover { text-decoration: underline; }
        .header-search input {
            padding: 6px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 13px;
        }
        .header-account-wrap a {
            color: var(--text);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            margin-left: 12px;
        }
        .header-account-wrap a:hover { color: var(--primary); }
        .hero {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            color: #ffffff;
            border-radius: 12px;
            padding: 40px;
            margin-bottom: 40px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        .hero-badge {
            display: inline-block;
            background: rgba(37, 99, 235, 0.25);
            border: 1px solid rgba(59, 130, 246, 0.5);
            color: #93c5fd;
            font-size: 12px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 9999px;
            margin-bottom: 16px;
        }
        .hero h2 {
            font-size: 28px;
            font-weight: 800;
            margin: 0 0 12px 0;
            letter-spacing: -0.02em;
        }
        .hero p {
            color: #94a3b8;
            font-size: 15px;
            margin: 0 0 20px 0;
            max-width: 640px;
        }
        .hero-actions a {
            display: inline-block;
            background: var(--primary);
            color: #ffffff;
            font-weight: 600;
            font-size: 14px;
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            transition: background 0.15s ease;
        }
        .hero-actions a:hover {
            background: var(--primary-hover);
        }
        .posts-section {
            margin-bottom: 40px;
        }
        .posts-heading {
            font-size: 20px;
            font-weight: 700;
            margin: 0 0 20px 0;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--border);
        }
        .post-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 20px;
            transition: box-shadow 0.15s ease;
        }
        .post-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }
        .post-title {
            font-size: 19px;
            font-weight: 700;
            margin: 0 0 8px 0;
        }
        .post-title a {
            color: var(--text);
            text-decoration: none;
        }
        .post-title a:hover {
            color: var(--primary);
        }
        .post-meta {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 12px;
        }
        .post-excerpt {
            font-size: 14px;
            color: #334155;
            margin: 0 0 16px 0;
        }
        .empty-posts {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 32px;
            text-align: center;
            color: var(--text-muted);
        }
        footer {
            border-top: 1px solid var(--border);
            padding: 32px 0;
            text-align: center;
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 60px;
        }
    </style>
</head>
<body>
    <header>
        <div class="container header-inner">
            <div class="site-branding">
                <h1><a href="<?php echo $base; ?>/"><?php echo htmlspecialchars($siteName); ?></a></h1>
                <?php if ($siteDesc): ?>
                    <p><?php echo htmlspecialchars($siteDesc); ?></p>
                <?php endif; ?>
            </div>
            <nav class="main-nav">
                <a href="<?php echo $base; ?>/">Home</a>
                <a href="<?php echo $base; ?>/admin">Admin Panel</a>
            </nav>
            <div class="header-search">
                <form action="<?php echo $base; ?>/search" method="GET">
                    <input type="search" name="q" placeholder="Search…">
                </form>
            </div>
            <?php
            $headerUser = function_exists('current_user') ? current_user() : null;
            if (!$headerUser && !empty($_SESSION['auth_user_id'])) {
                $headerUser = \FavoriteCMS\Models\User::find((int)$_SESSION['auth_user_id']);
            }
            if ($headerUser):
                $accountItems = \FavoriteCMS\Core\AccountMenu::getItems($headerUser);
            ?>
                <div class="header-account-wrap">
                    <?php foreach ($accountItems as $item): ?>
                        <a href="<?php echo (string)$base . (string)$item['url']; ?>"><?php echo htmlspecialchars((string)$item['label']); ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <main class="container">
        <?php if (isset($post) && !isset($posts)): ?>
            <article class="post-card" style="margin-top: 24px;">
                <h2 style="font-size: 26px; font-weight: 800; margin: 0 0 12px 0;"><?php echo htmlspecialchars((string)($post->title ?? '')); ?></h2>
                <div class="post-meta">
                    Published on <?php echo date('F j, Y', strtotime((string)($post->published_at ?? $post->created_at ?? 'now'))); ?>
                </div>
                <div class="post-content" style="margin: 24px 0; line-height: 1.8; font-size: 15px;">
                    <?php echo (string)($post->content ?? ''); ?>
                </div>

                <?php
                $comments = method_exists($post, 'comments') ? $post->comments() : [];
                if (!empty($comments)):
                ?>
                    <div style="margin-top: 40px; border-top: 1px solid var(--border); padding-top: 24px;">
                        <h3 style="font-size: 18px; font-weight: 700; margin-bottom: 16px;">Comments</h3>
                        <?php foreach ($comments as $comment): ?>
                            <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: 6px; padding: 12px 16px; margin-bottom: 12px;">
                                <strong style="font-size: 13.5px;"><?php echo htmlspecialchars((string)($comment->author_name ?? 'Anonymous')); ?>:</strong>
                                <p style="margin: 4px 0 0; font-size: 13.5px; color: #334155;"><?php echo htmlspecialchars((string)($comment->content ?? '')); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--border);">
                    <a href="<?php echo $base; ?>/" style="color: var(--primary); font-size: 13.5px; text-decoration: none;">&larr; Back to Home</a>
                </div>
            </article>
        <?php elseif (isset($page)): ?>
            <article class="post-card" style="margin-top: 24px;">
                <h2 style="font-size: 26px; font-weight: 800; margin: 0 0 12px 0;"><?php echo htmlspecialchars((string)($page->title ?? '')); ?></h2>
                <div class="post-content" style="margin: 24px 0; line-height: 1.8; font-size: 15px;">
                    <?php echo (string)($page->content ?? ''); ?>
                </div>
                <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--border);">
                    <a href="<?php echo $base; ?>/" style="color: var(--primary); font-size: 13.5px; text-decoration: none;">&larr; Back to Home</a>
                </div>
            </article>
        <?php else: ?>
            <div class="hero">
                <span class="hero-badge">Favorite CMS Universal v<?php echo htmlspecialchars($currentVersion); ?></span>
                <h2>Welcome to Your New Website</h2>
                <p>
                    Your Favorite CMS Universal Core is active and operational. Themes and plugins are fully decoupled for speed, reliability, and security. You can install official themes and plugins at any time from your administration panel.
                </p>
                <div class="hero-actions">
                    <a href="<?php echo $base; ?>/admin">Go to Admin Dashboard &rarr;</a>
                </div>
            </div>

            <section class="posts-section">
                <h3 class="posts-heading"><?php echo htmlspecialchars($archiveTitle ?? 'Latest Posts'); ?></h3>

                <?php if (!empty($posts)): ?>
                    <?php foreach ($posts as $postItem): ?>
                        <article class="post-card">
                            <h4 class="post-title">
                                <a href="<?php echo $base; ?>/post/<?php echo htmlspecialchars((string)($postItem->slug ?? '')); ?>">
                                    <?php echo htmlspecialchars((string)($postItem->title ?? 'Untitled')); ?>
                                </a>
                            </h4>
                            <div class="post-meta">
                                Published on <?php echo date('F j, Y', strtotime((string)($postItem->published_at ?? $postItem->created_at ?? 'now'))); ?>
                            </div>
                            <div class="post-excerpt">
                                <?php echo htmlspecialchars((string)($postItem->excerpt ?? substr(strip_tags((string)($postItem->content ?? '')), 0, 160))); ?>
                            </div>
                            <a href="<?php echo $base; ?>/post/<?php echo htmlspecialchars((string)($postItem->slug ?? '')); ?>" style="color: var(--primary); font-size: 13px; font-weight: 600; text-decoration: none;">
                                Read More &rarr;
                            </a>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-posts">
                        <p style="margin: 0 0 12px 0; font-size: 15px; font-weight: 600; color: #1e293b;">No posts published yet</p>
                        <p style="margin: 0; font-size: 13px;">Log in to the administration dashboard to create your first article or page.</p>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>

    <footer>
        <div class="container">
            <p style="margin: 0 0 6px 0;">Powered by <a href="https://github.com/favoritecode/Favorite-CMS-Universal" target="_blank" rel="noopener" style="color: var(--primary); text-decoration: none;">Favorite CMS Universal</a> v<?php echo htmlspecialchars($currentVersion); ?></p>
            <p style="margin: 0;">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($siteName); ?>. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>
