<?php
$h = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$mode = ($old['setup_mode'] ?? 'manual') === 'automatic' ? 'automatic' : 'manual';
$field = static fn (string $key, string $fallback = ''): string => (string)($old[$key] ?? $dbDefaults[$key] ?? $fallback);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Favorite CMS Installation</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #f3f4f6;
            color: #1f2937;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            font-size: 14px;
            line-height: 1.5;
        }
        .shell { max-width: 980px; margin: 0 auto; padding: 32px 16px 44px; }
        .brand { text-align: center; margin-bottom: 24px; }
        .brand h1 { margin: 0; font-size: 30px; font-weight: 700; letter-spacing: 0; color: #111827; }
        .brand p { margin: 6px 0 0; color: #4b5563; }
        .panel {
            background: #fff;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 24px;
            margin-bottom: 16px;
            box-shadow: 0 1px 2px rgba(0,0,0,.04);
        }
        h2 { margin: 0 0 14px; font-size: 18px; color: #111827; }
        h3 { margin: 18px 0 10px; font-size: 15px; color: #111827; }
        p { margin: 0 0 12px; color: #4b5563; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .checks { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
        .check {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 10px;
            min-height: 66px;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 44px;
            height: 22px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .pass { background: #dcfce7; color: #166534; }
        .warn { background: #fef3c7; color: #92400e; }
        .fail { background: #fee2e2; color: #991b1b; }
        label { display: block; margin-bottom: 5px; font-weight: 600; color: #111827; }
        input, select {
            width: 100%;
            min-height: 38px;
            padding: 8px 10px;
            border: 1px solid #9ca3af;
            border-radius: 4px;
            background: #fff;
            color: #111827;
            font-size: 14px;
        }
        input:focus, select:focus { outline: 2px solid #bfdbfe; border-color: #2563eb; }
        .description { display: block; margin-top: 4px; color: #6b7280; font-size: 12px; }
        .alert {
            border-left: 4px solid #2563eb;
            background: #eff6ff;
            color: #1e3a8a;
            padding: 12px 14px;
            margin-bottom: 16px;
            border-radius: 4px;
        }
        .alert.error { border-color: #dc2626; background: #fef2f2; color: #7f1d1d; }
        .alert ul { margin: 6px 0 0 18px; padding: 0; }
        .mode-row { display: flex; flex-wrap: wrap; gap: 12px; margin: 10px 0 14px; }
        .mode-row label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 10px 12px;
            cursor: pointer;
        }
        .mode-row input { width: auto; min-height: 0; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
        button {
            min-height: 40px;
            border: 1px solid #1d4ed8;
            border-radius: 4px;
            background: #2563eb;
            color: #fff;
            padding: 0 16px;
            font-weight: 700;
            cursor: pointer;
        }
        button.secondary { background: #fff; color: #1d4ed8; }
        button:disabled { border-color: #9ca3af; background: #9ca3af; cursor: not-allowed; }
        .hidden { display: none; }
        .footer { text-align: center; color: #6b7280; font-size: 12px; margin-top: 18px; }
        @media (max-width: 760px) {
            .grid, .checks { grid-template-columns: 1fr; }
            .panel { padding: 18px; }
        }
    </style>
</head>
<body>
<main class="shell">
    <header class="brand">
        <h1>Favorite CMS</h1>
        <p>Browser-based first-run setup</p>
    </header>

    <?php if (!empty($errors)): ?>
        <div class="alert error">
            <strong>Please correct the following:</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?php echo $h($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php foreach ($notices as $notice): ?>
        <div class="alert"><?php echo $h($notice); ?></div>
    <?php endforeach; ?>

    <section class="panel">
        <h2>Step 1 - Welcome & Requirements</h2>
        <div class="checks">
            <?php foreach ($checks as $check): ?>
                <div class="check">
                    <span class="badge <?php echo $h($check['status']); ?>"><?php echo $h($check['status']); ?></span>
                    <div>
                        <strong><?php echo $h($check['label']); ?></strong>
                        <span class="description"><?php echo $h($check['message']); ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <form method="POST" action="<?php echo $h($installAction); ?>" autocomplete="off">
        <input type="hidden" name="_token" value="<?php echo $h($token); ?>">

        <section class="panel">
            <h2>Step 2 - Database</h2>
            <p><?php echo $h($dbStatus['message'] ?? 'Enter the database credentials from your hosting control panel.'); ?></p>

            <div class="mode-row">
                <label>
                    <input type="radio" name="setup_mode" value="manual" <?php echo $mode === 'manual' ? 'checked' : ''; ?>>
                    Manual Database Setup
                </label>
                <label>
                    <input type="radio" name="setup_mode" value="automatic" <?php echo $mode === 'automatic' ? 'checked' : ''; ?>>
                    Create Database Automatically
                </label>
            </div>

            <div id="automatic-fields" class="<?php echo $mode === 'automatic' ? '' : 'hidden'; ?>">
                <h3>Privileged Database Account</h3>
                <div class="grid">
                    <div>
                        <label for="db_admin_username">Database Admin Username</label>
                        <input type="text" id="db_admin_username" name="db_admin_username" autocomplete="off">
                    </div>
                    <div>
                        <label for="db_admin_password">Database Admin Password</label>
                        <input type="password" id="db_admin_password" name="db_admin_password" autocomplete="new-password">
                    </div>
                </div>
                <span class="description">Automatic creation works only when this account has CREATE DATABASE, CREATE USER, and GRANT privileges.</span>
            </div>

            <h3>Database Connection</h3>
            <div class="grid">
                <div>
                    <label for="db_host">Database Host</label>
                    <input type="text" id="db_host" name="db_host" value="<?php echo $h($field('db_host', 'localhost')); ?>" required>
                </div>
                <div>
                    <label for="db_port">Database Port</label>
                    <input type="text" id="db_port" name="db_port" value="<?php echo $h($field('db_port', '3306')); ?>" required>
                </div>
                <div>
                    <label for="db_name">Database Name</label>
                    <input type="text" id="db_name" name="db_name" value="<?php echo $h($field('db_name')); ?>" required>
                </div>
                <div>
                    <label for="db_username">Database Username</label>
                    <input type="text" id="db_username" name="db_username" value="<?php echo $h($field('db_username')); ?>" required autocomplete="off">
                </div>
                <div>
                    <label for="db_password">Database Password</label>
                    <input type="password" id="db_password" name="db_password" autocomplete="new-password">
                </div>
                <div>
                    <label for="db_prefix">Table Prefix</label>
                    <input type="text" id="db_prefix" name="db_prefix" value="<?php echo $h($field('db_prefix', 'fvcms_')); ?>" required>
                    <span class="description">Use a unique prefix for multiple installations in one database.</span>
                </div>
            </div>

            <div class="actions">
                <button type="submit" name="db_action" value="test_database" class="secondary">Test Database Connection</button>
            </div>
        </section>

        <section class="panel">
            <h2>Step 3 - Site Information</h2>
            <div class="grid">
                <div>
                    <label for="site_name">Site Name</label>
                    <input type="text" id="site_name" name="site_name" value="<?php echo $h($field('site_name', 'Favorite CMS')); ?>" required>
                </div>
                <div>
                    <label for="site_url">Site URL</label>
                    <input type="text" id="site_url" name="site_url" value="<?php echo $h($field('site_url', $detectedUrl)); ?>" required>
                    <span class="description">Detected from the current domain, subdomain, and subdirectory.</span>
                </div>
            </div>

            <h3>Administrator</h3>
            <div class="grid">
                <div>
                    <label for="admin_username">Admin Username</label>
                    <input type="text" id="admin_username" name="admin_username" value="<?php echo $h($field('admin_username')); ?>" required autocomplete="username">
                    <span class="description">A custom username is preferable to admin.</span>
                </div>
                <div>
                    <label for="admin_email">Admin Email</label>
                    <input type="email" id="admin_email" name="admin_email" value="<?php echo $h($field('admin_email')); ?>" required autocomplete="email">
                </div>
                <div>
                    <label for="admin_password">Admin Password</label>
                    <input type="password" id="admin_password" name="admin_password" required autocomplete="new-password">
                </div>
                <div>
                    <label for="admin_password_confirm">Confirm Password</label>
                    <input type="password" id="admin_password_confirm" name="admin_password_confirm" required autocomplete="new-password">
                </div>
            </div>
        </section>

        <section class="panel">
            <h2>Step 4 - Install</h2>
            <p>The installer will verify the database, run migrations in order, create the admin user, write configuration, and create the installation lock.</p>
            <div class="actions">
                <button type="submit" name="db_action" value="install" <?php echo $hasRequirementFailures ? 'disabled' : ''; ?>>Install Favorite CMS</button>
            </div>
        </section>
    </form>

    <div class="footer">Favorite CMS installer pages are sent with no-cache headers.</div>
</main>
<script>
    const radios = document.querySelectorAll('input[name="setup_mode"]');
    const autoFields = document.getElementById('automatic-fields');
    const syncMode = () => {
        const selected = document.querySelector('input[name="setup_mode"]:checked');
        autoFields.classList.toggle('hidden', !selected || selected.value !== 'automatic');
    };
    radios.forEach((radio) => radio.addEventListener('change', syncMode));
    syncMode();
</script>
</body>
</html>
