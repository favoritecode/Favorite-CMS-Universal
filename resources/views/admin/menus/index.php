<div class="page-header">
    <h1 class="page-title">Navigation Menus</h1>
</div>

<!-- Menu Selector / Create Menu -->
<div class="form-card" style="margin-bottom: 20px; padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
    <?php if (!empty($menus)): ?>
        <form method="GET" action="<?php echo htmlspecialchars(site_path('/admin/menus'), ENT_QUOTES, 'UTF-8'); ?>" style="display: flex; gap: 8px; align-items: center;">
            <label for="select_menu" style="font-weight: 600;">Select a menu to edit:</label>
            <select id="select_menu" name="menu" class="form-control" style="width: auto;" onchange="this.form.submit()">
                <?php foreach ($menus as $m): ?>
                    <option value="<?php echo (int)$m->id; ?>" <?php echo ($selectedMenu?->id == $m->id) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($m->name, ENT_QUOTES, 'UTF-8'); ?> <?php echo $m->location ? '(' . htmlspecialchars($m->location, ENT_QUOTES, 'UTF-8') . ')' : ''; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary">Select</button>
        </form>
    <?php endif; ?>

    <!-- Create New Menu Form -->
    <form method="POST" action="<?php echo htmlspecialchars(site_path('/admin/menus/create'), ENT_QUOTES, 'UTF-8'); ?>" style="display: flex; gap: 8px; align-items: center;">
        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        <input type="text" name="name" class="form-control" placeholder="Menu Name (e.g. Main Menu)" required style="width: 200px;">
        <button type="submit" class="btn btn-secondary">+ Create Menu</button>
    </form>
</div>

<?php if (!$selectedMenu): ?>
    <div class="form-card" style="text-align: center; color: var(--wp-text-muted, #64748b); padding: 40px;">
        No menus created yet. Enter a menu name above and click "+ Create Menu" to get started.
    </div>
