<div class="page-header">
    <h1 class="page-title"><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
</div>

<div style="display: grid; grid-template-columns: 320px 1fr; gap: 24px; align-items: start;">
    <!-- Add New Form -->
    <div class="form-card">
        <h2 style="font-size: 15px; font-weight: 600; margin-bottom: 14px;">Add New <?php echo $taxonomyType === 'tag' ? 'Tag' : 'Category'; ?></h2>
        <form method="POST" action="/admin/taxonomies/store">
            <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="taxonomy" value="<?php echo htmlspecialchars($taxonomyType, ENT_QUOTES, 'UTF-8'); ?>">

            <div class="form-group">
                <label for="name">Name *</label>
                <input type="text" id="name" name="name" class="form-control" required>
                <span class="description">The name is how it appears on your site.</span>
            </div>

            <div class="form-group">
                <label for="slug">Slug</label>
                <input type="text" id="slug" name="slug" class="form-control" placeholder="auto-generated">
                <span class="description">The "slug" is the URL-friendly version of the name.</span>
            </div>

            <?php if ($taxonomyType === 'category'): ?>
                <div class="form-group">
                    <label for="parent_id">Parent Category</label>
                    <select id="parent_id" name="parent_id" class="form-control">
                        <option value="0">&mdash; None &mdash;</option>
                        <?php foreach ($items as $item): ?>
                            <option value="<?php echo (int)$item->id; ?>"><?php echo htmlspecialchars($item->name, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" class="form-control" rows="3"></textarea>
            </div>

            <button type="submit" class="btn btn-primary">Add New <?php echo $taxonomyType === 'tag' ? 'Tag' : 'Category'; ?></button>
        </form>
    </div>

    <!-- Table of existing items -->
    <div class="wp-table-wrap">
        <table class="wp-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Slug</th>
                    <th>Count</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; color: var(--wp-text-muted); padding: 24px;">No items found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td>
                                <strong>
                                    <?php echo htmlspecialchars($item->name, ENT_QUOTES, 'UTF-8'); ?>
                                </strong>
                                <div class="row-actions">
                                    <a href="/<?php echo $taxonomyType; ?>/<?php echo htmlspecialchars($item->slug, ENT_QUOTES, 'UTF-8'); ?>" target="_blank">View</a>
                                    <?php if ($item->slug !== 'uncategorized'): ?>
                                        <?php $csrfToken = htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                        | <a href="/admin/taxonomies/delete?id=<?php echo (int)$item->id; ?>&_token=<?php echo $csrfToken; ?>" onclick="return confirm('Delete this <?php echo $taxonomyType; ?>?');" style="color: var(--wp-danger);">Delete</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($item->description ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><code><?php echo htmlspecialchars($item->slug, ENT_QUOTES, 'UTF-8'); ?></code></td>
                            <td><?php echo (int)($item->post_count ?? 0); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

