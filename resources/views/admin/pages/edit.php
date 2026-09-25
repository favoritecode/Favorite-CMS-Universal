<?php
$isEdit = !empty($page);
$action = site_path($isEdit ? '/admin/pages/update' : '/admin/pages/store');
$revision = \FavoriteCMS\Services\ContentRevision::prepare($page ?? null, 'page', \FavoriteCMS\Models\User::find((int)($_SESSION['auth_user_id'] ?? 0)));
$editorContent = $revision['content'];
$currentFeatImg = $page?->getFeaturedImage();
$pageId = (int)($page->id ?? 0);
?>
<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
    <h1 class="page-title"><?php echo $isEdit ? 'Edit Page' : 'Add New Page'; ?></h1>
    <div style="display: flex; gap: 8px;">
        <?php if ($isEdit): ?>
            <a href="<?php echo htmlspecialchars(site_path('/admin/pages/new'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary">Add New</a>
            <a href="<?php echo htmlspecialchars(site_path('/page/'), ENT_QUOTES, 'UTF-8'); ?><?php echo htmlspecialchars($page->slug, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="btn btn-secondary">View Page &#8599;</a>
        <?php endif; ?>
    </div>
</div>

<form id="page-editor-form" method="POST" action="<?php echo $action; ?>">
    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="_content_revision" value="<?php echo htmlspecialchars($revision['token'], ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?php echo $pageId; ?>">
    <?php endif; ?>

    <div class="editor-layout-grid">
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
                        <?php echo $revision['visual'] ?? $editorContent; ?>
                    </div>
                </div>

                <!-- Code Mode Container -->
                <div id="page-code-container" style="display: none; background: #1e293b;">
                    <textarea id="page-code-editor" style="width: 100%; min-height: 380px; background: #1e293b; color: #f8fafc; font-family: Consolas, Monaco, monospace; font-size: 13px; line-height: 1.6; border: none; padding: 14px; outline: none; resize: vertical; tab-size: 2;"><?php echo htmlspecialchars($editorContent, ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <!-- Canonical form textarea -->
                <textarea id="page-content" name="content" style="display: none;"><?php echo htmlspecialchars($editorContent, ENT_QUOTES, 'UTF-8'); ?></textarea>
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
                <div class="form-row">
                    <div class="form-group">
                        <label for="canonical_url">Canonical URL Override</label>
                        <input type="url" id="canonical_url" name="canonical_url" class="form-control" value="<?php echo htmlspecialchars($seo->canonical_url ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Leave blank to use default page URL">
                    </div>
                    <div class="form-group">
                        <label for="robots">Robots Meta Directive</label>
                        <select id="robots" name="robots" class="form-control">
                            <?php $currentRobots = $seo->robots ?? 'index,follow'; ?>
                            <option value="index,follow" <?php echo ($currentRobots === 'index,follow') ? 'selected' : ''; ?>>Index, Follow (Default)</option>
                            <option value="noindex,follow" <?php echo ($currentRobots === 'noindex,follow') ? 'selected' : ''; ?>>Noindex, Follow</option>
                            <option value="noindex,nofollow" <?php echo ($currentRobots === 'noindex,nofollow') ? 'selected' : ''; ?>>Noindex, Nofollow</option>
                            <option value="index,nofollow" <?php echo ($currentRobots === 'index,nofollow') ? 'selected' : ''; ?>>Index, Nofollow</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Sidebar Column -->
        <div class="editor-sidebar-sticky">
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
                        <button type="submit" form="core-action-form" formmethod="POST" formnovalidate formaction="<?php echo htmlspecialchars(site_base_path(), ENT_QUOTES, 'UTF-8'); ?>/admin/pages/trash?id=<?php echo $pageId; ?>" class="core-action-link" style="color: var(--wp-danger); font-size: 12px;" onclick="return confirm('Move this page to trash?');">Move to Trash</button>
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

            <!-- Featured Image Card connected to Media Library, Local Upload & URL Import -->
            <div class="form-card feat-img-card" style="margin-bottom: 20px;">
                <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 10px; border-bottom: 1px solid var(--wp-border); padding-bottom: 8px;">
                    Featured Image
                </h3>
                <input type="hidden" id="featured_image_id" name="featured_image_id" value="<?php echo (int)($page?->featured_image_id ?? 0); ?>">
                <input type="hidden" id="page-featured-image" value="<?php echo (int)($page?->featured_image_id ?? 0); ?>">

                <!-- Error Box -->
                <div id="feat-img-error" class="feat-img-error" style="display: none;"></div>

                <!-- Preview container -->
                <div id="featured-image-preview" style="<?php echo $currentFeatImg ? '' : 'display: none;'; ?> margin-bottom: 12px;">
                    <div class="feat-img-preview-box">
                        <img id="feat-img-display" 
                             src="<?php echo htmlspecialchars($currentFeatImg->url ?? '', ENT_QUOTES, 'UTF-8'); ?>" 
                             alt="Featured Image" 
                             style="width: 100%; max-height: 180px; object-fit: cover; display: block;">
                    </div>
                    <div style="margin-top: 8px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px;">
                        <button type="button" id="change-feat-img-btn" class="btn btn-secondary" style="font-size: 11px; padding: 2px 8px;">Change Image</button>
                        <button type="button" id="remove-feat-img-btn" style="background: none; border: none; color: var(--wp-danger); font-size: 11px; cursor: pointer; text-decoration: underline;">Remove Image</button>
                    </div>
                </div>

                <!-- Empty / Input state container -->
                <div id="featured-image-actions" style="<?php echo $currentFeatImg ? 'display: none;' : ''; ?>">
                    <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 8px;">
                        <button type="button" id="set-feat-img-btn" class="btn btn-secondary" style="width: 100%; font-size: 12px; display: flex; align-items: center; justify-content: center; gap: 6px;">
                            &#128193; Choose from Media Library
                        </button>
                        <div style="display: flex; gap: 6px;">
                            <input type="file" id="feat-img-file-input" accept="image/*" style="display: none;">
                            <button type="button" id="upload-feat-img-btn" class="btn btn-secondary" style="flex: 1; font-size: 12px; display: flex; align-items: center; justify-content: center; gap: 6px;">
                                &#128229; Upload Image
                            </button>
                            <button type="button" id="toggle-url-import-btn" class="btn btn-secondary" style="flex: 1; font-size: 12px; display: flex; align-items: center; justify-content: center; gap: 6px;">
                                &#127760; Import URL
                            </button>
                        </div>
                    </div>

                    <!-- URL Import Row (toggleable) -->
                    <div id="feat-img-url-box" style="display: none; margin-top: 8px; padding: 10px; background: #f8fafc; border: 1px solid var(--wp-border); border-radius: 4px;">
                        <label style="display: block; font-size: 11px; font-weight: 600; margin-bottom: 4px; color: var(--wp-dark);">Image URL (HTTP/HTTPS):</label>
                        <div style="display: flex; gap: 6px;">
                            <input type="url" id="feat-img-url-input" class="form-control" placeholder="https://example.com/image.jpg" style="font-size: 12px; padding: 4px 8px; flex: 1;">
                            <button type="button" id="feat-img-url-submit-btn" class="btn btn-primary" style="font-size: 11px; padding: 4px 10px; white-space: nowrap;">Import</button>
                        </div>
                        <span id="feat-img-url-loading" style="display: none; font-size: 11px; color: var(--wp-blue); margin-top: 4px;">Downloading &amp; verifying...</span>
                    </div>
                </div>
                <span class="description" style="margin-top: 8px; display: block;">Upload directly, import from URL, or choose from your media library.</span>
            </div>
        </div>
    </div>
</form>

<?php include APP_ROOT . '/resources/views/admin/partials/media-modal.php'; ?>

<style>
.media-filter-btn {
    background: #ffffff;
    border: 1px solid var(--wp-border);
    border-radius: 3px;
    padding: 2px 8px;
    font-size: 11px;
    cursor: pointer;
    color: #475569;
}
.media-filter-btn.active {
    background: var(--wp-blue);
    border-color: var(--wp-blue);
    color: #ffffff;
}
.media-picker-card {
    border: 2px solid transparent;
    border-radius: 6px;
    cursor: pointer;
    overflow: hidden;
    background: #ffffff;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    text-align: center;
    padding: 4px;
    transition: all 0.15s ease;
}
.media-picker-card img {
    width: 100%;
    height: 80px;
    object-fit: cover;
    border-radius: 4px;
    display: block;
}
.media-picker-card .card-name {
    font-size: 11px;
    margin-top: 4px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: #475569;
}
.media-picker-card:hover {
    border-color: var(--wp-blue) !important;
    background: #f0f9ff !important;
}
.media-picker-card.selected {
    border-color: var(--wp-blue) !important;
    box-shadow: 0 0 0 2px var(--wp-blue);
    background: #e0f2fe !important;
}
</style>

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

    // ==========================================
    // Featured Image & Media Library Modal
    // ==========================================
    var mediaModal         = document.getElementById('media-modal');
    var closeMediaModalBtn = document.getElementById('close-media-modal');
    var cancelMediaBtn     = document.getElementById('cancel-media-selection');
    var confirmMediaBtn    = document.getElementById('confirm-media-selection');

    var tabBrowseBtn       = document.getElementById('tab-browse-media');
    var tabUploadBtn       = document.getElementById('tab-upload-media');
    var viewBrowse         = document.getElementById('modal-view-browse');
    var viewUpload         = document.getElementById('modal-view-upload');

    var featIdInput        = document.getElementById('featured_image_id');
    var featPreview        = document.getElementById('featured-image-preview');
    var featActions        = document.getElementById('featured-image-actions');
    var featDisplay        = document.getElementById('feat-img-display');
    var featFileInput      = document.getElementById('feat-img-file-input');
    var uploadFeatBtn      = document.getElementById('upload-feat-img-btn');
    var toggleUrlBtn       = document.getElementById('toggle-url-import-btn');
    var featUrlBox         = document.getElementById('feat-img-url-box');
    var featUrlInput       = document.getElementById('feat-img-url-input');
    var featUrlSubmit      = document.getElementById('feat-img-url-submit-btn');
    var featUrlLoading     = document.getElementById('feat-img-url-loading');
    var featErrorBox       = document.getElementById('feat-img-error');

    var selectedMedia      = null;

    function showFeatImgError(msg) {
        if (featErrorBox) {
            featErrorBox.textContent = msg;
            featErrorBox.style.display = 'block';
        }
    }

    function clearFeatImgError() {
        if (featErrorBox) {
            featErrorBox.textContent = '';
            featErrorBox.style.display = 'none';
        }
    }

    function setFeaturedImage(id, url, name) {
        clearFeatImgError();
        featIdInput.value = id;
        featDisplay.src   = url;
        featPreview.style.display = 'block';
        if (featActions) featActions.style.display = 'none';
        if (featUrlBox) featUrlBox.style.display = 'none';
        if (featUrlInput) featUrlInput.value = '';
    }

    function removeFeaturedImage() {
        clearFeatImgError();
        featIdInput.value = '0';
        featDisplay.src   = '';
        featPreview.style.display = 'none';
        if (featActions) featActions.style.display = 'block';
    }

    function openMediaModal() {
        if (confirmMediaBtn) confirmMediaBtn.textContent = 'Set Featured Image';
        var insertOpts = document.getElementById('content-insert-options');
        if (insertOpts) insertOpts.style.display = 'none';
        if (mediaModal) mediaModal.style.display = 'flex';
        switchModalTab('browse');
    }

    function closeMediaModal() {
        if (mediaModal) mediaModal.style.display = 'none';
    }

    function switchModalTab(tab) {
        if (!tabBrowseBtn || !tabUploadBtn || !viewBrowse || !viewUpload) return;
        if (tab === 'browse') {
            tabBrowseBtn.classList.add('active');
            tabUploadBtn.classList.remove('active');
            tabBrowseBtn.style.background = '#ffffff';
            tabBrowseBtn.style.color = 'var(--wp-blue)';
            tabBrowseBtn.style.borderColor = 'var(--wp-blue)';
            tabUploadBtn.style.background = '#f8fafc';
            tabUploadBtn.style.color = '#64748b';
            tabUploadBtn.style.borderColor = 'var(--wp-border)';
            viewBrowse.style.display = 'flex';
            viewUpload.style.display = 'none';
        } else {
            tabUploadBtn.classList.add('active');
            tabBrowseBtn.classList.remove('active');
            tabUploadBtn.style.background = '#ffffff';
            tabUploadBtn.style.color = 'var(--wp-blue)';
            tabUploadBtn.style.borderColor = 'var(--wp-blue)';
            tabBrowseBtn.style.background = '#f8fafc';
            tabBrowseBtn.style.color = '#64748b';
            tabBrowseBtn.style.borderColor = 'var(--wp-border)';
            viewBrowse.style.display = 'none';
            viewUpload.style.display = 'block';
        }
    }

    if (tabBrowseBtn) tabBrowseBtn.addEventListener('click', function() { switchModalTab('browse'); });
    if (tabUploadBtn) tabUploadBtn.addEventListener('click', function() { switchModalTab('upload'); });
    var emptyUploadBtn = document.getElementById('empty-go-upload-btn');
    if (emptyUploadBtn) emptyUploadBtn.addEventListener('click', function() { switchModalTab('upload'); });

    var setFeatBtn = document.getElementById('set-feat-img-btn');
    if (setFeatBtn) setFeatBtn.addEventListener('click', openMediaModal);
    var changeFeatBtn = document.getElementById('change-feat-img-btn');
    if (changeFeatBtn) changeFeatBtn.addEventListener('click', openMediaModal);
    if (closeMediaModalBtn) closeMediaModalBtn.addEventListener('click', closeMediaModal);
    if (cancelMediaBtn) cancelMediaBtn.addEventListener('click', closeMediaModal);

    var removeFeatBtn = document.getElementById('remove-feat-img-btn');
    if (removeFeatBtn) removeFeatBtn.addEventListener('click', removeFeaturedImage);

    // Media library browsing & batches
    var mediaGrid     = document.getElementById('modal-media-grid');
    var mediaMoreBtn  = document.getElementById('modal-media-more-btn');
    var mediaStatus   = document.getElementById('modal-media-status');
    var mediaState    = {
        page: 1,
        perPage: <?php echo (int)($mediaBatchSize ?? 24); ?>,
        category: 'all',
        search: '',
        hasMore: <?php echo count($mediaItems ?? []) < (int)($mediaTotal ?? 0) ? 'true' : 'false'; ?>,
        loading: false,
        requestId: 0
    };

    function escapeMediaHtml(value) {
        return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function(ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    function buildMediaCard(m) {
        var card = document.createElement('div');
        card.className = 'media-picker-card';
        card.setAttribute('data-id', m.id);
        card.setAttribute('data-url', m.url);
        card.setAttribute('data-name', m.filename);
        card.setAttribute('data-cat', m.category);
        card.setAttribute('data-mime', m.mime_type || '');
        card.setAttribute('data-size', m.formatted_size || '');

        var inner;
        if (m.is_image) {
            inner = '<img src="' + escapeMediaHtml(m.url) + '" alt="' + escapeMediaHtml(m.filename) + '" loading="lazy">';
        } else if (m.is_video) {
            inner = '<div style="height: 80px; display: flex; align-items: center; justify-content: center; background: #0f172a; color: #ffffff; font-size: 24px;">&#127916;</div>';
        } else if (m.is_audio) {
            inner = '<div style="height: 80px; display: flex; align-items: center; justify-content: center; background: #0f172a; color: #ffffff; font-size: 24px;">&#127925;</div>';
        } else {
            inner = '<div style="height: 80px; display: flex; align-items: center; justify-content: center; background: #e2e8f0; font-size: 24px;">&#128196;</div>';
        }
        inner += '<div class="card-name">' + escapeMediaHtml(m.filename) + '</div>';
        card.innerHTML = inner;
        card.addEventListener('click', function() { selectCard(this); });
        return card;
    }

    function loadMediaBatch(reset) {
        if (!mediaGrid || (mediaState.loading && !reset)) return;
        var requestId = ++mediaState.requestId;
        var page = reset ? 1 : mediaState.page + 1;
        mediaState.loading = true;
        if (mediaStatus) mediaStatus.textContent = 'Loading media...';

        var xhr = new XMLHttpRequest();
        xhr.open('GET', <?php echo json_encode(site_path('/admin/media/library?page='), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?> + page + '&per_page=' + mediaState.perPage + '&category=' + encodeURIComponent(mediaState.category) + '&s=' + encodeURIComponent(mediaState.search), true);
        xhr.onload = function() {
            if (requestId !== mediaState.requestId) return;
            mediaState.loading = false;
            var res = null;
            try { res = JSON.parse(xhr.responseText); } catch (e) {}
            if (xhr.status !== 200 || !res || !res.success) {
                if (mediaStatus) mediaStatus.textContent = 'Could not load media. Please try again.';
                return;
            }
            if (reset) mediaGrid.innerHTML = '';
            res.items.forEach(function(m) {
                if (mediaGrid.querySelector('.media-picker-card[data-id="' + m.id + '"]')) return;
                mediaGrid.appendChild(buildMediaCard(m));
            });
            mediaState.page = res.page;
            mediaState.hasMore = !!res.has_more;
            var shown = mediaGrid.querySelectorAll('.media-picker-card').length;
            if (mediaStatus) mediaStatus.textContent = res.total === 0 ? 'No media files match this filter.' : 'Showing ' + shown + ' of ' + res.total;
            if (mediaMoreBtn) mediaMoreBtn.style.display = mediaState.hasMore ? '' : 'none';
        };
        xhr.onerror = function() {
            if (requestId !== mediaState.requestId) return;
            mediaState.loading = false;
            if (mediaStatus) mediaStatus.textContent = 'Network error while loading media.';
        };
        xhr.send();
    }

    document.querySelectorAll('.media-filter-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.media-filter-btn').forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            mediaState.category = this.getAttribute('data-cat') || 'all';
            loadMediaBatch(true);
        });
    });

    var mediaSearchInput = document.getElementById('modal-search-input');
    var mediaSearchTimer = null;
    if (mediaSearchInput) {
        mediaSearchInput.addEventListener('input', function() {
            var value = this.value.trim();
            clearTimeout(mediaSearchTimer);
            mediaSearchTimer = setTimeout(function() {
                mediaState.search = value;
                loadMediaBatch(true);
            }, 300);
        });
    }

    if (mediaMoreBtn) {
        mediaMoreBtn.addEventListener('click', function() { loadMediaBatch(false); });
    }

    function selectCard(card) {
        document.querySelectorAll('.media-picker-card').forEach(function(c) { c.classList.remove('selected'); });
        card.classList.add('selected');

        selectedMedia = {
            id: card.getAttribute('data-id'),
            url: card.getAttribute('data-url'),
            name: card.getAttribute('data-name'),
            cat: card.getAttribute('data-cat'),
            mime: card.getAttribute('data-mime'),
            size: card.getAttribute('data-size')
        };

        var detailsEmpty = document.getElementById('attachment-details-empty');
        var detailsWrap = document.getElementById('attachment-details-wrap');
        var filenameEl = document.getElementById('attachment-filename');
        var metaEl = document.getElementById('attachment-meta');
        var summaryEl = document.getElementById('selected-media-summary');

        if (detailsEmpty) detailsEmpty.style.display = 'none';
        if (detailsWrap) detailsWrap.style.display = 'block';
        if (filenameEl) filenameEl.textContent = selectedMedia.name;
        if (metaEl) metaEl.textContent = selectedMedia.size + ' • ' + selectedMedia.mime;

        var thumbEl = document.getElementById('attachment-thumb');
        if (thumbEl) {
            if (selectedMedia.cat === 'image') {
                thumbEl.innerHTML = '<img src="' + selectedMedia.url + '" style="max-width: 100%; max-height: 120px; object-fit: contain;">';
            } else {
                thumbEl.innerHTML = '<div style="padding: 24px; font-size: 32px;">&#128196;</div>';
            }
        }

        if (summaryEl) summaryEl.textContent = 'Selected: ' + selectedMedia.name;
        if (confirmMediaBtn) confirmMediaBtn.disabled = false;
    }

    document.querySelectorAll('.media-picker-card').forEach(function(card) {
        card.addEventListener('click', function() { selectCard(this); });
    });

    // Modal Upload
    var modalFileInput = document.getElementById('modal-file-input');
    var modalSelectBtn = document.getElementById('modal-select-file-btn');
    var modalUploadZone = document.getElementById('modal-upload-zone');
    var modalProgWrap  = document.getElementById('modal-progress-wrap');
    var modalProgBar   = document.getElementById('modal-progress-bar');
    var modalProgPct   = document.getElementById('modal-progress-percent');
    var modalProgName  = document.getElementById('modal-progress-filename');
    var modalStatusMsg = document.getElementById('modal-upload-status');

    if (modalSelectBtn && modalFileInput) {
        modalSelectBtn.addEventListener('click', function() { modalFileInput.click(); });
    }

    function handleModalUpload(file) {
        if (!file) return;

        var formData = new FormData();
        formData.append('file', file);
        formData.append('_token', '<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>');

        if (modalProgWrap) modalProgWrap.style.display = 'block';
        if (modalProgBar) modalProgBar.style.width = '0%';
        if (modalProgPct) modalProgPct.textContent = '0%';
        if (modalProgName) modalProgName.textContent = file.name;
        if (modalStatusMsg) {
            modalStatusMsg.style.color = '#334155';
            modalStatusMsg.textContent = 'Uploading file...';
        }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', <?php echo json_encode(site_path('/admin/media/upload-ajax'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, true);

        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable && modalProgBar && modalProgPct) {
                var pct = Math.round((e.loaded / e.total) * 100);
                modalProgBar.style.width = pct + '%';
                modalProgPct.textContent = pct + '%';
            }
        };

        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success && res.media) {
                        if (modalProgBar) modalProgBar.style.width = '100%';
                        if (modalProgPct) modalProgPct.textContent = '100%';
                        if (modalStatusMsg) {
                            modalStatusMsg.style.color = '#16a34a';
                            modalStatusMsg.textContent = 'Upload successful!';
                        }

                        var m = res.media;
                        if (mediaGrid) {
                            var card = buildMediaCard(m);
                            mediaGrid.insertBefore(card, mediaGrid.firstChild);
                            setTimeout(function() {
                                switchModalTab('browse');
                                selectCard(card);
                            }, 600);
                        }
                        return;
                    }
                } catch(err) {}
            }

            var err = 'Upload failed.';
            try {
                var errObj = JSON.parse(xhr.responseText);
                if (errObj.message) err = errObj.message;
            } catch(e) {}

            if (modalStatusMsg) {
                modalStatusMsg.style.color = '#dc2626';
                modalStatusMsg.textContent = err;
            }
        };

        xhr.onerror = function() {
            if (modalStatusMsg) {
                modalStatusMsg.style.color = '#dc2626';
                modalStatusMsg.textContent = 'Network error occurred.';
            }
        };

        xhr.send(formData);
    }

    if (modalFileInput) {
        modalFileInput.addEventListener('change', function() {
            if (this.files.length > 0) handleModalUpload(this.files[0]);
        });
    }

    if (modalUploadZone) {
        modalUploadZone.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.style.borderColor = 'var(--wp-blue)';
            this.style.background = '#eff6ff';
        });
        modalUploadZone.addEventListener('dragleave', function() {
            this.style.borderColor = '#94a3b8';
            this.style.background = '#ffffff';
        });
        modalUploadZone.addEventListener('drop', function(e) {
            e.preventDefault();
            this.style.borderColor = '#94a3b8';
            this.style.background = '#ffffff';
            if (e.dataTransfer.files.length > 0) {
                handleModalUpload(e.dataTransfer.files[0]);
            }
        });
    }

    // Confirm Modal Selection
    if (confirmMediaBtn) {
        confirmMediaBtn.addEventListener('click', function() {
            if (!selectedMedia) return;
            setFeaturedImage(selectedMedia.id, selectedMedia.url, selectedMedia.name);
            closeMediaModal();
        });
    }

    // Direct Upload Featured Image
    if (uploadFeatBtn && featFileInput) {
        uploadFeatBtn.addEventListener('click', function() {
            featFileInput.click();
        });

        featFileInput.addEventListener('change', function() {
            if (!this.files.length) return;
            var file = this.files[0];
            clearFeatImgError();

            var formData = new FormData();
            formData.append('file', file);
            formData.append('_token', '<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>');

            uploadFeatBtn.disabled = true;
            uploadFeatBtn.textContent = 'Uploading...';

            var xhr = new XMLHttpRequest();
            xhr.open('POST', <?php echo json_encode(site_path('/admin/media/upload-ajax'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, true);
            xhr.onload = function() {
                uploadFeatBtn.disabled = false;
                uploadFeatBtn.innerHTML = '&#128229; Upload Image';
                var res = null;
                try { res = JSON.parse(xhr.responseText); } catch (e) {}
                if (xhr.status === 200 && res && res.success && res.media) {
                    if (!res.media.is_image) {
                        showFeatImgError('The uploaded file is not an image.');
                        return;
                    }
                    setFeaturedImage(res.media.id, res.media.url, res.media.filename);
                } else {
                    showFeatImgError((res && res.message) ? res.message : 'Failed to upload image.');
                }
            };
            xhr.onerror = function() {
                uploadFeatBtn.disabled = false;
                uploadFeatBtn.innerHTML = '&#128229; Upload Image';
                showFeatImgError('Network error uploading image.');
            };
            xhr.send(formData);
            featFileInput.value = '';
        });
    }

    // Toggle Image URL Import Box
    if (toggleUrlBtn && featUrlBox) {
        toggleUrlBtn.addEventListener('click', function() {
            var isHidden = featUrlBox.style.display === 'none' || !featUrlBox.style.display;
            featUrlBox.style.display = isHidden ? 'block' : 'none';
            if (isHidden && featUrlInput) {
                featUrlInput.focus();
            }
        });
    }

    // Submit Image URL Import
    if (featUrlSubmit && featUrlInput) {
        function executeUrlImport() {
            var url = featUrlInput.value.trim();
            if (!url) {
                showFeatImgError('Please enter a valid image URL.');
                return;
            }
            clearFeatImgError();
            featUrlSubmit.disabled = true;
            if (featUrlLoading) featUrlLoading.style.display = 'block';

            var formData = new FormData();
            formData.append('url', url);
            formData.append('_token', '<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>');

            var xhr = new XMLHttpRequest();
            xhr.open('POST', <?php echo json_encode(site_path('/admin/media/import-url'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, true);
            xhr.onload = function() {
                featUrlSubmit.disabled = false;
                if (featUrlLoading) featUrlLoading.style.display = 'none';
                var res = null;
                try { res = JSON.parse(xhr.responseText); } catch (e) {}
                if (xhr.status === 200 && res && res.success && res.media) {
                    setFeaturedImage(res.media.id, res.media.url, res.media.filename);
                } else {
                    showFeatImgError((res && res.message) ? res.message : 'Failed to import image from URL.');
                }
            };
            xhr.onerror = function() {
                featUrlSubmit.disabled = false;
                if (featUrlLoading) featUrlLoading.style.display = 'none';
                showFeatImgError('Network error importing image.');
            };
            xhr.send(formData);
        }

        featUrlSubmit.addEventListener('click', executeUrlImport);

        featUrlInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                executeUrlImport();
            }
        });
    }
});
</script>
