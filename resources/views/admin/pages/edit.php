<?php
$isEdit = !empty($page);
$action = $isEdit ? '/admin/pages/update' : '/admin/pages/store';
$pageId = (int)($page->id ?? 0);
?>
<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
    <h1 class="page-title"><?php echo $isEdit ? 'Edit Page' : 'Add New Page'; ?></h1>
    <div style="display: flex; gap: 8px;">
        <?php if ($isEdit): ?>
            <a href="/admin/pages/new" class="btn btn-secondary">Add New</a>
            <a href="/page/<?php echo htmlspecialchars($page->slug, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="btn btn-secondary">View Page &#8599;</a>
        <?php endif; ?>
    </div>
</div>

<form id="page-editor-form" method="POST" action="<?php echo $action; ?>">
    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?php echo $pageId; ?>">
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: 1fr 310px; gap: 24px;">
        <!-- Left Main Column -->
        <div>
            <!-- Title -->
            <div class="form-group" style="margin-bottom: 8px;">
                <input type="text" id="page-title" name="title" class="form-control" style="font-size: 20px; padding: 12px 14px; font-weight: 700; border-radius: 4px;" placeholder="Add title..." value="<?php echo htmlspecialchars($page->title ?? '', ENT_QUOTES, 'UTF-8'); ?>" required autofocus>
            </div>

            <!-- Permalink / Slug -->
            <div style="margin-bottom: 18px; font-size: 13px; color: var(--wp-text-muted); display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                <span><strong>Permalink:</strong> <?php echo htmlspecialchars(env('APP_URL', 'http://favorite-cms.local')); ?>/page/</span>
                <input type="text" id="page-slug" name="slug" class="form-control" style="padding: 2px 8px; font-size: 12px; width: 220px;" placeholder="auto-generated" value="<?php echo htmlspecialchars($page->slug ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <!-- Dual-Mode Editor -->
            <div class="editor-wrapper" style="background: #ffffff; border: 1px solid var(--wp-border); border-radius: 6px; overflow: hidden; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <!-- Mode Switcher -->
                <div style="background: #f8fafc; border-bottom: 1px solid var(--wp-border); padding: 8px 12px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div style="display: flex; gap: 4px; background: #e2e8f0; padding: 3px; border-radius: 6px;">
                        <button type="button" id="page-mode-visual-btn" class="mode-tab-btn active" style="padding: 5px 14px; font-size: 12px; font-weight: 600; border: none; border-radius: 4px; cursor: pointer; background: #ffffff; color: var(--wp-blue);">
                            &#9998; Visual Mode
                        </button>
                        <button type="button" id="page-mode-code-btn" class="mode-tab-btn" style="padding: 5px 14px; font-size: 12px; font-weight: 600; border: none; border-radius: 4px; cursor: pointer; background: transparent; color: var(--wp-text-muted);">
                            &lt;/&gt; Code Mode
                        </button>
                    </div>
                </div>

                <!-- Visual Mode Toolbar -->
                <div id="page-visual-toolbar" style="background: #ffffff; border-bottom: 1px solid var(--wp-border); padding: 6px 10px; display: flex; gap: 4px; flex-wrap: wrap; align-items: center;">
                    <select id="page-format-block-select" style="border: 1px solid var(--wp-border); border-radius: 4px; padding: 3px 6px; font-size: 12px;">
                        <option value="p">Paragraph</option>
                        <option value="h1">Heading 1</option>
                        <option value="h2">Heading 2</option>
                        <option value="h3">Heading 3</option>
                        <option value="h4">Heading 4</option>
                        <option value="pre">Preformatted</option>
                    </select>
                    <button type="button" class="page-rich-btn" data-cmd="bold" style="padding: 3px 8px; font-size: 12px; border: 1px solid var(--wp-border); border-radius: 3px; background: #ffffff; cursor: pointer;"><strong>B</strong></button>
                    <button type="button" class="page-rich-btn" data-cmd="italic" style="padding: 3px 8px; font-size: 12px; border: 1px solid var(--wp-border); border-radius: 3px; background: #ffffff; cursor: pointer;"><em>I</em></button>
                    <button type="button" class="page-rich-btn" data-cmd="underline" style="padding: 3px 8px; font-size: 12px; border: 1px solid var(--wp-border); border-radius: 3px; background: #ffffff; cursor: pointer;"><u>U</u></button>
                    <button type="button" class="page-rich-btn" data-cmd="justifyLeft" style="padding: 3px 8px; font-size: 12px; border: 1px solid var(--wp-border); border-radius: 3px; background: #ffffff; cursor: pointer;">&#9776;</button>
                    <button type="button" class="page-rich-btn" data-cmd="justifyCenter" style="padding: 3px 8px; font-size: 12px; border: 1px solid var(--wp-border); border-radius: 3px; background: #ffffff; cursor: pointer;">&#9868;</button>
                    <button type="button" class="page-rich-btn" data-cmd="insertUnorderedList" style="padding: 3px 8px; font-size: 12px; border: 1px solid var(--wp-border); border-radius: 3px; background: #ffffff; cursor: pointer;">&bull; List</button>
                    <button type="button" class="page-rich-btn" data-cmd="insertOrderedList" style="padding: 3px 8px; font-size: 12px; border: 1px solid var(--wp-border); border-radius: 3px; background: #ffffff; cursor: pointer;">1. List</button>
                    <button type="button" class="page-rich-btn" data-cmd="insertHorizontalRule" style="padding: 3px 8px; font-size: 12px; border: 1px solid var(--wp-border); border-radius: 3px; background: #ffffff; cursor: pointer;">&mdash;</button>
                </div>

                <!-- Visual Mode Container -->
                <div id="page-visual-container" style="display: block; padding: 20px 24px; min-height: 380px; background: #ffffff; cursor: text;">
                    <div id="page-visual-editor" contenteditable="true" style="outline: none; min-height: 340px; font-size: 15px; line-height: 1.7; color: #1e293b;">
                        <?php echo $page->content ?? '<p></p>'; ?>
                    </div>
                </div>

                <!-- Code Mode Container -->
                <div id="page-code-container" style="display: none; background: #1e293b;">
                    <textarea id="page-code-editor" style="width: 100%; min-height: 380px; background: #1e293b; color: #f8fafc; font-family: Consolas, Monaco, monospace; font-size: 13px; line-height: 1.6; border: none; padding: 14px; outline: none; resize: vertical; tab-size: 2;"><?php echo htmlspecialchars($page->content ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <!-- Canonical form textarea -->
                <textarea id="page-content" name="content" style="display: none;"><?php echo htmlspecialchars($page->content ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <!-- SEO Settings Box -->
            <div class="form-card">
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
                        <a href="/admin/pages/trash?id=<?php echo $pageId; ?>" style="color: var(--wp-danger); font-size: 12px;" onclick="return confirm('Move this page to trash?');">Move to Trash</a>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>
                    <button type="submit" id="page-save-btn" class="btn btn-primary">
                        <?php echo $isEdit ? 'Update Page' : 'Publish Page'; ?>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    var titleIn     = document.getElementById('page-title');
    var slugIn      = document.getElementById('page-slug');
    var visEdit     = document.getElementById('page-visual-editor');
    var codeEdit    = document.getElementById('page-code-editor');
    var canonical   = document.getElementById('page-content');
    var visBtn      = document.getElementById('page-mode-visual-btn');
    var codeBtn     = document.getElementById('page-mode-code-btn');
    var visWrap     = document.getElementById('page-visual-container');
    var codeWrap    = document.getElementById('page-code-container');
    var visTool     = document.getElementById('page-visual-toolbar');
    var formatSel   = document.getElementById('page-format-block-select');
    var saveBtn     = document.getElementById('page-save-btn');
    var form        = document.getElementById('page-editor-form');

    var currentMode = 'visual';

    function setMode(m) {
        if (m === 'code') {
            codeEdit.value = visEdit.innerHTML;
            visWrap.style.display = 'none';
            visTool.style.display = 'none';
            codeWrap.style.display = 'block';
            codeBtn.style.background = '#ffffff';
            codeBtn.style.color = 'var(--wp-blue)';
            visBtn.style.background = 'transparent';
            visBtn.style.color = 'var(--wp-text-muted)';
            currentMode = 'code';
        } else {
            visEdit.innerHTML = codeEdit.value || '<p></p>';
            codeWrap.style.display = 'none';
            visWrap.style.display = 'block';
            visTool.style.display = 'flex';
            visBtn.style.background = '#ffffff';
            visBtn.style.color = 'var(--wp-blue)';
            codeBtn.style.background = 'transparent';
            codeBtn.style.color = 'var(--wp-text-muted)';
            currentMode = 'visual';
        }
    }

    visBtn.addEventListener('click', function() { setMode('visual'); });
    codeBtn.addEventListener('click', function() { setMode('code'); });

    document.querySelectorAll('.page-rich-btn[data-cmd]').forEach(function(b) {
        b.addEventListener('click', function(e) {
            e.preventDefault();
            visEdit.focus();
            document.execCommand(this.getAttribute('data-cmd'), false, null);
        });
    });

    formatSel.addEventListener('change', function() {
        visEdit.focus();
        document.execCommand('formatBlock', false, '<' + this.value + '>');
    });

    saveBtn.addEventListener('click', function() {
        if (currentMode === 'visual') {
            canonical.value = visEdit.innerHTML;
        } else {
            canonical.value = codeEdit.value;
        }
        form.submit();
    });
});
</script>
