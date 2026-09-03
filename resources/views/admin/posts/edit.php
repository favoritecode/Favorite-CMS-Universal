<?php
$isEdit = !empty($post);
$action = $isEdit ? '/admin/posts/update' : '/admin/posts/store';
$currentFeatImg = $post?->getFeaturedImage();
$postId = (int)($post->id ?? 0);
?>
<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
    <h1 class="page-title"><?php echo $isEdit ? 'Edit Post' : 'Add New Post'; ?></h1>
    <div style="display: flex; gap: 8px; align-items: center;">
        <button type="button" id="preview-post-btn" class="btn btn-secondary" title="Preview post with active theme">
            &#128065; Preview Post
        </button>
        <?php if ($isEdit): ?>
            <a href="/admin/posts/new" class="btn btn-secondary">Add New</a>
            <a href="/post/<?php echo htmlspecialchars($post->slug, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="btn btn-secondary">
                View Post &#8599;
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Autosave Restore Banner -->
<div id="autosave-banner" style="display: none; background: #eff6ff; border: 1px solid #93c5fd; border-left: 4px solid var(--wp-blue); padding: 10px 16px; border-radius: 4px; margin-bottom: 16px; justify-content: space-between; align-items: center;">
    <div style="font-size: 13px; color: #1e40af;">
        &#9888; <strong>Unsaved local draft found:</strong> A newer draft was saved in your browser from an earlier session.
    </div>
    <div style="display: flex; gap: 8px;">
        <button type="button" id="restore-draft-btn" class="btn btn-primary" style="padding: 3px 10px; font-size: 12px;">Restore Draft</button>
        <button type="button" id="dismiss-draft-btn" class="btn btn-secondary" style="padding: 3px 10px; font-size: 12px;">Dismiss</button>
    </div>
</div>

