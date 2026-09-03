<?php
$h = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Favorite CMS Installed</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f3f4f6;
            color: #1f2937;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            padding: 24px;
        }
        .panel {
            width: 100%;
            max-width: 620px;
            background: #fff;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 28px;
            box-shadow: 0 1px 2px rgba(0,0,0,.04);
        }
        h1 { margin: 0 0 12px; font-size: 26px; color: #111827; letter-spacing: 0; }
        p { margin: 0 0 16px; color: #4b5563; }
        .success {
            border-left: 4px solid #16a34a;
            background: #f0fdf4;
            color: #166534;
            padding: 12px 14px;
            margin-bottom: 18px;
            border-radius: 4px;
        }
        dl { display: grid; grid-template-columns: 140px 1fr; gap: 8px 12px; margin: 0 0 20px; }
        dt { font-weight: 700; color: #111827; }
        dd { margin: 0; color: #374151; overflow-wrap: anywhere; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; }
        a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            border: 1px solid #1d4ed8;
            border-radius: 4px;
            padding: 0 16px;
            background: #2563eb;
            color: #fff;
            text-decoration: none;
            font-weight: 700;
        }
        a.secondary { background: #fff; color: #1d4ed8; }
        @media (max-width: 560px) {
            dl { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <main class="panel">
        <h1>Favorite CMS</h1>
        <div class="success">Favorite CMS installed successfully.</div>

        <dl>
            <dt>Site Name</dt>
            <dd><?php echo $h($siteName); ?></dd>
            <dt>Site URL</dt>
            <dd><?php echo $h($siteUrl); ?></dd>
            <dt>Admin Username</dt>
            <dd><?php echo $h($adminUsername); ?></dd>
            <dt>Admin Email</dt>
            <dd><?php echo $h($adminEmail); ?></dd>
            <dt>Migrations</dt>
            <dd><?php echo count($migrations); ?> applied this run</dd>
        </dl>

        <div class="actions">
            <a href="<?php echo $h($loginUrl); ?>">Login to Admin</a>
            <a href="<?php echo $h($homeUrl); ?>" class="secondary">Visit Website</a>
        </div>
    </main>
</body>
</html>
