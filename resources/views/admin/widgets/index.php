<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 class="page-title" style="margin-bottom: 4px;">Widgets</h1>
        <div style="font-size: 13px; color: var(--wp-text-muted);">
            Active Theme: <strong><?php echo htmlspecialchars($themeName); ?></strong> (<code><?php echo htmlspecialchars($themeId); ?></code>)
        </div>
    </div>
    <div style="display: flex; gap: 8px;">
        <a href="/" target="_blank" class="btn btn-secondary">Preview Site &#8599;</a>
        <form method="POST" action="/admin/widgets/reset" onsubmit="return confirm('Are you sure you want to reset all widget regions back to the theme default layout?');" style="display: inline;">
            <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" class="btn btn-secondary" style="color: var(--wp-danger);">&#8635; Reset to Theme Defaults</button>
        </form>
    </div>
</div>

<div style="display: grid; grid-template-columns: 320px 1fr; gap: 24px; align-items: start;">
    <!-- Left Column: Available Widgets Palette -->
    <div>
        <div class="form-card" style="padding: 16px; position: sticky; top: 50px;">
            <h2 style="font-size: 15px; font-weight: 700; margin-bottom: 4px; color: var(--wp-dark);">Available Widgets</h2>
            <p style="font-size: 12px; color: var(--wp-text-muted); margin-bottom: 16px;">
                Choose a widget below and select a region to place it into your site layout.
            </p>

            <?php foreach ($availableWidgets as $category => $widgets): ?>
                <div style="margin-bottom: 16px;">
                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 8px; letter-spacing: 0.5px;">
                        <?php echo htmlspecialchars($category); ?>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <?php foreach ($widgets as $w): ?>
                            <div class="available-widget-card" style="border: 1px solid var(--wp-border); border-radius: 6px; padding: 10px 12px; background: #ffffff; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                                <div style="display: flex; justify-content: space-between; align-items: start; gap: 8px;">
                                    <div style="display: flex; gap: 8px; align-items: center;">
                                        <span style="font-size: 18px;"><?php echo $w->getIcon(); ?></span>
                                        <div>
                                            <div style="font-weight: 600; font-size: 13px; color: var(--wp-dark);"><?php echo htmlspecialchars($w->getName()); ?></div>
                                            <div style="font-size: 11px; color: var(--wp-text-muted); line-height: 1.3; margin-top: 2px;"><?php echo htmlspecialchars($w->getDescription()); ?></div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Add to region action -->
                                <div style="margin-top: 8px; border-top: 1px dashed var(--wp-border); padding-top: 8px; display: flex; justify-content: flex-end;">
                                    <form method="POST" action="/admin/widgets/store" style="display: flex; gap: 6px; width: 100%;">
                                        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="widget_id" value="<?php echo htmlspecialchars($w->getId()); ?>">
                                        <select name="region_id" class="form-control" style="font-size: 11px; padding: 2px 6px; flex: 1;" required>
                                            <option value="">Add to Region...</option>
                                            <?php foreach ($regions as $r): ?>
                                                <option value="<?php echo htmlspecialchars($r['id']); ?>"><?php echo htmlspecialchars($r['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-primary" style="font-size: 11px; padding: 2px 8px;">+ Add</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Right Column: Theme Layout Regions -->
    <div style="display: flex; flex-direction: column; gap: 20px;">
        <?php if (empty($regions)): ?>
            <div class="form-card" style="text-align: center; padding: 40px; color: var(--wp-text-muted);">
                This theme does not declare any widget regions in its <code>theme.json</code>.
            </div>
        <?php endif; ?>

        <?php foreach ($regions as $r): ?>
            <?php
            $rid = $r['id'];
            $instances = $regionData[$rid]['instances'] ?? [];
            ?>
            <div class="region-box form-card" style="padding: 0; overflow: hidden; border: 1px solid var(--wp-border); border-radius: 6px;">
                <!-- Region Header -->
                <div style="background: #f8fafc; border-bottom: 1px solid var(--wp-border); padding: 14px 18px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <h3 style="font-size: 15px; font-weight: 700; color: var(--wp-dark); margin: 0;"><?php echo htmlspecialchars($r['name']); ?></h3>
                            <span style="font-size: 11px; background: #e2e8f0; color: #475569; padding: 1px 8px; border-radius: 999px; font-weight: 600;">
                                <code><?php echo htmlspecialchars($rid); ?></code>
                            </span>
                        </div>
                        <?php if (!empty($r['description'])): ?>
                            <p style="font-size: 12px; color: var(--wp-text-muted); margin-top: 4px;"><?php echo htmlspecialchars($r['description']); ?></p>
                        <?php endif; ?>
                    </div>
                    <span style="font-size: 12px; color: #64748b; font-weight: 600;">
                        <?php echo count($instances); ?> <?php echo count($instances) === 1 ? 'widget' : 'widgets'; ?>
                    </span>
                </div>

                <!-- Region Body: Placed Widgets -->
                <div class="region-widget-list" data-region-id="<?php echo htmlspecialchars($rid); ?>" style="padding: 16px; min-height: 80px; background: #fdfdfd; display: flex; flex-direction: column; gap: 12px;">
                    <?php if (empty($instances)): ?>
                        <div style="border: 2px dashed #cbd5e1; border-radius: 6px; padding: 24px; text-align: center; color: var(--wp-text-muted); font-size: 13px;">
                            No widgets in this region yet. Select a widget on the left to add it here.
                        </div>
                    <?php else: ?>
                        <?php foreach ($instances as $idx => $inst): ?>
                            <?php
                            $wObj = $registry->get($inst['widget_id']);
                            $icon = $wObj ? $wObj->getIcon() : '🧩';
                            $typeName = $wObj ? $wObj->getName() : $inst['widget_id'];
                            $customTitle = !empty($inst['settings']['title']) ? $inst['settings']['title'] : $typeName;
                            $schema = $wObj ? $wObj->getSchema() : [];
                            $instId = $inst['id'];
                            ?>
                            <div class="widget-instance-card" id="card-<?php echo htmlspecialchars($instId); ?>" style="border: 1px solid var(--wp-border); border-radius: 6px; background: #ffffff; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
                                <!-- Instance Titlebar -->
                                <div style="padding: 10px 14px; background: #ffffff; border-radius: 6px; display: flex; justify-content: space-between; align-items: center; cursor: pointer;" onclick="toggleWidgetDrawer('<?php echo htmlspecialchars($instId); ?>')">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <span style="font-size: 16px;"><?php echo $icon; ?></span>
                                        <div>
                                            <span style="font-weight: 600; font-size: 13px; color: var(--wp-dark);"><?php echo htmlspecialchars($customTitle); ?></span>
                                            <span style="font-size: 11px; color: var(--wp-text-muted); margin-left: 6px;">(<?php echo htmlspecialchars($typeName); ?>)</span>
                                        </div>
                                    </div>

                                    <!-- Quick Actions -->
                                    <div style="display: flex; align-items: center; gap: 4px;" onclick="event.stopPropagation();">
                                        <!-- Move Up Form -->
                                        <form method="POST" action="/admin/widgets/move" style="display: inline;">
                                            <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="instance_id" value="<?php echo htmlspecialchars($instId); ?>">
                                            <input type="hidden" name="direction" value="up">
                                            <button type="submit" class="action-btn" title="Move Up" <?php echo ($idx === 0) ? 'disabled style="opacity:0.4;"' : ''; ?>>&#8593;</button>
                                        </form>

                                        <!-- Move Down Form -->
                                        <form method="POST" action="/admin/widgets/move" style="display: inline;">
                                            <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="instance_id" value="<?php echo htmlspecialchars($instId); ?>">
                                            <input type="hidden" name="direction" value="down">
                                            <button type="submit" class="action-btn" title="Move Down" <?php echo ($idx === count($instances) - 1) ? 'disabled style="opacity:0.4;"' : ''; ?>>&#8595;</button>
                                        </form>

                                        <!-- Duplicate Form -->
                                        <form method="POST" action="/admin/widgets/duplicate" style="display: inline;">
                                            <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="instance_id" value="<?php echo htmlspecialchars($instId); ?>">
                                            <button type="submit" class="action-btn" title="Duplicate Widget">&#x2398;</button>
                                        </form>

                                        <!-- Delete Form -->
                                        <form method="POST" action="/admin/widgets/delete" onsubmit="return confirm('Delete this widget instance?');" style="display: inline;">
                                            <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="instance_id" value="<?php echo htmlspecialchars($instId); ?>">
                                            <button type="submit" class="action-btn action-btn-danger" title="Remove Widget">&#10005;</button>
                                        </form>

                                        <span class="drawer-arrow" id="arrow-<?php echo htmlspecialchars($instId); ?>" style="font-size: 11px; color: #94a3b8; margin-left: 6px;">&#9660;</span>
                                    </div>
                                </div>

                                <!-- Expandable Settings Drawer -->
                                <div class="widget-drawer" id="drawer-<?php echo htmlspecialchars($instId); ?>" style="display: none; padding: 14px; border-top: 1px solid var(--wp-border); background: #f8fafc;">
                                    <form method="POST" action="/admin/widgets/update">
                                        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="instance_id" value="<?php echo htmlspecialchars($instId); ?>">

                                        <!-- Dynamic Schema Form Fields -->
                                        <?php foreach ($schema as $fieldKey => $fieldDef): ?>
                                            <?php
                                            $fieldType = $fieldDef['type'] ?? 'text';
                                            $fieldLabel = $fieldDef['label'] ?? ucfirst($fieldKey);
                                            $fieldVal = $inst['settings'][$fieldKey] ?? ($fieldDef['default'] ?? '');
                                            ?>
                                            <div class="form-group" style="margin-bottom: 12px;">
                                                <?php if ($fieldType === 'checkbox'): ?>
                                                    <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer; font-size: 13px;">
                                                        <input type="checkbox" name="settings[<?php echo htmlspecialchars($fieldKey); ?>]" value="1" <?php echo !empty($fieldVal) ? 'checked' : ''; ?>>
                                                        <strong><?php echo htmlspecialchars($fieldLabel); ?></strong>
                                                    </label>
                                                <?php else: ?>
                                                    <label style="font-size: 12px; font-weight: 600; margin-bottom: 4px;"><?php echo htmlspecialchars($fieldLabel); ?></label>
                                                    <?php if ($fieldType === 'textarea'): ?>
                                                        <textarea name="settings[<?php echo htmlspecialchars($fieldKey); ?>]" class="form-control" style="font-size: 12px; min-height: 80px;"><?php echo htmlspecialchars((string)$fieldVal, ENT_QUOTES, 'UTF-8'); ?></textarea>
                                                    <?php elseif ($fieldType === 'select'): ?>
                                                        <select name="settings[<?php echo htmlspecialchars($fieldKey); ?>]" class="form-control" style="font-size: 12px;">
                                                            <?php foreach ($fieldDef['options'] ?? [] as $optVal => $optLabel): ?>
                                                                <option value="<?php echo htmlspecialchars((string)$optVal); ?>" <?php echo ((string)$fieldVal === (string)$optVal) ? 'selected' : ''; ?>>
                                                                    <?php echo htmlspecialchars($optLabel); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    <?php elseif ($fieldType === 'number'): ?>
                                                        <input type="number" name="settings[<?php echo htmlspecialchars($fieldKey); ?>]" class="form-control" style="font-size: 12px; width: 100px;" value="<?php echo htmlspecialchars((string)$fieldVal); ?>">
                                                    <?php else: ?>
                                                        <input type="text" name="settings[<?php echo htmlspecialchars($fieldKey); ?>]" class="form-control" style="font-size: 12px;" value="<?php echo htmlspecialchars((string)$fieldVal, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>

                                        <!-- Visibility Rules -->
                                        <div class="form-group" style="margin-bottom: 14px; border-top: 1px dashed var(--wp-border); padding-top: 10px;">
                                            <label style="font-size: 12px; font-weight: 600;">Display Visibility</label>
                                            <select name="visibility[show_on]" class="form-control" style="font-size: 12px;">
                                                <?php $vis = $inst['visibility']['show_on'] ?? 'all'; ?>
                                                <option value="all" <?php echo ($vis === 'all') ? 'selected' : ''; ?>>Show on All Pages</option>
                                                <option value="home" <?php echo ($vis === 'home') ? 'selected' : ''; ?>>Show on Homepage Only</option>
                                                <option value="posts" <?php echo ($vis === 'posts') ? 'selected' : ''; ?>>Show on Single Posts Only</option>
                                                <option value="pages" <?php echo ($vis === 'pages') ? 'selected' : ''; ?>>Show on Static Pages Only</option>
                                            </select>
                                        </div>

                                        <!-- Save & Move footer -->
                                        <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--wp-border); padding-top: 10px;">
                                            <button type="submit" class="btn btn-primary" style="font-size: 12px; padding: 4px 12px;">Save Settings</button>
                                            <span style="font-size: 11px; color: var(--wp-text-muted);">Instance: <code><?php echo htmlspecialchars($instId); ?></code></span>
                                        </div>
                                    </form>

                                    <!-- Accessible Move to another region -->
                                    <form method="POST" action="/admin/widgets/move" style="margin-top: 10px; display: flex; align-items: center; gap: 8px; border-top: 1px dashed var(--wp-border); padding-top: 8px;">
                                        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="instance_id" value="<?php echo htmlspecialchars($instId); ?>">
                                        <span style="font-size: 11px; color: #64748b;">Move to:</span>
                                        <select name="target_region_id" class="form-control" style="font-size: 11px; padding: 2px 6px; width: auto;">
                                            <?php foreach ($regions as $targetR): ?>
                                                <?php if ($targetR['id'] === $rid) continue; ?>
                                                <option value="<?php echo htmlspecialchars($targetR['id']); ?>"><?php echo htmlspecialchars($targetR['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-secondary" style="font-size: 11px; padding: 2px 8px;">Move</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<style>
.action-btn {
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    border-radius: 3px;
    padding: 2px 6px;
    font-size: 11px;
    color: #475569;
    cursor: pointer;
    line-height: 1;
}
.action-btn:hover:not([disabled]) {
    background: #e2e8f0;
    color: var(--wp-blue);
}
.action-btn-danger:hover:not([disabled]) {
    background: #fee2e2;
    color: var(--wp-danger);
    border-color: #fca5a5;
}
</style>

<script>
function toggleWidgetDrawer(instanceId) {
    var drawer = document.getElementById('drawer-' + instanceId);
    var arrow = document.getElementById('arrow-' + instanceId);
    if (!drawer) return;

    if (drawer.style.display === 'none' || drawer.style.display === '') {
        drawer.style.display = 'block';
        if (arrow) arrow.innerHTML = '&#9650;';
    } else {
        drawer.style.display = 'none';
        if (arrow) arrow.innerHTML = '&#9660;';
    }
}
</script>