<?php else: ?>
    <div style="display: grid; grid-template-columns: 280px 1fr; gap: 20px; align-items: start;">
        <!-- Left: Add Items -->
        <div>
            <!-- Custom Link Box -->
            <div class="form-card" style="margin-bottom: 16px;">
                <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 12px;">Add Custom Link</h3>
                <form method="POST" action="<?php echo htmlspecialchars(site_path('/admin/menus/item/add'), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="menu_id" value="<?php echo (int)$selectedMenu->id; ?>">

                    <div class="form-group" style="margin-bottom: 12px;">
                        <label for="custom_url" style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;">URL</label>
                        <input type="text" id="custom_url" name="url" class="form-control" value="https://" required>
                    </div>

                    <div class="form-group" style="margin-bottom: 12px;">
                        <label for="custom_title" style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;">Link Text</label>
                        <input type="text" id="custom_title" name="title" class="form-control" placeholder="e.g. Blog" required>
                    </div>

                    <button type="submit" class="btn btn-secondary">Add to Menu</button>
                </form>
            </div>

            <!-- Add from Pages -->
            <?php if (!empty($pages)): ?>
                <div class="form-card">
                    <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 12px;">Add from Pages</h3>
                    <?php foreach ($pages as $p): ?>
                        <form method="POST" action="<?php echo htmlspecialchars(site_path('/admin/menus/item/add'), ENT_QUOTES, 'UTF-8'); ?>" style="margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
                            <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="menu_id" value="<?php echo (int)$selectedMenu->id; ?>">
                            <input type="hidden" name="title" value="<?php echo htmlspecialchars($p->title, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="url" value="/page/<?php echo htmlspecialchars($p->slug, ENT_QUOTES, 'UTF-8'); ?>">
                            <span style="font-size: 13px;"><?php echo htmlspecialchars($p->title, ENT_QUOTES, 'UTF-8'); ?></span>
                            <button type="submit" class="btn btn-secondary" style="font-size: 11px; padding: 2px 8px;">+ Add</button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Right: Menu Structure & Settings Form -->
        <div class="form-card">
            <form method="POST" action="<?php echo htmlspecialchars(site_path('/admin/menus/save'), ENT_QUOTES, 'UTF-8'); ?>" id="menu-structure-form">
                <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="menu_id" value="<?php echo (int)$selectedMenu->id; ?>">

                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid var(--wp-border, #ccd0d4);">
                    <h2 style="font-size: 16px; font-weight: 600; margin: 0;">
                        Menu Structure &mdash; <?php echo htmlspecialchars($selectedMenu->name, ENT_QUOTES, 'UTF-8'); ?>
                    </h2>
                    <span style="font-size: 12px; color: #64748b;">Drag items or use ↑ ↓ to organize into submenus</span>
                </div>

                <!-- Menu Structure Container -->
                <div style="background: #f8fafc; border: 1px solid var(--wp-border, #cbd5e1); border-radius: 6px; padding: 14px; margin-bottom: 20px;">
                    <?php if (empty($menuItems)): ?>
                        <p style="color: var(--wp-text-muted, #64748b); font-size: 13px; text-align: center; padding: 30px 20px; margin: 0;">
                            There are no items in this menu yet. Use the panel on the left to add items.
                        </p>
                    <?php else: ?>
                        <ul id="menu-items-list" class="menu-items-list" style="list-style: none; margin: 0; padding: 0; min-height: 50px;">
                            <?php foreach ($menuItems as $index => $item): ?>
                                <?php
                                $depth = (int)($item->depth ?? 0);
                                $parentId = (int)($item->parent_id ?? 0);
                                $indentPx = $depth * 36;
                                ?>
                                <li class="menu-item-row"
                                    id="menu-item-row-<?php echo (int)$item->id; ?>"
                                    data-id="<?php echo (int)$item->id; ?>"
                                    data-parent-id="<?php echo $parentId; ?>"
                                    data-depth="<?php echo $depth; ?>"
                                    style="margin-left: <?php echo $indentPx; ?>px; margin-bottom: 8px; border: 1px solid #cbd5e1; border-radius: 5px; background: #ffffff; box-shadow: 0 1px 2px rgba(0,0,0,0.04); transition: margin-left 0.15s ease;">
                                    
                                    <!-- Bar Header -->
                                    <div class="menu-item-bar" style="display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; gap: 10px; background: #ffffff; border-radius: 5px;">
                                        <div class="menu-item-left" style="display: flex; align-items: center; gap: 8px; min-width: 0; flex: 1 1 auto;">
                                            <span class="menu-drag-handle" title="Drag to reorder or nest" style="cursor: grab; font-size: 16px; color: #64748b; padding: 2px 4px; user-select: none;">☰</span>
                                            <span class="menu-branch-indicator" style="display: <?php echo $depth > 0 ? 'inline' : 'none'; ?>; font-family: monospace; font-weight: bold; color: #64748b; font-size: 13px;">└─</span>
                                            <strong class="menu-item-title-display" style="font-size: 14px; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                <?php echo htmlspecialchars($item->title, ENT_QUOTES, 'UTF-8'); ?>
                                            </strong>
                                            <span class="menu-sub-item-badge" style="display: <?php echo $depth > 0 ? 'inline-block' : 'none'; ?>; font-size: 11px; background: #e2e8f0; color: #475569; padding: 1px 6px; border-radius: 3px; font-weight: 500;">sub item</span>
                                            <span class="menu-item-url-display" style="color: #64748b; font-size: 12px; margin-left: 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 160px;">
                                                <?php echo htmlspecialchars($item->url ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </div>

                                        <!-- Right Controls: [ ↑ ] [ ↓ ] [ Edit ] [ Remove ] -->
                                        <div class="menu-item-actions" style="display: flex; align-items: center; gap: 4px; flex-shrink: 0;">
                                            <button type="button" class="btn-menu-action btn-move-up" title="Move Up" style="padding: 2px 8px; font-size: 12px; height: 28px; border: 1px solid #cbd5e1; background: #f8fafc; color: #334155; border-radius: 4px; cursor: pointer;">↑</button>
                                            <button type="button" class="btn-menu-action btn-move-down" title="Move Down" style="padding: 2px 8px; font-size: 12px; height: 28px; border: 1px solid #cbd5e1; background: #f8fafc; color: #334155; border-radius: 4px; cursor: pointer;">↓</button>
                                            <button type="button" class="btn-menu-action btn-menu-edit" title="Edit" style="padding: 2px 10px; font-size: 12px; height: 28px; border: 1px solid #bfdbfe; background: #eff6ff; color: #1d4ed8; border-radius: 4px; cursor: pointer; font-weight: 500;">Edit</button>
                                            <button type="button" class="btn-menu-action btn-menu-remove" title="Remove" style="padding: 2px 10px; font-size: 12px; height: 28px; border: 1px solid #fecaca; background: #fef2f2; color: #dc2626; border-radius: 4px; cursor: pointer; font-weight: 500;">Remove</button>
                                        </div>
                                    </div>

                                    <!-- Collapsible Inline Editor -->
                                    <div class="menu-item-editor" style="display: none; padding: 14px 16px; background: #f8fafc; border-top: 1px solid #e2e8f0; border-radius: 0 0 5px 5px;">
                                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 12px;">
                                            <div>
                                                <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Navigation Label</label>
                                                <input type="text" class="form-control item-edit-title" value="<?php echo htmlspecialchars($item->title, ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; font-size: 13px;">
                                            </div>
                                            <div>
                                                <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">URL</label>
                                                <input type="text" class="form-control item-edit-url" value="<?php echo htmlspecialchars($item->url ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; font-size: 13px;">
                                            </div>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: #64748b; padding-top: 4px;">
                                            <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; user-select: none;">
                                                <input type="checkbox" class="item-edit-target" value="_blank" <?php echo (($item->target ?? '') === '_blank') ? 'checked' : ''; ?>>
                                                Open link in a new tab
                                            </label>
                                            <div style="display: flex; gap: 8px;">
                                                <button type="button" class="btn-menu-action btn-menu-outdent" title="Move to top level" style="padding: 2px 8px; font-size: 11px;">◀ Outdent</button>
                                                <button type="button" class="btn-menu-action btn-menu-indent" title="Indent under previous item" style="padding: 2px 8px; font-size: 11px;">▶ Indent</button>
                                                <button type="button" class="btn-menu-action btn-menu-close-edit" style="padding: 2px 8px; font-size: 11px;">Done</button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Hidden Inputs -->
                                    <input type="hidden" class="hidden-item-id" name="items[<?php echo $index; ?>][id]" value="<?php echo (int)$item->id; ?>">
                                    <input type="hidden" class="hidden-item-parent-id" name="items[<?php echo $index; ?>][parent_id]" value="<?php echo $parentId; ?>">
                                    <input type="hidden" class="hidden-item-sort-order" name="items[<?php echo $index; ?>][sort_order]" value="<?php echo (int)($item->sort_order ?? ($index + 1)); ?>">
                                    <input type="hidden" class="hidden-item-title" name="items[<?php echo $index; ?>][title]" value="<?php echo htmlspecialchars($item->title, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" class="hidden-item-url" name="items[<?php echo $index; ?>][url]" value="<?php echo htmlspecialchars($item->url ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" class="hidden-item-target" name="items[<?php echo $index; ?>][target]" value="<?php echo htmlspecialchars($item->target ?? '_self', ENT_QUOTES, 'UTF-8'); ?>">
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <!-- Menu Location Assignment -->
                <div style="border-top: 1px solid var(--wp-border, #ccd0d4); padding-top: 16px; margin-top: 16px;">
                    <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 10px;">Menu Settings</h3>
                    <div class="form-group">
                        <label style="font-weight: 500; font-size: 13px;">Display Location</label>
                        <div style="margin-top: 6px;">
                            <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; margin-bottom: 6px; cursor: pointer;">
                                <input type="radio" name="location" value="primary" <?php echo ($selectedMenu->location === 'primary') ? 'checked' : ''; ?>>
                                Primary Header Navigation
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; margin-bottom: 6px; cursor: pointer;">
                                <input type="radio" name="location" value="footer" <?php echo ($selectedMenu->location === 'footer') ? 'checked' : ''; ?>>
                                Footer Menu
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer;">
                                <input type="radio" name="location" value="" <?php echo empty($selectedMenu->location) ? 'checked' : ''; ?>>
                                None / Unassigned
                            </label>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px;">
                        <button type="submit" form="core-action-form" formmethod="POST" formnovalidate formaction="<?php echo htmlspecialchars(site_base_path(), ENT_QUOTES, 'UTF-8'); ?>/admin/menus/delete?menu=<?php echo (int)$selectedMenu->id; ?>" class="core-action-link" onclick="return confirm('Delete this menu completely?');" style="color: var(--wp-danger, #dc2626); font-size: 13px;">Delete Menu</button>
                        <button type="submit" class="btn btn-primary" style="font-size: 14px; font-weight: 600; padding: 6px 18px;">Save Menu</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

<script>
(function() {
    'use strict';
    var list = document.getElementById('menu-items-list');
    if (!list) return;

    var INDENT_PX = 36;
    var MAX_DEPTH = 3;

    function getRows() {
        return Array.from(list.querySelectorAll('.menu-item-row'));
    }

    function getSubtree(row) {
        var depth = parseInt(row.dataset.depth, 10) || 0;
        var items = [row];
        var next = row.nextElementSibling;
        while (next && next.classList.contains('menu-item-row')) {
            var nextDepth = parseInt(next.dataset.depth, 10) || 0;
            if (nextDepth > depth) {
                items.push(next);
                next = next.nextElementSibling;
            } else {
                break;
            }
        }
        return items;
    }

    function updateHierarchy() {
        var rows = getRows();
        var parentStack = []; // stores { id, depth }

        rows.forEach(function(row, index) {
            var currentDepth = parseInt(row.dataset.depth, 10) || 0;
            var prevRow = rows[index - 1] || null;
            var prevDepth = prevRow ? (parseInt(prevRow.dataset.depth, 10) || 0) : -1;

            // Rule 1: The first item must always be top-level (depth 0)
            if (index === 0) {
                currentDepth = 0;
            } else {
                // Rule 2: Cannot indent more than 1 level deeper than the immediate predecessor
                if (currentDepth > prevDepth + 1) {
                    currentDepth = prevDepth + 1;
                }
            }
            if (currentDepth < 0) currentDepth = 0;
            if (currentDepth > MAX_DEPTH) currentDepth = MAX_DEPTH;

            row.dataset.depth = String(currentDepth);

            // Determine parent ID from parentStack
            while (parentStack.length > 0 && parentStack[parentStack.length - 1].depth >= currentDepth) {
                parentStack.pop();
            }

            var parentId = '0';
            if (currentDepth > 0 && parentStack.length > 0) {
                parentId = parentStack[parentStack.length - 1].id;
            }
            row.dataset.parentId = parentId;

            // Push current item to parentStack for potential future children
            parentStack.push({ id: row.dataset.id, depth: currentDepth });

            // Update visuals
            row.style.marginLeft = (currentDepth * INDENT_PX) + 'px';
            var branch = row.querySelector('.menu-branch-indicator');
            if (branch) branch.style.display = currentDepth > 0 ? 'inline' : 'none';
            var badge = row.querySelector('.menu-sub-item-badge');
            if (badge) badge.style.display = currentDepth > 0 ? 'inline-block' : 'none';

            // Update hidden inputs
            var hiddenId = row.querySelector('.hidden-item-id');
            var hiddenParent = row.querySelector('.hidden-item-parent-id');
            var hiddenOrder = row.querySelector('.hidden-item-sort-order');
            var hiddenTitle = row.querySelector('.hidden-item-title');
            var hiddenUrl = row.querySelector('.hidden-item-url');
            var hiddenTarget = row.querySelector('.hidden-item-target');

            if (hiddenId) hiddenId.name = 'items[' + index + '][id]';
            if (hiddenParent) {
                hiddenParent.name = 'items[' + index + '][parent_id]';
                hiddenParent.value = parentId;
            }
            if (hiddenOrder) {
                hiddenOrder.name = 'items[' + index + '][sort_order]';
                hiddenOrder.value = String(index + 1);
            }
            if (hiddenTitle) hiddenTitle.name = 'items[' + index + '][title]';
            if (hiddenUrl) hiddenUrl.name = 'items[' + index + '][url]';
            if (hiddenTarget) hiddenTarget.name = 'items[' + index + '][target]';

            // Enable/disable Up and Down buttons
            var btnUp = row.querySelector('.btn-move-up');
            var btnDown = row.querySelector('.btn-move-down');
            if (btnUp) btnUp.disabled = (index === 0);
            if (btnDown) btnDown.disabled = (index === rows.length - 1);

            // Update Outdent / Indent button states in editor
            var btnOutdent = row.querySelector('.btn-menu-outdent');
            var btnIndent = row.querySelector('.btn-menu-indent');
            if (btnOutdent) btnOutdent.disabled = (currentDepth === 0);
            if (btnIndent) btnIndent.disabled = (!prevRow || currentDepth >= prevDepth + 1 || currentDepth >= MAX_DEPTH);
        });
    }

    // Moving up
    function moveUp(row) {
        var rows = getRows();
        var index = rows.indexOf(row);
        if (index <= 0) return;

        var block = getSubtree(row);
        var target = rows[index - 1];
        var rowDepth = parseInt(row.dataset.depth, 10) || 0;

        for (var i = index - 1; i >= 0; i--) {
            var cand = rows[i];
            var candDepth = parseInt(cand.dataset.depth, 10) || 0;
            if (candDepth <= rowDepth) {
                target = cand;
                break;
            }
        }

        if (target) {
            for (var j = 0; j < block.length; j++) {
                list.insertBefore(block[j], target);
            }
            updateHierarchy();
        }
    }

    // Moving down
    function moveDown(row) {
        var rows = getRows();
        var index = rows.indexOf(row);
        if (index < 0 || index >= rows.length - 1) return;

        var block = getSubtree(row);
        var lastInBlock = block[block.length - 1];
        var next = lastInBlock.nextElementSibling;
        if (!next || !next.classList.contains('menu-item-row')) return;

        var nextBlock = getSubtree(next);
        var lastInNextBlock = nextBlock[nextBlock.length - 1];

        var insertRef = lastInNextBlock.nextElementSibling;
        for (var j = 0; j < block.length; j++) {
            list.insertBefore(block[j], insertRef);
        }
        updateHierarchy();
    }

    // Outdent
    function outdent(row) {
        var curDepth = parseInt(row.dataset.depth, 10) || 0;
        if (curDepth <= 0) return;
        var block = getSubtree(row);
        block.forEach(function(item) {
            var d = parseInt(item.dataset.depth, 10) || 0;
            item.dataset.depth = String(Math.max(0, d - 1));
        });
        updateHierarchy();
    }

    // Indent
    function indent(row) {
        var rows = getRows();
        var index = rows.indexOf(row);
        if (index <= 0) return;
        var prev = rows[index - 1];
        var prevDepth = parseInt(prev.dataset.depth, 10) || 0;
        var curDepth = parseInt(row.dataset.depth, 10) || 0;
        if (curDepth < prevDepth + 1 && curDepth < MAX_DEPTH) {
            var block = getSubtree(row);
            block.forEach(function(item) {
                var d = parseInt(item.dataset.depth, 10) || 0;
                item.dataset.depth = String(Math.min(MAX_DEPTH, d + 1));
            });
            updateHierarchy();
        }
    }

    // Remove
    function remove(row) {
        var titleDisplay = row.querySelector('.menu-item-title-display');
        var title = titleDisplay ? titleDisplay.textContent.trim() : 'this item';
        if (!confirm('Remove "' + title + '" from the menu?')) {
            return;
        }

        // Promote any direct children
        var curDepth = parseInt(row.dataset.depth, 10) || 0;
        var next = row.nextElementSibling;
        while (next && next.classList.contains('menu-item-row')) {
            var nextDepth = parseInt(next.dataset.depth, 10) || 0;
            if (nextDepth > curDepth) {
                next.dataset.depth = String(Math.max(0, nextDepth - 1));
                next = next.nextElementSibling;
            } else {
                break;
            }
        }

        // Add to deleted_items input
        var form = document.getElementById('menu-structure-form');
        if (form) {
            var delInput = document.createElement('input');
            delInput.type = 'hidden';
            delInput.name = 'deleted_items[]';
            delInput.value = row.dataset.id;
            form.appendChild(delInput);
        }

        row.remove();
        updateHierarchy();
    }

    // Event delegation
    list.addEventListener('click', function(e) {
        var target = e.target;
        var row = target.closest('.menu-item-row');
        if (!row) return;

        if (target.closest('.btn-move-up')) {
            e.preventDefault();
            moveUp(row);
        } else if (target.closest('.btn-move-down')) {
            e.preventDefault();
            moveDown(row);
        } else if (target.closest('.btn-menu-edit')) {
            e.preventDefault();
            var editor = row.querySelector('.menu-item-editor');
            if (editor) {
                var isOpen = (editor.style.display !== 'none');
                editor.style.display = isOpen ? 'none' : 'block';
                target.textContent = isOpen ? 'Edit' : 'Close';
                if (!isOpen) {
                    var titleInput = editor.querySelector('.item-edit-title');
                    if (titleInput) titleInput.focus();
                }
            }
        } else if (target.closest('.btn-menu-remove')) {
            e.preventDefault();
            remove(row);
        } else if (target.closest('.btn-menu-outdent')) {
            e.preventDefault();
            outdent(row);
        } else if (target.closest('.btn-menu-indent')) {
            e.preventDefault();
            indent(row);
        } else if (target.closest('.btn-menu-close-edit')) {
            e.preventDefault();
            var editor2 = row.querySelector('.menu-item-editor');
            if (editor2) editor2.style.display = 'none';
            var editBtn = row.querySelector('.btn-menu-edit');
            if (editBtn) editBtn.textContent = 'Edit';
        }
    });

    // Input sync for title, url and target
    list.addEventListener('input', function(e) {
        var row = e.target.closest('.menu-item-row');
        if (!row) return;

        if (e.target.classList.contains('item-edit-title')) {
            var titleDisplay = row.querySelector('.menu-item-title-display');
            var hiddenTitle = row.querySelector('.hidden-item-title');
            var val = e.target.value.trim() || 'Untitled';
            if (titleDisplay) titleDisplay.textContent = val;
            if (hiddenTitle) hiddenTitle.value = e.target.value;
        } else if (e.target.classList.contains('item-edit-url')) {
            var urlDisplay = row.querySelector('.menu-item-url-display');
            var hiddenUrl = row.querySelector('.hidden-item-url');
            if (urlDisplay) urlDisplay.textContent = e.target.value;
            if (hiddenUrl) hiddenUrl.value = e.target.value;
        }
    });

    list.addEventListener('change', function(e) {
        var row = e.target.closest('.menu-item-row');
        if (!row) return;
        if (e.target.classList.contains('item-edit-target')) {
            var hiddenTarget = row.querySelector('.hidden-item-target');
            if (hiddenTarget) hiddenTarget.value = e.target.checked ? '_blank' : '_self';
        }
    });

    // Drag and drop
    var draggedRow = null;
    var draggedBlock = [];
    var placeholder = document.createElement('li');
    placeholder.className = 'menu-drop-placeholder';
    placeholder.style.height = '44px';
    placeholder.style.marginBottom = '8px';
    placeholder.style.border = '2px dashed #3b82f6';
    placeholder.style.borderRadius = '5px';
    placeholder.style.background = 'rgba(59, 130, 246, 0.08)';
    placeholder.style.display = 'flex';
    placeholder.style.alignItems = 'center';
    placeholder.style.paddingLeft = '14px';
    placeholder.style.fontSize = '12px';
    placeholder.style.color = '#2563eb';
    placeholder.style.fontWeight = '500';

    list.querySelectorAll('.menu-item-row').forEach(function(row) {
        row.setAttribute('draggable', 'true');
    });

    list.addEventListener('dragstart', function(e) {
        var row = e.target.closest('.menu-item-row');
        if (!row) return;

        if (['INPUT', 'SELECT', 'BUTTON', 'TEXTAREA'].includes(e.target.tagName)) {
            e.preventDefault();
            return;
        }

        draggedRow = row;
        draggedBlock = getSubtree(row);
        row.classList.add('is-dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', row.dataset.id);
    });

    list.addEventListener('dragover', function(e) {
        e.preventDefault();
        if (!draggedRow) return;
        e.dataTransfer.dropEffect = 'move';

        var targetRow = e.target.closest('.menu-item-row');
        var listRect = list.getBoundingClientRect();
        var cursorX = e.clientX - listRect.left;

        if (targetRow && targetRow !== draggedRow && !draggedBlock.includes(targetRow)) {
            var targetRect = targetRow.getBoundingClientRect();
            var isAfter = (e.clientY - targetRect.top) > (targetRect.height / 2);
            var targetDepth = parseInt(targetRow.dataset.depth, 10) || 0;

            var targetNestDepth = targetDepth;
            if (isAfter) {
                if (cursorX > (targetDepth + 1) * INDENT_PX) {
                    targetNestDepth = Math.min(MAX_DEPTH, targetDepth + 1);
                } else if (cursorX < targetDepth * INDENT_PX) {
                    targetNestDepth = Math.max(0, targetDepth - 1);
                }
                targetRow.after(placeholder);
            } else {
                targetRow.before(placeholder);
            }

            placeholder.dataset.depth = String(targetNestDepth);
            placeholder.style.marginLeft = (targetNestDepth * INDENT_PX) + 'px';
            placeholder.innerHTML = targetNestDepth > 0 
                ? '<span>└─ ⇲ Drop as submenu</span>' 
                : '<span>⇲ Drop here</span>';
        } else if (!targetRow && getRows().length === 0) {
            list.appendChild(placeholder);
            placeholder.dataset.depth = '0';
            placeholder.style.marginLeft = '0px';
        }
    });

    list.addEventListener('drop', function(e) {
        e.preventDefault();
        if (!draggedRow || !placeholder.parentNode) return;

        var newDepth = parseInt(placeholder.dataset.depth, 10) || 0;
        var oldDepth = parseInt(draggedRow.dataset.depth, 10) || 0;
        var diff = newDepth - oldDepth;

        for (var i = 0; i < draggedBlock.length; i++) {
            var item = draggedBlock[i];
            var curD = parseInt(item.dataset.depth, 10) || 0;
            item.dataset.depth = String(Math.max(0, curD + diff));
            placeholder.before(item);
        }

        placeholder.remove();
        draggedRow.classList.remove('is-dragging');
        draggedRow = null;
        draggedBlock = [];

        updateHierarchy();
    });

    list.addEventListener('dragend', function(e) {
        if (draggedRow) {
            draggedRow.classList.remove('is-dragging');
            draggedRow = null;
        }
        draggedBlock = [];
        if (placeholder.parentNode) {
            placeholder.remove();
        }
        updateHierarchy();
    });

    // Initialize hierarchy on load
    updateHierarchy();
})();
</script>
<?php endif; ?>
