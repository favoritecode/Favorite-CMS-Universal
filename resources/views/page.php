<?php
/**
 * Favorite CMS Universal — Standalone Core Fallback Page View
 *
 * Rendered when no external theme is active or when themes are decoupled.
 *
 * @var object|array $page
 */

$siteName = (string)\FavoriteCMS\Models\Setting::get('general', 'site_name', 'Favorite CMS Universal');
$siteDesc = (string)\FavoriteCMS\Models\Setting::get('general', 'site_description', 'One CMS. Any Website.');
$base = (string)($GLOBALS['favorite_cms_base_path'] ?? '');
$currentVersion = defined('APP_VERSION') ? APP_VERSION : '1.0.0';

$pageObj = is_array($page) ? (object)$page : $page;
$title = (string)($pageObj->title ?? 'Untitled Page');
$content = (string)($pageObj->content ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?> &mdash; <?php echo htmlspecialchars($siteName); ?></title>
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
            padding: 20px 0;
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
        .post-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 32px;
            margin-bottom: 20px;
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
        <article class="post-card">
            <h1 style="font-size: 28px; font-weight: 800; margin: 0 0 12px 0;"><?php echo htmlspecialchars($title); ?></h1>
            <div class="post-content" style="margin: 24px 0; line-height: 1.8; font-size: 15px;">
                <?php echo $content; ?>
            </div>
            <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--border);">
                <a href="<?php echo $base; ?>/" style="color: var(--primary); font-size: 13.5px; text-decoration: none;">&larr; Back to Home</a>
            </div>
        </article>
    </main>

    <footer>
        <div class="container">
            <p style="margin: 0 0 6px 0;">Powered by <a href="https://github.com/favoritecode/Favorite-CMS-Universal" target="_blank" rel="noopener" style="color: var(--primary); text-decoration: none;">Favorite CMS Universal</a> v<?php echo htmlspecialchars($currentVersion); ?></p>
            <p style="margin: 0;">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($siteName); ?>. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>
