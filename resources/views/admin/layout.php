<?php
/**
 * Master Admin Layout
 * Parameters:
 * $pageTitle (string)
 * $activeMenu (string)
 * $content (string or closure/callable)
 */
try {
    $siteName = class_exists(\FavoriteCMS\Models\Setting::class) ? \FavoriteCMS\Models\Setting::get('general', 'site_name', 'Favorite CMS') : 'Favorite CMS';
} catch (\Throwable $e) {
    $siteName = 'Favorite CMS';
}

try {
    $currentAdminUser = function_exists('current_user') ? current_user() : (!empty($_SESSION['auth_user_id']) && class_exists(\FavoriteCMS\Models\User::class) ? \FavoriteCMS\Models\User::find((int)$_SESSION['auth_user_id']) : null);
} catch (\Throwable $e) {
    $currentAdminUser = null;
}
$user = $user ?? $currentAdminUser;
$username = $currentAdminUser ? ($currentAdminUser->name ?? $currentAdminUser->username) : 'Admin';

$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$activeMenu = $activeMenu ?? 'dashboard';
$siteFaviconUrl = function_exists('get_site_favicon_url') ? get_site_favicon_url('') : '';

// Resolve Admin Appearance Preference with deterministic precedence:
// 1. Authoritative Core Setting (for authenticated user)
// 2. Session cache
// 3. Client localStorage / default 'light'
$adminTheme = 'light';
if ($currentAdminUser) {
    try {
        $savedTheme = class_exists(\FavoriteCMS\Models\Setting::class)
            ? \FavoriteCMS\Models\Setting::get('admin_appearance', 'user_' . $currentAdminUser->id, null)
            : null;
        if ($savedTheme === 'dark' || $savedTheme === 'light') {
            $adminTheme = $savedTheme;
        } elseif (!empty($_SESSION['admin_theme']) && in_array($_SESSION['admin_theme'], ['dark', 'light'], true)) {
            $adminTheme = $_SESSION['admin_theme'];
        }
    } catch (\Throwable $e) {
        $adminTheme = $_SESSION['admin_theme'] ?? 'light';
    }
} elseif (!empty($_SESSION['admin_theme']) && in_array($_SESSION['admin_theme'], ['dark', 'light'], true)) {
    $adminTheme = $_SESSION['admin_theme'];
}
if ($adminTheme !== 'dark') {
    $adminTheme = 'light';
}
$_SESSION['admin_theme'] = $adminTheme;
?>
<!DOCTYPE html>
<html lang="en" data-admin-theme="<?php echo $adminTheme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle ?? 'Admin', ENT_QUOTES, 'UTF-8'); ?> &lsaquo; <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?> &mdash; Favorite CMS</title>
    <script>
    (function() {
        try {
            var serverTheme = <?php echo json_encode($adminTheme); ?>;
            var isAuth = <?php echo $currentAdminUser ? 'true' : 'false'; ?>;
            if (isAuth) {
                localStorage.setItem('favorite_admin_theme', serverTheme);
            } else {
                var localTheme = localStorage.getItem('favorite_admin_theme');
                if (localTheme === 'dark' || localTheme === 'light') {
                    document.documentElement.setAttribute('data-admin-theme', localTheme);
                }
            }
        } catch (e) {}
    })();
    </script>
    <?php if (!empty($siteFaviconUrl)): ?>
        <?php
        $favExt = strtolower(pathinfo(parse_url($siteFaviconUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        $favType = match ($favExt) {
            'ico'   => 'image/x-icon',
            'png'   => 'image/png',
            'svg'   => 'image/svg+xml',
            'gif'   => 'image/gif',
            'webp'  => 'image/webp',
            default => 'image/x-icon',
        };
        ?>
        <link rel="icon" type="<?php echo htmlspecialchars($favType, ENT_QUOTES, 'UTF-8'); ?>" href="<?php echo htmlspecialchars($siteFaviconUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            /* Semantic Admin Design Tokens - Light Theme */
            --admin-bg: #f8fafc;
            --admin-surface: #ffffff;
            --admin-surface-elevated: #ffffff;
            --admin-surface-subtle: #f1f5f9;
            --admin-border: #e2e8f0;
            --admin-border-subtle: #cbd5e1;
            --admin-border-focus: #3b82f6;
            --admin-text: #0f172a;
            --admin-text-muted: #64748b;
            --admin-text-heading: #0f172a;
            --admin-topbar-bg: #0f172a;
            --admin-topbar-text: #ffffff;
            --admin-sidebar-bg: #1e293b;
            --admin-sidebar-hover: rgba(255,255,255,0.06);
            --admin-sidebar-text: #cbd5e1;
            --admin-sidebar-muted: #94a3b8;
            --admin-sidebar-active: #2563eb;
            --admin-input-bg: #ffffff;
            --admin-input-border: #cbd5e1;
            --admin-input-text: #0f172a;
            --admin-input-placeholder: #94a3b8;
            --admin-primary: #2563eb;
            --admin-primary-hover: #1d4ed8;
            --admin-secondary-bg: #ffffff;
            --admin-secondary-hover: #f8fafc;
            --admin-secondary-border: #cbd5e1;
            --admin-secondary-text: #0f172a;
            --admin-danger: #dc2626;
            --admin-danger-bg: #fee2e2;
            --admin-danger-border: #fecaca;
            --admin-danger-text: #991b1b;
            --admin-success: #16a34a;
            --admin-success-bg: #f0fdf4;
            --admin-success-border: #bbf7d0;
            --admin-success-text: #166534;
            --admin-warning: #d97706;
            --admin-warning-bg: #fffbeb;
            --admin-warning-border: #fde68a;
            --admin-warning-text: #92400e;
            --admin-info: #0284c7;
            --admin-info-bg: #f0f9ff;
            --admin-info-border: #bae6fd;
            --admin-info-text: #0369a1;
            --admin-table-th-bg: #f8fafc;
            --admin-table-th-text: #334155;
            --admin-table-row-hover: #f8fafc;
            --admin-table-row-selected: #eff6ff;

            /* Legacy --wp-* variable mappings for complete backward compatibility */
            --wp-dark: var(--admin-topbar-bg);
            --wp-sidebar-bg: var(--admin-sidebar-bg);
            --wp-blue: var(--admin-primary);
            --wp-blue-hover: var(--admin-primary-hover);
            --wp-blue-light: #eff6ff;
            --wp-light: var(--admin-bg);
            --wp-border: var(--admin-border);
            --wp-border-focus: var(--admin-border-focus);
            --wp-text: var(--admin-text);
            --wp-text-muted: var(--admin-text-muted);
            --wp-danger: var(--admin-danger);
            --wp-success: var(--admin-success);
            --wp-warning: var(--admin-warning);
            --wp-info: var(--admin-info);
            --sidebar-width: 220px;
            --radius-sm: 4px;
            --radius-md: 6px;
            --radius-lg: 8px;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.07), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
        }

        :root[data-admin-theme="dark"] {
            /* Semantic Admin Design Tokens - Dark Theme */
            --admin-bg: #0b1120;
            --admin-surface: #1e293b;
            --admin-surface-elevated: #334155;
            --admin-surface-subtle: #0f172a;
            --admin-border: #334155;
            --admin-border-subtle: #475569;
            --admin-border-focus: #60a5fa;
            --admin-text: #f1f5f9;
            --admin-text-muted: #94a3b8;
            --admin-text-heading: #f8fafc;
            --admin-topbar-bg: #0b1120;
            --admin-topbar-text: #f8fafc;
            --admin-sidebar-bg: #0f172a;
            --admin-sidebar-hover: rgba(255,255,255,0.08);
            --admin-sidebar-text: #e2e8f0;
            --admin-sidebar-muted: #94a3b8;
            --admin-sidebar-active: #3b82f6;
            --admin-input-bg: #0f172a;
            --admin-input-border: #475569;
            --admin-input-text: #f1f5f9;
            --admin-input-placeholder: #64748b;
            --admin-primary: #3b82f6;
            --admin-primary-hover: #2563eb;
            --admin-secondary-bg: #1e293b;
            --admin-secondary-hover: #334155;
            --admin-secondary-border: #475569;
            --admin-secondary-text: #f1f5f9;
            --admin-danger: #ef4444;
            --admin-danger-bg: rgba(239, 68, 68, 0.15);
            --admin-danger-border: rgba(239, 68, 68, 0.35);
            --admin-danger-text: #fca5a5;
            --admin-success: #22c55e;
            --admin-success-bg: rgba(34, 197, 94, 0.15);
            --admin-success-border: rgba(34, 197, 94, 0.35);
            --admin-success-text: #86efac;
            --admin-warning: #f59e0b;
            --admin-warning-bg: rgba(245, 158, 11, 0.15);
            --admin-warning-border: rgba(245, 158, 11, 0.35);
            --admin-warning-text: #fcd34d;
            --admin-info: #38bdf8;
            --admin-info-bg: rgba(56, 189, 248, 0.15);
            --admin-info-border: rgba(56, 189, 248, 0.35);
            --admin-info-text: #7dd3fc;
            --admin-table-th-bg: #0f172a;
            --admin-table-th-text: #cbd5e1;
            --admin-table-row-hover: #1e293b;
            --admin-table-row-selected: rgba(59, 130, 246, 0.2);

            /* Legacy variable overrides in dark mode */
            --wp-dark: var(--admin-topbar-bg);
            --wp-sidebar-bg: var(--admin-sidebar-bg);
            --wp-blue: var(--admin-primary);
            --wp-blue-hover: var(--admin-primary-hover);
            --wp-blue-light: rgba(59, 130, 246, 0.2);
            --wp-light: var(--admin-bg);
            --wp-border: var(--admin-border);
            --wp-border-focus: var(--admin-border-focus);
            --wp-text: var(--admin-text);
            --wp-text-muted: var(--admin-text-muted);
            --wp-danger: var(--admin-danger);
            --wp-success: var(--admin-success);
            --wp-warning: var(--admin-warning);
            --wp-info: var(--admin-info);
            --shadow-sm: 0 1px 3px 0 rgba(0, 0, 0, 0.3);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.4), 0 2px 4px -2px rgba(0, 0, 0, 0.3);
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            background: var(--wp-light);
            color: var(--wp-text);
            font-size: 13.5px;
            line-height: 1.5;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* Accessibility focus-visible */
        a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible {
            outline: 2px solid var(--wp-blue);
            outline-offset: 2px;
        }

        /* Topbar */
        .wp-topbar {
            background: var(--wp-dark);
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            color: #fff;
            font-size: 12.5px;
            position: sticky;
            top: 0;
            z-index: 999;
            box-shadow: 0 1px 3px rgba(0,0,0,0.15);
        }
        .wp-topbar a { color: #f1f5f9; text-decoration: none; transition: color 0.15s; }
        .wp-topbar a:hover { color: #93c5fd; }
        .topbar-left { display: flex; align-items: center; gap: 14px; font-weight: 500; }
        .topbar-left .star { color: #f59e0b; font-size: 15px; }
        .topbar-right { display: flex; align-items: center; gap: 16px; }

        .mobile-menu-toggle {
            display: none;
            background: transparent;
            border: 1px solid rgba(255,255,255,0.2);
            color: #fff;
            font-size: 18px;
            padding: 2px 8px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            line-height: 1;
        }
        .mobile-menu-toggle:hover { background: rgba(255,255,255,0.1); }

        /* Main Container */
        .wp-body {
            display: flex;
            flex: 1;
            position: relative;
        }

        /* Sidebar */
        .wp-sidebar {
            width: var(--sidebar-width);
            background: var(--wp-sidebar-bg);
            color: #94a3b8;
            flex-shrink: 0;
            padding: 12px 0;
            display: flex;
            flex-direction: column;
        }
        .wp-menu { list-style: none; }
        .wp-menu-item { position: relative; }
        .wp-menu-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 18px;
            color: #cbd5e1;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.15s ease;
        }
        .wp-menu-item.has-submenu > .wp-menu-link {
            cursor: pointer;
            user-select: none;
        }
        .wp-menu-link:hover {
            background: rgba(255,255,255,0.06);
            color: #fff;
        }
        .wp-menu-item.active > .wp-menu-link {
            color: #fff;
            background: var(--wp-blue);
        }
        .wp-menu-toggle-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-left: auto;
            color: #94a3b8;
            transition: transform 0.2s ease, color 0.15s ease;
            flex-shrink: 0;
        }
        .wp-menu-item.active .wp-menu-toggle-icon {
            color: rgba(255,255,255,0.85);
        }
        .wp-menu-item.has-submenu.is-expanded .wp-menu-toggle-icon {
            transform: rotate(180deg);
        }
        .wp-submenu {
            list-style: none;
            background: #0f172a;
            padding: 4px 0;
            display: none;
        }
        .wp-menu-item.has-submenu.is-expanded > .wp-submenu {
            display: block;
        }
        .wp-submenu a {
            display: block;
            padding: 6px 18px 6px 42px;
            color: #94a3b8;
            text-decoration: none;
            font-size: 12.5px;
            transition: color 0.15s;
        }
        .wp-submenu a:hover, .wp-submenu a.active {
            color: #fff;
        }

        /* Content Area */
        .wp-content {
            flex: 1;
            padding: 24px 28px;
            min-width: 0;
        }
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .page-title {
            font-size: 22px;
            font-weight: 700;
            color: #0f172a;
            letter-spacing: -0.3px;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: var(--radius-md);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            border: 1px solid transparent;
            line-height: 1.5;
            transition: all 0.15s ease-in-out;
            font-family: inherit;
        }
        .btn-primary {
            background: var(--wp-blue);
            color: #fff;
            border-color: var(--wp-blue);
        }
        .btn-primary:hover { background: var(--wp-blue-hover); border-color: var(--wp-blue-hover); color: #fff; }
        .btn-secondary {
            background: #fff;
            color: var(--wp-text);
            border-color: #cbd5e1;
        }
        .btn-secondary:hover { background: #f8fafc; border-color: #94a3b8; }
        .btn-danger {
            background: #fee2e2;
            color: var(--wp-danger);
            border-color: #fecaca;
        }
        .btn-danger:hover { background: var(--wp-danger); color: #fff; border-color: var(--wp-danger); }
        .btn-success {
            background: #16a34a;
            color: #fff;
            border-color: #15803d;
        }
        .btn-success:hover { background: #15803d; color: #fff; }
        .btn-outline-secondary {
            background: transparent;
            color: var(--wp-text-muted);
            border-color: #cbd5e1;
        }
        .btn-outline-secondary:hover { background: #f1f5f9; color: var(--wp-text); border-color: #94a3b8; }
        .btn-sm { padding: 3px 8px; font-size: 12px; border-radius: var(--radius-sm); }

        /* Notices and Alerts */
        .notice, .alert {
            background: #fff;
            border-left: 4px solid var(--wp-blue);
            box-shadow: var(--shadow-sm);
            padding: 12px 16px;
            margin-bottom: 20px;
            font-size: 13px;
            border-radius: var(--radius-md);
            line-height: 1.5;
        }
        .notice-success, .alert-success { border-left-color: var(--wp-success); background: #f0fdf4; color: #166534; }
        .notice-error, .alert-danger { border-left-color: var(--wp-danger); background: #fef2f2; color: #991b1b; }
        .notice-warning, .alert-warning { border-left-color: var(--wp-warning); background: #fffbeb; color: #92400e; }
        .notice-info, .alert-info { border-left-color: var(--wp-info); background: #f0f9ff; color: #0369a1; }

        /* Cards */
        .card, .form-card {
            background: #fff;
            border: 1px solid var(--wp-border);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            margin-bottom: 20px;
        }
        .form-card { padding: 24px; }
        .card-header {
            padding: 14px 18px;
            background: #f8fafc;
            border-bottom: 1px solid var(--wp-border);
            border-top-left-radius: var(--radius-md);
            border-top-right-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .card-header h5 { margin: 0; font-size: 14px; font-weight: 600; color: #0f172a; }
        .card-body { padding: 20px; }
        .card-footer {
            padding: 12px 18px;
            background: #f8fafc;
            border-top: 1px solid var(--wp-border);
            border-bottom-left-radius: var(--radius-md);
            border-bottom-right-radius: var(--radius-md);
        }

        /* Badges / Status Chips */
        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            line-height: 1.2;
        }
        .badge-success, .badge.bg-success { background: #dcfce7 !important; color: #166534 !important; border: 1px solid #bbf7d0; }
        .badge-warning, .badge.bg-warning { background: #fef3c7 !important; color: #92400e !important; border: 1px solid #fde68a; }
        .badge-danger, .badge.bg-danger { background: #fee2e2 !important; color: #991b1b !important; border: 1px solid #fecaca; }
        .badge-info, .badge.bg-info { background: #e0f2fe !important; color: #075985 !important; border: 1px solid #bae6fd; }
        .badge-secondary, .badge.bg-secondary { background: #f1f5f9 !important; color: #475569 !important; border: 1px solid #e2e8f0; }
        .badge-primary, .badge.bg-primary { background: #eff6ff !important; color: #1d4ed8 !important; border: 1px solid #bfdbfe; }

        /* Tabs */
        .nav-tabs {
            display: flex;
            gap: 4px;
            border-bottom: 2px solid var(--wp-border);
            margin-bottom: 20px;
            list-style: none;
            padding: 0;
        }
        .nav-item { margin-bottom: -2px; }
        .nav-link {
            display: inline-block;
            padding: 9px 16px;
            font-size: 13.5px;
            font-weight: 500;
            color: var(--wp-text-muted);
            text-decoration: none;
            border-bottom: 2px solid transparent;
            transition: color 0.15s, border-color 0.15s;
        }
        .nav-link:hover { color: var(--wp-blue); }
        .nav-link.active {
            color: var(--wp-blue);
            font-weight: 600;
            border-bottom-color: var(--wp-blue);
        }

        /* Form Controls */
        .form-group { margin-bottom: 18px; }
        .form-group label, .form-label {
            display: block;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 6px;
            font-size: 13px;
        }
        .form-control, .form-select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            border-radius: var(--radius-md);
            font-size: 13.5px;
            background: #fff;
            color: var(--wp-text);
            font-family: inherit;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--wp-blue);
            outline: none;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }
        textarea.form-control { resize: vertical; min-height: 100px; }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .description, .form-text {
            display: block;
            margin-top: 5px;
            color: var(--wp-text-muted);
            font-size: 12px;
        }

        /* Switches & Checkboxes */
        .form-check { display: flex; align-items: center; gap: 8px; }
        .form-switch { display: flex; align-items: center; gap: 10px; }
        .form-switch input[type="checkbox"] {
            width: 36px;
            height: 20px;
            appearance: none;
            -webkit-appearance: none;
            background: #cbd5e1;
            border-radius: 9999px;
            position: relative;
            cursor: pointer;
            outline: none;
            transition: background 0.2s;
            flex-shrink: 0;
            margin: 0;
        }
        .form-switch input[type="checkbox"]:checked { background: var(--wp-blue); }
        .form-switch input[type="checkbox"]::before {
            content: "";
            position: absolute;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: #fff;
            top: 2px;
            left: 2px;
            transition: transform 0.2s;
            box-shadow: 0 1px 2px rgba(0,0,0,0.2);
        }
        .form-switch input[type="checkbox"]:checked::before { transform: translateX(16px); }

        /* Data Tables */
        .wp-table-wrap {
            background: #fff;
            border: 1px solid var(--wp-border);
            box-shadow: var(--shadow-sm);
            border-radius: var(--radius-md);
            overflow-x: auto;
            margin-bottom: 20px;
        }
        table.wp-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 13px;
        }
        table.wp-table th, table.wp-table td {
            padding: 12px 14px;
            border-bottom: 1px solid var(--wp-border);
            vertical-align: middle;
        }
        table.wp-table th {
            font-weight: 600;
            color: #334155;
            background: #f8fafc;
            border-bottom: 2px solid var(--wp-border);
        }
        table.wp-table tr:hover td {
            background: #f8fafc;
        }
        table.wp-table tr.is-selected td {
            background-color: #eff6ff !important;
        }
        .bulk-actions-wrap {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }
        .bulk-count-badge {
            display: inline-block;
            background: #f1f5f9;
            color: #64748b;
            font-size: 11.5px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 9999px;
            border: 1px solid var(--wp-border);
            transition: all 0.15s ease;
        }
        .bulk-count-badge.has-selected {
            background: var(--wp-blue);
            color: #ffffff;
            border-color: var(--wp-blue);
        }
        .row-actions {
            font-size: 12px;
            color: var(--wp-text-muted);
            margin-top: 5px;
        }
        .row-actions a { text-decoration: none; }
        .row-actions a:hover { text-decoration: underline; }

        /* Subsubsub Filters (All | Published | Draft | Trash) */
        ul.subsubsub {
            list-style: none;
            display: flex;
            gap: 10px;
            font-size: 13px;
            color: var(--wp-text-muted);
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        ul.subsubsub a { color: var(--wp-blue); text-decoration: none; }
        ul.subsubsub a.current { font-weight: 700; color: #0f172a; }

        /* Layout Grid & Flex Utilities */
        .d-flex { display: flex; }
        .justify-content-between { justify-content: space-between; }
        .justify-content-end { justify-content: flex-end; }
        .align-items-center { align-items: center; }
        .align-items-start { align-items: flex-start; }
        .flex-wrap { flex-wrap: wrap; }
        .gap-1 { gap: 4px; }
        .gap-2 { gap: 8px; }
        .gap-3 { gap: 12px; }
        .gap-4 { gap: 16px; }

        .row { display: flex; flex-wrap: wrap; margin-left: -10px; margin-right: -10px; }
        [class*="col-"] { padding-left: 10px; padding-right: 10px; box-sizing: border-box; width: 100%; }
        .col-12 { flex: 0 0 100%; max-width: 100%; }
        .col-md-3 { flex: 0 0 25%; max-width: 25%; }
        .col-md-4 { flex: 0 0 33.333333%; max-width: 33.333333%; }
        .col-md-6 { flex: 0 0 50%; max-width: 50%; }
        .col-md-8 { flex: 0 0 66.666667%; max-width: 66.666667%; }
        .col-lg-4 { flex: 0 0 33.333333%; max-width: 33.333333%; }

        .text-muted { color: var(--wp-text-muted) !important; }
        .text-success { color: var(--wp-success) !important; }
        .text-danger { color: var(--wp-danger) !important; }
        .text-white { color: #fff !important; }
        .small { font-size: 12px !important; }
        .font-weight-bold { font-weight: 600 !important; }

        .mb-0 { margin-bottom: 0 !important; }
        .mb-1 { margin-bottom: 4px !important; }
        .mb-2 { margin-bottom: 8px !important; }
        .mb-3 { margin-bottom: 12px !important; }
        .mb-4 { margin-bottom: 18px !important; }
        .mt-1 { margin-top: 4px !important; }
        .mt-2 { margin-top: 8px !important; }
        .mt-3 { margin-top: 12px !important; }
        .mt-4 { margin-top: 18px !important; }
        .py-2 { padding-top: 8px !important; padding-bottom: 8px !important; }
        .list-unstyled { list-style: none; padding: 0; margin: 0; }

        /* Responsive Mobile Styles */
        .sidebar-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.5);
            z-index: 998;
        }

        @media (max-width: 991px) {
            .col-lg-4 { flex: 0 0 50%; max-width: 50%; }
        }

        @media (max-width: 782px) {
            .mobile-menu-toggle { display: inline-block; }
            .sidebar-backdrop.is-active { display: block; }
            .wp-sidebar {
                position: fixed;
                top: 42px;
                left: -240px;
                bottom: 0;
                width: 240px;
                z-index: 1000;
                box-shadow: var(--shadow-md);
                transition: left 0.25s ease;
                overflow-y: auto;
            }
            .wp-sidebar.is-open {
                left: 0;
            }
            .wp-content {
                padding: 16px;
            }
            .col-md-3, .col-md-4, .col-md-6, .col-lg-4, .col-md-8 {
                flex: 0 0 100%;
                max-width: 100%;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        /* Admin Theme Toggle Button */
        .admin-theme-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            padding: 0;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: var(--radius-sm);
            color: #f1f5f9;
            font-size: 15px;
            cursor: pointer;
            line-height: 1;
            transition: all 0.15s ease;
            user-select: none;
            flex-shrink: 0;
        }
        .admin-theme-toggle:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.35);
            color: #ffffff;
            transform: scale(1.04);
        }
        .admin-theme-toggle:focus-visible {
            outline: 2px solid #60a5fa;
            outline-offset: 2px;
        }
        .admin-theme-toggle .theme-icon-sun,
        .admin-theme-toggle .theme-icon-moon {
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        /* Dark Mode Component Overrides */
        [data-admin-theme="dark"] body {
            background: var(--admin-bg) !important;
            color: var(--admin-text) !important;
        }
        [data-admin-theme="dark"] .wp-content {
            background: var(--admin-bg);
        }
        [data-admin-theme="dark"] .page-title,
        [data-admin-theme="dark"] h1,
        [data-admin-theme="dark"] h2,
        [data-admin-theme="dark"] h3,
        [data-admin-theme="dark"] h4,
        [data-admin-theme="dark"] h5,
        [data-admin-theme="dark"] h6 {
            color: var(--admin-text-heading) !important;
        }
        [data-admin-theme="dark"] .card,
        [data-admin-theme="dark"] .form-card,
        [data-admin-theme="dark"] .plugin-page-card,
        [data-admin-theme="dark"] .wp-table-wrap {
            background: var(--admin-surface) !important;
            border-color: var(--admin-border) !important;
            color: var(--admin-text) !important;
        }
        [data-admin-theme="dark"] .card-header,
        [data-admin-theme="dark"] .card-footer {
            background: var(--admin-surface-subtle) !important;
            border-color: var(--admin-border) !important;
            color: var(--admin-text-heading) !important;
        }
        [data-admin-theme="dark"] .form-control,
        [data-admin-theme="dark"] .form-select,
        [data-admin-theme="dark"] input[type="text"],
        [data-admin-theme="dark"] input[type="password"],
        [data-admin-theme="dark"] input[type="email"],
        [data-admin-theme="dark"] input[type="url"],
        [data-admin-theme="dark"] input[type="number"],
        [data-admin-theme="dark"] input[type="search"],
        [data-admin-theme="dark"] input[type="date"],
        [data-admin-theme="dark"] input[type="time"],
        [data-admin-theme="dark"] input[type="file"],
        [data-admin-theme="dark"] select,
        [data-admin-theme="dark"] textarea {
            background: var(--admin-input-bg) !important;
            border-color: var(--admin-input-border) !important;
            color: var(--admin-input-text) !important;
        }
        [data-admin-theme="dark"] .form-control:focus,
        [data-admin-theme="dark"] .form-select:focus,
        [data-admin-theme="dark"] input:focus,
        [data-admin-theme="dark"] select:focus,
        [data-admin-theme="dark"] textarea:focus {
            border-color: var(--admin-border-focus) !important;
            box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.25) !important;
        }
        [data-admin-theme="dark"] .form-group label,
        [data-admin-theme="dark"] .form-label,
        [data-admin-theme="dark"] label {
            color: var(--admin-text) !important;
        }
        [data-admin-theme="dark"] .description,
        [data-admin-theme="dark"] .form-text,
        [data-admin-theme="dark"] .text-muted {
            color: var(--admin-text-muted) !important;
        }
        [data-admin-theme="dark"] table.wp-table th {
            background: var(--admin-table-th-bg) !important;
            color: var(--admin-table-th-text) !important;
            border-color: var(--admin-border) !important;
        }
        [data-admin-theme="dark"] table.wp-table td {
            background: var(--admin-surface) !important;
            color: var(--admin-text) !important;
            border-color: var(--admin-border) !important;
        }
        [data-admin-theme="dark"] table.wp-table tr:hover td {
            background: var(--admin-table-row-hover) !important;
        }
        [data-admin-theme="dark"] table.wp-table tr.is-selected td {
            background: var(--admin-table-row-selected) !important;
        }
        [data-admin-theme="dark"] .btn-secondary {
            background: var(--admin-secondary-bg) !important;
            border-color: var(--admin-secondary-border) !important;
            color: var(--admin-secondary-text) !important;
        }
        [data-admin-theme="dark"] .btn-secondary:hover {
            background: var(--admin-secondary-hover) !important;
            border-color: var(--admin-border-focus) !important;
            color: var(--admin-text-heading) !important;
        }
        [data-admin-theme="dark"] .btn-outline-secondary {
            background: transparent !important;
            color: var(--admin-text-muted) !important;
            border-color: var(--admin-border) !important;
        }
        [data-admin-theme="dark"] .btn-outline-secondary:hover {
            background: var(--admin-surface-elevated) !important;
            color: var(--admin-text-heading) !important;
            border-color: var(--admin-border-subtle) !important;
        }
        [data-admin-theme="dark"] .btn-danger {
            background: var(--admin-danger-bg) !important;
            color: var(--admin-danger-text) !important;
            border-color: var(--admin-danger-border) !important;
        }
        [data-admin-theme="dark"] .btn-danger:hover {
            background: var(--admin-danger) !important;
            color: #ffffff !important;
        }
        [data-admin-theme="dark"] .btn-success {
            background: var(--admin-success) !important;
            border-color: #15803d !important;
            color: #ffffff !important;
        }
        [data-admin-theme="dark"] .nav-tabs {
            border-color: var(--admin-border) !important;
        }
        [data-admin-theme="dark"] .nav-link {
            color: var(--admin-text-muted) !important;
        }
        [data-admin-theme="dark"] .nav-link.active {
            color: var(--admin-primary) !important;
            border-bottom-color: var(--admin-primary) !important;
        }
        [data-admin-theme="dark"] .bulk-count-badge {
            background: var(--admin-surface-subtle) !important;
            border-color: var(--admin-border) !important;
            color: var(--admin-text-muted) !important;
        }
        [data-admin-theme="dark"] .bulk-count-badge.has-selected {
            background: var(--admin-primary) !important;
            color: #ffffff !important;
            border-color: var(--admin-primary) !important;
        }
        [data-admin-theme="dark"] ul.subsubsub a.current {
            color: var(--admin-text-heading) !important;
        }
        [data-admin-theme="dark"] ul.subsubsub {
            color: var(--admin-text-muted) !important;
        }
        [data-admin-theme="dark"] code,
        [data-admin-theme="dark"] pre {
            background: var(--admin-surface-subtle) !important;
            border-color: var(--admin-border) !important;
            color: #38bdf8 !important;
        }
        [data-admin-theme="dark"] hr {
            border-color: var(--admin-border) !important;
        }
        [data-admin-theme="dark"] .notice,
        [data-admin-theme="dark"] .alert {
            background: var(--admin-surface) !important;
            box-shadow: 0 1px 3px rgba(0,0,0,0.3) !important;
            color: var(--admin-text) !important;
        }
        [data-admin-theme="dark"] .notice-success,
        [data-admin-theme="dark"] .alert-success {
            background: var(--admin-success-bg) !important;
            color: var(--admin-success-text) !important;
            border-left-color: var(--admin-success) !important;
        }
        [data-admin-theme="dark"] .notice-error,
        [data-admin-theme="dark"] .alert-danger {
            background: var(--admin-danger-bg) !important;
            color: var(--admin-danger-text) !important;
            border-left-color: var(--admin-danger) !important;
        }
        [data-admin-theme="dark"] .notice-warning,
        [data-admin-theme="dark"] .alert-warning {
            background: var(--admin-warning-bg) !important;
            color: var(--admin-warning-text) !important;
            border-left-color: var(--admin-warning) !important;
        }
        [data-admin-theme="dark"] .notice-info,
        [data-admin-theme="dark"] .alert-info {
            background: var(--admin-info-bg) !important;
            color: var(--admin-info-text) !important;
            border-left-color: var(--admin-info) !important;
        }
        [data-admin-theme="dark"] .badge-secondary,
        [data-admin-theme="dark"] .badge.bg-secondary {
            background: var(--admin-surface-elevated) !important;
            color: var(--admin-text-muted) !important;
            border-color: var(--admin-border) !important;
        }
        /* Menu Management dark theme rules */
        [data-admin-theme="dark"] .menu-item-row {
            background: var(--admin-surface) !important;
            border-color: var(--admin-border) !important;
            box-shadow: 0 1px 3px rgba(0,0,0,0.3) !important;
        }
        [data-admin-theme="dark"] .menu-item-bar {
            background: var(--admin-surface) !important;
        }
        [data-admin-theme="dark"] .menu-item-editor {
            background: var(--admin-surface-subtle) !important;
            border-top-color: var(--admin-border) !important;
        }
        [data-admin-theme="dark"] .menu-item-title-display {
            color: var(--admin-text-heading) !important;
        }
        [data-admin-theme="dark"] .menu-sub-item-badge {
            background: var(--admin-surface-elevated) !important;
            color: var(--admin-text-muted) !important;
        }
        [data-admin-theme="dark"] .btn-menu-action.btn-move-up,
        [data-admin-theme="dark"] .btn-menu-action.btn-move-down {
            background: var(--admin-surface-subtle) !important;
            border-color: var(--admin-border) !important;
            color: var(--admin-text) !important;
        }
        [data-admin-theme="dark"] .btn-menu-action.btn-move-up:hover,
        [data-admin-theme="dark"] .btn-menu-action.btn-move-down:hover {
            background: var(--admin-surface-elevated) !important;
            color: var(--admin-text-heading) !important;
            border-color: var(--admin-border-subtle) !important;
        }
        [data-admin-theme="dark"] .btn-menu-action.btn-menu-edit {
            background: rgba(59, 130, 246, 0.15) !important;
            border-color: rgba(59, 130, 246, 0.35) !important;
            color: #93c5fd !important;
        }
        [data-admin-theme="dark"] .btn-menu-action.btn-menu-edit:hover {
            background: rgba(59, 130, 246, 0.25) !important;
            color: #bfdbfe !important;
        }
        [data-admin-theme="dark"] .btn-menu-action.btn-menu-remove {
            background: rgba(239, 68, 68, 0.15) !important;
            border-color: rgba(239, 68, 68, 0.35) !important;
            color: #fca5a5 !important;
        }
        [data-admin-theme="dark"] .btn-menu-action.btn-menu-remove:hover {
            background: var(--admin-danger) !important;
            color: #ffffff !important;
        }
        /* Fallback for inline-styled white/light containers across admin views */
        [data-admin-theme="dark"] div[style*="background: #f8fafc"],
        [data-admin-theme="dark"] div[style*="background:#f8fafc"] {
            background: var(--admin-surface-subtle) !important;
            border-color: var(--admin-border) !important;
        }
        [data-admin-theme="dark"] div[style*="background: #ffffff"],
        [data-admin-theme="dark"] div[style*="background:#ffffff"],
        [data-admin-theme="dark"] div[style*="background: #fff"],
        [data-admin-theme="dark"] div[style*="background:#fff"] {
            background: var(--admin-surface) !important;
            border-color: var(--admin-border) !important;
        }
        [data-admin-theme="dark"] div[style*="color: #0f172a"],
        [data-admin-theme="dark"] div[style*="color: #1d2327"],
        [data-admin-theme="dark"] div[style*="color: #1e293b"],
        [data-admin-theme="dark"] span[style*="color: #0f172a"],
        [data-admin-theme="dark"] strong[style*="color: #0f172a"] {
            color: var(--admin-text-heading) !important;
        }
        /* Editor wrapper and toolbars */
        [data-admin-theme="dark"] .editor-wrapper,
        [data-admin-theme="dark"] #visual-toolbar,
        [data-admin-theme="dark"] #code-toolbar,
        [data-admin-theme="dark"] #visual-mode-container {
            background: var(--admin-surface) !important;
            border-color: var(--admin-border) !important;
        }
        [data-admin-theme="dark"] #visual-editor {
            background: var(--admin-surface) !important;
            color: var(--admin-text) !important;
        }
        [data-admin-theme="dark"] .rich-btn,
        [data-admin-theme="dark"] .code-insert-btn,
        [data-admin-theme="dark"] .toolbar-select {
            background: var(--admin-surface-subtle) !important;
            border-color: var(--admin-border) !important;
            color: var(--admin-text) !important;
        }
        [data-admin-theme="dark"] .rich-btn:hover,
        [data-admin-theme="dark"] .code-insert-btn:hover {
            background: var(--admin-surface-elevated) !important;
            color: var(--admin-text-heading) !important;
        }
        [data-admin-theme="dark"] .modal-tab-btn {
            background: var(--admin-surface-subtle) !important;
            border-color: var(--admin-border) !important;
            color: var(--admin-text-muted) !important;
        }
        [data-admin-theme="dark"] .modal-tab-btn.active {
            background: var(--admin-surface) !important;
            border-color: var(--admin-primary) !important;
            color: var(--admin-primary) !important;
        }
        [data-admin-theme="dark"] #preview-modal > div,
        [data-admin-theme="dark"] #media-modal > div {
            background: var(--admin-surface) !important;
            color: var(--admin-text) !important;
        }
        [data-admin-theme="dark"] #preview-modal div[style*="background: #f8fafc"],
        [data-admin-theme="dark"] #media-modal div[style*="background: #f8fafc"] {
            background: var(--admin-surface-subtle) !important;
            border-color: var(--admin-border) !important;
        }
    </style>
</head>
<body>
<form id="core-action-form" method="POST" hidden><?php echo csrf_field(); ?></form>
<style>.core-action-link { background: none; border: 0; padding: 0; font: inherit; color: inherit; cursor: pointer; text-align: inherit; }</style>

    <div class="wp-topbar">
        <div class="topbar-left">
            <button type="button" class="mobile-menu-toggle" id="mobile-menu-toggle" aria-expanded="false" aria-label="Toggle navigation menu">&#9776;</button>
            <a href="/" target="_blank" title="Visit Site"><span class="star">&#9733;</span> <strong><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></strong> &rarr;</a>
        </div>
        <div class="topbar-right">
            <button type="button" 
                    id="admin-theme-toggle" 
                    class="admin-theme-toggle" 
                    aria-label="<?php echo $adminTheme === 'dark' ? 'Switch to Light Mode' : 'Switch to Dark Mode'; ?>" 
                    title="<?php echo $adminTheme === 'dark' ? 'Switch to Light Mode' : 'Switch to Dark Mode'; ?>" 
                    aria-pressed="<?php echo $adminTheme === 'dark' ? 'true' : 'false'; ?>">
                <span class="theme-icon-sun" aria-hidden="true" style="<?php echo $adminTheme === 'dark' ? 'display:inline-flex;' : 'display:none;'; ?>">☀️</span>
                <span class="theme-icon-moon" aria-hidden="true" style="<?php echo $adminTheme === 'dark' ? 'display:none;' : 'display:inline-flex;'; ?>">🌙</span>
            </button>
            <span>Howdy, <strong><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></strong></span>
            <a href="/admin/users/profile">Edit Profile</a>
            <a href="/admin/logout" style="color: #fca5a5;">Log Out</a>
        </div>
    </div>
    <div class="sidebar-backdrop" id="sidebar-backdrop"></div>

    <div class="wp-body">
        <nav class="wp-sidebar">
            <ul class="wp-menu">
                <li class="wp-menu-item <?php echo $activeMenu === 'dashboard' ? 'active' : ''; ?>">
                    <a href="/admin" class="wp-menu-link">📊 Dashboard</a>
                </li>
                <?php
                $isAdmin = $currentAdminUser && ($currentAdminUser->hasRole('admin') || $currentAdminUser->hasRole('super-admin') || $currentAdminUser->isSuperAdmin());
                ?>
                <?php if ($isAdmin): ?>
                    <li class="wp-menu-item <?php echo $activeMenu === 'updates' ? 'active' : ''; ?>">
                        <a href="/admin/updates" class="wp-menu-link">🔄 Updates</a>
                    </li>
                <?php endif; ?>
                <?php
                $canModerate = $currentAdminUser && $currentAdminUser->canModeratePosts();
                $canModerateComments = $currentAdminUser && $currentAdminUser->canModerateComments();
                $canManageUsers = $currentAdminUser && $currentAdminUser->canManageUsers();
                try {
                    $pendingCount = class_exists(\FavoriteCMS\Models\Post::class) ? (int)(\FavoriteCMS\Models\Post::countByStatus()['pending'] ?? 0) : 0;
                } catch (\Throwable $e) {
                    $pendingCount = 0;
                }
                try {
                    $pendingCommentsCount = $canModerateComments && class_exists(\FavoriteCMS\Models\Comment::class) ? (int)(\FavoriteCMS\Models\Comment::countByStatus()['pending'] ?? 0) : 0;
                } catch (\Throwable $e) {
                    $pendingCommentsCount = 0;
                }
                ?>
                <?php
                $canAccessPosts = $currentAdminUser && ($currentAdminUser->canCreatePosts() || $currentAdminUser->canUpdatePosts() || $currentAdminUser->canModeratePosts());
                $canAccessPosts = $currentAdminUser && $currentAdminUser->isActive();
                $canCreatePosts = $currentAdminUser && $currentAdminUser->canCreatePosts();
                $canManageTaxonomies = $currentAdminUser && $currentAdminUser->canManageTaxonomies();
                $canManagePages = $currentAdminUser && $currentAdminUser->canManagePages();
                $canUploadMedia = $currentAdminUser && $currentAdminUser->canUploadMedia();
                $canManageMedia = $currentAdminUser && $currentAdminUser->canManageMedia();
                $canManageMenus = $currentAdminUser && $currentAdminUser->canManageMenus();
                $isPostsActive = in_array($activeMenu, ['posts', 'posts-new', 'categories', 'tags']);
                ?>
                <?php if ($canAccessPosts): ?>
                <li class="wp-menu-item has-submenu <?php echo $isPostsActive ? 'active is-expanded' : ''; ?>">
                    <a href="/admin/posts" class="wp-menu-link" aria-haspopup="true" aria-expanded="<?php echo $isPostsActive ? 'true' : 'false'; ?>" aria-controls="submenu-posts">
                        <span>📝 Posts</span>
                        <?php if ($canModerate && $pendingCount > 0): ?>
                            <span style="background: #e5a00d; color: #fff; font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 10px; margin-left: 6px;"><?php echo $pendingCount; ?></span>
                        <?php endif; ?>
                        <span class="wp-menu-toggle-icon" aria-hidden="true">
                            <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                        </span>
                    </a>
                    <ul class="wp-submenu" id="submenu-posts">
                        <li><a href="/admin/posts" class="<?php echo $activeMenu === 'posts' ? 'active' : ''; ?>">All Posts</a></li>
                        <?php if ($canCreatePosts): ?>
                            <li><a href="/admin/posts/new" class="<?php echo $activeMenu === 'posts-new' ? 'active' : ''; ?>">Add New Post</a></li>
                        <?php endif; ?>
                        <?php if ($canModerate): ?>
                            <li>
                                <a href="/admin/posts?status=pending" style="<?php echo $pendingCount > 0 ? 'font-weight: 700; color: #e5a00d;' : ''; ?>">
                                    Pending Review <?php echo $pendingCount > 0 ? "({$pendingCount})" : ''; ?>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if ($canManageTaxonomies): ?>
                            <li><a href="/admin/taxonomies/categories" class="<?php echo $activeMenu === 'categories' ? 'active' : ''; ?>">Categories</a></li>
                            <li><a href="/admin/taxonomies/tags" class="<?php echo $activeMenu === 'tags' ? 'active' : ''; ?>">Tags</a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>
                <?php if ($canManagePages): ?>
                <?php
                $isPagesActive = in_array($activeMenu, ['pages', 'pages-new']);
                ?>
                <li class="wp-menu-item has-submenu <?php echo $isPagesActive ? 'active is-expanded' : ''; ?>">
                    <a href="/admin/pages" class="wp-menu-link" aria-haspopup="true" aria-expanded="<?php echo $isPagesActive ? 'true' : 'false'; ?>" aria-controls="submenu-pages">
                        <span>📄 Pages</span>
                        <span class="wp-menu-toggle-icon" aria-hidden="true">
                            <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                        </span>
                    </a>
                    <ul class="wp-submenu" id="submenu-pages">
                        <li><a href="/admin/pages" class="<?php echo $activeMenu === 'pages' ? 'active' : ''; ?>">All Pages</a></li>
                        <li><a href="/admin/pages/new" class="<?php echo $activeMenu === 'pages-new' ? 'active' : ''; ?>">Add New Page</a></li>
                    </ul>
                </li>
                <?php endif; ?>
                <?php if ($canManageMedia): ?>
                <li class="wp-menu-item <?php echo $activeMenu === 'media' ? 'active' : ''; ?>">
                    <a href="/admin/media" class="wp-menu-link">🖼️ Media</a>
                </li>
                <?php endif; ?>
                <?php if ($canModerateComments): ?>
                    <li class="wp-menu-item <?php echo $activeMenu === 'comments' ? 'active' : ''; ?>">
                        <a href="/admin/comments" class="wp-menu-link">
                            💬 Comments
                            <?php if ($pendingCommentsCount > 0): ?>
                                <span style="background: #e5a00d; color: #fff; font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 10px; margin-left: 6px;"><?php echo $pendingCommentsCount; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if ($isAdmin): ?>
                    <?php
                    $isThemesActive = in_array($activeMenu, ['themes', 'widgets', 'customize', 'menus']);
                    ?>
                    <li class="wp-menu-item has-submenu <?php echo $isThemesActive ? 'active is-expanded' : ''; ?>">
                        <a href="/admin/themes" class="wp-menu-link" aria-haspopup="true" aria-expanded="<?php echo $isThemesActive ? 'true' : 'false'; ?>" aria-controls="submenu-themes">
                            <span>🎨 Appearance</span>
                            <span class="wp-menu-toggle-icon" aria-hidden="true">
                                <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                            </span>
                        </a>
                        <ul class="wp-submenu" id="submenu-themes">
                            <li><a href="/admin/themes" class="<?php echo $activeMenu === 'themes' ? 'active' : ''; ?>">Themes</a></li>
                            <li><a href="/admin/customize" class="<?php echo $activeMenu === 'customize' ? 'active' : ''; ?>">Customize</a></li>
                            <li><a href="/admin/widgets" class="<?php echo $activeMenu === 'widgets' ? 'active' : ''; ?>">Widgets</a></li>
                            <li><a href="/admin/menus" class="<?php echo $activeMenu === 'menus' ? 'active' : ''; ?>">Menus</a></li>
                        </ul>
                    </li>
                    <li class="wp-menu-item <?php echo $activeMenu === 'plugins' ? 'active' : ''; ?>">
                        <a href="/admin/plugins" class="wp-menu-link">🔌 Plugins</a>
                    </li>
                <?php elseif ($canManageMenus): ?>
                    <li class="wp-menu-item <?php echo $activeMenu === 'menus' ? 'active' : ''; ?>">
                        <a href="/admin/menus" class="wp-menu-link">🎨 Menus</a>
                    </li>
                <?php endif; ?>

                <?php
                $isUsersActive = in_array($activeMenu, ['users', 'users-new', 'profile']);
                ?>
                <li class="wp-menu-item has-submenu <?php echo $isUsersActive ? 'active is-expanded' : ''; ?>">
                    <a href="<?php echo $canManageUsers ? '/admin/users' : '/admin/users/profile'; ?>" class="wp-menu-link" aria-haspopup="true" aria-expanded="<?php echo $isUsersActive ? 'true' : 'false'; ?>" aria-controls="submenu-users">
                        <span>👥 Users</span>
                        <span class="wp-menu-toggle-icon" aria-hidden="true">
                            <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                        </span>
                    </a>
                    <ul class="wp-submenu" id="submenu-users">
                        <?php if ($canManageUsers): ?>
                            <li><a href="/admin/users" class="<?php echo $activeMenu === 'users' ? 'active' : ''; ?>">All Users</a></li>
                            <li><a href="/admin/users/new" class="<?php echo $activeMenu === 'users-new' ? 'active' : ''; ?>">Add New</a></li>
                        <?php endif; ?>
                        <li><a href="/admin/users/profile" class="<?php echo $activeMenu === 'profile' ? 'active' : ''; ?>">Profile</a></li>
                    </ul>
                </li>

                <?php if ($isAdmin): ?>
                    <li class="wp-menu-item <?php echo $activeMenu === 'settings' ? 'active' : ''; ?>">
                        <a href="/admin/settings" class="wp-menu-link">⚙️ Settings</a>
                    </li>
                    <li class="wp-menu-item <?php echo $activeMenu === 'seo' ? 'active' : ''; ?>">
                        <a href="/admin/seo" class="wp-menu-link">🔍 SEO</a>
                    </li>
                    <?php
                    $isToolsActive = in_array($activeMenu, ['tools', 'tools-import']);
                    $isToolsActive = in_array($activeMenu, ['tools', 'tools-import', 'updates']);
                    ?>
                    <li class="wp-menu-item has-submenu <?php echo $isToolsActive ? 'active is-expanded' : ''; ?>">
                        <a href="/admin/tools" class="wp-menu-link" aria-haspopup="true" aria-expanded="<?php echo $isToolsActive ? 'true' : 'false'; ?>" aria-controls="submenu-tools">
                            <span>🛠️ Tools</span>
                            <span class="wp-menu-toggle-icon" aria-hidden="true">
                                <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                            </span>
                        </a>
                        <ul class="wp-submenu" id="submenu-tools">
                            <li><a href="/admin/tools" class="<?php echo $activeMenu === 'tools' ? 'active' : ''; ?>">Backups &amp; Health</a></li>
                            <li><a href="/admin/tools/import" class="<?php echo $activeMenu === 'tools-import' ? 'active' : ''; ?>">Import / Migration</a></li>
                            <li><a href="/admin/updates" class="<?php echo $activeMenu === 'updates' ? 'active' : ''; ?>">Core Updates</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php
                // Dynamic plugin admin menus
                $dynamicMenus = class_exists(\FavoriteCMS\Core\AdminMenu::class) ? \FavoriteCMS\Core\AdminMenu::getMenus() : [];
                foreach ($dynamicMenus as $dMenu):
                    if (function_exists('current_user_can') && !current_user_can($dMenu['capability'])) continue;
                    $isDActive = ($activeMenu === $dMenu['slug']);
                    $hasSub = !empty($dMenu['submenus']);
                    $subSlugs = $hasSub ? array_column($dMenu['submenus'], 'slug') : [];
                    $isAnySubActive = $isDActive || in_array($activeMenu, $subSlugs);
                    $menuUrl = '/admin/page/' . htmlspecialchars($dMenu['slug'], ENT_QUOTES, 'UTF-8');
                    $subId = 'submenu-dmenu-' . preg_replace('/[^a-zA-Z0-9_\-]/', '-', $dMenu['slug']);
                ?>
                    <li class="wp-menu-item <?php echo $hasSub ? 'has-submenu ' : ''; ?><?php echo $isAnySubActive ? 'active ' : ''; ?><?php echo ($hasSub && $isAnySubActive) ? 'is-expanded' : ''; ?>">
                        <a href="<?php echo $menuUrl; ?>" class="wp-menu-link"<?php if ($hasSub): ?> aria-haspopup="true" aria-expanded="<?php echo $isAnySubActive ? 'true' : 'false'; ?>" aria-controls="<?php echo $subId; ?>"<?php endif; ?>>
                            <span><?php echo htmlspecialchars($dMenu['icon'] ?? '🔌', ENT_QUOTES, 'UTF-8'); ?></span>
                            <span><?php echo htmlspecialchars($dMenu['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php if ($hasSub): ?>
                                <span class="wp-menu-toggle-icon" aria-hidden="true">
                                    <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                                </span>
                            <?php endif; ?>
                        </a>
                        <?php if ($hasSub): ?>
                            <ul class="wp-submenu" id="<?php echo $subId; ?>">
                                <?php
                                $hasParentSub = isset($dMenu['submenus'][$dMenu['slug']]) || in_array($dMenu['slug'], $subSlugs, true);
                                if (!$hasParentSub):
                                ?>
                                    <li><a href="<?php echo $menuUrl; ?>" class="<?php echo $activeMenu === $dMenu['slug'] ? 'active' : ''; ?>"><?php echo htmlspecialchars($dMenu['title'], ENT_QUOTES, 'UTF-8'); ?></a></li>
                                <?php endif; ?>
                                <?php foreach ($dMenu['submenus'] as $sub): ?>
                                    <li><a href="/admin/page/<?php echo htmlspecialchars($sub['slug'], ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $activeMenu === $sub['slug'] ? 'active' : ''; ?>"><?php echo htmlspecialchars($sub['title'], ENT_QUOTES, 'UTF-8'); ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <main class="wp-content">
            <?php if ($flashSuccess): ?>
                <div class="notice notice-success"><?php echo htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if ($flashError): ?>
                <div class="notice notice-error"><?php echo htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php
            // Evaluate child view content
            if (isset($contentView) && is_string($contentView) && file_exists($contentView)) {
                extract($viewData ?? [], EXTR_SKIP);
                include $contentView;
            } elseif (isset($customHtml)) {
                echo $customHtml;
            } elseif (isset($htmlBody)) {
                echo $htmlBody;
            }
            ?>
        </main>
    </div>

    <script>
    window.initAdminMultiSelect = function(formId, options) {
        options = options || {};
        var form = document.getElementById(formId);
        if (!form) return;

        var masterCheckbox = form.querySelector('[data-select-all]') || form.querySelector('th input[type="checkbox"]');
        var rowCheckboxes = function() {
            return form.querySelectorAll('tbody input[type="checkbox"][name="ids[]"]');
        };
        var countBadge = form.querySelector('.bulk-count-badge');
        var actionSelect = form.querySelector('select[name="bulk_action"]');

        function updateState() {
            var cbs = rowCheckboxes();
            var total = 0;
            var checkedCount = 0;

            for (var i = 0; i < cbs.length; i++) {
                if (!cbs[i].disabled) {
                    total++;
                    var tr = cbs[i].closest('tr');
                    if (cbs[i].checked) {
                        checkedCount++;
                        if (tr) tr.classList.add('is-selected');
                    } else {
                        if (tr) tr.classList.remove('is-selected');
                    }
                }
            }

            if (masterCheckbox) {
                if (total > 0 && checkedCount === total) {
                    masterCheckbox.checked = true;
                    masterCheckbox.indeterminate = false;
                } else if (checkedCount > 0) {
                    masterCheckbox.checked = false;
                    masterCheckbox.indeterminate = true;
                } else {
                    masterCheckbox.checked = false;
                    masterCheckbox.indeterminate = false;
                }
            }

            if (countBadge) {
                countBadge.textContent = checkedCount + ' selected';
                if (checkedCount > 0) {
                    countBadge.classList.add('has-selected');
                } else {
                    countBadge.classList.remove('has-selected');
                }
            }
        }

        if (masterCheckbox) {
            masterCheckbox.addEventListener('change', function() {
                var isChecked = masterCheckbox.checked;
                var cbs = rowCheckboxes();
                for (var i = 0; i < cbs.length; i++) {
                    if (!cbs[i].disabled) {
                        cbs[i].checked = isChecked;
                    }
                }
                updateState();
            });
        }

        form.addEventListener('change', function(e) {
            if (e.target && e.target.matches('tbody input[type="checkbox"][name="ids[]"]')) {
                updateState();
            }
        });

        form.addEventListener('submit', function(e) {
            var action = actionSelect ? actionSelect.value.trim() : '';
            if (!action) {
                alert('Please select a bulk action.');
                e.preventDefault();
                return false;
            }

            var checkedCbs = form.querySelectorAll('tbody input[type="checkbox"][name="ids[]"]:checked');
            if (checkedCbs.length === 0) {
                alert('Please select at least one item.');
                e.preventDefault();
                return false;
            }

            var count = checkedCbs.length;
            var itemType = options.itemType || 'item';

            var confirmMsg = null;
            if (options.confirmMessages && options.confirmMessages[action]) {
                confirmMsg = typeof options.confirmMessages[action] === 'function'
                    ? options.confirmMessages[action](count, itemType)
                    : options.confirmMessages[action].replace('{count}', count);
            } else if (action === 'delete') {
                confirmMsg = itemType === 'plugin'
                    ? 'Are you sure you want to delete and uninstall ' + count + ' ' + (count > 1 ? 'plugins' : 'plugin') + '? This will remove plugin files and data.'
                    : 'Are you sure you want to permanently delete ' + count + ' ' + itemType + (count > 1 ? 's' : '') + '? This action cannot be undone.';
            } else if (action === 'deactivate') {
                confirmMsg = 'Are you sure you want to deactivate ' + count + ' ' + (itemType === 'plugin' ? (count > 1 ? 'plugins' : 'plugin') : (count > 1 ? itemType + 's' : itemType)) + '?';
            } else if (action === 'trash') {
                confirmMsg = 'Are you sure you want to move ' + count + ' ' + itemType + (count > 1 ? 's' : '') + ' to trash?';
            } else if (action === 'ban') {
                confirmMsg = 'Are you sure you want to ban ' + count + ' ' + itemType + (count > 1 ? 's' : '') + '? They will immediately lose access.';
            } else if (action === 'suspend') {
                confirmMsg = 'Are you sure you want to suspend ' + count + ' ' + itemType + (count > 1 ? 's' : '') + '?';
            } else if (action === 'spam') {
                confirmMsg = 'Are you sure you want to mark ' + count + ' ' + itemType + (count > 1 ? 's' : '') + ' as spam?';
            } else if (action === 'archive') {
                confirmMsg = 'Are you sure you want to archive ' + count + ' ' + itemType + (count > 1 ? 's' : '') + '?';
            } else if (action === 'cancel') {
                confirmMsg = 'Are you sure you want to cancel ' + count + ' ' + itemType + (count > 1 ? 's' : '') + '?';
            } else if (action === 'expire') {
                confirmMsg = 'Are you sure you want to expire ' + count + ' ' + itemType + (count > 1 ? 's' : '') + '?';
            }

            if (confirmMsg && !confirm(confirmMsg)) {
                e.preventDefault();
                return false;
            }

            return true;
        });

        updateState();
    };

    // Admin navigation: click-to-expand / click-to-collapse & mobile menu toggle
    document.addEventListener('DOMContentLoaded', function() {
        var sidebar = document.querySelector('.wp-sidebar');
        if (sidebar) {
            sidebar.addEventListener('click', function(e) {
                var link = e.target.closest('.wp-menu-item.has-submenu > .wp-menu-link');
                if (!link) return;

                // Allow modifier clicks (Ctrl/Cmd/Shift or middle-click) to open in new tab
                if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) {
                    return;
                }

                e.preventDefault();

                var item = link.parentElement;
                var isExpanded = item.classList.contains('is-expanded');

                if (isExpanded) {
                    item.classList.remove('is-expanded');
                    link.setAttribute('aria-expanded', 'false');
                } else {
                    item.classList.add('is-expanded');
                    link.setAttribute('aria-expanded', 'true');
                }
            });

            // Keyboard accessibility: Space key toggles disclosure
            sidebar.addEventListener('keydown', function(e) {
                if (e.key === ' ' || e.key === 'Spacebar') {
                    var link = e.target.closest('.wp-menu-item.has-submenu > .wp-menu-link');
                    if (link) {
                        e.preventDefault();
                        link.click();
                    }
                }
            });
        }

        var menuBtn = document.getElementById('mobile-menu-toggle');
        var backdrop = document.getElementById('sidebar-backdrop');

        if (menuBtn && sidebar && backdrop) {
            menuBtn.addEventListener('click', function() {
                var isOpen = sidebar.classList.toggle('is-open');
                backdrop.classList.toggle('is-active', isOpen);
                menuBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });

            backdrop.addEventListener('click', function() {
                sidebar.classList.remove('is-open');
                backdrop.classList.remove('is-active');
                menuBtn.setAttribute('aria-expanded', 'false');
            });
        }

        // Admin Dark/Light Mode toggle handler
        var themeToggleBtn = document.getElementById('admin-theme-toggle');
        if (themeToggleBtn) {
            themeToggleBtn.addEventListener('click', function() {
                var currentTheme = document.documentElement.getAttribute('data-admin-theme') || 'light';
                var nextTheme = currentTheme === 'dark' ? 'light' : 'dark';
                var isDark = nextTheme === 'dark';

                // 1. Update DOM immediately
                document.documentElement.setAttribute('data-admin-theme', nextTheme);

                // 2. Update toggle button attributes and icons
                themeToggleBtn.setAttribute('aria-pressed', isDark ? 'true' : 'false');
                var labelText = isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode';
                themeToggleBtn.setAttribute('aria-label', labelText);
                themeToggleBtn.setAttribute('title', labelText);
                var sunIcon = themeToggleBtn.querySelector('.theme-icon-sun');
                var moonIcon = themeToggleBtn.querySelector('.theme-icon-moon');
                if (sunIcon) sunIcon.style.display = isDark ? 'inline-flex' : 'none';
                if (moonIcon) moonIcon.style.display = isDark ? 'none' : 'inline-flex';

                // 3. Save to localStorage for early flash-free client bootstrap
                try {
                    localStorage.setItem('favorite_admin_theme', nextTheme);
                } catch (e) {}

                // 4. Send background POST to persist in authoritative Core Setting for authenticated user
                var csrfToken = <?php echo json_encode($_SESSION['_token'] ?? ''); ?>;
                var formData = new FormData();
                formData.append('_token', csrfToken);
                formData.append('theme', nextTheme);

                fetch('/admin/appearance/toggle', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }).catch(function(err) {
                    console.warn('Could not persist admin appearance to server:', err);
                });
            });
        }
    });
    </script>
</body>
</html>

