<div class="page-header">
    <h1 class="page-title">Plugins</h1>
</div>

<?php if (!empty($bootErrors)): ?>
    <div class="notice notice-error">
        <strong>Plugin Execution Warnings:</strong>
        <ul style="margin-top: 6px; padding-left: 20px;">
            <?php foreach ($bootErrors as $pId => $pErr): ?>
                <li>Plugin <code><?php echo htmlspecialchars($pId, ENT_QUOTES, 'UTF-8'); ?></code>: <?php echo htmlspecialchars($pErr, ENT_QUOTES, 'UTF-8'); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Upload Plugin Box -->
<div class="form-card" style="margin-bottom: 24px; padding: 20px;">
    <h2 style="font-size: 15px; font-weight: 600; margin-bottom: 12px;">Upload Plugin</h2>
    <form method="POST" action="/admin/plugins/upload" enctype="multipart/form-data" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
        <input type="hidden" name="_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="file" name="plugin_zip" accept=".zip" required class="form-control" style="max-width: 300px;">
        <button type="submit" class="btn btn-primary">Install Now</button>
        <span class="description">Upload a plugin in .zip format. Must contain a valid plugin.json manifest.</span>
    </form>
</div>

<!-- Plugins Table with Multi-Select / Bulk Actions -->
<form method="POST" action="/admin/plugins/bulk" id="plugins-bulk-form">
    <input type="hidden" name="_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

    <div class="bulk-actions-wrap">
        <select name="bulk_action" class="form-control" style="width: auto; display: inline-block;">
            <option value="">Bulk Actions</option>
            <option value="activate">Activate</option>
            <option value="deactivate">Deactivate</option>
            <option value="delete">Delete</option>
        </select>
        <button type="submit" class="btn btn-secondary">Apply</button>
        <span class="bulk-count-badge">0 selected</span>
    </div>

    <div class="wp-table-wrap">
        <table class="wp-table">
            <thead>
                <tr>
                    <th style="width: 32px; text-align: center;">
                        <input type="checkbox" id="select-all-plugins" data-select-all aria-label="Select All">
                    </th>
                    <th style="width: 250px;">Plugin</th>
                    <th>Description & Compatibility</th>
                    <th style="width: 140px;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($plugins)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; color: var(--wp-text-muted); padding: 24px;">No plugins installed.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($plugins as $id => $plugin): ?>
                        <tr>
                            <td style="text-align: center;">
                                <input type="checkbox" name="ids[]" value="<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>" class="row-checkbox" aria-label="Select <?php echo htmlspecialchars($plugin['name'] ?? $id, ENT_QUOTES, 'UTF-8'); ?>">
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($plugin['name'] ?? $id, ENT_QUOTES, 'UTF-8'); ?></strong>
                                <div class="row-actions">
                                    <?php if ($plugin['active']): ?>
                                        <a href="/admin/plugins/deactivate?id=<?php echo urlencode($id); ?>" style="color: #b35900;">Deactivate</a>
                                    <?php else: ?>
                                        <?php if ($plugin['valid'] && $plugin['compatible']): ?>
                                            <a href="/admin/plugins/activate?id=<?php echo urlencode($id); ?>" style="color: var(--wp-blue); font-weight: 600;">Activate</a> |
                                        <?php endif; ?>
                                        <a href="/admin/plugins/delete?id=<?php echo urlencode($id); ?>" onclick="return confirm('Delete this plugin completely?');" style="color: var(--wp-danger);">Delete</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div style="margin-bottom: 6px; font-size: 13px; color: #334155;">
                                    <?php echo htmlspecialchars($plugin['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <div style="font-size: 12px; color: var(--wp-text-muted); display: flex; gap: 14px; flex-wrap: wrap;">
                                    <span>Version <?php echo htmlspecialchars($plugin['version'] ?? '1.0.0', ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span>By <?php echo htmlspecialchars($plugin['author'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span>PHP: <?php echo htmlspecialchars($plugin['requires_php'] ?? '>=8.1', ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>

                                <?php if (!empty($plugin['errors'])): ?>
                                    <div style="margin-top: 6px; font-size: 12px; color: var(--wp-danger);">
                                        &#9888; <?php echo htmlspecialchars(implode(' | ', $plugin['errors']), ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($plugin['dependencies'])): ?>
                                    <div style="margin-top: 4px; font-size: 11px; color: var(--wp-text-muted);">
                                        Dependencies: <?php echo htmlspecialchars(implode(', ', $plugin['dependencies']), ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($plugin['active']): ?>
                                    <span style="background: #dcfce7; color: #15803d; font-size: 11px; font-weight: 600; padding: 3px 8px; border-radius: 3px; text-transform: uppercase;">Active</span>
                                <?php else: ?>
                                    <span style="background: #f1f5f9; color: #64748b; font-size: 11px; font-weight: 600; padding: 3px 8px; border-radius: 3px; text-transform: uppercase;">Inactive</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof initAdminMultiSelect === 'function') {
        initAdminMultiSelect('plugins-bulk-form', { itemType: 'plugin' });
    }
});
</script>
