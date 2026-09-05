<?php
$h = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Favorite CMS &mdash; Installation Complete</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --success: #16a34a;
            --success-light: #f0fdf4;
            --slate-900: #0f172a;
            --slate-800: #1e293b;
            --slate-700: #334155;
            --slate-600: #475569;
            --slate-500: #64748b;
            --slate-400: #94a3b8;
            --slate-300: #cbd5e1;
            --slate-200: #e2e8f0;
            --slate-100: #f1f5f9;
            --slate-50: #f8fafc;
            --radius-md: 8px;
            --radius-lg: 12px;
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.08), 0 2px 4px -2px rgba(0, 0, 0, 0.04);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.08), 0 4px 6px -4px rgba(0, 0, 0, 0.04);
        }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f1f5f9;
            color: var(--slate-800);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            padding: 24px 16px;
            overflow-x: hidden;
        }
        .panel {
            width: 100%;
            max-width: 640px;
            background: #fff;
            border: 1px solid var(--slate-200);
            border-radius: var(--radius-lg);
            padding: 36px 32px;
            box-shadow: var(--shadow-lg);
        }
        .header-icon {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: var(--success-light);
            border: 2px solid #bbf7d0;
            color: var(--success);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 18px;
        }
        h1 {
            margin: 0 0 12px;
            font-size: 26px;
            font-weight: 800;
            color: var(--slate-900);
            letter-spacing: -0.4px;
        }
        .success {
            border-left: 4px solid var(--success);
            background: var(--success-light);
            color: #166534;
            padding: 14px 16px;
            margin-bottom: 24px;
            border-radius: var(--radius-md);
            font-size: 14px;
            font-weight: 600;
        }
        .details-card {
            background: var(--slate-50);
            border: 1px solid var(--slate-200);
            border-radius: var(--radius-md);
            padding: 20px;
            margin-bottom: 28px;
        }
        dl {
            display: grid;
            grid-template-columns: 140px 1fr;
            gap: 12px 14px;
            margin: 0;
            font-size: 13.5px;
            line-height: 1.6;
        }
        dt {
            font-weight: 600;
            color: var(--slate-600);
        }
        dd {
            margin: 0;
            color: var(--slate-900);
            font-weight: 500;
            overflow-wrap: anywhere;
        }
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }
        a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            border: 1px solid var(--primary);
            border-radius: var(--radius-md);
            padding: 0 22px;
            background: var(--primary);
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.15s ease;
        }
        a:hover {
            background: var(--primary-hover);
            border-color: var(--primary-hover);
        }
        a.secondary {
            background: #fff;
            color: var(--slate-700);
            border-color: var(--slate-300);
        }
        a.secondary:hover {
            background: var(--slate-50);
            color: var(--slate-900);
            border-color: var(--slate-400);
        }
        @media (max-width: 560px) {
            .panel { padding: 24px 20px; }
            dl { grid-template-columns: 1fr; gap: 6px 0; }
            dt { margin-top: 8px; }
            dt:first-child { margin-top: 0; }
        }
    </style>
</head>
<body>
    <main class="panel">
        <div class="header-icon">&#10004;</div>
        <h1>Favorite CMS</h1>
        <div class="success">Favorite CMS installed successfully.</div>

        <div class="details-card">
            <dl>
                <dt>Site Name</dt>
                <dd><?php echo $h($siteName); ?></dd>
                <dt>Site URL</dt>
                <dd><?php echo $h($siteUrl); ?></dd>
                <dt>Admin Username</dt>
                <dd><strong><?php echo $h($adminUsername); ?></strong></dd>
                <dt>Admin Email</dt>
                <dd><?php echo $h($adminEmail); ?></dd>
                <dt>Migrations</dt>
                <dd><?php echo count($migrations); ?> applied this run</dd>
            </dl>
        </div>

        <div class="actions">
            <a href="<?php echo $h($loginUrl); ?>">Login to Admin &rarr;</a>
            <a href="<?php echo $h($homeUrl); ?>" class="secondary">Visit Website</a>
        </div>
    </main>
</body>
</html>
