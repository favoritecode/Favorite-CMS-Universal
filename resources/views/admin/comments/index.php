<div class="page-header">
    <h1 class="page-title">Comments</h1>
</div>

<ul class="subsubsub">
    <li><a href="/admin/comments?status=all" class="<?php echo $status === 'all' ? 'current' : ''; ?>">All (<?php echo $counts['all'] ?? 0; ?>)</a> |</li>
    <li><a href="/admin/comments?status=pending" class="<?php echo $status === 'pending' ? 'current' : ''; ?>">Pending (<?php echo $counts['pending'] ?? 0; ?>)</a> |</li>
    <li><a href="/admin/comments?status=approved" class="<?php echo $status === 'approved' ? 'current' : ''; ?>">Approved (<?php echo $counts['approved'] ?? 0; ?>)</a> |</li>
    <li><a href="/admin/comments?status=spam" class="<?php echo $status === 'spam' ? 'current' : ''; ?>">Spam (<?php echo $counts['spam'] ?? 0; ?>)</a> |</li>
    <li><a href="/admin/comments?status=trash" class="<?php echo $status === 'trash' ? 'current' : ''; ?>">Trash (<?php echo $counts['trash'] ?? 0; ?>)</a></li>
</ul>

<div class="wp-table-wrap">
    <table class="wp-table">
        <thead>
            <tr>
                <th style="width: 180px;">Author</th>
                <th>Comment</th>
                <th style="width: 200px;">In Response To</th>
                <th style="width: 140px;">Submitted On</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($comments)): ?>
                <tr>
                    <td colspan="4" style="text-align: center; color: var(--wp-text-muted); padding: 24px;">No comments found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($comments as $comment): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($comment->author_name, ENT_QUOTES, 'UTF-8'); ?></strong>
                            <div style="font-size: 12px; color: var(--wp-text-muted);">
                                <a href="mailto:<?php echo htmlspecialchars($comment->author_email, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($comment->author_email, ENT_QUOTES, 'UTF-8'); ?></a>
                            </div>
                        </td>
                        <td>
                            <div style="margin-bottom: 6px;">
                                <?php echo nl2br(htmlspecialchars($comment->content, ENT_QUOTES, 'UTF-8')); ?>
                            </div>
                            <div class="row-actions">
                                <?php if ($comment->status === 'pending'): ?>
                                    <a href="/admin/comments/approve?id=<?php echo (int)$comment->id; ?>" style="color: var(--wp-success); font-weight: 600;">Approve</a> |
                                <?php elseif ($comment->status === 'approved'): ?>
                                    <a href="/admin/comments/unapprove?id=<?php echo (int)$comment->id; ?>" style="color: #b35900;">Unapprove</a> |
                                <?php endif; ?>

                                <?php if ($comment->status !== 'spam'): ?>
                                    <a href="/admin/comments/spam?id=<?php echo (int)$comment->id; ?>" style="color: var(--wp-danger);">Spam</a> |
                                <?php endif; ?>

                                <?php if ($comment->status === 'trash'): ?>
                                    <a href="/admin/comments/approve?id=<?php echo (int)$comment->id; ?>" style="color: var(--wp-success);">Restore</a> |
                                    <a href="/admin/comments/delete?id=<?php echo (int)$comment->id; ?>" onclick="return confirm('Permanently delete this comment?');" style="color: var(--wp-danger);">Delete Permanently</a>
                                <?php else: ?>
                                    <a href="/admin/comments/trash?id=<?php echo (int)$comment->id; ?>" style="color: var(--wp-danger);">Trash</a>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($post = $comment->getPost()): ?>
                                <a href="/post/<?php echo htmlspecialchars($post->slug, ENT_QUOTES, 'UTF-8'); ?>" target="_blank">
                                    <?php echo htmlspecialchars($post->title, ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                            <?php else: ?>
                                <span style="color: var(--wp-text-muted);">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo date('Y/m/d \a\t g:i a', strtotime($comment->created_at)); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

