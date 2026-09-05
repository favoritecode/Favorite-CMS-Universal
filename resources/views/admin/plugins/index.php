<div class="page-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
    <div>
        <h1 class="page-title" style="margin-bottom: 4px;">Plugins</h1>
        <p style="margin: 0; font-size: 13px; color: var(--wp-text-muted);">
            Manage installed plugins, upload new extensions, or perform bulk activations and deactivations.
        </p>
    </div>
    <?php
        $totalPlugins = count($plugins ?? []);
        $activePlugins = 0;
        foreach ($plugins ?? [] as $p) {
            if (!empty($p['active'])) {
                $activePlugins++;
            }
        }
    ?>
    <div style="display: flex; gap: 8px; align-items: center; align-self: center;">
        <span class="badge badge-success" style="font-size: 12px; padding: 4px 10px;"><?php echo $activePlugins; ?> Active</span>
        <span class="badge badge-secondary" style="font-size: 12px; padding: 4px 10px;"><?php echo $totalPlugins; ?> Total</span>
    </div>
</div>

<?php if (!empty($bootErrors)): ?>
    <div class="notice notice-error" style="margin-bottom: 20px;">
        <strong style="display: flex; align-items: center; gap: 6px;">
            <span>⚠️</span> Plugin Execution Warnings
        </strong>
        <ul style="margin-top: 8px; margin-bottom: 0; padding-left: 20px; font-size: 13px;">
            <?php foreach ($bootErrors as $pId => $pErr): ?>
                <li>Plugin <code><?php echo htmlspecialchars($pId, ENT_QUOTES, 'UTF-8'); ?></code>: <?php echo htmlspecialchars($pErr, ENT_QUOTES, 'UTF-8'); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Upload Plugin Box -->
<div class="card" style="margin-bottom: 24px; padding: 22px 24px;">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; flex-wrap: wrap; gap: 8px;">
        <div style="display: flex; align-items: center; gap: 8px;">
            <span style="font-size: 18px;">📦</span>
            <h2 style="font-size: 15px; font-weight: 600; margin: 0; color: var(--wp-text-main);">Upload & Install Plugin</h2>
        </div>
        <span style="font-size: 12px; color: var(--wp-text-muted);">Supports standard Favorite CMS plugin .zip packages</span>
    </div>
    <form method="POST" action="/admin/plugins/upload" enctype="multipart/form-data" id="plugin-upload-form" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
        <input type="hidden" name="_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
        <div style="position: relative; flex: 1; min-width: 260px; max-width: 480px;">
            <input type="file" name="plugin_zip" id="plugin-zip-input" accept=".zip" required class="form-control" style="padding-top: 7px; padding-bottom: 7px;">
        </div>
        <button type="submit" id="plugin-upload-btn" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px;">
            <span id="upload-btn-spinner" style="display:none; width: 14px; height: 14px; border: 2px solid rgba(255,255,255,0.3); border-top-color: #fff; border-radius: 50%; animation: spin 0.8s linear infinite;"></span>
            <span id="upload-btn-text">Install Now</span>
        </button>
        <div style="width: 100%; font-size: 12px; color: var(--wp-text-muted); margin-top: 4px;">
            Upload a plugin in <code>.zip</code> format. The archive root must contain a valid <code>plugin.json</code> manifest and entry point.
        </div>
    </form>
</div>

