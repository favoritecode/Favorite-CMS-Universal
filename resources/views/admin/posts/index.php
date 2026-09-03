<div class="page-header">
    <h1 class="page-title">Posts</h1>
    <a href="/admin/posts/new" class="btn btn-primary">Add New Post</a>
</div>

<ul class="subsubsub">
    <li><a href="/admin/posts?status=all" class="<?php echo $status === 'all' ? 'current' : ''; ?>">All (<?php echo (int)($counts['all'] ?? 0); ?>)</a> |</li>
    <li><a href="/admin/posts?status=published" class="<?php echo $status === 'published' ? 'current' : ''; ?>">Published (<?php echo (int)($counts['published'] ?? 0); ?>)</a> |</li>
    <li><a href="/admin/posts?status=pending" class="<?php echo $status === 'pending' ? 'current' : ''; ?>" style="<?php echo ($counts['pending'] ?? 0) > 0 ? 'font-weight: 700; color: #b45309;' : ''; ?>">Pending Review (<?php echo (int)($counts['pending'] ?? 0); ?>)</a> |</li>
    <li><a href="/admin/posts?status=draft" class="<?php echo $status === 'draft' ? 'current' : ''; ?>">Drafts (<?php echo (int)($counts['draft'] ?? 0); ?>)</a> |</li>
    <li><a href="/admin/posts?status=rejected" class="<?php echo $status === 'rejected' ? 'current' : ''; ?>">Rejected (<?php echo (int)($counts['rejected'] ?? 0); ?>)</a> |</li>
    <li><a href="/admin/posts?status=trash" class="<?php echo $status === 'trash' ? 'current' : ''; ?>">Trash (<?php echo (int)($counts['trash'] ?? 0); ?>)</a></li>
</ul>

<form method="GET" action="/admin/posts" style="margin-bottom: 16px; display: flex; gap: 8px;">
    <input type="hidden" name="status" value="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="text" name="s" class="form-control" style="max-width: 280px;" placeholder="Search posts..." value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
    <button type="submit" class="btn btn-secondary">Search Posts</button>
    <?php if ($search !== ''): ?>
        <a href="/admin/posts?status=<?php echo urlencode($status); ?>" class="btn btn-secondary" style="display: flex; align-items: center;">Clear</a>
    <?php endif; ?>
</form>

<div class="wp-table-wrap">
    <table class="wp-table">
        <thead>
            <tr>
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
                    <td colspan="8" style="text-align: center; color: var(--wp-text-muted); padding: 24px;">No posts found.</td>
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
                        <td>
                            <?php if ($featImg && !empty($featImg->url)): ?>
                                <img src="<?php echo htmlspecialchars($featImg->url, ENT_QUOTES, 'UTF-8'); ?>" 
                                     alt="Thumbnail" 
                                     style="width: 44px; height: 44px; object-fit: cover; border-radius: 4px; border: 1px solid #e2e8f0;"
                                     onerror="this.style.display='none';">
                            <?php else: ?>
                                <div style="width: 44px; height: 44px; background: #f1f5f9; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 16px;">
                                    &#128247;
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong>
                                <a href="/admin/posts/edit?id=<?php echo (int)$post->id; ?>" style="color: #1d2327; font-size: 14px; font-weight: 600;">
                                    <?php echo htmlspecialchars($post->title, ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                            </strong>
                            <div class="row-actions">
                                <?php if ($post->status === 'trash'): ?>
                                    <a href="/admin/posts/restore?id=<?php echo (int)$post->id; ?>" style="color: var(--wp-blue);">Restore</a> |
                                    <a href="/admin/posts/delete?id=<?php echo (int)$post->id; ?>" onclick="return confirm('Permanently delete this post?');" style="color: var(--wp-danger);">Delete Permanently</a>
                                <?php else: ?>
                                    <?php if ($post->status === 'pending' && $currentUser && $currentUser->canModeratePosts()): ?>
                                        <a href="/admin/posts/approve?id=<?php echo (int)$post->id; ?>" style="color: #00a32a; font-weight: 700;">&#10003; Approve</a> |
                                        <a href="/admin/posts/reject?id=<?php echo (int)$post->id; ?>" style="color: #d63638; font-weight: 600;">&#10007; Reject</a> |
                                    <?php endif; ?>
                                    <a href="/admin/posts/edit?id=<?php echo (int)$post->id; ?>">Edit</a> |
                                    <a href="/post/<?php echo htmlspecialchars($post->slug, ENT_QUOTES, 'UTF-8'); ?>" target="_blank">View Post</a> |
                                    <a href="/admin/posts/trash?id=<?php echo (int)$post->id; ?>" style="color: var(--wp-danger);">Trash</a>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($author->name ?? $author->username ?? 'Admin', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php if (!empty($cats)): ?>
                                <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                    <?php foreach ($cats as $c): ?>
                                        <span style="background: #e0f2fe; color: #0284c7; padding: 1px 6px; border-radius: 3px; font-size: 11px;">
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
                                <span style="font-size: 12px; color: #64748b;">
                                    <?php echo htmlspecialchars(implode(', ', array_map(fn($t) => $t->name, $tags)), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            <?php else: ?>
                                <span style="color: var(--wp-text-muted);">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center;">
                            <span style="display: inline-block; min-width: 22px; height: 22px; line-height: 22px; background: #f1f5f9; border-radius: 11px; font-size: 11px; font-weight: 600; color: #475569;">
                                <?php echo $commentCount; ?>
                            </span>
                        </td>
                        <td>
                            <?php
                            $badgeStyle = match ($post->status) {
                                'published' => 'background: #dcfce7; color: #15803d;',
                                'pending'   => 'background: #fef3c7; color: #92400e; font-weight: 700;',
                                'rejected'  => 'background: #fee2e2; color: #991b1b;',
                                'trash'     => 'background: #fee2e2; color: #991b1b;',
                                default     => 'background: #f1f5f9; color: #475569;',
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
                                <?php echo date('Y/m/d', strtotime($post->published_at ?? $post->created_at)); ?>
                            </div>
                            <div style="font-size: 11px; color: var(--wp-text-muted);">
                                <?php echo date('g:i a', strtotime($post->published_at ?? $post->created_at)); ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

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
            <a href="/admin/posts?<?php echo http_build_query($pageParams); ?>" class="btn btn-secondary" style="padding: 4px 8px; font-size: 12px;">&laquo; Prev</a>
        <?php endif; ?>

        <?php if ($currentPage < $totalPages): ?>
            <?php $pageParams['p'] = $currentPage + 1; ?>
            <a href="/admin/posts?<?php echo http_build_query($pageParams); ?>" class="btn btn-secondary" style="padding: 4px 8px; font-size: 12px;">Next &raquo;</a>
        <?php endif; ?>
    </div>
<?php endif; ?>
