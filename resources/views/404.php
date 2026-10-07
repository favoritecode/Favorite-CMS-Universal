<?php
/**
 * Favorite CMS Universal — Standalone Core Fallback 404 View
 */

$siteName = (string)\FavoriteCMS\Models\Setting::get('general', 'site_name', 'Favorite CMS Universal');
$base = (string)($GLOBALS['favorite_cms_base_path'] ?? '');
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo \FavoriteCMS\Services\Appearance::resolve(); ?>">
<head>
    <?php include __DIR__ . '/partials/appearance/head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,follow">
    <title>404 Not Found &mdash; <?php echo htmlspecialchars($siteName); ?></title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--cms-surface-subtle);
            color: var(--cms-text-heading);
            margin: 0;
            padding: 40px 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            box-sizing: border-box;
        }
        .card {
            background: var(--cms-surface);
            border: 1px solid var(--cms-border);
            border-radius: 12px;
            padding: 40px;
            max-width: 520px;
            text-align: center;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        .code {
            font-size: 72px;
            font-weight: 900;
            color: var(--cms-danger-text);
            line-height: 1;
            margin: 0 0 16px 0;
        }
        h1 {
            font-size: 24px;
            font-weight: 700;
            margin: 0 0 12px 0;
        }
        p {
            color: var(--cms-text-muted);
            font-size: 15px;
            margin: 0 0 24px 0;
            line-height: 1.5;
        }
        a {
            display: inline-block;
            background: var(--cms-info-solid);
            color: #ffffff;
            font-weight: 600;
            padding: 10px 24px;
            border-radius: 6px;
            text-decoration: none;
        }
        a:hover {
            background: #1d4ed8;
        }
    </style>
    <style><?php readfile(__DIR__ . '/partials/appearance/frontend.css'); ?></style>
</head>
<body>
<button type="button" class="cms-appearance-toggle" data-appearance-toggle aria-label="Switch appearance"><span class="theme-icon-sun" aria-hidden="true">&#9728;</span><span class="theme-icon-moon" aria-hidden="true">&#9790;</span></button>
    <div class="card">
        <div class="code">404</div>
        <h1>Page Not Found</h1>
        <p>The page or post you requested could not be located. It may have been moved or deleted.</p>
        <a href="<?php echo $base; ?>/">&larr; Return to Homepage</a>
    </div>
</body>
</html>

