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
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --primary-light: #eff6ff;
            --success: #16a34a;
            --success-light: #f0fdf4;
            --warning: #d97706;
            --warning-light: #fffbeb;
            --danger: #dc2626;
            --danger-light: #fef2f2;
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
            --radius-sm: 4px;
            --radius-md: 8px;
            --radius-lg: 12px;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.08), 0 2px 4px -2px rgba(0, 0, 0, 0.04);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.08), 0 4px 6px -4px rgba(0, 0, 0, 0.04);
        }
        body {
            background: #f1f5f9;
            color: var(--slate-800);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            font-size: 14px;
            line-height: 1.5;
            overflow-x: hidden;
            min-height: 100vh;
        }
        a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }

        .shell {
            max-width: 840px;
            margin: 0 auto;
            padding: 36px 20px 60px;
        }

        /* Brand Header */
        .brand {
            text-align: center;
            margin-bottom: 28px;
        }
        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #fff;
            border: 1px solid var(--slate-200);
            border-radius: 9999px;
            padding: 4px 14px;
            font-size: 12px;
            font-weight: 600;
            color: var(--primary);
            box-shadow: var(--shadow-sm);
            margin-bottom: 12px;
        }
        .brand-badge span.star { color: #f59e0b; font-size: 14px; }
        .brand h1 {
            margin: 0;
            font-size: 30px;
            font-weight: 800;
            letter-spacing: -0.6px;
            color: var(--slate-900);
        }
        .brand p {
            margin: 6px 0 0;
            color: var(--slate-500);
            font-size: 14.5px;
        }

        /* Step Progress Stepper */
        .stepper {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #fff;
            border: 1px solid var(--slate-200);
            border-radius: var(--radius-lg);
            padding: 14px 20px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
            overflow-x: auto;
            gap: 12px;
        }
        .step-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--slate-400);
            white-space: nowrap;
        }
        .step-item.is-active {
            color: var(--primary);
        }
        .step-num {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--slate-100);
            color: var(--slate-600);
            font-size: 11.5px;
            font-weight: 700;
        }
        .step-item.is-active .step-num {
            background: var(--primary);
            color: #fff;
        }
        .step-divider {
            flex: 1;
            height: 1px;
            background: var(--slate-200);
            min-width: 16px;
        }

        /* Panels */
        .panel {
            background: #ffffff;
            border: 1px solid var(--slate-200);
            border-radius: var(--radius-lg);
            padding: 28px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
        }
        .panel-header {
            margin-bottom: 18px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--slate-100);
        }
        .panel-header h2 {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
            color: var(--slate-900);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .panel-header p {
            margin: 4px 0 0;
            color: var(--slate-500);
            font-size: 13px;
        }

        /* Requirements Grid */
        .checks {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .check {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            border: 1px solid var(--slate-200);
            border-radius: var(--radius-md);
            padding: 12px 14px;
            background: var(--slate-50);
            transition: border-color 0.15s ease;
        }
        .check:hover { border-color: var(--slate-300); }
        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 52px;
            height: 24px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            flex-shrink: 0;
        }
        .badge.pass { background: var(--success-light); color: var(--success); border: 1px solid #bbf7d0; }
        .badge.warn { background: var(--warning-light); color: var(--warning); border: 1px solid #fde68a; }
        .badge.fail { background: var(--danger-light); color: var(--danger); border: 1px solid #fecaca; }

        /* Form Inputs & Controls */
        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }
        .form-group {
            margin-bottom: 16px;
        }
        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: var(--slate-700);
            font-size: 13px;
        }
        label .req {
            color: var(--danger);
            font-weight: 700;
            margin-left: 2px;
        }
        input[type="text"], input[type="email"], input[type="password"], input[type="file"], select {
            width: 100%;
            height: 42px;
            padding: 8px 12px;
            border: 1px solid var(--slate-300);
            border-radius: var(--radius-md);
            background: #fff;
            color: var(--slate-900);
            font-size: 13.5px;
            font-family: inherit;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        input[type="file"] {
            height: auto;
            padding: 10px;
        }
        input:focus, select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.15);
        }
        .description {
            display: block;
            margin-top: 5px;
            color: var(--slate-500);
            font-size: 12px;
            line-height: 1.4;
        }

        /* Password input with show/hide toggle */
        .password-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }
        .password-wrap input {
            padding-right: 68px;
        }
        .password-toggle-btn {
            position: absolute;
            right: 6px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: var(--slate-500);
            font-size: 11.5px;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: color 0.15s, background-color 0.15s;
        }
        .password-toggle-btn:hover {
            color: var(--primary);
            background: var(--slate-100);
        }

        /* Alerts */
        .alert {
            border-left: 4px solid var(--primary);
            background: var(--primary-light);
            color: #1e40af;
            padding: 14px 18px;
            margin-bottom: 20px;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            font-size: 13.5px;
        }
        .alert.error {
            border-color: var(--danger);
            background: var(--danger-light);
            color: #991b1b;
        }
        .alert ul { margin: 6px 0 0 18px; padding: 0; }

        /* Buttons */
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            margin-top: 20px;
        }
        button, .btn {
            min-height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            border: 1px solid var(--primary);
            border-radius: var(--radius-md);
            background: var(--primary);
            color: #fff;
            padding: 0 20px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
            font-family: inherit;
        }
        button:hover, .btn:hover { background: var(--primary-hover); border-color: var(--primary-hover); }
        button.secondary, .btn.secondary {
            background: #fff;
            border-color: var(--slate-300);
            color: var(--slate-700);
        }
        button.secondary:hover, .btn.secondary:hover {
            background: var(--slate-50);
            border-color: var(--slate-400);
            color: var(--slate-900);
        }
        button:disabled {
            border-color: var(--slate-300);
            background: var(--slate-400);
            cursor: not-allowed;
            opacity: 0.7;
        }

        /* Tab buttons */
        .tab-buttons {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--slate-200);
            padding-bottom: 8px;
        }
        .tab-btn {
            background: transparent;
            border: none;
            color: var(--slate-500);
            font-size: 14px;
            font-weight: 600;
            padding: 8px 16px;
            cursor: pointer;
            border-radius: var(--radius-md);
            transition: all 0.15s;
        }
        .tab-btn:hover { color: var(--primary); }
        .tab-btn.active { color: var(--primary); background: var(--primary-light); }

        /* Advanced collapsible */
        .hidden { display: none !important; }
        .toggle-box {
            background: var(--slate-50);
            border: 1px dashed var(--slate-300);
            border-radius: var(--radius-md);
            padding: 18px;
            margin-top: 14px;
        }
        .collapsible-trigger {
            cursor: pointer;
            color: var(--primary);
            font-weight: 600;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            user-select: none;
            margin-top: 10px;
            transition: color 0.15s;
        }
        .collapsible-trigger:hover { text-decoration: underline; color: var(--primary-hover); }

        /* Install CTA Card */
        .install-cta-panel {
            background: #fff;
            border: 1px solid var(--slate-200);
            border-radius: var(--radius-lg);
            padding: 32px 28px;
            text-align: center;
            box-shadow: var(--shadow-md);
        }
        .install-cta-panel p {
            color: var(--slate-600);
            max-width: 580px;
            margin: 0 auto 20px;
            font-size: 14px;
            line-height: 1.6;
        }
        .btn-install-primary {
            font-size: 16px;
            min-height: 48px;
            padding: 0 36px;
            box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.25);
        }
        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .footer {
            text-align: center;
            color: var(--slate-400);
            font-size: 12.5px;
            margin-top: 28px;
        }

        @media (max-width: 768px) {
            .shell { padding: 24px 16px 40px; }
            .grid, .checks { grid-template-columns: 1fr; }
            .panel { padding: 20px 16px; }
            .stepper { display: none; }
        }
    </style>
