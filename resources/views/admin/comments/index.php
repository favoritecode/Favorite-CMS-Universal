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

<form method="POST" action="/admin/comments/bulk" id="comments-bulk-form">
    <input type="hidden" name="_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="status" value="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">

    <div class="bulk-actions-wrap">
        <select name="bulk_action" class="form-control" style="width: auto; max-width: 200px; display: inline-block;">
            <option value="">Bulk Actions</option>
            <?php if ($status === 'trash'): ?>
                <option value="approve">Restore (Approve)</option>
                <option value="delete">Delete Permanently</option>
            <?php else: ?>
                <option value="approve">Approve</option>
                <option value="unapprove">Unapprove</option>
                <option value="spam">Mark as Spam</option>
                <option value="trash">Move to Trash</option>
                <option value="delete">Delete Permanently</option>
            <?php endif; ?>
        </select>
        <button type="submit" class="btn btn-secondary">Apply</button>
        <span class="bulk-count-badge">0 selected</span>
    </div>

    <div class="wp-table-wrap">
        <table class="wp-table">
            <thead>
                <tr>
                    <th style="width: 32px; text-align: center;">
                        <input type="checkbox" id="select-all-comments" data-select-all>
                    </th>
                    <th style="width: 180px;">Author</th>
                    <th>Comment</th>
                    <th style="width: 200px;">In Response To</th>
                    <th style="width: 140px;">Submitted On</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($comments)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; color: var(--wp-text-muted); padding: 24px;">No comments found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($comments as $comment): ?>
                        <tr>
                            <td style="text-align: center;">
                                <input type="checkbox" name="ids[]" value="<?php echo (int)$comment->id; ?>" class="bulk-cb">
                            </td>
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
                                    <?php 
                                    $csrfToken = htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); 
                                    ?>
                                    <?php if ($comment->status === 'pending'): ?>
                                        <a href="/admin/comments/approve?id=<?php echo (int)$comment->id; ?>&_token=<?php echo $csrfToken; ?>" style="color: var(--wp-success); font-weight: 600;">Approve</a> |
                                    <?php elseif ($comment->status === 'approved'): ?>
                                        <a href="/admin/comments/unapprove?id=<?php echo (int)$comment->id; ?>&_token=<?php echo $csrfToken; ?>" style="color: #b35900;">Unapprove</a> |
                                    <?php endif; ?>

                                    <?php if ($comment->status !== 'spam'): ?>
                                        <a href="/admin/comments/spam?id=<?php echo (int)$comment->id; ?>&_token=<?php echo $csrfToken; ?>" style="color: var(--wp-danger);">Spam</a> |
                                    <?php endif; ?>

                                    <?php if ($comment->status === 'trash'): ?>
                                        <a href="/admin/comments/approve?id=<?php echo (int)$comment->id; ?>&_token=<?php echo $csrfToken; ?>&status=trash" style="color: var(--wp-success);">Restore</a> |
                                        <a href="/admin/comments/delete?id=<?php echo (int)$comment->id; ?>&_token=<?php echo $csrfToken; ?>" onclick="return confirm('Permanently delete this comment?');" style="color: var(--wp-danger);">Delete Permanently</a>
                                    <?php else: ?>
                                        <a href="/admin/comments/trash?id=<?php echo (int)$comment->id; ?>&_token=<?php echo $csrfToken; ?>" style="color: var(--wp-danger);">Trash</a>
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
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    initAdminMultiSelect('comments-bulk-form', { itemType: 'comment' });
});
</script>

