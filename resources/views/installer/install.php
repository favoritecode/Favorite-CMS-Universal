<?php
$h = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$mode = ($old['setup_mode'] ?? $dbDefaults['setup_mode'] ?? 'recommended');
$field = static fn (string $key, string $fallback = ''): string => (string)($old[$key] ?? $dbDefaults[$key] ?? $fallback);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Favorite CMS &mdash; Installation</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #f0f2f5;
            color: #1e293b;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            font-size: 14px;
            line-height: 1.5;
        }
        .shell { max-width: 860px; margin: 0 auto; padding: 40px 20px 60px; }
        .brand { text-align: center; margin-bottom: 28px; }
        .brand h1 { margin: 0; font-size: 32px; font-weight: 800; letter-spacing: -0.5px; color: #0f172a; }
        .brand p { margin: 8px 0 0; color: #64748b; font-size: 15px; }
        .panel {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 28px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        h2 { margin: 0 0 16px; font-size: 19px; font-weight: 700; color: #0f172a; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px; }
        h3 { margin: 18px 0 12px; font-size: 15px; font-weight: 600; color: #1e293b; }
        p { margin: 0 0 14px; color: #475569; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .checks { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .check {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 12px;
            background: #f8fafc;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 48px;
            height: 22px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .pass { background: #dcfce7; color: #15803d; }
        .warn { background: #fef3c7; color: #b45309; }
        .fail { background: #fee2e2; color: #b91c1c; }
        label { display: block; margin-bottom: 6px; font-weight: 600; color: #1e293b; font-size: 13px; }
        input, select {
            width: 100%;
            min-height: 40px;
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #fff;
            color: #0f172a;
            font-size: 14px;
            transition: border-color 0.15s ease;
        }
        input:focus, select:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.15); }
        .description { display: block; margin-top: 5px; color: #64748b; font-size: 12px; }
        .alert {
            border-left: 4px solid #2563eb;
            background: #eff6ff;
            color: #1e40af;
            padding: 14px 16px;
            margin-bottom: 20px;
            border-radius: 6px;
        }
        .alert.error { border-color: #ef4444; background: #fef2f2; color: #991b1b; }
        .alert ul { margin: 8px 0 0 20px; padding: 0; }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 22px; align-items: center; }
        button, .btn {
            min-height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #1d4ed8;
            border-radius: 6px;
            background: #2563eb;
            color: #fff;
            padding: 0 20px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.15s ease;
        }
        button:hover, .btn:hover { background: #1d4ed8; }
        button.secondary { background: #fff; border-color: #cbd5e1; color: #334155; }
        button.secondary:hover { background: #f8fafc; border-color: #94a3b8; }
        button:disabled { border-color: #cbd5e1; background: #94a3b8; cursor: not-allowed; }
        .hidden { display: none; }
        .toggle-box {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 6px;
            padding: 16px;
            margin-top: 14px;
        }
        .collapsible-trigger {
            cursor: pointer;
            color: #2563eb;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            user-select: none;
            margin-top: 10px;
        }
        .collapsible-trigger:hover { text-decoration: underline; }
        .tab-buttons { display: flex; gap: 8px; margin-bottom: 18px; border-bottom: 2px solid #e2e8f0; padding-bottom: 8px; }
        .tab-btn {
            background: transparent;
            border: none;
            color: #64748b;
            font-size: 14px;
            font-weight: 600;
            padding: 8px 14px;
            cursor: pointer;
            border-radius: 4px;
        }
        .tab-btn.active { color: #2563eb; background: #eff6ff; }
        .footer { text-align: center; color: #94a3b8; font-size: 12px; margin-top: 24px; }
        @media (max-width: 760px) {
            .grid, .checks { grid-template-columns: 1fr; }
            .panel { padding: 20px; }
        }
    </style>
</head>
<body>
<main class="shell">
    <header class="brand">
        <h1>Favorite CMS Universal</h1>
        <p>Simple, portable installation for shared hosting &amp; local servers</p>
    </header>

    <?php if (!empty($errors)): ?>
        <div class="alert error">
            <strong>Please resolve the following issues:</strong>
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

    <!-- Step 1: Requirements -->
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

    <!-- Installation / Restore Navigation Tabs -->
    <div class="tab-buttons">
        <button type="button" id="tab-install-btn" class="tab-btn active" onclick="switchMainTab('install')">&#128640; Fresh Installation (Recommended)</button>
        <button type="button" id="tab-restore-btn" class="tab-btn" onclick="switchMainTab('restore')">&#128229; Restore Existing Site from Backup</button>
    </div>

    <!-- Fresh Installation Form -->
    <form id="install-form" method="POST" action="<?php echo $h($installAction); ?>" autocomplete="off">
        <input type="hidden" name="_token" value="<?php echo $h($token); ?>">

        <!-- Step 2: Database Setup -->
        <section class="panel">
            <h2>Step 2 - Database</h2>
            <p style="font-size: 13px; color: #475569; margin-bottom: 16px;">
                Favorite CMS automatically uses default shared hosting parameters (Host: <code>localhost</code>, Port: <code>3306</code>) and generates a unique table prefix (<code><?php echo $h($field('db_prefix')); ?></code>).
                You only need to enter your database credentials.
            </p>

            <div class="grid">
                <div>
                    <label for="db_name">Database Name <span style="color:#ef4444;">*</span></label>
                    <input type="text" id="db_name" name="db_name" value="<?php echo $h($field('db_name')); ?>" required placeholder="e.g. u123456_fvcms">
                    <span class="description">The MySQL database created in your hosting control panel.</span>
                </div>
                <div>
                    <label for="db_username">Database Username <span style="color:#ef4444;">*</span></label>
                    <input type="text" id="db_username" name="db_username" value="<?php echo $h($field('db_username')); ?>" required autocomplete="off" placeholder="e.g. u123456_admin">
                    <span class="description">Your hosting database user assigned to this database.</span>
                </div>
            </div>

            <div style="margin-top: 14px;">
                <label for="db_password">Database Password</label>
                <input type="password" id="db_password" name="db_password" autocomplete="new-password" placeholder="Database user password">
                <span class="description">Leave empty only if your local server database user has no password (e.g. default XAMPP root).</span>
            </div>

            <!-- Advanced Database Options Toggle -->
            <div style="margin-top: 16px;">
                <span class="collapsible-trigger" id="advanced-trigger" onclick="toggleAdvancedDb()">
                    &#9656; Advanced Database Settings (Custom Host, Port, Prefix, or Auto-Create)
                </span>
            </div>

            <div id="advanced-db-box" class="toggle-box <?php echo in_array($mode, ['advanced', 'automatic'], true) ? '' : 'hidden'; ?>">
                <input type="hidden" name="setup_mode" id="setup_mode" value="<?php echo $h($mode); ?>">

                <div class="grid">
                    <div>
                        <label for="db_host">Database Host</label>
                        <input type="text" id="db_host" name="db_host" value="<?php echo $h($field('db_host', 'localhost')); ?>" required>
                        <span class="description">Almost always <code>localhost</code> on Hostinger, cPanel, and XAMPP.</span>
                    </div>
                    <div>
                        <label for="db_port">Database Port</label>
                        <input type="text" id="db_port" name="db_port" value="<?php echo $h($field('db_port', '3306')); ?>" required>
                        <span class="description">Standard MySQL TCP port is <code>3306</code>.</span>
                    </div>
                </div>

                <div style="margin-top: 14px;">
                    <label for="db_prefix">Table Prefix</label>
                    <input type="text" id="db_prefix" name="db_prefix" value="<?php echo $h($field('db_prefix')); ?>" required>
                    <span class="description">Unique prefix to isolate tables. Generated automatically for safety.</span>
                </div>

                <div style="margin-top: 18px; border-top: 1px solid #e2e8f0; padding-top: 14px;">
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" id="auto_create_toggle" style="width: auto; min-height: 0;" onchange="toggleAutoCreate(this.checked)" <?php echo $mode === 'automatic' ? 'checked' : ''; ?>>
                        <strong>Create database automatically using a privileged root account (VPS / Localhost only)</strong>
                    </label>
                    <div id="auto-create-fields" class="<?php echo $mode === 'automatic' ? '' : 'hidden'; ?>" style="margin-top: 12px;">
                        <div class="grid">
                            <div>
                                <label for="db_admin_username">Privileged Admin Username</label>
                                <input type="text" id="db_admin_username" name="db_admin_username" placeholder="root">
                            </div>
                            <div>
                                <label for="db_admin_password">Privileged Admin Password</label>
                                <input type="password" id="db_admin_password" name="db_admin_password">
                            </div>
                        </div>
                        <span class="description">Requires GRANT, CREATE USER, and CREATE DATABASE permissions. Not supported on typical shared hosting.</span>
                    </div>
                </div>
            </div>

            <div class="actions">
                <button type="submit" name="db_action" value="test_database" class="secondary">&#128268; Test Database Connection</button>
            </div>
        </section>

        <!-- Step 3: Site Information -->
        <section class="panel">
            <h2>Step 3 &mdash; Site Information</h2>
            <div class="grid">
                <div>
                    <label for="site_name">Site Name <span style="color:#ef4444;">*</span></label>
                    <input type="text" id="site_name" name="site_name" value="<?php echo $h($field('site_name', 'Favorite CMS')); ?>" required>
                </div>
                <div>
                    <label for="site_url">Site URL <span style="color:#ef4444;">*</span></label>
                    <input type="text" id="site_url" name="site_url" value="<?php echo $h($field('site_url', $detectedUrl)); ?>" required>
                    <span class="description">Detected automatically from current domain or subdomain.</span>
                </div>
            </div>
        </section>

        <!-- Step 4: Administrator Account -->
        <section class="panel">
            <h2>Step 4 &mdash; Administrator Account</h2>
            <div class="grid">
                <div>
                    <label for="admin_username">Admin Username <span style="color:#ef4444;">*</span></label>
                    <input type="text" id="admin_username" name="admin_username" value="<?php echo $h($field('admin_username')); ?>" required autocomplete="username">
                    <span class="description">3-60 alphanumeric characters.</span>
                </div>
                <div>
                    <label for="admin_email">Admin Email Address <span style="color:#ef4444;">*</span></label>
                    <input type="email" id="admin_email" name="admin_email" value="<?php echo $h($field('admin_email')); ?>" required autocomplete="email">
                </div>
                <div>
                    <label for="admin_password">Admin Password <span style="color:#ef4444;">*</span></label>
                    <input type="password" id="admin_password" name="admin_password" required autocomplete="new-password">
                    <span class="description">At least 10 characters with letters and numbers.</span>
                </div>
                <div>
                    <label for="admin_password_confirm">Confirm Password <span style="color:#ef4444;">*</span></label>
                    <input type="password" id="admin_password_confirm" name="admin_password_confirm" required autocomplete="new-password">
                </div>
            </div>
        </section>

        <!-- Step 5: Install Action -->
        <section class="panel" style="text-align: center;">
            <p style="color: #64748b; margin-bottom: 18px;">
                When you click Install, Favorite CMS will verify the database connection, execute all migrations in order, create your administrator account, and finalize the installation.
            </p>
            <button type="submit" name="db_action" value="install" style="font-size: 16px; min-height: 48px; padding: 0 32px;" <?php echo $hasRequirementFailures ? 'disabled' : ''; ?>>
                &#10004; Install Favorite CMS
            </button>
        </section>
    </form>

    <!-- Site Restore Form -->
    <form id="restore-form" class="hidden" method="POST" action="<?php echo $h($installAction); ?>" enctype="multipart/form-data" autocomplete="off">
        <input type="hidden" name="_token" value="<?php echo $h($token); ?>">
        <input type="hidden" name="db_action" value="restore">

        <section class="panel">
            <h2>Restore Site from Backup (.zip)</h2>
            <p>
                Migrating from another server or domain? Upload your <code>favorite_cms_backup_*.zip</code> archive.
                The installer will restore your database tables, media uploads, themes, and plugins, and automatically update site URLs to this domain.
            </p>

            <div style="margin-bottom: 18px;">
                <label for="backup_file">Select Backup Archive (.zip) <span style="color:#ef4444;">*</span></label>
                <input type="file" id="backup_file" name="backup_file" accept=".zip" required>
            </div>

            <h3>Target Database Credentials</h3>
            <div class="grid">
                <div>
                    <label for="res_db_name">Database Name <span style="color:#ef4444;">*</span></label>
                    <input type="text" id="res_db_name" name="db_name" value="<?php echo $h($field('db_name')); ?>" required>
                </div>
                <div>
                    <label for="res_db_username">Database Username <span style="color:#ef4444;">*</span></label>
                    <input type="text" id="res_db_username" name="db_username" value="<?php echo $h($field('db_username')); ?>" required>
                </div>
                <div>
                    <label for="res_db_password">Database Password</label>
                    <input type="password" id="res_db_password" name="db_password">
                </div>
                <div>
                    <label for="res_site_url">New Site URL</label>
                    <input type="text" id="res_site_url" name="site_url" value="<?php echo $h($field('site_url', $detectedUrl)); ?>" required>
                    <span class="description">All database references will be migrated to this URL.</span>
                </div>
            </div>

            <div style="margin-top: 24px;">
                <button type="submit" style="background: #059669; border-color: #047857; min-height: 44px; padding: 0 24px;">
                    &#128229; Restore &amp; Migrate Site
                </button>
            </div>
        </section>
    </form>

    <div class="footer">Favorite CMS Universal &bull; Protected with standard CSRF verification &amp; no-cache headers.</div>
</main>

<script>
function switchMainTab(tab) {
    const installForm = document.getElementById('install-form');
    const restoreForm = document.getElementById('restore-form');
    const installBtn = document.getElementById('tab-install-btn');
    const restoreBtn = document.getElementById('tab-restore-btn');

    if (tab === 'restore') {
        installForm.classList.add('hidden');
        restoreForm.classList.remove('hidden');
        installBtn.classList.remove('active');
        restoreBtn.classList.add('active');
    } else {
        restoreForm.classList.add('hidden');
        installForm.classList.remove('hidden');
        restoreBtn.classList.remove('active');
        installBtn.classList.add('active');
    }
}

function toggleAdvancedDb() {
    const box = document.getElementById('advanced-db-box');
    const trigger = document.getElementById('advanced-trigger');
    const isHidden = box.classList.contains('hidden');

    box.classList.toggle('hidden');
    trigger.innerHTML = isHidden
        ? '&#9662; Advanced Database Settings (Custom Host, Port, Prefix, or Auto-Create)'
        : '&#9656; Advanced Database Settings (Custom Host, Port, Prefix, or Auto-Create)';
}

function toggleAutoCreate(enabled) {
    const fields = document.getElementById('auto-create-fields');
    const modeInput = document.getElementById('setup_mode');
    fields.classList.toggle('hidden', !enabled);
    modeInput.value = enabled ? 'automatic' : 'advanced';
}
</script>
</body>
</html>
