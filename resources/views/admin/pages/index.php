<div class="page-header">
    <h1 class="page-title">Pages</h1>
    <a href="/admin/pages/new" class="btn btn-primary">Add New Page</a>
</div>

<ul class="subsubsub">
    <li><a href="/admin/pages?status=all" class="<?php echo $status === 'all' ? 'current' : ''; ?>">All (<?php echo $counts['all'] ?? 0; ?>)</a> |</li>
    <li><a href="/admin/pages?status=published" class="<?php echo $status === 'published' ? 'current' : ''; ?>">Published (<?php echo $counts['published'] ?? 0; ?>)</a> |</li>
    <li><a href="/admin/pages?status=draft" class="<?php echo $status === 'draft' ? 'current' : ''; ?>">Drafts (<?php echo $counts['draft'] ?? 0; ?>)</a> |</li>
    <li><a href="/admin/pages?status=trash" class="<?php echo $status === 'trash' ? 'current' : ''; ?>">Trash (<?php echo $counts['trash'] ?? 0; ?>)</a></li>
</ul>

<form method="GET" action="/admin/pages" style="margin-bottom: 16px; display: flex; gap: 8px;">
    <input type="hidden" name="status" value="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="text" name="s" class="form-control" style="max-width: 280px;" placeholder="Search pages..." value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
    <button type="submit" class="btn btn-secondary">Search</button>
</form>

<form method="POST" action="/admin/pages/bulk" id="pages-bulk-form">
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
                                    <a href="/admin/pages/edit?id=<?php echo (int)$page->id; ?>" style="color: #1d2327; font-size: 14px;">
                                        <?php echo htmlspecialchars($page->title, ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </strong>
                                <div class="row-actions">
                                    <?php if ($page->status === 'trash'): ?>
                                        <a href="/admin/pages/restore?id=<?php echo (int)$page->id; ?>" style="color: var(--wp-blue);">Restore</a> |
                                        <a href="/admin/pages/delete?id=<?php echo (int)$page->id; ?>" onclick="return confirm('Permanently delete this page?');" style="color: var(--wp-danger);">Delete Permanently</a>
                                    <?php else: ?>
                                        <a href="/admin/pages/edit?id=<?php echo (int)$page->id; ?>">Edit</a> |
                                        <a href="/page/<?php echo htmlspecialchars($page->slug, ENT_QUOTES, 'UTF-8'); ?>" target="_blank">View</a> |
                                        <a href="/admin/pages/trash?id=<?php echo (int)$page->id; ?>" style="color: var(--wp-danger);">Trash</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($page->getAuthor()?->name ?? 'Admin', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <span style="display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 11px; text-transform: uppercase; font-weight: 600; background: <?php echo $page->status === 'published' ? '#dcfce7; color: #15803d;' : '#f1f5f9; color: #475569;'; ?>">
                                    <?php echo htmlspecialchars($page->status, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td><?php echo (int)($page->menu_order ?? 0); ?></td>
                            <td><?php echo date('Y/m/d \a\t g:i a', strtotime($page->created_at)); ?></td>
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
});
</script>

