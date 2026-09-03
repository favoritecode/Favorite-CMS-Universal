<div class="page-header">
    <h1 class="page-title">Navigation Menus</h1>
</div>

<!-- Menu Selector / Create Menu -->
<div class="form-card" style="margin-bottom: 20px; padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
    <?php if (!empty($menus)): ?>
        <form method="GET" action="/admin/menus" style="display: flex; gap: 8px; align-items: center;">
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
    <form method="POST" action="/admin/menus/create" style="display: flex; gap: 8px; align-items: center;">
        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        <input type="text" name="name" class="form-control" placeholder="Menu Name (e.g. Main Menu)" required style="width: 200px;">
        <button type="submit" class="btn btn-secondary">+ Create Menu</button>
    </form>
</div>

<?php if (!$selectedMenu): ?>
    <div class="form-card" style="text-align: center; color: var(--wp-text-muted); padding: 40px;">
        No menus created yet. Enter a menu name above and click "+ Create Menu" to get started.
    </div>
<?php else: ?>
    <div style="display: grid; grid-template-columns: 280px 1fr; gap: 20px; align-items: start;">
        <!-- Left: Add Items -->
        <div>
            <!-- Custom Link Box -->
            <div class="form-card" style="margin-bottom: 16px;">
                <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 12px;">Add Custom Link</h3>
                <form method="POST" action="/admin/menus/item/add">
                    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="menu_id" value="<?php echo (int)$selectedMenu->id; ?>">

                    <div class="form-group">
                        <label for="url">URL</label>
                        <input type="text" id="url" name="url" class="form-control" value="https://" required>
                    </div>

                    <div class="form-group">
                        <label for="title">Link Text</label>
                        <input type="text" id="title" name="title" class="form-control" placeholder="e.g. Blog" required>
                    </div>

                    <button type="submit" class="btn btn-secondary">Add to Menu</button>
                </form>
            </div>

            <!-- Add from Pages -->
            <?php if (!empty($pages)): ?>
                <div class="form-card">
                    <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 12px;">Add from Pages</h3>
                    <?php foreach ($pages as $p): ?>
                        <form method="POST" action="/admin/menus/item/add" style="margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
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

        <!-- Right: Menu Structure -->
        <div class="form-card">
            <h2 style="font-size: 16px; font-weight: 600; margin-bottom: 12px;">
                Menu Structure &mdash; <?php echo htmlspecialchars($selectedMenu->name, ENT_QUOTES, 'UTF-8'); ?>
            </h2>

            <div style="background: #fafafa; border: 1px solid var(--wp-border); border-radius: 4px; padding: 12px; margin-bottom: 20px;">
                <?php if (empty($menuItems)): ?>
                    <p style="color: var(--wp-text-muted); font-size: 13px; text-align: center; padding: 20px;">
                        There are no items in this menu yet. Use the panel on the left to add items.
                    </p>
                <?php else: ?>
                    <ul style="list-style: none;">
                        <?php foreach ($menuItems as $item): ?>
                            <li style="background: #fff; border: 1px solid var(--wp-border); border-radius: 4px; padding: 10px 14px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <strong><?php echo htmlspecialchars($item->title, ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <span style="color: var(--wp-text-muted); font-size: 12px; margin-left: 8px;"><?php echo htmlspecialchars($item->url ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                                <div>
                                    <a href="/admin/menus/item/delete?id=<?php echo (int)$item->id; ?>&menu=<?php echo (int)$selectedMenu->id; ?>" style="color: var(--wp-danger); font-size: 12px;">Remove</a>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- Menu Location Assignment -->
            <form method="POST" action="/admin/menus/location" style="border-top: 1px solid var(--wp-border); padding-top: 16px; margin-top: 16px;">
                <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="menu_id" value="<?php echo (int)$selectedMenu->id; ?>">

                <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 10px;">Menu Settings</h3>
                <div class="form-group">
                    <label>Display Location</label>
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
                    <a href="/admin/menus/delete?menu=<?php echo (int)$selectedMenu->id; ?>" onclick="return confirm('Delete this menu completely?');" style="color: var(--wp-danger); font-size: 13px;">Delete Menu</a>
                    <button type="submit" class="btn btn-primary">Save Menu</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

