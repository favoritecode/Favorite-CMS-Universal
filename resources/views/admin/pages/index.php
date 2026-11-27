
<style>
/* Small, scoped, theme-variable-aware site page action menu. */
.fpb-edit-picker{display:inline-block;position:relative;vertical-align:baseline}
.fpb-edit-picker summary{display:inline-flex;align-items:center;gap:3px;cursor:pointer;color:var(--wp-blue,var(--admin-primary,#2271b1));list-style:none;border-radius:4px;padding:2px 3px}
.fpb-edit-picker summary::-webkit-details-marker{display:none}
.fpb-edit-picker summary:focus-visible{outline:2px solid var(--wp-blue,var(--admin-primary,#2271b1));outline-offset:2px}
.fpb-edit-picker-menu{display:none;min-width:205px;max-width:min(270px,calc(100vw - 16px));padding:5px;z-index:9999;border:1px solid var(--admin-border,var(--wp-border,#64748b));background:var(--admin-surface,var(--wp-card-bg,var(--wp-bg,#fff)));color:var(--admin-text,var(--wp-text,#111));border-radius:7px;box-shadow:0 8px 22px rgba(0,0,0,.2)}
.fpb-edit-picker[open] .fpb-edit-picker-menu{display:block;position:fixed;left:var(--fpb-menu-x,0px);top:var(--fpb-menu-y,0px)}
.fpb-edit-picker-menu a{display:block;padding:9px 10px;text-decoration:none;border-radius:4px;white-space:normal;color:inherit!important;font-size:12px;line-height:1.35}
.fpb-edit-picker-menu a:hover,.fpb-edit-picker-menu a:focus-visible{background:var(--admin-surface-subtle,var(--wp-hover-bg,rgba(127,127,127,.18)));text-decoration:none}
@media (max-width:600px){.fpb-edit-picker-menu{min-width:190px}}
</style>
<div class="page-header">
    <h1 class="page-title">Pages</h1>
    <a href="<?php echo htmlspecialchars(site_path('/admin/pages/new'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary">Add New Page</a>
</div>

<ul class="subsubsub">
    <li><a href="<?php echo htmlspecialchars(site_path('/admin/pages?status=all'), ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $status === 'all' ? 'current' : ''; ?>">All (<?php echo $counts['all'] ?? 0; ?>)</a> |</li>
    <li><a href="<?php echo htmlspecialchars(site_path('/admin/pages?status=published'), ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $status === 'published' ? 'current' : ''; ?>">Published (<?php echo $counts['published'] ?? 0; ?>)</a> |</li>
    <li><a href="<?php echo htmlspecialchars(site_path('/admin/pages?status=scheduled'),ENT_QUOTES,'UTF-8'); ?>" class="<?php echo $status==='scheduled'?'current':''; ?>">Scheduled (<?php echo (int)($counts['scheduled']??0); ?>)</a> |</li>
    <li><a href="<?php echo htmlspecialchars(site_path('/admin/pages?status=draft'), ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $status === 'draft' ? 'current' : ''; ?>">Drafts (<?php echo $counts['draft'] ?? 0; ?>)</a> |</li>
    <li><a href="<?php echo htmlspecialchars(site_path('/admin/pages?status=trash'), ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $status === 'trash' ? 'current' : ''; ?>">Trash (<?php echo $counts['trash'] ?? 0; ?>)</a></li>
</ul>

<form method="GET" action="<?php echo htmlspecialchars(site_path('/admin/pages'), ENT_QUOTES, 'UTF-8'); ?>" style="margin-bottom: 16px; display: flex; gap: 8px;">
    <input type="hidden" name="status" value="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="text" name="s" class="form-control" style="max-width: 280px;" placeholder="Search pages..." value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
    <button type="submit" class="btn btn-secondary">Search</button>
</form>

<form method="POST" action="<?php echo htmlspecialchars(site_path('/admin/pages/bulk'), ENT_QUOTES, 'UTF-8'); ?>" id="pages-bulk-form">
    <input type="hidden" name="_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="status" value="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">

    <div class="bulk-actions-wrap">
        <select name="bulk_action" class="form-control" style="width: auto; max-width: 200px; display: inline-block;">
            <option value="">Bulk Actions</option>
            <?php if ($status === 'trash'): ?>
                <option value="restore">Restore</option>
                <option value="delete">Delete Permanently</option>
            <?php else: ?>
                <option value="trash">Move to Trash</option>
                <option value="delete">Delete Permanently</option>
            <?php endif; ?>
        </select>
        <button type="submit" class="btn btn-secondary">Apply</button>
        <span class="bulk-count-badge">0 selected</span>
    </div>

    <div class="wp-table-wrap">
        <table class="wp-table">
            <thead>
                <tr>
                    <th style="width: 32px; text-align: center;">
                        <input type="checkbox" id="select-all-pages" data-select-all>
                    </th>
                    <th>Title</th>
                    <th>Author</th>
                    <th>Status</th>
                    <th>Order</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pages)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--wp-text-muted); padding: 24px;">No pages found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pages as $page): ?>
                        <tr>
                            <td style="text-align: center;">
                                <input type="checkbox" name="ids[]" value="<?php echo (int)$page->id; ?>" class="bulk-cb">
                            </td>
                            <td>
                                <strong>
                                    <a href="<?php echo htmlspecialchars(site_path('/admin/pages/edit?id='), ENT_QUOTES, 'UTF-8'); ?><?php echo (int)$page->id; ?>" class="row-title">
                                        <?php echo htmlspecialchars($page->title, ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </strong>
                                <div class="row-actions">
                                    <?php if ($page->status === 'trash'): ?>
                                        <button type="submit" form="core-action-form" formmethod="POST" formnovalidate formaction="<?php echo htmlspecialchars(site_base_path(), ENT_QUOTES, 'UTF-8'); ?>/admin/pages/restore?id=<?php echo (int)$page->id; ?>" class="core-action-link" style="color: var(--wp-blue);">Restore</button> |
                                        <button type="submit" form="core-action-form" formmethod="POST" formnovalidate formaction="<?php echo htmlspecialchars(site_base_path(), ENT_QUOTES, 'UTF-8'); ?>/admin/pages/delete?id=<?php echo (int)$page->id; ?>" class="core-action-link" onclick="return confirm('Permanently delete this page?');" style="color: var(--wp-danger);">Delete Permanently</button>
                                    <?php else: ?>
                                        <?php
                                        $basicEditUrl = site_path('/admin/pages/edit?id=' . (int)$page->id);
                                        // The Page Builder registers a URL only while the plugin is active.
                                        $builderEditUrl = \FavoriteCMS\Core\Hook::applyFilters('favorite_page_builder_page_edit_url', '', (int)$page->id);
                                        $builderEditUrl = is_string($builderEditUrl) && str_starts_with($builderEditUrl, '/') && !str_starts_with($builderEditUrl, '//') ? $builderEditUrl : '';
                                        ?>
                                        <?php if ($builderEditUrl !== ''): ?>
                                            <details class="fpb-edit-picker">
                                                <summary>Edit <span aria-hidden="true">▾</span></summary>
                                                <div class="fpb-edit-picker-menu">
                                                    <a href="<?php echo htmlspecialchars($basicEditUrl, ENT_QUOTES, 'UTF-8'); ?>">Simple Edit</a>
                                                    <a href="<?php echo htmlspecialchars($builderEditUrl, ENT_QUOTES, 'UTF-8'); ?>">Favorite Page Builder Edit</a>
                                                </div>
                                            </details> |
                                        <?php else: ?>
                                            <a href="<?php echo htmlspecialchars($basicEditUrl, ENT_QUOTES, 'UTF-8'); ?>">Edit</a> |
                                        <?php endif; ?>
                                        <a href="<?php echo htmlspecialchars(site_path('/page/'), ENT_QUOTES, 'UTF-8'); ?><?php echo htmlspecialchars($page->slug, ENT_QUOTES, 'UTF-8'); ?>" target="_blank">View</a> |
                                        <button type="submit" form="core-action-form" formmethod="POST" formnovalidate formaction="<?php echo htmlspecialchars(site_base_path(), ENT_QUOTES, 'UTF-8'); ?>/admin/pages/trash?id=<?php echo (int)$page->id; ?>" class="core-action-link" style="color: var(--wp-danger);">Trash</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($page->getAuthor()?->name ?? 'Admin', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <span style="display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 11px; text-transform: uppercase; font-weight: 600; background: <?php echo $page->status === 'published' ? 'var(--admin-success-bg); color: var(--admin-success-text);' : 'var(--admin-surface-subtle); color: var(--admin-text-muted);'; ?>">
                                    <?php echo htmlspecialchars($page->status, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td><?php echo (int)($page->menu_order ?? 0); ?></td>
                            <td style="white-space: nowrap;"><?php echo format_date($page->status==='scheduled'?$page->scheduled_at:$page->created_at, 'Y/m/d \a\t g:i a'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    initAdminMultiSelect('pages-bulk-form', { itemType: 'page' });
    const pickers = Array.from(document.querySelectorAll('.fpb-edit-picker'));
    pickers.forEach(picker => picker.addEventListener('toggle', () => {
        if (!picker.open) return;
        pickers.forEach(other => { if (other !== picker) other.open = false; });
        const anchor = picker.querySelector('summary');
        const menu = picker.querySelector('.fpb-edit-picker-menu');
        const rect = anchor.getBoundingClientRect();
        const menuWidth = Math.min(270, Math.max(205, menu.offsetWidth));
        picker.style.setProperty('--fpb-menu-x', Math.max(8, Math.min(rect.left, window.innerWidth - menuWidth - 8)) + 'px');
        const menuHeight = menu.offsetHeight;
        picker.style.setProperty('--fpb-menu-y', (rect.bottom + menuHeight + 8 < window.innerHeight ? rect.bottom + 4 : Math.max(8, rect.top - menuHeight - 4)) + 'px');
    }));
    document.addEventListener('click', event => {pickers.forEach(picker => {if (picker.open && !picker.contains(event.target)) picker.open=false;});});
    document.addEventListener('keydown', event => {if(event.key==='Escape') pickers.forEach(picker=>picker.open=false);});
});
</script>

<?php $paginationBase = '/admin/pages'; include APP_ROOT . '/resources/views/admin/partials/pagination.php'; ?>