<form id="post-editor-form" method="POST" action="<?php echo $action; ?>">
    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" id="action_type" name="action_type" value="">
    <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?php echo $postId; ?>">
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: 1fr 320px; gap: 24px;">
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

            <!-- Professional Dual-Mode Post Editor -->
            <div class="editor-wrapper" style="background: #ffffff; border: 1px solid var(--wp-border); border-radius: 6px; overflow: hidden; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <!-- Top Bar: Mode Switcher & Word Count -->
                <div style="background: #f8fafc; border-bottom: 1px solid var(--wp-border); padding: 8px 12px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <!-- Mode Switcher -->
                    <div style="display: flex; gap: 4px; background: #e2e8f0; padding: 3px; border-radius: 6px;">
                        <button type="button" id="mode-visual-btn" class="mode-tab-btn active" style="padding: 5px 14px; font-size: 12px; font-weight: 600; border: none; border-radius: 4px; cursor: pointer; background: #ffffff; color: var(--wp-blue); box-shadow: 0 1px 2px rgba(0,0,0,0.08);">
                            &#9998; Visual Mode
                        </button>
                        <button type="button" id="mode-code-btn" class="mode-tab-btn" style="padding: 5px 14px; font-size: 12px; font-weight: 600; border: none; border-radius: 4px; cursor: pointer; background: transparent; color: var(--wp-text-muted);">
                            &lt;/&gt; Code Mode
                        </button>
                    </div>

                    <!-- Right helpers -->
                    <div style="display: flex; align-items: center; gap: 12px; font-size: 12px; color: var(--wp-text-muted);">
                        <span id="editor-word-count">Words: 0</span>
                        <span id="editor-char-count">Chars: 0</span>
                        <span id="autosave-status" style="color: #64748b; font-style: italic;"></span>
                    </div>
                </div>

                <!-- Visual Mode Formatting Toolbar -->
                <div id="visual-toolbar" style="background: #ffffff; border-bottom: 1px solid var(--wp-border); padding: 6px 10px; display: flex; gap: 4px; flex-wrap: wrap; align-items: center;">
                    <!-- Style block dropdown -->
                    <select id="format-block-select" class="toolbar-select" title="Paragraph Format">
                        <option value="p">Paragraph</option>
                        <option value="h1">Heading 1</option>
                        <option value="h2">Heading 2</option>
                        <option value="h3">Heading 3</option>
                        <option value="h4">Heading 4</option>
                        <option value="pre">Preformatted</option>
                    </select>

                    <span class="toolbar-sep"></span>

                    <!-- Font styling buttons -->
                    <button type="button" class="rich-btn" data-cmd="bold" title="Bold (Ctrl+B)"><strong>B</strong></button>
                    <button type="button" class="rich-btn" data-cmd="italic" title="Italic (Ctrl+I)"><em>I</em></button>
                    <button type="button" class="rich-btn" data-cmd="underline" title="Underline (Ctrl+U)"><u>U</u></button>
                    <button type="button" class="rich-btn" data-cmd="strikeThrough" title="Strikethrough"><s>S</s></button>

                    <span class="toolbar-sep"></span>

                    <!-- Alignment buttons -->
                    <button type="button" class="rich-btn" data-cmd="justifyLeft" title="Align Left">&#9776;</button>
                    <button type="button" class="rich-btn" data-cmd="justifyCenter" title="Align Center">&#9868;</button>
                    <button type="button" class="rich-btn" data-cmd="justifyRight" title="Align Right">&#9777;</button>
                    <button type="button" class="rich-btn" data-cmd="justifyFull" title="Justify">&#9778;</button>

                    <span class="toolbar-sep"></span>

                    <!-- Lists & Indent -->
                    <button type="button" class="rich-btn" data-cmd="insertUnorderedList" title="Bullet List">&bull; List</button>
                    <button type="button" class="rich-btn" data-cmd="insertOrderedList" title="Numbered List">1. List</button>
                    <button type="button" class="rich-btn" data-cmd="outdent" title="Decrease Indent">&larr;</button>
                    <button type="button" class="rich-btn" data-cmd="indent" title="Increase Indent">&rarr;</button>

                    <span class="toolbar-sep"></span>

                    <!-- Inserts -->
                    <button type="button" class="rich-btn" data-cmd="formatBlock" data-val="blockquote" title="Quote Block">&ldquo;&rdquo;</button>
                    <button type="button" class="rich-btn" data-cmd="insertHorizontalRule" title="Horizontal Line">&mdash;</button>
                    <button type="button" class="rich-btn" id="insert-link-btn" title="Insert / Edit Link">&#128279; Link</button>
                    <button type="button" class="rich-btn" data-cmd="unlink" title="Remove Link">&#10060;</button>

                    <span class="toolbar-sep"></span>

                    <!-- Table button -->
                    <button type="button" class="rich-btn" id="insert-table-btn" title="Insert Table">&#128392; Table</button>

                    <!-- Media button -->
                    <button type="button" class="rich-btn" id="open-media-modal-btn" style="background: #f0fdf4; border-color: #86efac; color: #166534; font-weight: 600;" title="Insert Media Image or File">
                        &#128247; Add Media
                    </button>

                    <span class="toolbar-sep"></span>

                    <!-- Undo, Redo, Clear -->
                    <button type="button" class="rich-btn" data-cmd="undo" title="Undo (Ctrl+Z)">&#8617;</button>
                    <button type="button" class="rich-btn" data-cmd="redo" title="Redo (Ctrl+Y)">&#8618;</button>
                    <button type="button" class="rich-btn" data-cmd="removeFormat" title="Clear Formatting">&#129529;</button>
                </div>

                <!-- Code Mode Secondary Bar (Quick Tag Inserts) -->
                <div id="code-toolbar" style="display: none; background: #ffffff; border-bottom: 1px solid var(--wp-border); padding: 6px 10px; gap: 4px; flex-wrap: wrap; align-items: center;">
                    <span style="font-size: 11px; font-weight: 600; color: #64748b; margin-right: 4px;">HTML Inserts:</span>
                    <button type="button" class="code-insert-btn" data-snip="<h2>", data-endsnip="</h2>">H2</button>
                    <button type="button" class="code-insert-btn" data-snip="<h3>", data-endsnip="</h3>">H3</button>
                    <button type="button" class="code-insert-btn" data-snip="<p>", data-endsnip="</p>">&lt;p&gt;</button>
                    <button type="button" class="code-insert-btn" data-snip="<strong>", data-endsnip="</strong>">&lt;strong&gt;</button>
                    <button type="button" class="code-insert-btn" data-snip="<em>", data-endsnip="</em>">&lt;em&gt;</button>
                    <button type="button" class="code-insert-btn" data-snip="<a href=&quot;https://&quot;>", data-endsnip="</a>">&lt;a&gt;</button>
                    <button type="button" class="code-insert-btn" data-snip="<blockquote>\n  ", data-endsnip="\n</blockquote>">&lt;quote&gt;</button>
                    <button type="button" class="code-insert-btn" data-snip="<ul>\n  <li>", data-endsnip="</li>\n</ul>">&lt;ul&gt;</button>
                    <button type="button" class="code-insert-btn" data-snip="<ol>\n  <li>", data-endsnip="</li>\n</ol>">&lt;ol&gt;</button>
                    <button type="button" class="code-insert-btn" data-snip="<pre><code>", data-endsnip="</code></pre>">&lt;code&gt;</button>
                    <button type="button" class="code-insert-btn" id="open-media-code-btn" style="background: #f0fdf4; border-color: #86efac; color: #166534; font-weight: 600;">&#128247; Add Media</button>
                </div>

                <!-- 1. Visual Mode Content Container (contenteditable) -->
                <div id="visual-mode-container" style="display: block; padding: 20px 24px; min-height: 440px; background: #ffffff; cursor: text;">
                    <div id="visual-editor" 
                         contenteditable="true" 
                         class="entry-content" 
                         style="outline: none; min-height: 400px; font-size: 15px; line-height: 1.7; color: #1e293b;">
                        <?php echo $post->content ?? '<p></p>'; ?>
                    </div>
                </div>

                <!-- 2. Code Mode Container (with synchronized Line Numbers Gutter) -->
                <div id="code-mode-container" style="display: none; background: #1e293b; position: relative;">
                    <div style="display: flex; min-height: 440px;">
                        <!-- Gutter for Line Numbers -->
                        <div id="code-gutter" style="width: 48px; background: #0f172a; color: #64748b; font-family: Consolas, Monaco, monospace; font-size: 13px; line-height: 1.6; padding: 14px 6px; text-align: right; user-select: none; border-right: 1px solid #334155; overflow: hidden;">
                            1
                        </div>
                        <!-- Source Textarea -->
                        <textarea id="code-editor" 
                                  class="code-editor-textarea" 
                                  style="flex: 1; min-height: 440px; background: #1e293b; color: #f8fafc; font-family: Consolas, Monaco, 'Courier New', monospace; font-size: 13px; line-height: 1.6; border: none; padding: 14px; outline: none; resize: vertical; tab-size: 2; white-space: pre; overflow-x: auto;" 
                                  placeholder="Write your post HTML content here..."><?php echo htmlspecialchars($post->content ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                </div>

                <!-- Canonical Single Form Field -->
                <textarea id="post-content" name="content" style="display: none;"><?php echo htmlspecialchars($post->content ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <!-- Excerpt Box -->
            <div class="form-card" style="margin-bottom: 24px;">
                <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 8px;">Excerpt</h3>
                <textarea id="excerpt" 
                          name="excerpt" 
                          class="form-control" 
                          style="min-height: 80px;" 
                          placeholder="Write an optional short summary of this post for post cards and search feeds..."><?php echo htmlspecialchars($post->excerpt ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                <span class="description">Excerpts are optional hand-crafted summaries that appear on the homepage, archives, and social cards.</span>
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
                        <a href="/admin/posts/trash?id=<?php echo $postId; ?>" 
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
                <span class="description" style="margin-top: 8px; display: block;">Click to select from your media library or upload a new image.</span>
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
                <input type="text" name="tags" class="form-control" placeholder="news, tech, episodes" value="<?php echo htmlspecialchars($tagsString ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <span class="description" style="margin-top: 4px; display: block;">Separate tags with commas.</span>
            </div>
        </div>
    </div>
</form>

<!-- Live Post Preview Modal -->
<div id="preview-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.65); z-index: 100000; align-items: center; justify-content: center;">
    <div style="background: #ffffff; width: 95%; max-width: 960px; height: 90vh; border-radius: 8px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);">
        <div style="padding: 12px 20px; border-bottom: 1px solid var(--wp-border); display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
            <div style="font-weight: 700; font-size: 14px; color: var(--wp-dark); display: flex; align-items: center; gap: 8px;">
                <span>&#128065; Live Post Preview</span>
                <span style="font-weight: normal; font-size: 11px; color: var(--wp-text-muted);">(Rendered with Theme Styling)</span>
            </div>
            <button type="button" id="close-preview-modal" style="background: none; border: none; font-size: 22px; cursor: pointer; color: #64748b;">&times;</button>
        </div>
        <div style="flex: 1; overflow: hidden; background: #f1f5f9;">
            <iframe id="preview-iframe" style="width: 100%; height: 100%; border: none; background: #ffffff;"></iframe>
        </div>
    </div>
</div>

<!-- Enhanced Media Library & Direct Upload Modal -->
<div id="media-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 10000; align-items: center; justify-content: center;">
    <div style="background: #ffffff; width: 92%; max-width: 900px; height: 85vh; border-radius: 8px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2);">
        <!-- Modal Header with Tabs -->
        <div style="padding: 12px 20px; border-bottom: 1px solid var(--wp-border); display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
            <div style="display: flex; gap: 8px; align-items: center;">
                <button type="button" id="tab-browse-media" class="modal-tab-btn active" style="padding: 6px 14px; font-size: 13px; font-weight: 600; border: 1px solid var(--wp-blue); background: #ffffff; color: var(--wp-blue); border-radius: 4px; cursor: pointer;">
                    &#128193; Browse Media
                </button>
                <button type="button" id="tab-upload-media" class="modal-tab-btn" style="padding: 6px 14px; font-size: 13px; font-weight: 600; border: 1px solid var(--wp-border); background: #f8fafc; color: #64748b; border-radius: 4px; cursor: pointer;">
                    &#128229; Upload New Media
                </button>
            </div>
            <button type="button" id="close-media-modal" style="background: none; border: none; font-size: 22px; cursor: pointer; color: #64748b;">&times;</button>
        </div>

        <!-- Modal Body 1: Browse Media Library -->
        <div id="modal-view-browse" style="display: flex; flex: 1; overflow: hidden;">
            <!-- Left Grid: Media items -->
            <div style="flex: 1; padding: 16px; overflow-y: auto; border-right: 1px solid var(--wp-border);">
                <!-- Media Search & Category Filter -->
                <div style="display: flex; justify-content: space-between; gap: 10px; margin-bottom: 14px; flex-wrap: wrap;">
                    <div style="display: flex; gap: 4px;">
                        <button type="button" class="media-filter-btn active" data-cat="all">All</button>
                        <button type="button" class="media-filter-btn" data-cat="image">Images</button>
                        <button type="button" class="media-filter-btn" data-cat="video">Videos</button>
                        <button type="button" class="media-filter-btn" data-cat="document">Docs</button>
                    </div>
                    <input type="text" id="modal-search-input" placeholder="Search media..." style="padding: 4px 8px; font-size: 12px; border: 1px solid var(--wp-border); border-radius: 4px; width: 160px;">
                </div>

                <?php if (empty($mediaItems)): ?>
                    <div id="modal-empty-state" style="text-align: center; padding: 50px 20px; color: var(--wp-text-muted);">
                        <p style="font-size: 15px; margin-bottom: 8px;">No media files uploaded yet.</p>
                        <button type="button" id="empty-go-upload-btn" class="btn btn-primary" style="font-size: 12px;">Upload Your First File</button>
                    </div>
                <?php endif; ?>

                <div id="modal-media-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(115px, 1fr)); gap: 10px;">
                    <?php foreach ($mediaItems ?? [] as $m): ?>
                        <div class="media-picker-card" 
                             data-id="<?php echo (int)$m->id; ?>" 
                             data-url="<?php echo htmlspecialchars($m->url, ENT_QUOTES, 'UTF-8'); ?>"
                             data-name="<?php echo htmlspecialchars($m->filename, ENT_QUOTES, 'UTF-8'); ?>"
                             data-cat="<?php echo htmlspecialchars($m->getTypeCategory()); ?>"
                             data-mime="<?php echo htmlspecialchars($m->mime_type ?? ''); ?>"
                             data-size="<?php echo htmlspecialchars($m->getFormattedSize()); ?>">
                            <?php if ($m->isImage()): ?>
                                <img src="<?php echo htmlspecialchars($m->url, ENT_QUOTES, 'UTF-8'); ?>" 
                                     alt="<?php echo htmlspecialchars($m->filename, ENT_QUOTES, 'UTF-8'); ?>" 
                                     loading="lazy">
                            <?php elseif ($m->isVideo()): ?>
                                <div style="height: 80px; display: flex; align-items: center; justify-content: center; background: #0f172a; color: #ffffff; font-size: 24px;">&#127916;</div>
                            <?php elseif ($m->isAudio()): ?>
                                <div style="height: 80px; display: flex; align-items: center; justify-content: center; background: #0f172a; color: #ffffff; font-size: 24px;">&#127925;</div>
                            <?php else: ?>
                                <div style="height: 80px; display: flex; align-items: center; justify-content: center; background: #e2e8f0; font-size: 24px;">&#128196;</div>
                            <?php endif; ?>
                            <div class="card-name"><?php echo htmlspecialchars($m->filename, ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Right Sidebar: Item Details & Formatting Options -->
            <div style="width: 260px; padding: 16px; background: #f8fafc; display: flex; flex-direction: column; overflow-y: auto;">
                <h4 style="font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 10px; border-bottom: 1px solid var(--wp-border); padding-bottom: 6px;">Attachment Details</h4>
                <div id="attachment-details-empty" style="color: var(--wp-text-muted); font-size: 12px; font-style: italic;">
                    Select an item from the library to view details and insert options.
                </div>
                <div id="attachment-details-wrap" style="display: none; font-size: 12px;">
                    <div id="attachment-thumb" style="max-height: 120px; overflow: hidden; border-radius: 4px; margin-bottom: 10px; text-align: center; background: #ffffff; border: 1px solid var(--wp-border);"></div>
                    <div style="font-weight: 600; word-break: break-all; margin-bottom: 4px;" id="attachment-filename"></div>
                    <div style="color: #64748b; margin-bottom: 10px;" id="attachment-meta"></div>

                    <!-- Insertion Options for Content Mode -->
                    <div id="content-insert-options">
                        <div class="form-group" style="margin-bottom: 8px;">
                            <label style="font-size: 11px; font-weight: 600;">Alt Text</label>
                            <input type="text" id="insert-alt-text" class="form-control" style="font-size: 12px; padding: 4px;">
                        </div>
                        <div class="form-group" style="margin-bottom: 8px;">
                            <label style="font-size: 11px; font-weight: 600;">Alignment</label>
                            <select id="insert-align-select" class="form-control" style="font-size: 12px; padding: 4px;">
                                <option value="none">None (Inline)</option>
                                <option value="center" selected>Center</option>
                                <option value="left">Left</option>
                                <option value="right">Right</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 8px;">
                            <label style="font-size: 11px; font-weight: 600;">Size</label>
                            <select id="insert-size-select" class="form-control" style="font-size: 12px; padding: 4px;">
                                <option value="full" selected>Full Size</option>
                                <option value="medium">Medium (600px)</option>
                                <option value="thumbnail">Thumbnail (250px)</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Body 2: Upload New Media (Direct AJAX with Progress) -->
        <div id="modal-view-upload" style="display: none; flex: 1; padding: 30px; overflow-y: auto; background: #f8fafc;">
            <div id="modal-upload-zone" style="max-width: 500px; margin: 20px auto; border: 2px dashed #94a3b8; border-radius: 8px; padding: 40px 20px; text-align: center; background: #ffffff;">
                <div style="font-size: 44px; color: var(--wp-blue); margin-bottom: 10px;">&#128229;</div>
                <h3 style="font-size: 16px; font-weight: 600; margin-bottom: 6px;">Drop files to upload</h3>
                <p style="font-size: 12px; color: var(--wp-text-muted); margin-bottom: 16px;">
                    Supports large movies, web-series video, audio, images, documents, and archives.
                </p>
                <input type="file" id="modal-file-input" style="display: none;">
                <button type="button" id="modal-select-file-btn" class="btn btn-primary" style="padding: 8px 20px; font-size: 13px;">Select File</button>

                <!-- Upload Progress -->
                <div id="modal-progress-wrap" style="display: none; margin-top: 20px; text-align: left;">
                    <div style="display: flex; justify-content: space-between; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 4px;">
                        <span id="modal-progress-filename">Uploading...</span>
                        <span id="modal-progress-percent">0%</span>
                    </div>
                    <div style="background: #e2e8f0; border-radius: 999px; height: 10px; overflow: hidden;">
                        <div id="modal-progress-bar" style="width: 0%; height: 100%; background: var(--wp-blue); transition: width 0.15s ease;"></div>
                    </div>
                    <div id="modal-upload-status" style="font-size: 12px; margin-top: 6px; text-align: center;"></div>
                </div>
            </div>
        </div>

        <!-- Modal Footer -->
        <div style="padding: 12px 20px; border-top: 1px solid var(--wp-border); background: #f8fafc; display: flex; justify-content: space-between; align-items: center;">
            <span id="selected-media-summary" style="font-size: 12px; color: var(--wp-text-muted);">No media selected</span>
            <div style="display: flex; gap: 8px;">
                <button type="button" id="cancel-media-selection" class="btn btn-secondary">Cancel</button>
                <button type="button" id="confirm-media-selection" class="btn btn-primary" disabled>Insert Into Post</button>
            </div>
        </div>
    </div>
</div>

<style>
.mode-tab-btn {
    transition: all 0.15s ease;
}
.mode-tab-btn.active {
    background: #ffffff !important;
    color: var(--wp-blue) !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}
.toolbar-select {
    border: 1px solid var(--wp-border);
    border-radius: 4px;
    padding: 3px 6px;
    font-size: 12px;
    background: #ffffff;
    color: var(--wp-dark);
    cursor: pointer;
}
.toolbar-sep {
    display: inline-block;
    width: 1px;
    height: 18px;
    background: var(--wp-border);
    margin: 0 4px;
}
.rich-btn {
    background: #ffffff;
    border: 1px solid var(--wp-border);
    border-radius: 3px;
    padding: 3px 8px;
    font-size: 12px;
    color: var(--wp-dark);
    cursor: pointer;
    line-height: 1.4;
    transition: background 0.1s ease;
}
.rich-btn:hover {
    background: #f1f5f9;
    border-color: var(--wp-blue);
    color: var(--wp-blue);
}
.rich-btn.active {
    background: #e0f2fe;
    border-color: var(--wp-blue);
    color: var(--wp-blue);
}
.code-insert-btn {
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    border-radius: 3px;
    padding: 2px 6px;
    font-size: 11px;
    font-family: monospace;
    color: #334155;
    cursor: pointer;
}
.code-insert-btn:hover {
    background: #e2e8f0;
    color: var(--wp-blue);
}
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
/* Visual Editor Styling matching Theme Content */
#visual-editor p { margin-bottom: 1em; }
#visual-editor h1, #visual-editor h2, #visual-editor h3, #visual-editor h4 { margin-top: 1.2em; margin-bottom: 0.5em; font-weight: 700; color: #0f172a; }
#visual-editor blockquote { border-left: 4px solid var(--wp-blue); margin: 1em 0; padding: 8px 16px; background: #f8fafc; font-style: italic; color: #475569; }
#visual-editor ul, #visual-editor ol { margin: 1em 0 1em 24px; }
#visual-editor li { margin-bottom: 0.3em; }
#visual-editor table { border-collapse: collapse; width: 100%; margin: 1em 0; }
#visual-editor th, #visual-editor td { border: 1px solid #cbd5e1; padding: 8px 12px; text-align: left; }
#visual-editor th { background: #f1f5f9; font-weight: 600; }
#visual-editor img { max-width: 100%; height: auto; border-radius: 4px; }
#visual-editor img.align-center { display: block; margin: 1em auto; }
#visual-editor img.align-left { float: left; margin: 0 1em 1em 0; }
#visual-editor img.align-right { float: right; margin: 0 0 1em 1em; }
#visual-editor pre { background: #1e293b; color: #f8fafc; padding: 12px 16px; border-radius: 6px; overflow-x: auto; font-family: monospace; font-size: 13px; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var postId         = <?php echo $postId; ?>;
    var titleInput     = document.getElementById('post-title');
    var slugDisplay    = document.getElementById('slug-display');
    var slugInput      = document.getElementById('slug-input');
    var editSlugBtn    = document.getElementById('edit-slug-btn');
    var saveSlugBtn    = document.getElementById('save-slug-btn');
    var slugEditWrap   = document.getElementById('slug-edit-wrap');

    var canonicalContent = document.getElementById('post-content');
    var visualEditor   = document.getElementById('visual-editor');
    var codeEditor     = document.getElementById('code-editor');
    var codeGutter     = document.getElementById('code-gutter');

    var modeVisualBtn  = document.getElementById('mode-visual-btn');
    var modeCodeBtn    = document.getElementById('mode-code-btn');
    var visualContainer= document.getElementById('visual-mode-container');
    var codeContainer  = document.getElementById('code-mode-container');
    var visualToolbar  = document.getElementById('visual-toolbar');
    var codeToolbar    = document.getElementById('code-toolbar');

    var form           = document.getElementById('post-editor-form');
    var actionType     = document.getElementById('action_type');
    var statusSelect   = document.getElementById('status');
    var formatSelect   = document.getElementById('format-block-select');

    var wordCountEl    = document.getElementById('editor-word-count');
    var charCountEl    = document.getElementById('editor-char-count');
    var autosaveStatus = document.getElementById('autosave-status');

    var currentMode    = localStorage.getItem('favorite_post_editor_mode') || 'visual';
    var isUserEditedSlug = <?php echo ($isEdit && !empty($post->slug)) ? 'true' : 'false'; ?>;

    // ==========================================
    // 1. Slug Management
    // ==========================================
    function slugify(text) {
        return text.toString().toLowerCase().trim()
            .replace(/\s+/g, '-')
            .replace(/[^\w\-]+/g, '')
            .replace(/\-\-+/g, '-');
    }

    titleInput.addEventListener('input', function() {
        if (!isUserEditedSlug) {
            var s = slugify(this.value);
            slugDisplay.textContent = s || 'auto-generated';
            slugInput.value = s;
        }
    });

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

    // ==========================================
    // 2. Metrics & Line Numbers Calculation
    // ==========================================
    function updateMetrics() {
        var text = (currentMode === 'visual') ? (visualEditor.innerText || '') : (codeEditor.value || '');
        var words = text.trim() ? text.trim().split(/\s+/).length : 0;
        var chars = text.length;
        wordCountEl.textContent = 'Words: ' + words;
        charCountEl.textContent = 'Chars: ' + chars;
    }

    function updateCodeGutter() {
        var lines = (codeEditor.value.match(/\n/g) || []).length + 1;
        var numbers = [];
        for (var i = 1; i <= lines; i++) {
            numbers.push(i);
        }
        codeGutter.innerHTML = numbers.join('<br>');
    }

    codeEditor.addEventListener('input', function() {
        updateCodeGutter();
        updateMetrics();
        triggerAutosave();
    });

    codeEditor.addEventListener('scroll', function() {
        codeGutter.scrollTop = codeEditor.scrollTop;
    });

    // Support Tab key in Code Mode for clean indentation
    codeEditor.addEventListener('keydown', function(e) {
        if (e.key === 'Tab') {
            e.preventDefault();
            var start = this.selectionStart;
            var end = this.selectionEnd;
            this.value = this.value.substring(0, start) + '  ' + this.value.substring(end);
            this.selectionStart = this.selectionEnd = start + 2;
            updateCodeGutter();
        }
    });

    visualEditor.addEventListener('input', function() {
        updateMetrics();
        triggerAutosave();
    });

    // ==========================================
    // 3. Bidirectional Mode Synchronization
    // ==========================================
    function setEditorMode(mode) {
        if (mode === 'code') {
            // Visual -> Code sync
            var html = visualEditor.innerHTML;
            codeEditor.value = html;
            updateCodeGutter();

            visualContainer.style.display = 'none';
            visualToolbar.style.display = 'none';
            codeContainer.style.display = 'block';
            codeToolbar.style.display = 'flex';

            modeCodeBtn.classList.add('active');
            modeVisualBtn.classList.remove('active');
            currentMode = 'code';
            localStorage.setItem('favorite_post_editor_mode', 'code');
        } else {
            // Code -> Visual sync
            var code = codeEditor.value;
            visualEditor.innerHTML = code || '<p></p>';

            codeContainer.style.display = 'none';
            codeToolbar.style.display = 'none';
            visualContainer.style.display = 'block';
            visualToolbar.style.display = 'flex';

            modeVisualBtn.classList.add('active');
            modeCodeBtn.classList.remove('active');
            currentMode = 'visual';
            localStorage.setItem('favorite_post_editor_mode', 'visual');
        }
        updateMetrics();
    }

    modeVisualBtn.addEventListener('click', function() { setEditorMode('visual'); });
    modeCodeBtn.addEventListener('click', function() { setEditorMode('code'); });

    // Initialize mode
    setEditorMode(currentMode);

    // ==========================================
    // 4. Visual Mode Rich-Text Toolbar Commands
    // ==========================================
    document.querySelectorAll('.rich-btn[data-cmd]').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var cmd = this.getAttribute('data-cmd');
            var val = this.getAttribute('data-val') || null;

            visualEditor.focus();
            document.execCommand(cmd, false, val);
            updateMetrics();
        });
    });

    // Paragraph format block selector
    formatSelect.addEventListener('change', function() {
        var val = this.value;
        visualEditor.focus();
        if (val === 'pre') {
            document.execCommand('formatBlock', false, 'pre');
        } else {
            document.execCommand('formatBlock', false, '<' + val + '>');
        }
    });

    // Link insertion
    document.getElementById('insert-link-btn').addEventListener('click', function() {
        var currentUrl = 'https://';
        var url = prompt('Enter link URL:', currentUrl);
        if (url && url.trim() !== '') {
            visualEditor.focus();
            document.execCommand('createLink', false, url.trim());
        }
    });

    // Table insertion
    document.getElementById('insert-table-btn').addEventListener('click', function() {
        var rows = parseInt(prompt('Number of rows:', '3') || '0', 10);
        var cols = parseInt(prompt('Number of columns:', '3') || '0', 10);
        if (rows > 0 && cols > 0) {
            var tableHtml = '<table><thead><tr>';
            for (var c = 1; c <= cols; c++) {
                tableHtml += '<th>Header ' + c + '</th>';
            }
            tableHtml += '</tr></thead><tbody>';
            for (var r = 1; r <= rows; r++) {
                tableHtml += '<tr>';
                for (var c = 1; c <= cols; c++) {
                    tableHtml += '<td>Cell ' + r + '-' + c + '</td>';
                }
                tableHtml += '</tr>';
            }
            tableHtml += '</tbody></table><p></p>';

            visualEditor.focus();
            document.execCommand('insertHTML', false, tableHtml);
            updateMetrics();
        }
    });

    // Paste Cleanup Handler: strips Word/Docs MSO junk
    visualEditor.addEventListener('paste', function(e) {
        var clipboardData = e.clipboardData || window.clipboardData;
        if (!clipboardData) return;

        var pastedHtml = clipboardData.getData('text/html');
        if (pastedHtml) {
            e.preventDefault();
            // Clean MSO tags and excessive styles
            var cleaned = pastedHtml
                .replace(/<!--[\s\S]*?-->/g, '')
                .replace(/<\/?o:[^>]*>/gi, '')
                .replace(/class="mso[^"]*"/gi, '')
                .replace(/style="[^"]*mso-[^"]*"/gi, '');
            document.execCommand('insertHTML', false, cleaned);
            updateMetrics();
        }
    });

    // Quick tag insert in Code Mode
    document.querySelectorAll('.code-insert-btn[data-snip]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var snip = this.getAttribute('data-snip');
            var endsnip = this.getAttribute('data-endsnip') || '';
            var start = codeEditor.selectionStart;
            var end = codeEditor.selectionEnd;
            var val = codeEditor.value;
            var selected = val.substring(start, end);

            var insertion = snip + selected + endsnip;
            codeEditor.value = val.substring(0, start) + insertion + val.substring(end);
            codeEditor.focus();
            codeEditor.selectionStart = start + snip.length;
            codeEditor.selectionEnd = start + snip.length + selected.length;
            updateCodeGutter();
            updateMetrics();
        });
    });

    // ==========================================
    // 5. Canonical Form Submission Sync
    // ==========================================
    function syncContentToCanonical() {
        if (currentMode === 'visual') {
            canonicalContent.value = visualEditor.innerHTML;
        } else {
            canonicalContent.value = codeEditor.value;
        }
    }

    document.getElementById('save-draft-btn').addEventListener('click', function() {
        syncContentToCanonical();
        actionType.value = 'draft';
        statusSelect.value = 'draft';
        clearDraftSnapshot();
        form.submit();
    });

    document.getElementById('publish-btn').addEventListener('click', function() {
        syncContentToCanonical();
        actionType.value = 'publish';
        statusSelect.value = 'published';
        clearDraftSnapshot();
        form.submit();
    });

    // ==========================================
    // 6. Live Theme Preview
    // ==========================================
    var previewBtn   = document.getElementById('preview-post-btn');
    var previewModal = document.getElementById('preview-modal');
    var previewIframe= document.getElementById('preview-iframe');
    var closePreview = document.getElementById('close-preview-modal');

    previewBtn.addEventListener('click', function() {
        syncContentToCanonical();

        // Create temporary form to POST to /admin/posts/preview with iframe target
        var tempForm = document.createElement('form');
        tempForm.method = 'POST';
        tempForm.action = '/admin/posts/preview';
        tempForm.target = 'preview-iframe';
        tempForm.style.display = 'none';

        var titleField = document.createElement('input');
        titleField.name = 'title';
        titleField.value = titleInput.value || 'Untitled Preview';
        tempForm.appendChild(titleField);

        var contentField = document.createElement('input');
        contentField.name = 'content';
        contentField.value = canonicalContent.value;
        tempForm.appendChild(contentField);

        var featField = document.createElement('input');
        featField.name = 'featured_image_id';
        featField.value = document.getElementById('featured_image_id').value;
        tempForm.appendChild(featField);

        var tokenField = document.createElement('input');
        tokenField.name = '_token';
        tokenField.value = '<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>';
        tempForm.appendChild(tokenField);

        document.body.appendChild(tempForm);
        previewModal.style.display = 'flex';
        tempForm.submit();
        document.body.removeChild(tempForm);
    });

    closePreview.addEventListener('click', function() {
        previewModal.style.display = 'none';
        previewIframe.src = 'about:blank';
    });

    // ==========================================
    // 7. LocalStorage Autosave Disaster Recovery
    // ==========================================
    var autosaveTimer = null;
    var draftStorageKey = 'favorite_post_draft_' + postId;

    function triggerAutosave() {
        clearTimeout(autosaveTimer);
        autosaveTimer = setTimeout(function() {
            var content = (currentMode === 'visual') ? visualEditor.innerHTML : codeEditor.value;
            var payload = {
                title: titleInput.value,
                content: content,
                slug: slugInput.value,
                time: new Date().toISOString()
            };
            try {
                localStorage.setItem(draftStorageKey, JSON.stringify(payload));
                autosaveStatus.textContent = 'Draft autosaved locally (' + new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) + ')';
            } catch(e) {}
        }, 2000);
    }

    function clearDraftSnapshot() {
        try { localStorage.removeItem(draftStorageKey); } catch(e) {}
    }

    // Check for existing draft on load
    try {
        var savedDraftRaw = localStorage.getItem(draftStorageKey);
        if (savedDraftRaw) {
            var savedDraft = JSON.parse(savedDraftRaw);
            var serverContent = canonicalContent.value;
            if (savedDraft.content && savedDraft.content.trim() !== serverContent.trim()) {
                var banner = document.getElementById('autosave-banner');
                banner.style.display = 'flex';

                document.getElementById('restore-draft-btn').addEventListener('click', function() {
                    if (savedDraft.title && !titleInput.value) titleInput.value = savedDraft.title;
                    if (savedDraft.content) {
                        visualEditor.innerHTML = savedDraft.content;
                        codeEditor.value = savedDraft.content;
                        updateCodeGutter();
                        updateMetrics();
                    }
                    banner.style.display = 'none';
                });

                document.getElementById('dismiss-draft-btn').addEventListener('click', function() {
                    banner.style.display = 'none';
                    clearDraftSnapshot();
                });
            }
        }
    } catch(e) {}

    // ==========================================
    // 8. Media Library Modal with Upload & Progress
    // ==========================================
    var mediaModal      = document.getElementById('media-modal');
    var closeMediaModalBtn = document.getElementById('close-media-modal');
    var cancelMediaBtn  = document.getElementById('cancel-media-selection');
    var confirmMediaBtn = document.getElementById('confirm-media-selection');

    var tabBrowseBtn    = document.getElementById('tab-browse-media');
    var tabUploadBtn    = document.getElementById('tab-upload-media');
    var viewBrowse      = document.getElementById('modal-view-browse');
    var viewUpload      = document.getElementById('modal-view-upload');

    var featIdInput     = document.getElementById('featured_image_id');
    var featPreview     = document.getElementById('featured-image-preview');
    var featEmpty       = document.getElementById('featured-image-empty');
    var featDisplay     = document.getElementById('feat-img-display');

    var selectedMedia   = null;
    var modalTarget     = 'content'; // 'content' or 'featured'

    function openMediaModal(target) {
        modalTarget = target;
        confirmMediaBtn.textContent = (target === 'featured') ? 'Set Featured Image' : 'Insert Into Post';
        document.getElementById('content-insert-options').style.display = (target === 'featured') ? 'none' : 'block';
        mediaModal.style.display = 'flex';
        switchModalTab('browse');
    }

    function closeMediaModal() {
        mediaModal.style.display = 'none';
    }

    function switchModalTab(tab) {
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

    tabBrowseBtn.addEventListener('click', function() { switchModalTab('browse'); });
    tabUploadBtn.addEventListener('click', function() { switchModalTab('upload'); });
    var emptyUploadBtn = document.getElementById('empty-go-upload-btn');
    if (emptyUploadBtn) emptyUploadBtn.addEventListener('click', function() { switchModalTab('upload'); });

    document.getElementById('open-media-modal-btn').addEventListener('click', function() { openMediaModal('content'); });
    document.getElementById('open-media-code-btn').addEventListener('click', function() { openMediaModal('content'); });
    document.getElementById('set-feat-img-btn').addEventListener('click', function() { openMediaModal('featured'); });
    document.getElementById('change-feat-img-btn').addEventListener('click', function() { openMediaModal('featured'); });
    closeMediaModalBtn.addEventListener('click', closeMediaModal);
    cancelMediaBtn.addEventListener('click', closeMediaModal);

    // Filter media items in modal
    document.querySelectorAll('.media-filter-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.media-filter-btn').forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            var cat = this.getAttribute('data-cat');
            document.querySelectorAll('.media-picker-card').forEach(function(card) {
                if (cat === 'all' || card.getAttribute('data-cat') === cat) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        });
    });

    // Search media items in modal
    document.getElementById('modal-search-input').addEventListener('input', function() {
        var query = this.value.toLowerCase();
        document.querySelectorAll('.media-picker-card').forEach(function(card) {
            var name = card.getAttribute('data-name').toLowerCase();
            card.style.display = name.indexOf(query) !== -1 ? 'block' : 'none';
        });
    });

    // Card selection in modal
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

        document.getElementById('attachment-details-empty').style.display = 'none';
        document.getElementById('attachment-details-wrap').style.display = 'block';
        document.getElementById('attachment-filename').textContent = selectedMedia.name;
        document.getElementById('attachment-meta').textContent = selectedMedia.size + ' • ' + selectedMedia.mime;
        document.getElementById('insert-alt-text').value = selectedMedia.name.replace(/\.[^/.]+$/, "");

        var thumbEl = document.getElementById('attachment-thumb');
        if (selectedMedia.cat === 'image') {
            thumbEl.innerHTML = '<img src="' + selectedMedia.url + '" style="max-width: 100%; max-height: 120px; object-fit: contain;">';
        } else {
            thumbEl.innerHTML = '<div style="padding: 24px; font-size: 32px;">&#128196;</div>';
        }

        document.getElementById('selected-media-summary').textContent = 'Selected: ' + selectedMedia.name;
        confirmMediaBtn.disabled = false;
    }

    document.querySelectorAll('.media-picker-card').forEach(function(card) {
        card.addEventListener('click', function() { selectCard(this); });
    });

    // Modal File Upload with Progress
    var modalFileInput = document.getElementById('modal-file-input');
    var modalSelectBtn = document.getElementById('modal-select-file-btn');
    var modalUploadZone = document.getElementById('modal-upload-zone');
    var modalProgWrap  = document.getElementById('modal-progress-wrap');
    var modalProgBar   = document.getElementById('modal-progress-bar');
    var modalProgPct   = document.getElementById('modal-progress-percent');
    var modalProgName  = document.getElementById('modal-progress-filename');
    var modalStatusMsg = document.getElementById('modal-upload-status');

    modalSelectBtn.addEventListener('click', function() { modalFileInput.click(); });

    function handleModalUpload(file) {
        if (!file) return;

        var formData = new FormData();
        formData.append('file', file);
        formData.append('_token', '<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>');

        modalProgWrap.style.display = 'block';
        modalProgBar.style.width = '0%';
        modalProgPct.textContent = '0%';
        modalProgName.textContent = file.name;
        modalStatusMsg.style.color = '#334155';
        modalStatusMsg.textContent = 'Uploading file...';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/admin/media/upload-ajax', true);

        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable) {
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
                        modalProgBar.style.width = '100%';
                        modalProgPct.textContent = '100%';
                        modalStatusMsg.style.color = '#16a34a';
                        modalStatusMsg.textContent = 'Upload successful!';

                        // Add new card to grid
                        var m = res.media;
                        var grid = document.getElementById('modal-media-grid');
                        var card = document.createElement('div');
                        card.className = 'media-picker-card';
                        card.setAttribute('data-id', m.id);
                        card.setAttribute('data-url', m.url);
                        card.setAttribute('data-name', m.filename);
                        card.setAttribute('data-cat', m.category);
                        card.setAttribute('data-mime', m.mime_type);
                        card.setAttribute('data-size', m.formatted_size);

                        var innerHtml = '';
                        if (m.is_image) {
                            innerHtml = '<img src="' + m.url + '" alt="' + m.filename + '">';
                        } else {
                            innerHtml = '<div style="height: 80px; display: flex; align-items: center; justify-content: center; background: #e2e8f0; font-size: 24px;">&#128196;</div>';
                        }
                        innerHtml += '<div class="card-name">' + m.filename + '</div>';
                        card.innerHTML = innerHtml;

                        card.addEventListener('click', function() { selectCard(this); });
                        grid.insertBefore(card, grid.firstChild);

                        setTimeout(function() {
                            switchModalTab('browse');
                            selectCard(card);
                        }, 600);
                        return;
                    }
                } catch(err) {}
            }

            var err = 'Upload failed.';
            try {
                var errObj = JSON.parse(xhr.responseText);
                if (errObj.message) err = errObj.message;
            } catch(e) {}

            modalStatusMsg.style.color = '#dc2626';
            modalStatusMsg.textContent = err;
        };

        xhr.onerror = function() {
            modalStatusMsg.style.color = '#dc2626';
            modalStatusMsg.textContent = 'Network error occurred.';
        };

        xhr.send(formData);
    }

    modalFileInput.addEventListener('change', function() {
        if (this.files.length > 0) handleModalUpload(this.files[0]);
    });

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

    // Confirm Modal Selection (Featured Image or Content Insert)
    confirmMediaBtn.addEventListener('click', function() {
        if (!selectedMedia) return;

        if (modalTarget === 'featured') {
            featIdInput.value = selectedMedia.id;
            featDisplay.src   = selectedMedia.url;
            featPreview.style.display = 'block';
            featEmpty.style.display   = 'none';
        } else {
            // Build HTML for Content Insertion
            var align = document.getElementById('insert-align-select').value;
            var size  = document.getElementById('insert-size-select').value;
            var alt   = document.getElementById('insert-alt-text').value || selectedMedia.name;
            var mediaHtml = '';

            var alignClass = (align !== 'none') ? ' class="align-' + align + '"' : '';
            var styleAttr = '';
            if (size === 'medium') styleAttr = ' style="max-width: 600px;"';
            else if (size === 'thumbnail') styleAttr = ' style="max-width: 250px;"';

            if (selectedMedia.cat === 'image') {
                mediaHtml = '<p><img src="' + selectedMedia.url + '" alt="' + alt + '"' + alignClass + styleAttr + '></p>\n';
            } else if (selectedMedia.cat === 'video') {
                mediaHtml = '<p><video src="' + selectedMedia.url + '" controls style="max-width: 100%; height: auto;"></video></p>\n';
            } else if (selectedMedia.cat === 'audio') {
                mediaHtml = '<p><audio src="' + selectedMedia.url + '" controls></audio></p>\n';
            } else {
                mediaHtml = '<p><a href="' + selectedMedia.url + '" target="_blank" class="download-link">&#128196; Download ' + alt + '</a></p>\n';
            }

            if (currentMode === 'visual') {
                visualEditor.focus();
                document.execCommand('insertHTML', false, mediaHtml);
                updateMetrics();
            } else {
                var start = codeEditor.selectionStart;
                var end   = codeEditor.selectionEnd;
                var val   = codeEditor.value;
                codeEditor.value = val.substring(0, start) + mediaHtml + val.substring(end);
                codeEditor.focus();
                updateCodeGutter();
                updateMetrics();
            }
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
