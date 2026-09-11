<?php
/**
 * @var \Closure    $e
 * @var \Closure    $field
 * @var \Closure    $url
 * @var string      $siteName
 * @var string      $token
 * @var string|null $error
 * @var string|null $success
 * @var string      $email
 */
$pageTitle = 'Resend verification email - ' . $siteName;
?>
<section class="fc-auth__card" aria-labelledby="auth-title">
    <header>
        <h1 class="fc-auth__title" id="auth-title">Resend verification email</h1>
        <p class="fc-auth__subtitle">Enter the email address you registered with. If the account still needs verification, we will send a new link.</p>
    </header>

    <?php if (!empty($error)): ?>
        <div class="fc-alert fc-alert--error" role="alert"><p><?php echo $e($error); ?></p></div>
    <?php elseif (!empty($success)): ?>
        <div class="fc-alert fc-alert--success" role="status"><p><?php echo $e($success); ?></p></div>
    <?php endif; ?>

    <form class="fc-form" method="POST" action="<?php echo $e($url('/resend-verification')); ?>" data-enhance-form>
        <input type="hidden" name="_token" value="<?php echo $e($token); ?>">
        <?php echo $field(['id' => 'email', 'label' => 'Email address', 'type' => 'email', 'value' => $email, 'required' => true, 'autocomplete' => 'email', 'attrs' => ['autofocus' => true, 'data-error' => 'Enter a valid email address.']]); ?>
        <button type="submit" class="fc-btn fc-btn--primary fc-btn--block" data-busy-label="Sending...">Send verification link</button>
    </form>

    <div class="fc-auth__alt">
        <p><a href="<?php echo $e($url('/admin/login')); ?>">&larr; Back to log in</a></p>
    </div>
</section>