<!-- Plugins Table with Multi-Select / Bulk Actions -->
<form method="POST" action="/admin/plugins/bulk" id="plugins-bulk-form">
    <input type="hidden" name="_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

    <div class="bulk-actions-wrap" style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px; flex-wrap: wrap;">
        <select name="bulk_action" class="form-control" style="width: auto; min-width: 160px; display: inline-block;">
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
                    <th style="width: 36px; text-align: center;">
                        <input type="checkbox" id="select-all-plugins" data-select-all aria-label="Select All">
                    </th>
                    <th style="width: 250px;">Plugin</th>
                    <th>Description & Details</th>
                    <th style="width: 120px; text-align: center;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($plugins)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; color: var(--wp-text-muted); padding: 36px;">
                            <div style="font-size: 28px; margin-bottom: 8px;">🔌</div>
                            <div style="font-weight: 500;">No plugins installed.</div>
                            <div style="font-size: 12px; margin-top: 4px;">Upload a plugin .zip above to get started.</div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($plugins as $id => $plugin): ?>
                        <tr style="<?php echo !empty($plugin['active']) ? 'background: rgba(34, 113, 177, 0.02);' : ''; ?>">
                            <td style="text-align: center;">
                                <input type="checkbox" name="ids[]" value="<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>" class="row-checkbox" aria-label="Select <?php echo htmlspecialchars($plugin['name'] ?? $id, ENT_QUOTES, 'UTF-8'); ?>">
                            </td>
                            <td>
                                <div style="font-weight: 600; font-size: 14px; color: var(--wp-text-main); margin-bottom: 4px;">
                                    <?php echo htmlspecialchars($plugin['name'] ?? $id, ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <div class="row-actions" style="font-size: 12px;">
                                    <?php if (!empty($plugin['active'])): ?>
                                        <?php if ($id === 'favorite-pay'): ?>
                                            <a href="/admin/page/favorite-pay" style="color: var(--wp-blue); font-weight: 600;">Settings</a>
                                            <span style="color: #cbd5e1;"> | </span>
                                        <?php endif; ?>
                                        <a href="/admin/plugins/deactivate?id=<?php echo urlencode($id); ?>" style="color: #b45309; font-weight: 500;">Deactivate</a>
                                    <?php else: ?>
                                        <?php if (!empty($plugin['valid']) && !empty($plugin['compatible'])): ?>
                                            <a href="/admin/plugins/activate?id=<?php echo urlencode($id); ?>" style="color: var(--wp-blue); font-weight: 600;">Activate</a>
                                            <span style="color: #cbd5e1;"> | </span>
                                        <?php endif; ?>
                                        <a href="/admin/plugins/delete?id=<?php echo urlencode($id); ?>" onclick="return confirm('Are you sure you want to permanently delete this plugin? This action cannot be undone.');" style="color: var(--wp-danger); font-weight: 500;">Delete</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div style="margin-bottom: 8px; font-size: 13px; color: #334155; line-height: 1.5;">
                                    <?php echo htmlspecialchars($plugin['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <div style="font-size: 12px; color: var(--wp-text-muted); display: flex; gap: 14px; flex-wrap: wrap; align-items: center;">
                                    <span><strong>Version:</strong> <?php echo htmlspecialchars($plugin['version'] ?? '1.0.0', ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span><strong>By:</strong> <?php echo htmlspecialchars($plugin['author'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span><strong>PHP:</strong> <?php echo htmlspecialchars($plugin['requires_php'] ?? '>=8.1', ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>

                                <?php if (!empty($plugin['errors'])): ?>
                                    <div style="margin-top: 8px; padding: 6px 10px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 4px; font-size: 12px; color: var(--wp-danger);">
                                        ⚠️ <?php echo htmlspecialchars(implode(' | ', $plugin['errors']), ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($plugin['dependencies'])): ?>
                                    <div style="margin-top: 6px; font-size: 11px; color: var(--wp-text-muted); display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                                        <span>Dependencies:</span>
                                        <?php foreach ($plugin['dependencies'] as $dep): ?>
                                            <span class="badge badge-secondary" style="font-size: 11px;"><?php echo htmlspecialchars($dep, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <?php if (!empty($plugin['active'])): ?>
                                    <span class="badge badge-success" style="display: inline-flex; align-items: center; gap: 5px;">
                                        <span style="width: 6px; height: 6px; border-radius: 50%; background: #16a34a;"></span>
                                        Active
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-secondary" style="display: inline-flex; align-items: center; gap: 5px;">
                                        <span style="width: 6px; height: 6px; border-radius: 50%; background: #94a3b8;"></span>
                                        Inactive
                                    </span>
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

    var uploadForm = document.getElementById('plugin-upload-form');
    if (uploadForm) {
        uploadForm.addEventListener('submit', function() {
            var btn = document.getElementById('plugin-upload-btn');
            var text = document.getElementById('upload-btn-text');
            var spinner = document.getElementById('upload-btn-spinner');
            if (btn && text && spinner) {
                btn.setAttribute('disabled', 'disabled');
                spinner.style.display = 'inline-block';
                text.textContent = 'Installing Plugin...';
            }
        });
    }
});
</script>
