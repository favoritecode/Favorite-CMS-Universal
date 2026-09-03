<?php
$isEdit = !empty($page);
$action = $isEdit ? '/admin/pages/update' : '/admin/pages/store';
?>
<div class="page-header">
    <h1 class="page-title"><?php echo $isEdit ? 'Edit Page' : 'Add New Page'; ?></h1>
    <?php if ($isEdit): ?>
        <a href="/admin/pages/new" class="btn btn-secondary">Add New</a>
        <a href="/page/<?php echo htmlspecialchars($page->slug, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="btn btn-secondary">View Page</a>
    <?php endif; ?>
</div>

<form method="POST" action="<?php echo $action; ?>">
    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?php echo (int)$page->id; ?>">
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: 1fr 280px; gap: 20px;">
        <!-- Left Main Column -->
        <div>
            <div class="form-group">
                <input type="text" name="title" class="form-control" style="font-size: 18px; padding: 10px 12px; font-weight: 600;" placeholder="Add title" value="<?php echo htmlspecialchars($page->title ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>

            <div class="form-group">
                <label for="slug">Permalink / URL Slug</label>
                <input type="text" id="slug" name="slug" class="form-control" placeholder="auto-generated-from-title" value="<?php echo htmlspecialchars($page->slug ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <div class="form-group">
                <label for="content">Page Content</label>
                <textarea id="content" name="content" class="form-control" style="min-height: 360px;" placeholder="Write your page content here..."><?php echo htmlspecialchars($page->content ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <!-- SEO Settings Box -->
            <div class="form-card" style="margin-top: 24px;">
                <h3 style="font-size: 15px; margin-bottom: 14px; font-weight: 600; border-bottom: 1px solid var(--wp-border); padding-bottom: 8px;">
                    Search Engine Optimization (SEO)
                </h3>
                <div class="form-group">
                    <label for="meta_title">SEO Meta Title</label>
                    <input type="text" id="meta_title" name="meta_title" class="form-control" value="<?php echo htmlspecialchars($seo->meta_title ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Custom title tag for search engines">
                </div>
                <div class="form-group">
                    <label for="meta_description">Meta Description</label>
                    <textarea id="meta_description" name="meta_description" class="form-control" style="min-height: 60px;" placeholder="Brief description for search engine snippets..."><?php echo htmlspecialchars($seo->meta_description ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="og_title">Social (Open Graph) Title</label>
                        <input type="text" id="og_title" name="og_title" class="form-control" value="<?php echo htmlspecialchars($seo->og_title ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="og_description">Social Description</label>
                        <input type="text" id="og_description" name="og_description" class="form-control" value="<?php echo htmlspecialchars($seo->og_description ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Sidebar Column -->
        <div>
            <!-- Publish Box -->
            <div class="form-card" style="margin-bottom: 20px;">
                <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 12px;">Publish</h3>
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" class="form-control">
                        <option value="draft" <?php echo ($page?->status === 'draft' || empty($page)) ? 'selected' : ''; ?>>Draft</option>
                        <option value="published" <?php echo ($page?->status === 'published') ? 'selected' : ''; ?>>Published</option>
                    </select>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 16px;">
                    <?php if ($isEdit): ?>
                        <a href="/admin/pages/trash?id=<?php echo (int)$page->id; ?>" style="color: var(--wp-danger); font-size: 12px;">Move to Trash</a>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary">
                        <?php echo $isEdit ? 'Update Page' : 'Publish / Save'; ?>
                    </button>
                </div>
            </div>

            <!-- Page Attributes Box -->
            <div class="form-card" style="margin-bottom: 20px;">
                <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 10px;">Page Attributes</h3>
                <div class="form-group">
                    <label for="parent_id">Parent Page</label>
                    <select id="parent_id" name="parent_id" class="form-control">
                        <option value="0">&mdash; No Parent (Top Level) &mdash;</option>
                        <?php foreach ($allPages as $p): ?>
                            <?php if ($isEdit && $p->id == $page->id) continue; ?>
                            <option value="<?php echo (int)$p->id; ?>" <?php echo ($page?->parent_id == $p->id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($p->title, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="menu_order">Order</label>
                    <input type="number" id="menu_order" name="menu_order" class="form-control" value="<?php echo (int)($page->menu_order ?? 0); ?>">
                    <span class="description">Higher numbers appear later in lists.</span>
                </div>
            </div>

            <!-- Featured Image Box -->
            <div class="form-card">
                <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 10px;">Featured Image</h3>
                <div class="form-group">
                    <select name="featured_image_id" class="form-control">
                        <option value="0">&mdash; None &mdash;</option>
                        <?php foreach ($mediaItems as $media): ?>
                            <option value="<?php echo (int)$media->id; ?>" <?php echo ($page?->featured_image_id == $media->id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($media->filename, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>
</form>

