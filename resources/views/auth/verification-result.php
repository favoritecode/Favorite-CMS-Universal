<?php
/**
 * @var \Closure $e
 * @var \Closure $url
 * @var string   $siteName
 * @var bool     $success
 * @var string   $message
 */
$title = $success ? 'Email verified' : 'Verification failed';
$pageTitle = $title . ' - ' . $siteName;
?>
<section class="fc-auth__card" aria-labelledby="auth-title">
    <span class="fc-status-icon <?php echo $success ? 'fc-status-icon--success' : 'fc-status-icon--error'; ?>" aria-hidden="true"><?php echo $success ? '&#10003;' : '!'; ?></span>
    <header>
        <h1 class="fc-auth__title" id="auth-title"><?php echo $e($title); ?></h1>
        <p class="fc-auth__subtitle"><?php echo $success ? 'Your email address is confirmed.' : 'We could not confirm your email address with this link.'; ?></p>
    </header>

    <div class="fc-alert <?php echo $success ? 'fc-alert--success' : 'fc-alert--error'; ?>" role="<?php echo $success ? 'status' : 'alert'; ?>">
        <p><?php echo $e($message); ?></p>
    </div>

    <div class="fc-auth__actions">
        <a class="fc-btn fc-btn--primary" href="<?php echo $e($url('/admin/login')); ?>">Log in</a>
        <a class="fc-btn fc-btn--secondary" href="<?php echo $e($url('/resend-verification')); ?>">Resend verification link</a>
    </div>
</section>
