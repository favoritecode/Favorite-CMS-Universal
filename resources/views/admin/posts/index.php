
<style>
/* Small, scoped, theme-variable-aware CMS page action menu. */
.fpb-edit-picker{display:inline-block;position:relative;vertical-align:baseline}
.fpb-edit-picker summary{display:inline-flex;align-items:center;gap:3px;cursor:pointer;color:var(--wp-blue,var(--admin-primary,#2271b1));list-style:none;border-radius:4px;padding:2px 3px}
.fpb-edit-picker summary::-webkit-details-marker{display:none}
.fpb-edit-picker summary:focus-visible{outline:2px solid var(--wp-blue,var(--admin-primary,#2271b1));outline-offset:2px}
.fpb-edit-picker-menu{display:none;min-width:205px;max-width:min(270px,calc(100vw - 16px));padding:5px;z-index:9999;border:1px solid var(--admin-border,var(--wp-border,#64748b));background:var(--admin-surface,var(--wp-card-bg,var(--wp-bg,#fff)));color:var(--admin-text,var(--wp-text,#111));border-radius:7px;box-shadow:0 8px 22px rgba(0,0,0,.2)}
.fpb-edit-picker[open] .fpb-edit-picker-menu{display:block;position:fixed;left:var(--fpb-menu-x,0px);top:var(--fpb-menu-y,0px)}
.fpb-edit-picker-menu a{display:block;padding:9px 10px;text-decoration:none;border-radius:4px;white-space:normal;color:inherit!important;font-size:12px;line-height:1.35}
.fpb-edit-picker-menu a:hover,.fpb-edit-picker-menu a:focus-visible{background:var(--admin-surface-subtle,var(--wp-hover-bg,rgba(127,127,127,.18)));text-decoration:none}
@media (max-width:600px){.fpb-edit-picker-menu{min-width:190px}}
</style>

<div class="page-header">
    <h1 class="page-title">Posts</h1>
    <?php if ($currentUser && $currentUser->canCreatePosts()): ?>
        <a href="<?php echo htmlspecialchars(site_path('/admin/posts/new'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary">Add New Post</a>
    <?php endif; ?>
</div>

<ul class="subsubsub">
    <li><a href="<?php echo htmlspecialchars(site_path('/admin/posts?status=all'), ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $status === 'all' ? 'current' : ''; ?>">All (<?php echo (int)($counts['all'] ?? 0); ?>)</a> |</li>
    <li><a href="<?php echo htmlspecialchars(site_path('/admin/posts?status=published'), ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $status === 'published' ? 'current' : ''; ?>">Published (<?php echo (int)($counts['published'] ?? 0); ?>)</a> |</li>
    <li><a href="<?php echo htmlspecialchars(site_path('/admin/posts?status=scheduled'),ENT_QUOTES,'UTF-8'); ?>" class="<?php echo $status==='scheduled'?'current':''; ?>">Scheduled (<?php echo (int)($counts['scheduled']??0); ?>)</a> |</li>
    <li><a href="<?php echo htmlspecialchars(site_path('/admin/posts?status=pending'), ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $status === 'pending' ? 'current' : ''; ?>" style="<?php echo ($counts['pending'] ?? 0) > 0 ? 'font-weight: 700; color: var(--admin-warning-text);' : ''; ?>">Pending Review (<?php echo (int)($counts['pending'] ?? 0); ?>)</a> |</li>
    <li><a href="<?php echo htmlspecialchars(site_path('/admin/posts?status=draft'), ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $status === 'draft' ? 'current' : ''; ?>">Drafts (<?php echo (int)($counts['draft'] ?? 0); ?>)</a> |</li>
    <li><a href="<?php echo htmlspecialchars(site_path('/admin/posts?status=rejected'), ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $status === 'rejected' ? 'current' : ''; ?>">Rejected (<?php echo (int)($counts['rejected'] ?? 0); ?>)</a> |</li>
    <li><a href="<?php echo htmlspecialchars(site_path('/admin/posts?status=trash'), ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $status === 'trash' ? 'current' : ''; ?>">Trash (<?php echo (int)($counts['trash'] ?? 0); ?>)</a></li>
</ul>

<form method="GET" action="<?php echo htmlspecialchars(site_path('/admin/posts'), ENT_QUOTES, 'UTF-8'); ?>" style="margin-bottom: 16px; display: flex; gap: 8px;">
    <input type="hidden" name="status" value="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="text" name="s" class="form-control" style="max-width: 280px;" placeholder="Search posts..." value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
    <button type="submit" class="btn btn-secondary">Search Posts</button>
    <?php if ($search !== ''): ?>
        <a href="<?php echo htmlspecialchars(site_path('/admin/posts?status='), ENT_QUOTES, 'UTF-8'); ?><?php echo urlencode($status); ?>" class="btn btn-secondary" style="display: flex; align-items: center;">Clear</a>
    <?php endif; ?>
</form>

<form method="POST" action="<?php echo htmlspecialchars(site_path('/admin/posts/bulk'), ENT_QUOTES, 'UTF-8'); ?>" id="posts-bulk-form">
    <input type="hidden" name="_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="status" value="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">

    <?php
    $canBulkTrash = $currentUser && ($currentUser->canDeleteOwnPosts() || $currentUser->canDeleteOtherPosts());
    $canBulkRestore = $currentUser && ($currentUser->canRestoreOwnPosts() || $currentUser->canDeleteOtherPosts());
    $canBulkDelete = $currentUser && ($currentUser->canDeleteOwnPosts() || $currentUser->canDeleteOtherPosts());
    $canBulkModerate = $currentUser && $currentUser->canModeratePosts();
    $hasAnyBulkAction = ($status === 'trash') ? ($canBulkRestore || $canBulkDelete) : ($canBulkTrash || $canBulkDelete || $canBulkModerate);
    ?>
    <?php if ($hasAnyBulkAction): ?>
    <div class="bulk-actions-wrap">
        <select name="bulk_action" class="form-control" style="width: auto; max-width: 200px; display: inline-block;">
            <option value="">Bulk Actions</option>
            <?php if ($status === 'trash'): ?>
                <?php if ($canBulkRestore): ?>
                    <option value="restore">Restore</option>
                <?php endif; ?>
                <?php if ($canBulkDelete): ?>
                    <option value="delete">Delete Permanently</option>
                <?php endif; ?>
            <?php else: ?>
                <?php if ($canBulkModerate): ?>
                    <option value="approve">Approve</option>
                    <option value="reject">Reject</option>
                <?php endif; ?>
                <?php if ($canBulkTrash): ?>
                    <option value="trash">Move to Trash</option>
                <?php endif; ?>
                <?php if ($currentUser && $currentUser->canDeleteOtherPosts()): ?>
                    <option value="delete">Delete Permanently</option>
                <?php endif; ?>
            <?php endif; ?>
        </select>
        <button type="submit" class="btn btn-secondary">Apply</button>
        <span class="bulk-count-badge">0 selected</span>
    </div>
    <?php endif; ?>

    <div class="wp-table-wrap">
        <table class="wp-table">
            <thead>
                <tr>
                    <th style="width: 32px; text-align: center;">
                        <?php if ($hasAnyBulkAction): ?>
                            <input type="checkbox" id="select-all-posts" data-select-all>
                        <?php endif; ?>
                    </th>
                    <th style="width: 60px;">Image</th>
                    <th>Title</th>
                    <th>Author</th>
                    <th>Categories</th>
                    <th>Tags</th>
                    <th style="text-align: center; width: 70px;">Comments</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($posts)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; color: var(--wp-text-muted); padding: 24px;">No posts found.</td>
                    </tr>
            <?php else: ?>
                <?php foreach ($posts as $post): ?>
                    <?php
                    $featImg = $post->getFeaturedImage();
                    $author  = $post->getAuthor();
                    $cats    = $post->getTaxonomies('category');
                    $tags    = $post->getTaxonomies('tag');
                    $commentCount = count($post->getComments('approved'));
                    ?>
                    <tr>
                        <td style="text-align: center;">
                            <?php if ($hasAnyBulkAction): ?>
                                <input type="checkbox" name="ids[]" value="<?php echo (int)$post->id; ?>" class="bulk-cb">
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($featImg && !empty($featImg->url)): ?>
                                <img src="<?php echo htmlspecialchars($featImg->url, ENT_QUOTES, 'UTF-8'); ?>" 
                                     alt="Thumbnail" 
                                     style="width: 44px; height: 44px; object-fit: cover; border-radius: 4px; border: 1px solid var(--admin-border);"
                                     onerror="this.style.display='none';">
                            <?php else: ?>
                                <div style="width: 44px; height: 44px; background: var(--admin-surface-subtle); border-radius: 4px; display: flex; align-items: center; justify-content: center; color: var(--admin-text-muted); font-size: 16px;">
                                    &#128247;
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $canEditThisPost = $currentUser && $currentUser->canEditPost($post);
                            $canDeleteThisPost = $currentUser && $currentUser->canDeletePost($post);
                            $canRestoreThisPost = $currentUser && $currentUser->canRestorePost($post);
                            ?>
                            <strong>
                                <?php if ($canEditThisPost): ?>
                                    <a href="<?php echo htmlspecialchars(site_path('/admin/posts/edit?id='), ENT_QUOTES, 'UTF-8'); ?><?php echo (int)$post->id; ?>" class="row-title">
                                        <?php echo htmlspecialchars($post->title, ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="row-title">
                                        <?php echo htmlspecialchars($post->title, ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                <?php endif; ?>
                            </strong>
                            <div class="row-actions">
                                <?php if ($post->status === 'trash'): ?>
                                    <?php if ($canRestoreThisPost): ?>
                                        <button type="submit" form="core-action-form" formmethod="POST" formnovalidate formaction="<?php echo htmlspecialchars(site_base_path(), ENT_QUOTES, 'UTF-8'); ?>/admin/posts/restore?id=<?php echo (int)$post->id; ?>" class="core-action-link" style="color: var(--wp-blue);">Restore</button><?php echo $canDeleteThisPost ? ' |' : ''; ?>
                                    <?php endif; ?>
                                    <?php if ($canDeleteThisPost): ?>
                                        <button type="submit" form="core-action-form" formmethod="POST" formnovalidate formaction="<?php echo htmlspecialchars(site_base_path(), ENT_QUOTES, 'UTF-8'); ?>/admin/posts/delete?id=<?php echo (int)$post->id; ?>" class="core-action-link" onclick="return confirm('Permanently delete this post?');" style="color: var(--wp-danger);">Delete Permanently</button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if ($post->status === 'pending' && $currentUser && $currentUser->canModeratePosts()): ?>
                                        <button type="submit" form="core-action-form" formmethod="POST" formnovalidate formaction="<?php echo htmlspecialchars(site_base_path(), ENT_QUOTES, 'UTF-8'); ?>/admin/posts/approve?id=<?php echo (int)$post->id; ?>" class="core-action-link" style="color: var(--admin-success-text); font-weight: 700;">&#10003; Approve</button> |
                                        <button type="submit" form="core-action-form" formmethod="POST" formnovalidate formaction="<?php echo htmlspecialchars(site_base_path(), ENT_QUOTES, 'UTF-8'); ?>/admin/posts/reject?id=<?php echo (int)$post->id; ?>" class="core-action-link" style="color: var(--admin-danger-text); font-weight: 600;">&#10007; Reject</button> |
                                    <?php endif; ?>
                                    <?php if ($canEditThisPost): ?>
                                        <?php
                                        $simplePostEditUrl = site_path('/admin/posts/edit?id=' . (int)$post->id);
                                        $builderPostEditUrl = \FavoriteCMS\Core\Hook::applyFilters('favorite_page_builder_post_edit_url', '', (int)$post->id);
                                        $builderPostEditUrl = is_string($builderPostEditUrl) && str_starts_with($builderPostEditUrl, '/') && !str_starts_with($builderPostEditUrl, '//') ? $builderPostEditUrl : '';
                                        ?>
                                        <?php if ($builderPostEditUrl !== ''): ?>
                                            <details class="fpb-edit-picker">
                                                <summary>Edit <span aria-hidden="true">▾</span></summary>
                                                <div class="fpb-edit-picker-menu">
                                                    <a href="<?php echo htmlspecialchars($simplePostEditUrl, ENT_QUOTES, 'UTF-8'); ?>">Simple Edit</a>
                                                    <a href="<?php echo htmlspecialchars($builderPostEditUrl, ENT_QUOTES, 'UTF-8'); ?>">Favorite Page Builder Edit</a>
                                                </div>
                                            </details> |
                                        <?php else: ?>
                                            <a href="<?php echo htmlspecialchars($simplePostEditUrl, ENT_QUOTES, 'UTF-8'); ?>">Edit</a> |
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <a href="<?php echo htmlspecialchars(site_path('/post/'), ENT_QUOTES, 'UTF-8'); ?><?php echo htmlspecialchars($post->slug, ENT_QUOTES, 'UTF-8'); ?>" target="_blank">View Post</a>
                                    <?php if ($canDeleteThisPost): ?>
                                        | <button type="submit" form="core-action-form" formmethod="POST" formnovalidate formaction="<?php echo htmlspecialchars(site_base_path(), ENT_QUOTES, 'UTF-8'); ?>/admin/posts/trash?id=<?php echo (int)$post->id; ?>" class="core-action-link" style="color: var(--wp-danger);">Trash</button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($author->name ?? $author->username ?? 'Admin', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php if (!empty($cats)): ?>
                                <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                    <?php foreach ($cats as $c): ?>
                                        <span style="background: var(--admin-info-bg); color: var(--admin-info-text); padding: 1px 6px; border-radius: 3px; font-size: 11px;">
                                            <?php echo htmlspecialchars($c->name, ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span style="color: var(--wp-text-muted);">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($tags)): ?>
                                <span style="font-size: 12px; color: var(--admin-text-muted);">
                                    <?php echo htmlspecialchars(implode(', ', array_map(fn($t) => $t->name, $tags)), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            <?php else: ?>
                                <span style="color: var(--wp-text-muted);">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center;">
                            <span style="display: inline-block; min-width: 22px; height: 22px; line-height: 22px; background: var(--admin-surface-subtle); border-radius: 11px; font-size: 11px; font-weight: 600; color: var(--admin-text-muted);">
                                <?php echo $commentCount; ?>
                            </span>
                        </td>
                        <td>
                            <?php
                            $badgeStyle = match ($post->status) {
                                'published' => 'background: var(--admin-success-bg); color: var(--admin-success-text);',
                                'pending'   => 'background: var(--admin-warning-bg); color: var(--admin-warning-text); font-weight: 700;',
                                'rejected'  => 'background: var(--admin-danger-bg); color: var(--admin-danger-text);',
                                'trash'     => 'background: var(--admin-danger-bg); color: var(--admin-danger-text);',
                                default     => 'background: var(--admin-surface-subtle); color: var(--admin-text-muted);',
                            };
                            $badgeLabel = match ($post->status) {
                                'pending'   => 'Pending Review',
                                'published' => 'Published',
                                'rejected'  => 'Rejected',
                                'trash'     => 'Trash',
                                'draft'     => 'Draft',
                                default     => ucfirst((string)$post->status),
                            };
                            ?>
                            <span style="display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; text-transform: uppercase; font-weight: 600; <?php echo $badgeStyle; ?>">
                                <?php echo htmlspecialchars($badgeLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>
                        <td>
                            <div style="font-size: 13px;">
                                <?php echo format_date(($post->status==='scheduled'?$post->scheduled_at:($post->published_at??$post->created_at)), 'Y/m/d'); ?>
                            </div>
                            <div style="font-size: 11px; color: var(--wp-text-muted);">
                                <?php echo format_date(($post->status==='scheduled'?$post->scheduled_at:($post->published_at??$post->created_at)), 'g:i a'); ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    initAdminMultiSelect('posts-bulk-form', { itemType: 'post' });
});
</script>

<?php if (!empty($totalPages) && $totalPages > 1): ?>
    <div style="display: flex; justify-content: flex-end; align-items: center; gap: 6px; margin-top: 16px;">
        <span style="font-size: 12px; color: var(--wp-text-muted); margin-right: 8px;">
            <?php echo (int)$totalItems; ?> items &bull; Page <?php echo (int)$currentPage; ?> of <?php echo (int)$totalPages; ?>
        </span>
        <?php
        $pageParams = $_GET;
        ?>
        <?php if ($currentPage > 1): ?>
            <?php $pageParams['p'] = $currentPage - 1; ?>
            <a href="<?php echo htmlspecialchars(site_path('/admin/posts?'), ENT_QUOTES, 'UTF-8'); ?><?php echo http_build_query($pageParams); ?>" class="btn btn-secondary" style="padding: 4px 8px; font-size: 12px;">&laquo; Prev</a>
        <?php endif; ?>

        <?php if ($currentPage < $totalPages): ?>
            <?php $pageParams['p'] = $currentPage + 1; ?>
            <a href="<?php echo htmlspecialchars(site_path('/admin/posts?'), ENT_QUOTES, 'UTF-8'); ?><?php echo http_build_query($pageParams); ?>" class="btn btn-secondary" style="padding: 4px 8px; font-size: 12px;">Next &raquo;</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    initAdminMultiSelect('pages-bulk-form', { itemType: 'page' });
    const pickers = Array.from(document.querySelectorAll('.fpb-edit-picker'));
    pickers.forEach(picker => picker.addEventListener('toggle', () => {
        if (!picker.open) return;
        pickers.forEach(other => { if (other !== picker) other.open = false; });
        const anchor = picker.querySelector('summary');
        const menu = picker.querySelector('.fpb-edit-picker-menu');
        const rect = anchor.getBoundingClientRect();
        const menuWidth = Math.min(270, Math.max(205, menu.offsetWidth));
        picker.style.setProperty('--fpb-menu-x', Math.max(8, Math.min(rect.left, window.innerWidth - menuWidth - 8)) + 'px');
        const menuHeight = menu.offsetHeight;
        picker.style.setProperty('--fpb-menu-y', (rect.bottom + menuHeight + 8 < window.innerHeight ? rect.bottom + 4 : Math.max(8, rect.top - menuHeight - 4)) + 'px');
    }));
    document.addEventListener('click', event => {pickers.forEach(picker => {if (picker.open && !picker.contains(event.target)) picker.open=false;});});
    document.addEventListener('keydown', event => {if(event.key==='Escape') pickers.forEach(picker=>picker.open=false);});
});
</script>

