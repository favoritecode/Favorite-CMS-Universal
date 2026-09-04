<?php
/**
 * Master Admin Layout
 * Parameters:
 * $pageTitle (string)
 * $activeMenu (string)
 * $content (string or closure/callable)
 */
$siteName = \FavoriteCMS\Models\Setting::get('general', 'site_name', 'Favorite CMS');
$user = !empty($_SESSION['auth_user_id']) ? \FavoriteCMS\Models\User::find((int)$_SESSION['auth_user_id']) : null;
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
            --wp-dark: #1d2327;
            --wp-blue: #2271b1;
            --wp-blue-hover: #135e96;
            --wp-light: #f0f0f1;
            --wp-border: #c3c4c7;
            --wp-text: #2c3338;
            --wp-text-muted: #646970;
            --wp-danger: #d63638;
            --wp-success: #00a32a;
            --sidebar-width: 200px;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            background: var(--wp-light);
            color: var(--wp-text);
            font-size: 13px;
            line-height: 1.4;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        /* Topbar */
        .wp-topbar {
            background: var(--wp-dark);
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            color: #fff;
            font-size: 12px;
            position: sticky;
            top: 0;
            z-index: 999;
        }
        .wp-topbar a { color: #f0f0f1; text-decoration: none; }
        .wp-topbar a:hover { color: #72aee6; }
        .topbar-left { display: flex; align-items: center; gap: 16px; font-weight: 500; }
        .topbar-left .star { color: #e5a00d; font-size: 14px; }
        .topbar-right { display: flex; align-items: center; gap: 14px; }

        /* Main Container */
        .wp-body {
            display: flex;
            flex: 1;
        }
        /* Sidebar */
        .wp-sidebar {
            width: var(--sidebar-width);
            background: var(--wp-dark);
            color: #c3c4c7;
            flex-shrink: 0;
            padding: 10px 0;
        }
        .wp-menu { list-style: none; }
        .wp-menu-item { position: relative; }
        .wp-menu-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 16px;
            color: #c3c4c7;
            text-decoration: none;
            font-size: 13px;
            font-weight: 400;
            transition: all 0.1s;
        }
        .wp-menu-link:hover, .wp-menu-item.active > .wp-menu-link {
            background: #13181b;
            color: #72aee6;
        }
        .wp-menu-item.active > .wp-menu-link {
            color: #fff;
            background: var(--wp-blue);
        }
        .wp-submenu {
            list-style: none;
            background: #2c3338;
            padding: 4px 0;
            display: none;
        }
        .wp-menu-item.active .wp-submenu, .wp-menu-item:hover .wp-submenu {
            display: block;
        }
        .wp-submenu a {
            display: block;
            padding: 5px 16px 5px 36px;
            color: #c3c4c7;
            text-decoration: none;
            font-size: 12px;
        }
        .wp-submenu a:hover, .wp-submenu a.active {
            color: #fff;
        }

        /* Content Area */
        .wp-content {
            flex: 1;
            padding: 20px 24px;
            min-width: 0;
        }
        .page-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        .page-title {
            font-size: 22px;
            font-weight: 400;
            color: #1d2327;
        }
        .btn {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 3px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            border: 1px solid transparent;
            line-height: 2;
        }
        .btn-primary {
            background: var(--wp-blue);
            color: #fff;
            border-color: var(--wp-blue);
        }
        .btn-primary:hover { background: var(--wp-blue-hover); border-color: var(--wp-blue-hover); color: #fff; }
        .btn-secondary {
            background: #f6f7f7;
            color: var(--wp-blue);
            border-color: var(--wp-blue);
        }
        .btn-secondary:hover { background: #f0f0f1; border-color: var(--wp-blue-hover); }
        .btn-danger {
            background: #fcf0f1;
            color: var(--wp-danger);
            border-color: #f1aeb5;
        }
        .btn-danger:hover { background: var(--wp-danger); color: #fff; }

        /* Notices */
        .notice {
            background: #fff;
            border-left: 4px solid #72aee6;
            box-shadow: 0 1px 1px 0 rgba(0,0,0,0.05);
            padding: 10px 14px;
            margin-bottom: 16px;
            font-size: 13px;
        }
        .notice-success { border-left-color: var(--wp-success); background: #edfaef; color: #1a6826; }
        .notice-error { border-left-color: var(--wp-danger); background: #fcf0f1; color: #8a1f11; }

        /* Data Tables */
        .wp-table-wrap {
            background: #fff;
            border: 1px solid var(--wp-border);
            box-shadow: 0 1px 1px rgba(0,0,0,0.04);
            border-radius: 2px;
            overflow-x: auto;
        }
        table.wp-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }
        table.wp-table th, table.wp-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #f0f0f1;
        }
        table.wp-table th {
            font-weight: 600;
            color: #1d2327;
            background: #fafafa;
        }
        table.wp-table tr:hover td {
            background: #f9f9f9;
        }
        table.wp-table tr.is-selected td {
            background-color: #f0f6fc !important;
        }
        .bulk-actions-wrap {
            display: flex;
            gap: 8px;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        .bulk-count-badge {
            display: inline-block;
            background: #e2e8f0;
            color: #475569;
            font-size: 11px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 999px;
            transition: all 0.15s ease;
        }
        .bulk-count-badge.has-selected {
            background: var(--wp-blue);
            color: #ffffff;
        }
        .row-actions {
            font-size: 12px;
            color: var(--wp-text-muted);
            margin-top: 4px;
        }
        .row-actions a { text-decoration: none; }
        .row-actions a:hover { text-decoration: underline; }

        /* Subsubsub Filters (All | Published | Draft | Trash) */
        ul.subsubsub {
            list-style: none;
            display: flex;
            gap: 8px;
            font-size: 13px;
            color: var(--wp-text-muted);
            margin-bottom: 12px;
        }
        ul.subsubsub a { color: var(--wp-blue); text-decoration: none; }
        ul.subsubsub a.current { font-weight: 600; color: #000; }

        /* Forms */
        .form-card {
            background: #fff;
            border: 1px solid var(--wp-border);
            border-radius: 4px;
            padding: 24px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.04);
        }
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            color: #1d2327;
            margin-bottom: 5px;
            font-size: 13px;
        }
        .form-control {
            width: 100%;
            padding: 7px 10px;
            border: 1px solid #8c8f94;
            border-radius: 4px;
            font-size: 13.5px;
            background: #fff;
            font-family: inherit;
        }
        .form-control:focus {
            border-color: var(--wp-blue);
            outline: 2px solid transparent;
            box-shadow: 0 0 0 1px var(--wp-blue);
        }
        textarea.form-control { resize: vertical; min-height: 120px; }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .description {
            display: block;
            margin-top: 4px;
            color: var(--wp-text-muted);
            font-size: 12px;
        }
    </style>
</head>
<body>

    <div class="wp-topbar">
        <div class="topbar-left">
            <a href="/" target="_blank" title="Visit Site"><span class="star">&#9733;</span> <strong><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></strong> &rarr;</a>
        </div>
        <div class="topbar-right">
            <span>Howdy, <strong><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></strong></span>
            <a href="/admin/users/profile">Edit Profile</a>
            <a href="/admin/logout" style="color: #ff8080;">Log Out</a>
        </div>
    </div>

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
                $pendingCount = (int)(\FavoriteCMS\Models\Post::countByStatus()['pending'] ?? 0);
                $pendingCommentsCount = $canModerateComments ? (int)(\FavoriteCMS\Models\Comment::countByStatus()['pending'] ?? 0) : 0;
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
                    <li class="wp-menu-item <?php echo $activeMenu === 'tools' ? 'active' : ''; ?>">
                        <a href="/admin/tools" class="wp-menu-link">🛠️ Tools</a>
                    </li>
                <?php endif; ?>

                <?php
                // Dynamic plugin admin menus
                $dynamicMenus = \FavoriteCMS\Core\AdminMenu::getMenus();
                foreach ($dynamicMenus as $dMenu):
                    if (!current_user_can($dMenu['capability'])) continue;
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

    // Backward compatibility helper
    window.toggleSelectAll = function(master, groupName) {
        var cbs = document.getElementsByName(groupName);
        for (var i = 0; i < cbs.length; i++) {
            if (!cbs[i].disabled) {
                cbs[i].checked = master.checked;
                var tr = cbs[i].closest('tr');
                if (tr) {
                    if (master.checked) tr.classList.add('is-selected');
                    else tr.classList.remove('is-selected');
                }
            }
        }
    };
    </script>
</body>
</html>

