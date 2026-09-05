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
    $user = !empty($_SESSION['auth_user_id']) && class_exists(\FavoriteCMS\Models\User::class) ? \FavoriteCMS\Models\User::find((int)$_SESSION['auth_user_id']) : null;
} catch (\Throwable $e) {
    $user = null;
}
$username = $user ? ($user->name ?? $user->username) : 'Admin';

$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$activeMenu = $activeMenu ?? 'dashboard';
$siteFaviconUrl = function_exists('get_site_favicon_url') ? get_site_favicon_url('') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle ?? 'Admin', ENT_QUOTES, 'UTF-8'); ?> &lsaquo; <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?> &mdash; Favorite CMS</title>
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
            --wp-dark: #0f172a;
            --wp-sidebar-bg: #1e293b;
            --wp-blue: #2563eb;
            --wp-blue-hover: #1d4ed8;
            --wp-blue-light: #eff6ff;
            --wp-light: #f8fafc;
            --wp-border: #e2e8f0;
            --wp-border-focus: #3b82f6;
            --wp-text: #0f172a;
            --wp-text-muted: #64748b;
            --wp-danger: #dc2626;
            --wp-success: #16a34a;
            --wp-warning: #d97706;
            --wp-info: #0284c7;
            --sidebar-width: 220px;
            --radius-sm: 4px;
            --radius-md: 6px;
            --radius-lg: 8px;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.07), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
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
        .wp-menu-link:hover {
            background: rgba(255,255,255,0.06);
            color: #fff;
        }
        .wp-menu-item.active > .wp-menu-link {
            color: #fff;
            background: var(--wp-blue);
        }
        .wp-submenu {
            list-style: none;
            background: #0f172a;
            padding: 4px 0;
            display: none;
        }
        .wp-menu-item.active .wp-submenu, .wp-menu-item:hover .wp-submenu {
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
    </style>
</head>
<body>

    <div class="wp-topbar">
        <div class="topbar-left">
            <button type="button" class="mobile-menu-toggle" id="mobile-menu-toggle" aria-expanded="false" aria-label="Toggle navigation menu">&#9776;</button>
            <a href="/" target="_blank" title="Visit Site"><span class="star">&#9733;</span> <strong><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></strong> &rarr;</a>
        </div>
        <div class="topbar-right">
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
                $isAdmin = $user && ($user->hasRole('admin') || $user->hasRole('super-admin'));
                $canModerate = $user && $user->canModeratePosts();
                $canModerateComments = $user && $user->canModerateComments();
                $canManageUsers = $user && $user->canManageUsers();
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
                <li class="wp-menu-item <?php echo in_array($activeMenu, ['posts', 'posts-new', 'categories', 'tags']) ? 'active' : ''; ?>">
                    <a href="/admin/posts" class="wp-menu-link">
                        📝 Posts
                        <?php if ($canModerate && $pendingCount > 0): ?>
                            <span style="background: #e5a00d; color: #fff; font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 10px; margin-left: 6px;"><?php echo $pendingCount; ?></span>
                        <?php endif; ?>
                    </a>
                    <ul class="wp-submenu">
                        <li><a href="/admin/posts" class="<?php echo $activeMenu === 'posts' ? 'active' : ''; ?>">All Posts</a></li>
                        <li><a href="/admin/posts/new" class="<?php echo $activeMenu === 'posts-new' ? 'active' : ''; ?>">Add New Post</a></li>
                        <?php if ($canModerate): ?>
                            <li>
                                <a href="/admin/posts?status=pending" style="<?php echo $pendingCount > 0 ? 'font-weight: 700; color: #e5a00d;' : ''; ?>">
                                    Pending Review <?php echo $pendingCount > 0 ? "({$pendingCount})" : ''; ?>
                                </a>
                            </li>
                        <?php endif; ?>
                        <li><a href="/admin/taxonomies/categories" class="<?php echo $activeMenu === 'categories' ? 'active' : ''; ?>">Categories</a></li>
                        <li><a href="/admin/taxonomies/tags" class="<?php echo $activeMenu === 'tags' ? 'active' : ''; ?>">Tags</a></li>
                    </ul>
                </li>
                <li class="wp-menu-item <?php echo in_array($activeMenu, ['pages', 'pages-new']) ? 'active' : ''; ?>">
                    <a href="/admin/pages" class="wp-menu-link">📄 Pages</a>
                    <ul class="wp-submenu">
                        <li><a href="/admin/pages" class="<?php echo $activeMenu === 'pages' ? 'active' : ''; ?>">All Pages</a></li>
                        <li><a href="/admin/pages/new" class="<?php echo $activeMenu === 'pages-new' ? 'active' : ''; ?>">Add New Page</a></li>
                    </ul>
                </li>
                <li class="wp-menu-item <?php echo $activeMenu === 'media' ? 'active' : ''; ?>">
                    <a href="/admin/media" class="wp-menu-link">🖼️ Media</a>
                </li>
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
                    <li class="wp-menu-item <?php echo in_array($activeMenu, ['themes', 'widgets', 'customize', 'menus']) ? 'active' : ''; ?>">
                        <a href="/admin/themes" class="wp-menu-link">🎨 Appearance</a>
                        <ul class="wp-submenu">
                            <li><a href="/admin/themes" class="<?php echo $activeMenu === 'themes' ? 'active' : ''; ?>">Themes</a></li>
                            <li><a href="/admin/customize" class="<?php echo $activeMenu === 'customize' ? 'active' : ''; ?>">Customize</a></li>
                            <li><a href="/admin/widgets" class="<?php echo $activeMenu === 'widgets' ? 'active' : ''; ?>">Widgets</a></li>
                            <li><a href="/admin/menus" class="<?php echo $activeMenu === 'menus' ? 'active' : ''; ?>">Menus</a></li>
                        </ul>
                    </li>
                    <li class="wp-menu-item <?php echo $activeMenu === 'plugins' ? 'active' : ''; ?>">
                        <a href="/admin/plugins" class="wp-menu-link">🔌 Plugins</a>
                    </li>
                <?php endif; ?>

                <li class="wp-menu-item <?php echo in_array($activeMenu, ['users', 'users-new', 'profile']) ? 'active' : ''; ?>">
                    <a href="<?php echo $canManageUsers ? '/admin/users' : '/admin/users/profile'; ?>" class="wp-menu-link">👥 Users</a>
                    <ul class="wp-submenu">
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
                    <li class="wp-menu-item <?php echo in_array($activeMenu, ['tools', 'tools-import']) ? 'active' : ''; ?>">
                        <a href="/admin/tools" class="wp-menu-link">🛠️ Tools</a>
                        <ul class="wp-submenu">
                            <li><a href="/admin/tools" class="<?php echo $activeMenu === 'tools' ? 'active' : ''; ?>">Backups &amp; Health</a></li>
                            <li><a href="/admin/tools/import" class="<?php echo $activeMenu === 'tools-import' ? 'active' : ''; ?>">Import / Migration</a></li>
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
                    $menuUrl = '/admin/page/' . htmlspecialchars($dMenu['slug'], ENT_QUOTES, 'UTF-8');
                ?>
                    <li class="wp-menu-item <?php echo $isDActive ? 'active' : ''; ?>">
                        <a href="<?php echo $menuUrl; ?>" class="wp-menu-link">
                            <span><?php echo htmlspecialchars($dMenu['icon'] ?? '🔌', ENT_QUOTES, 'UTF-8'); ?></span>
                            <span><?php echo htmlspecialchars($dMenu['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </a>
                        <?php if ($hasSub): ?>
                            <ul class="wp-submenu">
                                <li><a href="<?php echo $menuUrl; ?>" class="<?php echo $activeMenu === $dMenu['slug'] ? 'active' : ''; ?>"><?php echo htmlspecialchars($dMenu['title'], ENT_QUOTES, 'UTF-8'); ?></a></li>
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
            if (action === 'delete') {
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
            }

            if (confirmMsg && !confirm(confirmMsg)) {
                e.preventDefault();
                return false;
            }

            return true;
        });

        updateState();
    };

    // Mobile menu toggle
    document.addEventListener('DOMContentLoaded', function() {
        var menuBtn = document.getElementById('mobile-menu-toggle');
        var sidebar = document.querySelector('.wp-sidebar');
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
    });
    </script>
</body>
</html>

