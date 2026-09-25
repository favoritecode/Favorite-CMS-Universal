<?php
/**
 * Comments list and reply area. Authentication rules, CSRF, redirects and wording follow the
 * logged-in-only commenting behavior: only the presentation lives here.
 *
 * @var object      $post
 * @var array       $comments      Approved comments
 * @var string|null $commentNotice Notice passed by the controller after submission
 */
$comments          = is_array($comments ?? null) ? $comments : [];
$commentCount      = count($comments);
$commentUser       = function_exists('current_user') ? current_user() : null;
$commentBasePath   = (string)($GLOBALS['favorite_cms_base_path'] ?? '');
$commentReturnPath = '/post/' . $post->slug . '#comments';

$commentSuccess = '';
if (!empty($_SESSION['flash_comment_success'])) {
    $commentSuccess = (string)$_SESSION['flash_comment_success'];
    unset($_SESSION['flash_comment_success']);
} elseif (!empty($commentNotice)) {
    $commentSuccess = (string)$commentNotice;
}

$commentError = '';
if (!empty($_SESSION['comment_error'])) {
    $commentError = (string)$_SESSION['comment_error'];
    unset($_SESSION['comment_error']);
}
?>
<section class="comments" id="comments" aria-labelledby="comments-title">
    <h2 class="comments__title" id="comments-title">Discussion <span class="comments__count">(<?php echo $commentCount; ?>)</span></h2>

    <?php if ($commentSuccess !== ''): ?>
        <div class="notice notice--success" role="status"><?php echo fcd_e($commentSuccess); ?></div>
    <?php endif; ?>

    <?php if ($commentError !== ''): ?>
        <div class="notice notice--error" role="alert"><?php echo fcd_e($commentError); ?></div>
    <?php endif; ?>

    <?php if ($comments !== []): ?>
        <ol class="comment-list">
            <?php foreach ($comments as $comment): ?>
                <?php $commentAuthor = trim((string)($comment->author_name ?? '')) !== '' ? (string)$comment->author_name : 'Anonymous'; ?>
                <li class="comment" id="comment-<?php echo (int)$comment->id; ?>">
                    <div class="comment__avatar" aria-hidden="true"><?php echo fcd_e(fcd_initial($commentAuthor)); ?></div>
                    <div class="comment__main">
                        <p class="comment__meta">
                            <span class="comment__author"><?php echo fcd_e($commentAuthor); ?></span>
                            <a class="comment__permalink" href="#comment-<?php echo (int)$comment->id; ?>"><time datetime="<?php echo fcd_e(format_date($comment->created_at, 'c')); ?>"><?php echo fcd_e(format_date($comment->created_at, 'M j, Y \a\t g:i a')); ?></time></a>
                        </p>
                        <div class="comment__text"><?php echo nl2br(fcd_e($comment->content ?? '')); ?></div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php else: ?>
        <p class="comments__empty">No comments yet. Be the first to share your thoughts.</p>
    <?php endif; ?>

    <div class="comment-respond">
        <?php if ($commentUser): ?>
            <h3 class="comment-respond__title">Leave a Reply</h3>
            <p class="comment-respond__note">Commenting as <strong><?php echo htmlspecialchars((string)($commentUser->name ?: $commentUser->username), ENT_QUOTES, 'UTF-8'); ?></strong></p>

            <form class="comment-form" action="<?php echo htmlspecialchars($commentBasePath . '/post/' . $post->slug . '/comment', ENT_QUOTES, 'UTF-8'); ?>" method="POST">
                <input type="hidden" name="_token" value="<?php echo htmlspecialchars(function_exists('csrf_token') ? csrf_token() : ($_SESSION['_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="post_id" value="<?php echo (int)$post->id; ?>">
                <input type="hidden" name="post_slug" value="<?php echo htmlspecialchars($post->slug, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="form-field">
                    <label for="comment_content" class="form-label">Comment <span class="required" aria-hidden="true">*</span></label>
                    <textarea id="comment_content" name="content" class="form-textarea" rows="5" required placeholder="Share your thoughts..."></textarea>
                </div>

                <button type="submit" class="button button--primary">Post Comment</button>
            </form>
        <?php else: ?>
            <h3 class="comment-respond__title">Join the Discussion</h3>
            <p class="comment-respond__note">You need an account to comment on this article.</p>

            <div class="comment-auth-actions">
                <a href="<?php echo htmlspecialchars($commentBasePath . '/admin/login?redirect=' . rawurlencode($commentReturnPath), ENT_QUOTES, 'UTF-8'); ?>" class="button button--primary">Log in to comment</a>
                <?php if ((int)\FavoriteCMS\Models\Setting::get('general', 'allow_registration', 1)): ?>
                    <a href="<?php echo htmlspecialchars($commentBasePath . '/register?redirect=' . rawurlencode($commentReturnPath), ENT_QUOTES, 'UTF-8'); ?>" class="button button--secondary">Create an account</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
