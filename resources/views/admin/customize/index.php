<?php
/**
 * Generic Fallback Customizer View — Favorite CMS Universal Core
 * Rendered inside the master Fullscreen Customizer Shell for themes without a customizer.php.
 */

declare(strict_types=1);

$mods = $mods ?? [];
$sections = $sections ?? [];
$manifest = $manifest ?? [];
$globalTokens = $globalTokens ?? [];
$builderTree = $builderTree ?? [];
$elementsRegistry = $elementsRegistry ?? \FavoriteCMS\Themes\BuilderElementRegistry::getInstance()->all();
$csrfToken = $csrfToken ?? csrf_token();
$colors = $globalTokens['colors'] ?? [];
?>

<div class="core-fallback-customizer" style="padding: 16px;">
    <form id="core-fallback-form" method="POST" action="<?php echo htmlspecialchars(site_path('/admin/customize/save'), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="direction" value="">

        <div style="margin-bottom: 16px;">
            <h2 style="font-size: 16px; font-weight: 700; color: var(--admin-text-heading); margin-bottom: 4px;">Theme Controls</h2>
            <p style="font-size: 12px; color: var(--admin-text-muted);">Configure layout, global design system, and homepage sections.</p>
        </div>

        <div style="display: flex; flex-direction: column; gap: 12px;">
            <!-- Panel 1: Site Identity & Layout -->
            <details class="core-panel" open style="border: 1px solid var(--admin-border); border-radius: 8px; overflow: hidden; background: var(--admin-surface);">
                <summary style="padding: 12px 14px; font-weight: 600; font-size: 13.5px; cursor: pointer; background: var(--admin-surface-subtle); color: var(--admin-text-heading); user-select: none;">
                    🏷️ Site Identity & Layout
                </summary>
                <div style="padding: 14px; display: flex; flex-direction: column; gap: 12px;">
                    <div>
                        <label style="display:block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Sidebar Alignment</label>
                        <?php $layout = $mods['site_layout'] ?? 'right'; ?>
                        <select name="mods[site_layout]" class="form-control" style="width: 100%; padding: 7px; border: 1px solid var(--admin-border); border-radius: 6px; background: var(--admin-input-bg); color: var(--admin-input-text);">
                            <option value="right" <?php echo ($layout === 'right') ? 'selected' : ''; ?>>Content on Left, Sidebar on Right</option>
                            <option value="left" <?php echo ($layout === 'left') ? 'selected' : ''; ?>>Sidebar on Left, Content on Right</option>
                            <option value="none" <?php echo ($layout === 'none') ? 'selected' : ''; ?>>No Sidebar (Full Width)</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Brand Accent Color</label>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <input type="color" value="<?php echo htmlspecialchars($mods['accent_color'] ?? '#2563eb'); ?>" style="width: 40px; height: 34px; border: 1px solid var(--admin-border); border-radius: 4px; padding: 2px; cursor: pointer;" oninput="document.getElementById('core-mod-accent').value = this.value;">
                            <input type="text" name="mods[accent_color]" id="core-mod-accent" value="<?php echo htmlspecialchars($mods['accent_color'] ?? '#2563eb'); ?>" style="flex: 1; padding: 6px 10px; border: 1px solid var(--admin-border); border-radius: 6px; background: var(--admin-input-bg); color: var(--admin-input-text); font-family: monospace;">
                        </div>
                    </div>
                    <div>
                        <label style="display:block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Site Logo URL</label>
                        <input type="url" name="mods[site_logo_url]" class="form-control" placeholder="https://example.com/logo.png" value="<?php echo htmlspecialchars($mods['site_logo_url'] ?? ''); ?>" style="width: 100%; padding: 7px; border: 1px solid var(--admin-border); border-radius: 6px; background: var(--admin-input-bg); color: var(--admin-input-text);">
                    </div>
                    <div>
                        <label style="display:block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Favicon URL</label>
                        <input type="url" name="mods[site_favicon_url]" class="form-control" placeholder="https://example.com/favicon.png" value="<?php echo htmlspecialchars($mods['site_favicon_url'] ?? ''); ?>" style="width: 100%; padding: 7px; border: 1px solid var(--admin-border); border-radius: 6px; background: var(--admin-input-bg); color: var(--admin-input-text);">
                    </div>
                    <div>
                        <label style="display:block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Footer Copyright</label>
                        <input type="text" name="mods[footer_copyright]" class="form-control" placeholder="All rights reserved." value="<?php echo htmlspecialchars($mods['footer_copyright'] ?? ''); ?>" style="width: 100%; padding: 7px; border: 1px solid var(--admin-border); border-radius: 6px; background: var(--admin-input-bg); color: var(--admin-input-text);">
                    </div>
                </div>
            </details>

            <!-- Panel 2: Homepage Sections Manager -->
            <details class="core-panel" open style="border: 1px solid var(--admin-border); border-radius: 8px; overflow: hidden; background: var(--admin-surface);">
                <summary style="padding: 12px 14px; font-weight: 600; font-size: 13.5px; cursor: pointer; background: var(--admin-surface-subtle); color: var(--admin-text-heading); user-select: none;">
                    📑 Homepage Sections &amp; Order
                </summary>
                <div style="padding: 14px; display: flex; flex-direction: column; gap: 8px;">
                    <?php foreach ($sections as $idx => $s): ?>
                        <?php
                        $sid = $s['id'];
                        $isEnabled = !empty($s['enabled']);
                        ?>
                        <div style="border: 1px solid var(--admin-border); border-radius: 6px; padding: 10px 12px; background: var(--admin-surface); display: flex; justify-content: space-between; align-items: center;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" name="sections[<?php echo htmlspecialchars($sid); ?>][enabled]" value="1" <?php echo $isEnabled ? 'checked' : ''; ?> style="cursor: pointer;">
                                <div>
                                    <div style="font-weight: 600; font-size: 13px; color: var(--admin-text-heading);"><?php echo htmlspecialchars($s['name']); ?></div>
                                    <div style="font-size: 11px; color: var(--admin-text-muted);"><code><?php echo htmlspecialchars($sid); ?></code></div>
                                </div>
                            </div>
                            <div style="display: flex; gap: 4px;">
                                <button type="submit" formaction="<?php echo htmlspecialchars(site_path('/admin/customize/sections/reorder'), ENT_QUOTES, 'UTF-8'); ?>" formmethod="POST" name="section_id" value="<?php echo htmlspecialchars($sid); ?>" onclick="this.form.elements['direction'].value='up';" class="core-tool-btn" style="padding: 2px 6px; font-size: 11px;" <?php echo ($idx === 0) ? 'disabled style="opacity:0.35;"' : ''; ?>>↑</button>
                                <button type="submit" formaction="<?php echo htmlspecialchars(site_path('/admin/customize/sections/reorder'), ENT_QUOTES, 'UTF-8'); ?>" formmethod="POST" name="section_id" value="<?php echo htmlspecialchars($sid); ?>" onclick="this.form.elements['direction'].value='down';" class="core-tool-btn" style="padding: 2px 6px; font-size: 11px;" <?php echo ($idx === count($sections) - 1) ? 'disabled style="opacity:0.35;"' : ''; ?>>↓</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>

            <!-- Panel 3: Global Design Tokens -->
            <details class="core-panel" style="border: 1px solid var(--admin-border); border-radius: 8px; overflow: hidden; background: var(--admin-surface);">
                <summary style="padding: 12px 14px; font-weight: 600; font-size: 13.5px; cursor: pointer; background: var(--admin-surface-subtle); color: var(--admin-text-heading); user-select: none;">
                    🎨 Global Design Tokens
                </summary>
                <div style="padding: 14px; display: flex; flex-direction: column; gap: 10px;">
                    <?php foreach (['primary' => 'Primary Brand', 'secondary' => 'Secondary Accent', 'heading' => 'Heading Color', 'text' => 'Body Text', 'background' => 'Page Background', 'surface' => 'Card Surface'] as $tokenKey => $tokenLabel): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 12px;"><?php echo $tokenLabel; ?></span>
                            <div style="display: flex; gap: 6px; align-items: center;">
                                <input type="color" value="<?php echo htmlspecialchars($colors[$tokenKey] ?? '#000000'); ?>" style="width: 32px; height: 28px; border: 1px solid var(--admin-border); border-radius: 4px; padding: 1px; cursor: pointer;" oninput="document.getElementById('token-<?php echo $tokenKey; ?>').value = this.value;">
                                <input type="text" name="tokens[colors][<?php echo $tokenKey; ?>]" id="token-<?php echo $tokenKey; ?>"" value="<?php echo htmlspecialchars($colors[$tokenKey] ?? ''); ?>" style="width: 90px; padding: 4px 6px; font-size: 11px; border: 1px solid var(--admin-border); border-radius: 4px; font-family: monospace;">
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>

            <!-- Panel 4: Registered Visual Builder Elements -->
            <details class="core-panel" style="border: 1px solid var(--admin-border); border-radius: 8px; overflow: hidden; background: var(--admin-surface);">
                <summary style="padding: 12px 14px; font-weight: 600; font-size: 13.5px; cursor: pointer; background: var(--admin-surface-subtle); color: var(--admin-text-heading); user-select: none;">
                    🧩 Registered Builder Elements (<?php echo count($elementsRegistry); ?>)
                </summary>
                <div style="padding: 14px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                    <?php foreach ($elementsRegistry as $elId => $el): ?>
                        <div style="border: 1px solid var(--admin-border); border-radius: 6px; padding: 8px 10px; background: var(--admin-surface-subtle); font-size: 12px;">
                            <strong style="display:block; color:var(--admin-text-heading);"><?php echo htmlspecialchars($el['name']); ?></strong>
                            <span style="color:var(--admin-text-muted); font-size:10px;"><code><?php echo htmlspecialchars($elId); ?></code></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        </div>

        <div style="margin-top: 18px;">
            <button type="submit" class="core-btn-primary" style="width: 100%; justify-content: center;">Save Changes</button>
        </div>
    </form>
</div>
