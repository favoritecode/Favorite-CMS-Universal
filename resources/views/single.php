<?php
/**
 * Favorite CMS Universal — Standalone Core Fallback Single Post View
 *
 * Rendered when no external theme is active or when themes are decoupled.
 *
 * @var object|array $post
 * @var string|null $commentNotice
 */

$siteName = (string)\FavoriteCMS\Models\Setting::get('general', 'site_name', 'Favorite CMS Universal');
$siteDesc = (string)\FavoriteCMS\Models\Setting::get('general', 'site_description', 'One CMS. Any Website.');
$base = (string)($GLOBALS['favorite_cms_base_path'] ?? '');
$currentVersion = defined('APP_VERSION') ? APP_VERSION : '1.0.0';

$postObj = is_array($post) ? (object)$post : $post;
$title = (string)($postObj->title ?? 'Untitled Post');
$content = (string)($postObj->content ?? '');
$date = (string)($postObj->published_at ?? $postObj->created_at ?? 'now');

$commentUser = function_exists('current_user') ? current_user() : null;
if (!$commentUser && !empty($_SESSION['auth_user_id'])) {
    $commentUser = \FavoriteCMS\Models\User::find((int)$_SESSION['auth_user_id']);
}
$commentBasePath   = $base;
$commentReturnPath = '/post/' . ($postObj->slug ?? '') . '#comments';

$commentSuccess = '';
if (!empty($_SESSION['flash_comment_success'])) {
    $commentSuccess = (string)$_SESSION['flash_comment_success'];
    unset($_SESSION['flash_comment_success']);
} elseif (!empty($commentNotice)) {
    $commentSuccess = (string)$commentNotice;
}

$commentError = '';
if (!empty($_SESSION['comment_error'])) {
    $commentError = (string)$_SESSION['comment_error'];
    unset($_SESSION['comment_error']);
}
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
        .notice {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .notice--success {
            background: #dcfce7;
            border: 1px solid #86efac;
            color: #166534;
        }
        .notice--error {
            background: #fee2e2;
            border: 1px solid #fca5a5;
            color: #991b1b;
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
            <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 20px;">
                Published on <?php echo date('F j, Y', strtotime($date)); ?>
            </div>
            <div class="post-content" style="margin: 24px 0; line-height: 1.8; font-size: 15px;">
                <?php echo $content; ?>
            </div>

            <?php
            $comments = [];
            if (is_object($postObj) && method_exists($postObj, 'getComments')) {
                $comments = $postObj->getComments('approved');
            } elseif (is_object($postObj) && method_exists($postObj, 'comments')) {
                $comments = $postObj->comments();
            } elseif (!empty($postObj->id) && class_exists(\FavoriteCMS\Models\Comment::class)) {
                $comments = \FavoriteCMS\Models\Comment::forPost((int)$postObj->id, 'approved');
            }
            ?>

            <section class="comments" id="comments" style="margin-top: 40px; border-top: 1px solid var(--border); padding-top: 24px;">
                <h3 style="font-size: 18px; font-weight: 700; margin-bottom: 16px;">Comments (<?php echo count($comments); ?>)</h3>

                <?php if ($commentSuccess !== ''): ?>
                    <div class="notice notice--success" role="status"><?php echo htmlspecialchars($commentSuccess); ?></div>
                <?php endif; ?>

                <?php if ($commentError !== ''): ?>
                    <div class="notice notice--error" role="alert"><?php echo htmlspecialchars($commentError); ?></div>
                <?php endif; ?>

                <?php if (!empty($comments)): ?>
                    <div class="comment-list" style="margin-bottom: 32px;">
                        <?php foreach ($comments as $comment): ?>
                            <?php $commentObj = is_array($comment) ? (object)$comment : $comment; ?>
                            <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: 6px; padding: 12px 16px; margin-bottom: 12px;">
                                <strong style="font-size: 13.5px;"><?php echo htmlspecialchars((string)($commentObj->author_name ?? 'Anonymous')); ?>:</strong>
                                <p style="margin: 4px 0 0; font-size: 13.5px; color: var(--text);"><?php echo htmlspecialchars((string)($commentObj->content ?? '')); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="comment-respond" style="margin-top: 24px; border-top: 1px solid var(--border); padding-top: 20px;">
                    <?php if ($commentUser): ?>
                        <h4 style="font-size: 16px; font-weight: 700; margin: 0 0 8px 0;">Leave a Reply</h4>
                        <p style="font-size: 13.5px; color: var(--text-muted); margin: 0 0 16px 0;">Commenting as <strong><?php echo htmlspecialchars((string)($commentUser->name ?: $commentUser->username), ENT_QUOTES, 'UTF-8'); ?></strong></p>

                        <form class="comment-form" action="<?php echo htmlspecialchars($commentBasePath . '/post/' . ($postObj->slug ?? '') . '/comment', ENT_QUOTES, 'UTF-8'); ?>" method="POST">
                            <input type="hidden" name="_token" value="<?php echo htmlspecialchars(function_exists('csrf_token') ? csrf_token() : ($_SESSION['_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="post_id" value="<?php echo (int)($postObj->id ?? 0); ?>">
                            <input type="hidden" name="post_slug" value="<?php echo htmlspecialchars((string)($postObj->slug ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                            <div style="margin-bottom: 16px;">
                                <label for="comment_content" style="display: block; font-size: 13.5px; font-weight: 600; margin-bottom: 6px;">Comment <span style="color: #ef4444;">*</span></label>
                                <textarea id="comment_content" name="content" rows="4" required style="width: 100%; box-sizing: border-box; padding: 10px; border: 1px solid var(--border); border-radius: 6px; font-family: inherit; font-size: 14px;" placeholder="Share your thoughts..."></textarea>
                            </div>

                            <button type="submit" style="background: var(--primary); color: #ffffff; border: none; padding: 9px 18px; border-radius: 6px; font-size: 13.5px; font-weight: 600; cursor: pointer;">Post Comment</button>
                        </form>
                    <?php else: ?>
                        <h4 style="font-size: 16px; font-weight: 700; margin: 0 0 8px 0;">Join the Discussion</h4>
                        <p style="font-size: 13.5px; color: var(--text-muted); margin: 0 0 16px 0;">You need an account to comment on this article.</p>

                        <div class="comment-auth-actions" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                            <a href="<?php echo htmlspecialchars($commentBasePath . '/admin/login?redirect=' . rawurlencode($commentReturnPath), ENT_QUOTES, 'UTF-8'); ?>" style="display: inline-block; background: var(--primary); color: #ffffff; padding: 8px 16px; border-radius: 6px; font-size: 13.5px; font-weight: 600; text-decoration: none;">Log in to comment</a>
                            <?php if ((int)\FavoriteCMS\Models\Setting::get('general', 'allow_registration', 1)): ?>
                                <a href="<?php echo htmlspecialchars($commentBasePath . '/register?redirect=' . rawurlencode($commentReturnPath), ENT_QUOTES, 'UTF-8'); ?>" style="display: inline-block; background: #e2e8f0; color: #0f172a; padding: 8px 16px; border-radius: 6px; font-size: 13.5px; font-weight: 600; text-decoration: none;">Create an account</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

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
