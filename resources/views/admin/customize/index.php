<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 class="page-title" style="margin-bottom: 4px;">Customize Theme</h1>
        <div style="font-size: 13px; color: var(--wp-text-muted);">
            Customizing: <strong><?php echo htmlspecialchars($themeName); ?></strong> (<code><?php echo htmlspecialchars($themeId); ?></code>)
        </div>
    </div>
    <div style="display: flex; gap: 8px;">
        <a href="/" target="_blank" class="btn btn-secondary">Live Site Preview &#8599;</a>
        <form method="POST" action="/admin/customize/reset" onsubmit="return confirm('Reset all theme customizations and sections back to theme defaults?');" style="display: inline;">
            <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" class="btn btn-secondary" style="color: var(--wp-danger);">&#8635; Reset to Defaults</button>
        </form>
    </div>
</div>

<form method="POST" action="/admin/customize/save">
    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: start;">
        <!-- Card 1: Layout & Style Options -->
        <div class="form-card" style="padding: 20px;">
            <h2 style="font-size: 16px; font-weight: 700; margin-bottom: 6px; color: var(--wp-dark);">Layout & Presentation</h2>
            <p style="font-size: 12px; color: var(--wp-text-muted); margin-bottom: 20px;">
                Configure the overall layout structure and brand accents for your site.
            </p>

            <!-- Sidebar Position -->
            <div class="form-group">
                <label for="mod-site-layout">Sidebar Alignment & Structure</label>
                <?php $layout = $mods['site_layout'] ?? 'right'; ?>
                <select name="mods[site_layout]" id="mod-site-layout" class="form-control">
                    <option value="right" <?php echo ($layout === 'right') ? 'selected' : ''; ?>>Standard (Content on Left, Sidebar on Right)</option>
                    <option value="left" <?php echo ($layout === 'left') ? 'selected' : ''; ?>>Inverted (Sidebar on Left, Content on Right)</option>
                    <option value="none" <?php echo ($layout === 'none') ? 'selected' : ''; ?>>No Sidebar (Full Width Single Column)</option>
                </select>
                <span class="description">Controls where the primary sidebar is rendered across posts, archives, and pages.</span>
            </div>

            <!-- Accent Color -->
            <div class="form-group">
                <label for="mod-accent-color">Brand Accent Color</label>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <input type="color" id="mod-accent-color-picker" value="<?php echo htmlspecialchars($mods['accent_color'] ?? '#0284c7'); ?>" style="width: 44px; height: 36px; border: 1px solid var(--wp-border); border-radius: 4px; padding: 2px; cursor: pointer;" oninput="document.getElementById('mod-accent-color').value = this.value;">
                    <input type="text" name="mods[accent_color]" id="mod-accent-color" class="form-control" style="width: 140px; font-family: monospace;" value="<?php echo htmlspecialchars($mods['accent_color'] ?? '#0284c7'); ?>" oninput="document.getElementById('mod-accent-color-picker').value = this.value;">
                </div>
                <span class="description">Custom accent color used for buttons, badges, links, and highlights.</span>
            </div>

            <!-- Logo Image URL -->
            <div class="form-group">
                <label for="mod-site-logo">Site Logo Image URL</label>
                <input type="url" name="mods[site_logo_url]" id="mod-site-logo" class="form-control" placeholder="https://example.com/uploads/logo.png" value="<?php echo htmlspecialchars($mods['site_logo_url'] ?? ''); ?>">
                <span class="description">Leave blank to display the standard text site title and icon.</span>
            </div>

            <!-- Custom Copyright Notice -->
            <div class="form-group">
                <label for="mod-footer-copyright">Footer Copyright Text</label>
                <input type="text" name="mods[footer_copyright]" id="mod-footer-copyright" class="form-control" placeholder="All rights reserved." value="<?php echo htmlspecialchars($mods['footer_copyright'] ?? ''); ?>">
                <span class="description">Custom copyright notice rendered in the footer bar.</span>
            </div>

            <div style="margin-top: 24px;">
                <button type="submit" class="btn btn-primary">Save Customization Settings</button>
            </div>
        </div>

        <!-- Card 2: Homepage Sections Manager -->
        <div class="form-card" style="padding: 20px;">
            <h2 style="font-size: 16px; font-weight: 700; margin-bottom: 6px; color: var(--wp-dark);">Homepage Sections</h2>
            <p style="font-size: 12px; color: var(--wp-text-muted); margin-bottom: 20px;">
                Enable, disable, or reorder the structural sections displayed on your homepage.
            </p>

            <div style="display: flex; flex-direction: column; gap: 12px;">
                <?php foreach ($sections as $idx => $s): ?>
                    <?php
                    $sid = $s['id'];
                    $isEnabled = !empty($s['enabled']);
                    ?>
                    <div style="border: 1px solid var(--wp-border); border-radius: 6px; padding: 14px 16px; background: #ffffff; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <label style="margin: 0; cursor: pointer;">
                                <input type="checkbox" name="sections[<?php echo htmlspecialchars($sid); ?>][enabled]" value="1" <?php echo $isEnabled ? 'checked' : ''; ?>>
                            </label>
                            <div>
                                <div style="font-weight: 600; font-size: 14px; color: var(--wp-dark);"><?php echo htmlspecialchars($s['name']); ?></div>
                                <div style="font-size: 11px; color: var(--wp-text-muted); margin-top: 2px;">
                                    <code><?php echo htmlspecialchars($sid); ?></code> &bull; <?php echo htmlspecialchars($s['description'] ?? ''); ?>
                                </div>
                            </div>
                        </div>

                        <!-- Section Ordering Buttons -->
                        <div style="display: flex; gap: 4px;">
                            <button type="submit" formaction="/admin/customize/sections/reorder" formmethod="POST" name="section_id" value="<?php echo htmlspecialchars($sid); ?>" onclick="this.form.elements['direction'].value='up';" class="btn btn-secondary" style="padding: 2px 8px; font-size: 12px;" <?php echo ($idx === 0) ? 'disabled style="opacity:0.4;"' : ''; ?>>&#8593; Up</button>
                            <button type="submit" formaction="/admin/customize/sections/reorder" formmethod="POST" name="section_id" value="<?php echo htmlspecialchars($sid); ?>" onclick="this.form.elements['direction'].value='down';" class="btn btn-secondary" style="padding: 2px 8px; font-size: 12px;" <?php echo ($idx === count($sections) - 1) ? 'disabled style="opacity:0.4;"' : ''; ?>>&#8595; Down</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <input type="hidden" name="direction" value="">

            <div style="margin-top: 24px;">
                <button type="submit" class="btn btn-primary">Save Section Settings</button>
            </div>
        </div>
    </div>
</form>

