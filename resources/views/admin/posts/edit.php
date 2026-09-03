<?php
$isEdit = !empty($post);
$action = $isEdit ? '/admin/posts/update' : '/admin/posts/store';
$currentFeatImg = $post?->getFeaturedImage();
?>
<div class="page-header">
    <h1 class="page-title"><?php echo $isEdit ? 'Edit Post' : 'Add New Post'; ?></h1>
    <div style="display: flex; gap: 8px;">
        <?php if ($isEdit): ?>
            <a href="/admin/posts/new" class="btn btn-secondary">Add New</a>
            <a href="/post/<?php echo htmlspecialchars($post->slug, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="btn btn-secondary">
                View Post &#8599;
            </a>
        <?php endif; ?>
    </div>
</div>

<form id="post-editor-form" method="POST" action="<?php echo $action; ?>">
    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" id="action_type" name="action_type" value="">
    <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?php echo (int)$post->id; ?>">
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: 1fr 310px; gap: 24px;">
        <!-- Left Main Column -->
        <div>
            <!-- Post Title -->
            <div class="form-group" style="margin-bottom: 8px;">
                <input type="text" 
                       id="post-title" 
                       name="title" 
                       class="form-control" 
                       style="font-size: 20px; padding: 12px 14px; font-weight: 700; border-radius: 4px;" 
                       placeholder="Add post title..." 
                       value="<?php echo htmlspecialchars($post->title ?? '', ENT_QUOTES, 'UTF-8'); ?>" 
                       required 
                       autofocus>
            </div>

            <!-- Permalink / Slug Section -->
            <div style="margin-bottom: 18px; font-size: 13px; color: var(--wp-text-muted); display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                <span><strong>Permalink:</strong> <?php echo htmlspecialchars(env('APP_URL', 'http://favorite-cms.local')); ?>/post/</span>
                <span id="slug-display" style="color: var(--wp-dark); font-weight: 600; background: #e2e8f0; padding: 2px 6px; border-radius: 3px;">
                    <?php echo htmlspecialchars($post->slug ?? 'auto-generated'); ?>
                </span>
                <button type="button" id="edit-slug-btn" class="btn btn-secondary" style="padding: 2px 8px; font-size: 11px;">Edit</button>
                <div id="slug-edit-wrap" style="display: none; align-items: center; gap: 4px;">
                    <input type="text" 
                           id="slug-input" 
                           name="slug" 
                           value="<?php echo htmlspecialchars($post->slug ?? '', ENT_QUOTES, 'UTF-8'); ?>" 
                           class="form-control" 
                           style="padding: 2px 8px; font-size: 12px; width: 180px;">
                    <button type="button" id="save-slug-btn" class="btn btn-secondary" style="padding: 2px 8px; font-size: 11px;">OK</button>
                </div>
            </div>

            <!-- Dual-Mode Content Editor -->
            <div class="editor-wrapper" style="background: #ffffff; border: 1px solid var(--wp-border); border-radius: 6px; overflow: hidden; margin-bottom: 24px;">
                <!-- Editor Mode Tabs & Formatting Toolbar -->
                <div style="background: #f8fafc; border-bottom: 1px solid var(--wp-border); padding: 8px 12px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                    <!-- Mode Switcher -->
                    <div style="display: flex; gap: 4px; background: #e2e8f0; padding: 2px; border-radius: 4px;">
                        <button type="button" id="mode-code-btn" class="editor-tab-btn active" style="padding: 4px 12px; font-size: 12px; font-weight: 600; border: none; border-radius: 3px; cursor: pointer; background: #ffffff; color: var(--wp-blue);">
                            &lt;/&gt; Code Mode
                        </button>
                        <button type="button" id="mode-preview-btn" class="editor-tab-btn" style="padding: 4px 12px; font-size: 12px; font-weight: 600; border: none; border-radius: 3px; cursor: pointer; background: transparent; color: var(--wp-text-muted);">
                            &#128065; Preview Mode
                        </button>
                    </div>

                    <!-- Toolbar formatting buttons (active in Code Mode) -->
                    <div id="editor-toolbar" style="display: flex; gap: 4px; flex-wrap: wrap;">
                        <button type="button" class="toolbar-btn" data-tag="b" title="Bold (Ctrl+B)"><strong>B</strong></button>
                        <button type="button" class="toolbar-btn" data-tag="i" title="Italic (Ctrl+I)"><em>I</em></button>
                        <button type="button" class="toolbar-btn" data-tag="h2" title="Heading 2">H2</button>
                        <button type="button" class="toolbar-btn" data-tag="h3" title="Heading 3">H3</button>
                        <button type="button" class="toolbar-btn" data-tag="blockquote" title="Quote">&ldquo;&rdquo;</button>
                        <button type="button" class="toolbar-btn" data-tag="ul" title="Bullet List">&bull; List</button>
                        <button type="button" class="toolbar-btn" data-tag="ol" title="Numbered List">1. List</button>
                        <button type="button" class="toolbar-btn" data-tag="code" title="Inline Code">&lt;code&gt;</button>
                        <button type="button" class="toolbar-btn" data-tag="link" title="Insert Link">&#128279; Link</button>
                        <button type="button" id="open-media-for-content" class="toolbar-btn" title="Insert Media Image">&#128247; Add Media</button>
                    </div>
                </div>

                <!-- Code Mode View (HTML Source) -->
                <div id="code-mode-container" style="display: block;">
                    <textarea id="post-content" 
                              name="content" 
                              class="form-control" 
                              style="width: 100%; min-height: 420px; font-family: Consolas, Monaco, 'Courier New', monospace; font-size: 14px; line-height: 1.6; border: none; padding: 14px; border-radius: 0; outline: none; resize: vertical;" 
                              placeholder="Write your post content in HTML or plain text..."><?php echo htmlspecialchars($post->content ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <!-- Preview Mode View (Theme Frontend Appearance) -->
                <div id="preview-mode-container" style="display: none; padding: 24px; min-height: 420px; background: #ffffff;">
                    <div class="preview-header-note" style="margin-bottom: 16px; padding: 8px 12px; background: #eff6ff; border-left: 4px solid var(--wp-blue); font-size: 12px; color: #1e40af;">
                        &#128065; <strong>Theme Live Preview:</strong> Rendering post content formatted as on public frontend.
                    </div>
                    <!-- Styled using theme entry-content rules -->
                    <div id="preview-rendered-content" class="entry-content">
                        <!-- Populated by JavaScript on mode switch -->
                    </div>
                </div>
            </div>

            <!-- Excerpt Box -->
            <div class="form-card" style="margin-bottom: 24px;">
                <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 8px;">Excerpt</h3>
                <textarea id="excerpt" 
                          name="excerpt" 
                          class="form-control" 
                          style="min-height: 85px;" 
                          placeholder="Write an optional short summary of this post for post cards and search feeds..."><?php echo htmlspecialchars($post->excerpt ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                <span class="description">Excerpts are optional hand-crafted summaries that appear on the homepage and social cards.</span>
            </div>

            <!-- SEO Settings Box -->
            <div class="form-card">
                <h3 style="font-size: 14px; margin-bottom: 14px; font-weight: 600; border-bottom: 1px solid var(--wp-border); padding-bottom: 8px;">
                    Search Engine Optimization (SEO) & Social Meta
                </h3>
                <div class="form-group">
                    <label for="meta_title">SEO Title</label>
                    <input type="text" id="meta_title" name="meta_title" class="form-control" value="<?php echo htmlspecialchars($seo->meta_title ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Custom title for Google search results">
                </div>
                <div class="form-group">
                    <label for="meta_description">Meta Description</label>
                    <textarea id="meta_description" name="meta_description" class="form-control" style="min-height: 60px;" placeholder="Brief description displayed in search snippets..."><?php echo htmlspecialchars($seo->meta_description ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
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
            <!-- Publish Controls Card -->
            <div class="form-card" style="margin-bottom: 20px;">
                <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 14px; border-bottom: 1px solid var(--wp-border); padding-bottom: 8px;">
                    Publish
                </h3>

                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" class="form-control">
                        <option value="draft" <?php echo ($post?->status === 'draft' || empty($post)) ? 'selected' : ''; ?>>Draft</option>
                        <option value="published" <?php echo ($post?->status === 'published') ? 'selected' : ''; ?>>Published</option>
                    </select>
                </div>

                <div style="font-size: 12px; color: var(--wp-text-muted); margin-bottom: 14px;">
                    <?php if ($isEdit && $post->published_at): ?>
                        <span>Published on: <strong><?php echo date('M j, Y \a\t g:i a', strtotime($post->published_at)); ?></strong></span>
                    <?php else: ?>
                        <span>Publish immediately upon saving.</span>
                    <?php endif; ?>
                </div>

                <div style="display: flex; gap: 8px; justify-content: space-between; align-items: center; padding-top: 12px; border-top: 1px solid var(--wp-border);">
                    <button type="button" id="save-draft-btn" class="btn btn-secondary" style="font-size: 12px;">
                        Save Draft
                    </button>
                    <button type="button" id="publish-btn" class="btn btn-primary">
                        <?php echo ($isEdit && $post->status === 'published') ? 'Update Post' : 'Publish'; ?>
                    </button>
                </div>

                <?php if ($isEdit): ?>
                    <div style="margin-top: 14px; text-align: right;">
                        <a href="/admin/posts/trash?id=<?php echo (int)$post->id; ?>" 
                           onclick="return confirm('Move this post to trash?');" 
                           style="color: var(--wp-danger); font-size: 12px;">
                            Move to Trash
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Featured Image Card connected to Media Library -->
            <div class="form-card" style="margin-bottom: 20px;">
                <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 10px; border-bottom: 1px solid var(--wp-border); padding-bottom: 8px;">
                    Featured Image
                </h3>
                <input type="hidden" id="featured_image_id" name="featured_image_id" value="<?php echo (int)($post?->featured_image_id ?? 0); ?>">

                <!-- Preview container -->
                <div id="featured-image-preview" style="<?php echo $currentFeatImg ? '' : 'display: none;'; ?> margin-bottom: 12px;">
                    <div style="border-radius: 4px; overflow: hidden; border: 1px solid var(--wp-border); background: #f8fafc;">
                        <img id="feat-img-display" 
                             src="<?php echo htmlspecialchars($currentFeatImg->url ?? '', ENT_QUOTES, 'UTF-8'); ?>" 
                             alt="Featured Image" 
                             style="width: 100%; max-height: 180px; object-fit: cover; display: block;">
                    </div>
                    <div style="margin-top: 8px; display: flex; justify-content: space-between; align-items: center;">
                        <button type="button" id="change-feat-img-btn" class="btn btn-secondary" style="font-size: 11px; padding: 2px 8px;">Change Image</button>
                        <button type="button" id="remove-feat-img-btn" style="background: none; border: none; color: var(--wp-danger); font-size: 11px; cursor: pointer; text-decoration: underline;">Remove Image</button>
                    </div>
                </div>

                <!-- Empty state container -->
                <div id="featured-image-empty" style="<?php echo $currentFeatImg ? 'display: none;' : ''; ?>">
                    <button type="button" id="set-feat-img-btn" class="btn btn-secondary" style="width: 100%; padding: 10px; font-size: 13px;">
                        &#128247; Set Featured Image
                    </button>
                </div>
                <span class="description" style="margin-top: 8px; display: block;">Click to select from your media library.</span>
            </div>

            <!-- Categories Card -->
            <div class="form-card" style="margin-bottom: 20px;">
                <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 10px; border-bottom: 1px solid var(--wp-border); padding-bottom: 8px;">
                    Categories
                </h3>
                <div style="max-height: 190px; overflow-y: auto; padding: 2px 0;">
                    <?php if (empty($categories)): ?>
                        <p style="color: var(--wp-text-muted); font-size: 12px;">No categories created yet.</p>
                    <?php else: ?>
                        <?php foreach ($categories as $cat): ?>
                            <label style="display: flex; align-items: center; gap: 8px; font-weight: 400; font-size: 13px; margin-bottom: 6px; cursor: pointer;">
                                <input type="checkbox" name="categories[]" value="<?php echo (int)$cat->id; ?>" <?php echo in_array((int)$cat->id, $selectedCats ?? [], true) ? 'checked' : ''; ?>>
                                <?php echo htmlspecialchars($cat->name, ENT_QUOTES, 'UTF-8'); ?>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div style="margin-top: 8px; border-top: 1px solid var(--wp-border); padding-top: 8px;">
                    <a href="/admin/taxonomies/categories" target="_blank" style="font-size: 12px;">+ Manage Categories</a>
                </div>
            </div>

            <!-- Tags Card -->
            <div class="form-card">
                <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 10px; border-bottom: 1px solid var(--wp-border); padding-bottom: 8px;">
                    Tags
                </h3>
                <input type="text" name="tags" class="form-control" placeholder="news, tech, favorite" value="<?php echo htmlspecialchars($tagsString ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <span class="description" style="margin-top: 4px; display: block;">Separate tags with commas.</span>
            </div>
        </div>
    </div>
</form>

<!-- Media Library Modal for Featured Image and Content insertion -->
<div id="media-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 10000; align-items: center; justify-content: center;">
    <div style="background: #ffffff; width: 90%; max-width: 850px; max-height: 85vh; border-radius: 8px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2);">
        <!-- Modal Header -->
        <div style="padding: 14px 20px; border-bottom: 1px solid var(--wp-border); display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
            <h2 style="font-size: 16px; font-weight: 700; color: var(--wp-dark);">Select Media</h2>
            <button type="button" id="close-media-modal" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b;">&times;</button>
        </div>

        <!-- Modal Body (Media Grid) -->
        <div style="padding: 20px; overflow-y: auto; flex: 1;">
            <?php if (empty($mediaItems)): ?>
                <div style="text-align: center; padding: 40px 20px; color: var(--wp-text-muted);">
                    <p style="font-size: 15px; margin-bottom: 12px;">No images in media library yet.</p>
                    <a href="/admin/media" target="_blank" class="btn btn-primary">Upload Media in Library</a>
                </div>
            <?php else: ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 12px;">
                    <?php foreach ($mediaItems as $m): ?>
                        <div class="media-picker-card" 
                             data-id="<?php echo (int)$m->id; ?>" 
                             data-url="<?php echo htmlspecialchars($m->url, ENT_QUOTES, 'UTF-8'); ?>"
                             data-name="<?php echo htmlspecialchars($m->filename, ENT_QUOTES, 'UTF-8'); ?>"
                             style="border: 2px solid transparent; border-radius: 6px; cursor: pointer; overflow: hidden; background: #f8fafc; text-align: center; padding: 6px; transition: all 0.15s ease;">
                            <img src="<?php echo htmlspecialchars($m->url, ENT_QUOTES, 'UTF-8'); ?>" 
                                 alt="<?php echo htmlspecialchars($m->filename, ENT_QUOTES, 'UTF-8'); ?>" 
                                 style="width: 100%; height: 90px; object-fit: cover; border-radius: 4px; display: block;"
                                 onerror="this.src='/themes/default/assets/images/placeholder.svg';">
                            <div style="font-size: 11px; margin-top: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #475569;">
                                <?php echo htmlspecialchars($m->filename, ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Modal Footer -->
        <div style="padding: 12px 20px; border-top: 1px solid var(--wp-border); background: #f8fafc; display: flex; justify-content: space-between; align-items: center;">
            <span id="selected-media-name" style="font-size: 12px; color: var(--wp-text-muted);">No image selected</span>
            <div style="display: flex; gap: 8px;">
                <button type="button" id="cancel-media-selection" class="btn btn-secondary">Cancel</button>
                <button type="button" id="confirm-media-selection" class="btn btn-primary" disabled>Set Featured Image</button>
            </div>
        </div>
    </div>
</div>

<style>
.toolbar-btn {
    background: #ffffff;
    border: 1px solid var(--wp-border);
    border-radius: 3px;
    padding: 3px 8px;
    font-size: 12px;
    color: var(--wp-dark);
    cursor: pointer;
    font-family: inherit;
}
.toolbar-btn:hover {
    background: #f1f5f9;
    border-color: var(--wp-blue);
    color: var(--wp-blue);
}
.media-picker-card:hover {
    border-color: var(--wp-blue) !important;
    background: #eff6ff !important;
}
.media-picker-card.selected {
    border-color: var(--wp-blue) !important;
    box-shadow: 0 0 0 2px var(--wp-blue);
    background: #e0f2fe !important;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var titleInput   = document.getElementById('post-title');
    var slugDisplay  = document.getElementById('slug-display');
    var slugInput    = document.getElementById('slug-input');
    var editSlugBtn  = document.getElementById('edit-slug-btn');
    var saveSlugBtn  = document.getElementById('save-slug-btn');
    var slugEditWrap = document.getElementById('slug-edit-wrap');
    var contentArea  = document.getElementById('post-content');
    var codeModeBtn  = document.getElementById('mode-code-btn');
    var previewModeBtn = document.getElementById('mode-preview-btn');
    var codeContainer  = document.getElementById('code-mode-container');
    var previewContainer = document.getElementById('preview-mode-container');
    var previewOutput  = document.getElementById('preview-rendered-content');
    var editorToolbar  = document.getElementById('editor-toolbar');
    var form           = document.getElementById('post-editor-form');
    var actionType     = document.getElementById('action_type');
    var statusSelect   = document.getElementById('status');

    var isUserEditedSlug = <?php echo ($isEdit && !empty($post->slug)) ? 'true' : 'false'; ?>;

    // Helper: generate clean slug
    function slugify(text) {
        return text.toString().toLowerCase().trim()
            .replace(/\s+/g, '-')
            .replace(/[^\w\-]+/g, '')
            .replace(/\-\-+/g, '-');
    }

    // Auto-update slug while typing title if not manually customized
    titleInput.addEventListener('input', function() {
        if (!isUserEditedSlug) {
            var s = slugify(this.value);
            slugDisplay.textContent = s || 'auto-generated';
            slugInput.value = s;
        }
    });

    // Toggle manual slug edit
    editSlugBtn.addEventListener('click', function() {
        slugDisplay.style.display = 'none';
        editSlugBtn.style.display = 'none';
        slugEditWrap.style.display = 'inline-flex';
        slugInput.focus();
    });

    saveSlugBtn.addEventListener('click', function() {
        var s = slugify(slugInput.value);
        slugInput.value = s;
        slugDisplay.textContent = s || 'auto-generated';
        slugDisplay.style.display = 'inline-block';
        editSlugBtn.style.display = 'inline-block';
        slugEditWrap.style.display = 'none';
        isUserEditedSlug = true;
    });

    // Dual-Mode Editor: Code Mode vs Preview Mode
    function setEditorMode(mode) {
        if (mode === 'preview') {
            // Render content cleanly into preview container
            var raw = contentArea.value;
            if (!raw.trim()) {
                previewOutput.innerHTML = '<p style="color: #94a3b8; font-style: italic;">No content to preview.</p>';
            } else {
                // If contains tags, render HTML; otherwise format line breaks
                if (/<[a-z][\s\S]*>/i.test(raw)) {
                    previewOutput.innerHTML = raw;
                } else {
                    previewOutput.innerHTML = '<p>' + raw.replace(/\n/g, '<br>') + '</p>';
                }
            }

            codeContainer.style.display = 'none';
            previewContainer.style.display = 'block';
            editorToolbar.style.opacity = '0.4';
            editorToolbar.style.pointerEvents = 'none';

            previewModeBtn.style.background = '#ffffff';
            previewModeBtn.style.color = 'var(--wp-blue)';
            codeModeBtn.style.background = 'transparent';
            codeModeBtn.style.color = 'var(--wp-text-muted)';
        } else {
            // Switch to Code Mode
            codeContainer.style.display = 'block';
            previewContainer.style.display = 'none';
            editorToolbar.style.opacity = '1';
            editorToolbar.style.pointerEvents = 'auto';

            codeModeBtn.style.background = '#ffffff';
            codeModeBtn.style.color = 'var(--wp-blue)';
            previewModeBtn.style.background = 'transparent';
            previewModeBtn.style.color = 'var(--wp-text-muted)';
        }
    }

    codeModeBtn.addEventListener('click', function() { setEditorMode('code'); });
    previewModeBtn.addEventListener('click', function() { setEditorMode('preview'); });

    // Formatting Toolbar action handler
    document.querySelectorAll('.toolbar-btn[data-tag]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var tag = this.getAttribute('data-tag');
            var start = contentArea.selectionStart;
            var end   = contentArea.selectionEnd;
            var val   = contentArea.value;
            var sel   = val.substring(start, end);

            var replaceText = '';
            if (tag === 'link') {
                var url = prompt('Enter link URL:', 'https://');
                if (url) {
                    replaceText = '<a href="' + url + '">' + (sel || 'link text') + '</a>';
                }
            } else if (tag === 'ul') {
                replaceText = '<ul>\n  <li>' + (sel || 'Item 1') + '</li>\n  <li>Item 2</li>\n</ul>';
            } else if (tag === 'ol') {
                replaceText = '<ol>\n  <li>' + (sel || 'Step 1') + '</li>\n  <li>Step 2</li>\n</ol>';
            } else {
                replaceText = '<' + tag + '>' + (sel || (tag.toUpperCase() + ' text')) + '</' + tag + '>';
            }

            if (replaceText) {
                contentArea.value = val.substring(0, start) + replaceText + val.substring(end);
                contentArea.focus();
                contentArea.selectionStart = start + replaceText.length;
                contentArea.selectionEnd   = start + replaceText.length;
            }
        });
    });

    // Save Draft & Publish button click handlers
    document.getElementById('save-draft-btn').addEventListener('click', function() {
        actionType.value = 'draft';
        statusSelect.value = 'draft';
        form.submit();
    });

    document.getElementById('publish-btn').addEventListener('click', function() {
        actionType.value = 'publish';
        statusSelect.value = 'published';
        form.submit();
    });

    // ==========================================
    // Media Modal & Featured Image Picker
    // ==========================================
    var modal = document.getElementById('media-modal');
    var featIdInput = document.getElementById('featured_image_id');
    var featPreview = document.getElementById('featured-image-preview');
    var featEmpty   = document.getElementById('featured-image-empty');
    var featDisplay = document.getElementById('feat-img-display');
    var confirmBtn  = document.getElementById('confirm-media-selection');
    var selectedName= document.getElementById('selected-media-name');

    var selectedMedia = null;
    var modalTargetMode = 'featured'; // 'featured' or 'content'

    function openMediaModal(target) {
        modalTargetMode = target;
        confirmBtn.textContent = (target === 'content') ? 'Insert Into Content' : 'Set Featured Image';
        modal.style.display = 'flex';
    }

    function closeMediaModal() {
        modal.style.display = 'none';
        document.querySelectorAll('.media-picker-card').forEach(function(c) { c.classList.remove('selected'); });
        selectedMedia = null;
        confirmBtn.disabled = true;
        selectedName.textContent = 'No image selected';
    }

    document.getElementById('set-feat-img-btn').addEventListener('click', function() { openMediaModal('featured'); });
    document.getElementById('change-feat-img-btn').addEventListener('click', function() { openMediaModal('featured'); });
    document.getElementById('open-media-for-content').addEventListener('click', function() { openMediaModal('content'); });
    document.getElementById('close-media-modal').addEventListener('click', closeMediaModal);
    document.getElementById('cancel-media-selection').addEventListener('click', closeMediaModal);

    // Media card selection click
    document.querySelectorAll('.media-picker-card').forEach(function(card) {
        card.addEventListener('click', function() {
            document.querySelectorAll('.media-picker-card').forEach(function(c) { c.classList.remove('selected'); });
            this.classList.add('selected');

            selectedMedia = {
                id: this.getAttribute('data-id'),
                url: this.getAttribute('data-url'),
                name: this.getAttribute('data-name')
            };

            selectedName.textContent = 'Selected: ' + selectedMedia.name;
            confirmBtn.disabled = false;
        });
    });

    // Confirm selection
    confirmBtn.addEventListener('click', function() {
        if (!selectedMedia) return;

        if (modalTargetMode === 'featured') {
            featIdInput.value = selectedMedia.id;
            featDisplay.src   = selectedMedia.url;
            featPreview.style.display = 'block';
            featEmpty.style.display   = 'none';
        } else {
            // Insert <img> into content
            var imgHtml = '<p><img src="' + selectedMedia.url + '" alt="' + selectedMedia.name + '"></p>\n';
            var start = contentArea.selectionStart;
            var end   = contentArea.selectionEnd;
            var val   = contentArea.value;
            contentArea.value = val.substring(0, start) + imgHtml + val.substring(end);
            contentArea.focus();
        }

        closeMediaModal();
    });

    // Remove Featured Image
    document.getElementById('remove-feat-img-btn').addEventListener('click', function() {
        featIdInput.value = '0';
        featDisplay.src   = '';
        featPreview.style.display = 'none';
        featEmpty.style.display   = 'block';
    });
});
</script>
