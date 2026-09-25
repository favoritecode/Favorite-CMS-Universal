<?php
/**
 * Favorite CMS Universal — Standalone Core Fallback 404 View
 */

$siteName = (string)\FavoriteCMS\Models\Setting::get('general', 'site_name', 'Favorite CMS Universal');
$base = (string)($GLOBALS['favorite_cms_base_path'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 Not Found &mdash; <?php echo htmlspecialchars($siteName); ?></title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f8fafc;
            color: #0f172a;
            margin: 0;
            padding: 40px 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            box-sizing: border-box;
        }
        .card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 40px;
            max-width: 520px;
            text-align: center;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        .code {
            font-size: 72px;
            font-weight: 900;
            color: #ef4444;
            line-height: 1;
            margin: 0 0 16px 0;
        }
        h1 {
            font-size: 24px;
            font-weight: 700;
            margin: 0 0 12px 0;
        }
        p {
            color: #64748b;
            font-size: 15px;
            margin: 0 0 24px 0;
            line-height: 1.5;
        }
        a {
            display: inline-block;
            background: #2563eb;
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
</head>
<body>
    <div class="card">
        <div class="code">404</div>
        <h1>Page Not Found</h1>
        <p>The page or post you requested could not be located. It may have been moved or deleted.</p>
        <a href="<?php echo $base; ?>/">&larr; Return to Homepage</a>
    </div>
</body>
</html>