</head>
<body>
<main class="shell">
    <header class="brand">
        <div class="brand-badge"><span class="star">&#9733;</span> Modern &bull; Portable &bull; Fast</div>
        <h1>Favorite CMS Universal</h1>
        <p>Simple, portable installation for shared hosting &amp; local servers</p>
    </header>

    <!-- Visual Progress Stepper -->
    <nav class="stepper" aria-label="Installation Steps">
        <div class="step-item is-active">
            <span class="step-num">1</span>
            <span>Requirements</span>
        </div>
        <div class="step-divider"></div>
        <div class="step-item is-active">
            <span class="step-num">2</span>
            <span>Database</span>
        </div>
        <div class="step-divider"></div>
        <div class="step-item is-active">
            <span class="step-num">3</span>
            <span>Site Info</span>
        </div>
        <div class="step-divider"></div>
        <div class="step-item is-active">
            <span class="step-num">4</span>
            <span>Admin Account</span>
        </div>
        <div class="step-divider"></div>
        <div class="step-item">
            <span class="step-num">5</span>
            <span>Install</span>
        </div>
    </nav>

    <?php if (!empty($errors)): ?>
        <div class="alert error" role="alert">
            <strong>Please resolve the following issues:</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?php echo $h($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php foreach ($notices as $notice): ?>
        <div class="alert" role="status"><?php echo $h($notice); ?></div>
    <?php endforeach; ?>

    <!-- Step 1: Requirements -->
    <section class="panel">
        <div class="panel-header">
            <h2>Step 1 - Welcome & Requirements</h2>
            <p>Checking server environment, required PHP extensions, and directory write permissions.</p>
        </div>
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
    <form id="install-form" method="POST" action="<?php echo $h($installAction); ?>" autocomplete="off" onsubmit="return handleFormSubmit(this)">
        <input type="hidden" name="_token" value="<?php echo $h($token); ?>">

        <!-- Step 2: Database Setup -->
        <section class="panel">
            <div class="panel-header">
                <h2>Step 2 - Database</h2>
                <p>
                    Favorite CMS automatically uses default shared hosting parameters (Host: <code>localhost</code>, Port: <code>3306</code>) and generates a unique table prefix (<code><?php echo $h($field('db_prefix')); ?></code>).
                    You only need to enter your database credentials.
                </p>
            </div>

            <div class="grid">
                <div>
                    <label for="db_name">Database Name <span class="req" title="Required">*</span></label>
                    <input type="text" id="db_name" name="db_name" value="<?php echo $h($field('db_name')); ?>" required placeholder="e.g. u123456_fvcms">
                    <span class="description">The MySQL database created in your hosting control panel.</span>
                </div>
                <div>
                    <label for="db_username">Database Username <span class="req" title="Required">*</span></label>
                    <input type="text" id="db_username" name="db_username" value="<?php echo $h($field('db_username')); ?>" required autocomplete="off" placeholder="e.g. u123456_admin">
                    <span class="description">Your hosting database user assigned to this database.</span>
                </div>
            </div>

            <div style="margin-top: 14px;">
                <label for="db_password">Database Password</label>
                <div class="password-wrap">
                    <input type="password" id="db_password" name="db_password" autocomplete="new-password" placeholder="Database user password">
                    <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('db_password', this)">Show</button>
                </div>
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

                <div style="margin-top: 18px; border-top: 1px solid var(--slate-200); padding-top: 14px;">
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" id="auto_create_toggle" style="width: auto; height: auto;" onchange="toggleAutoCreate(this.checked)" <?php echo $mode === 'automatic' ? 'checked' : ''; ?>>
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
                                <div class="password-wrap">
                                    <input type="password" id="db_admin_password" name="db_admin_password">
                                    <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('db_admin_password', this)">Show</button>
                                </div>
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
            <div class="panel-header">
                <h2>Step 3 &mdash; Site Information</h2>
                <p>Configure your public website name and base domain URL.</p>
            </div>
            <div class="grid">
                <div>
                    <label for="site_name">Site Name <span class="req" title="Required">*</span></label>
                    <input type="text" id="site_name" name="site_name" value="<?php echo $h($field('site_name', 'Favorite CMS')); ?>" required>
                    <span class="description">The display name of your site (can be changed anytime).</span>
                </div>
                <div>
                    <label for="site_url">Site URL <span class="req" title="Required">*</span></label>
                    <input type="text" id="site_url" name="site_url" value="<?php echo $h($field('site_url', $detectedUrl)); ?>" required>
                    <span class="description">Detected automatically from current domain or subdomain.</span>
                </div>
            </div>
        </section>

        <!-- Step 4: Administrator Account -->
        <section class="panel">
            <div class="panel-header">
                <h2>Step 4 &mdash; Administrator Account</h2>
                <p>Create the primary Super Administrator account with full dashboard access.</p>
            </div>
            <div class="grid">
                <div>
                    <label for="admin_username">Admin Username <span class="req" title="Required">*</span></label>
                    <input type="text" id="admin_username" name="admin_username" value="<?php echo $h($field('admin_username')); ?>" required autocomplete="username" placeholder="e.g. admin">
                    <span class="description">3-60 alphanumeric characters.</span>
                </div>
                <div>
                    <label for="admin_email">Admin Email Address <span class="req" title="Required">*</span></label>
                    <input type="email" id="admin_email" name="admin_email" value="<?php echo $h($field('admin_email')); ?>" required autocomplete="email" placeholder="e.g. admin@example.com">
                    <span class="description">Used for account recovery and notifications.</span>
                </div>
                <div>
                    <label for="admin_password">Admin Password <span class="req" title="Required">*</span></label>
                    <div class="password-wrap">
                        <input type="password" id="admin_password" name="admin_password" required autocomplete="new-password" placeholder="Create strong password">
                        <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('admin_password', this)">Show</button>
                    </div>
                    <span class="description">At least 10 characters with letters and numbers.</span>
                </div>
                <div>
                    <label for="admin_password_confirm">Confirm Password <span class="req" title="Required">*</span></label>
                    <div class="password-wrap">
                        <input type="password" id="admin_password_confirm" name="admin_password_confirm" required autocomplete="new-password" placeholder="Re-enter password">
                        <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('admin_password_confirm', this)">Show</button>
                    </div>
                    <span class="description">Must match your admin password exactly.</span>
                </div>
            </div>
        </section>

        <!-- Step 5: Install Action -->
        <section class="install-cta-panel">
            <p>
                When you click Install, Favorite CMS will verify the database connection, execute all migrations in order, create your administrator account, and finalize the installation.
            </p>
            <button type="submit" id="btn-install-submit" name="db_action" value="install" class="btn-install-primary" <?php echo $hasRequirementFailures ? 'disabled' : ''; ?>>
                <span id="btn-install-text">&#10004; Install Favorite CMS</span>
            </button>
        </section>
    </form>

    <!-- Site Restore Form -->
    <form id="restore-form" class="hidden" method="POST" action="<?php echo $h($installAction); ?>" enctype="multipart/form-data" autocomplete="off">
        <input type="hidden" name="_token" value="<?php echo $h($token); ?>">
        <input type="hidden" name="db_action" value="restore">

        <section class="panel">
            <div class="panel-header">
                <h2>Restore Site from Backup (.zip)</h2>
                <p>
                    Migrating from another server or domain? Upload your <code>favorite_cms_backup_*.zip</code> archive.
                    The installer will restore your database tables, media uploads, themes, and plugins, and automatically update site URLs to this domain.
                </p>
            </div>

            <div style="margin-bottom: 18px;">
                <label for="backup_file">Select Backup Archive (.zip) <span class="req" title="Required">*</span></label>
                <input type="file" id="backup_file" name="backup_file" accept=".zip" required>
            </div>

            <h3 style="font-size: 15px; font-weight: 600; margin: 20px 0 12px; color: var(--slate-800);">Target Database Credentials</h3>
            <div class="grid">
                <div>
                    <label for="res_db_name">Database Name <span class="req" title="Required">*</span></label>
                    <input type="text" id="res_db_name" name="db_name" value="<?php echo $h($field('db_name')); ?>" required>
                </div>
                <div>
                    <label for="res_db_username">Database Username <span class="req" title="Required">*</span></label>
                    <input type="text" id="res_db_username" name="db_username" value="<?php echo $h($field('db_username')); ?>" required>
                </div>
                <div>
                    <label for="res_db_password">Database Password</label>
                    <div class="password-wrap">
                        <input type="password" id="res_db_password" name="db_password">
                        <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('res_db_password', this)">Show</button>
                    </div>
                </div>
                <div>
                    <label for="res_site_url">New Site URL</label>
                    <input type="text" id="res_site_url" name="site_url" value="<?php echo $h($field('site_url', $detectedUrl)); ?>" required>
                    <span class="description">All database references will be migrated to this URL.</span>
                </div>
            </div>

            <div style="margin-top: 24px;">
                <button type="submit" style="background: var(--success); border-color: #15803d; min-height: 44px; padding: 0 24px;">
                    &#128229; Restore &amp; Migrate Site
                </button>
            </div>
        </section>
    </form>

    <footer class="footer">Favorite CMS Universal &bull; Protected with standard CSRF verification &amp; no-cache headers.</footer>
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

function togglePasswordVisibility(inputId, btn) {
    const input = document.getElementById(inputId);
    if (!input) return;
    if (input.type === 'password') {
        input.type = 'text';
        btn.textContent = 'Hide';
    } else {
        input.type = 'password';
        btn.textContent = 'Show';
    }
}

function handleFormSubmit(form) {
    const submitBtn = document.getElementById('btn-install-submit');
    const btnText = document.getElementById('btn-install-text');
    if (submitBtn && event && event.submitter && event.submitter.value === 'install') {
        // Show installing state
        btnText.innerHTML = '<span class="spinner"></span> Installing Favorite CMS, please wait...';
        submitBtn.style.opacity = '0.85';
        submitBtn.style.cursor = 'wait';
    }
    return true;
}
</script>
</body>
</html>
