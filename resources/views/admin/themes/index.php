<div class="page-header">
    <h1 class="page-title">Themes</h1>
</div>

<!-- Upload Theme Box -->
<div class="form-card" style="margin-bottom: 24px; padding: 20px;">
    <h2 style="font-size: 15px; font-weight: 600; margin-bottom: 12px;">Upload Theme</h2>
    <form method="POST" action="/admin/themes/upload" enctype="multipart/form-data" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        <input type="file" name="theme_zip" accept=".zip" required class="form-control" style="max-width: 300px;">
        <button type="submit" class="btn btn-primary">Install Now</button>
        <span class="description">Upload a theme in .zip format. Must contain a valid theme.json.</span>
    </form>
</div>

<!-- Themes Grid -->
<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px;">
    <?php foreach ($themes as $id => $theme): ?>
        <div class="form-card" style="padding: 16px; border: 2px solid <?php echo $theme['active'] ? 'var(--wp-blue)' : 'var(--wp-border)'; ?>; display: flex; flex-direction: column;">
            <div style="height: 140px; background: #e2e8f0; border-radius: 4px; display: flex; align-items: center; justify-content: center; margin-bottom: 12px; font-size: 36px;">
                🎨
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                <h3 style="font-size: 16px; font-weight: 600; color: #1d2327;">
                    <?php echo htmlspecialchars($theme['name'] ?? $id, ENT_QUOTES, 'UTF-8'); ?>
                </h3>
                <span style="font-size: 12px; color: var(--wp-text-muted);">v<?php echo htmlspecialchars($theme['version'] ?? '1.0.0', ENT_QUOTES, 'UTF-8'); ?></span>
            </div>

            <div style="font-size: 12px; color: var(--wp-text-muted); margin-bottom: 8px;">
                By <?php echo htmlspecialchars($theme['author'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?>
            </div>

            <p style="font-size: 13px; color: #475569; margin-bottom: 16px; flex: 1;">
                <?php echo htmlspecialchars($theme['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
            </p>

            <div style="margin-top: auto; display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #f0f0f1; padding-top: 12px;">
                <?php if ($theme['active']): ?>
                    <span style="background: #dcfce7; color: #15803d; font-weight: 600; padding: 4px 10px; border-radius: 4px; font-size: 12px;">
                        &#10003; Active Theme
                    </span>
                    <a href="/" target="_blank" class="btn btn-secondary" style="font-size: 12px;">Customize / View</a>
                <?php else: ?>
                    <a href="/admin/themes/activate?id=<?php echo urlencode($id); ?>" class="btn btn-primary" style="font-size: 12px;">
                        Activate
                    </a>
                    <?php if ($id !== 'default'): ?>
                        <a href="/admin/themes/delete?id=<?php echo urlencode($id); ?>" onclick="return confirm('Delete this theme?');" style="color: var(--wp-danger); font-size: 12px;">Delete</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

