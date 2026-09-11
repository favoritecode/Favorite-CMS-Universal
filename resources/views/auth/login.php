<?php
/**
 * @var \Closure    $e
 * @var \Closure    $field
 * @var \Closure    $url
 * @var string      $siteName
 * @var string      $token
 * @var string|null $redirect   Validated local return path (Phase 1 SafeRedirect)
 * @var string      $error
 * @var string      $flash
 * @var string      $oldLogin
 * @var bool        $registrationEnabled
 */
$pageTitle = 'Log in - ' . $siteName;
$registerHref = $url('/register') . ($redirect !== null ? '?redirect=' . rawurlencode($redirect) : '');
?>
<section class="fc-auth__card" aria-labelledby="auth-title">
    <header>
        <h1 class="fc-auth__title" id="auth-title">Log in</h1>
        <p class="fc-auth__subtitle">Welcome back to <?php echo $e($siteName); ?>.</p>
    </header>

    <?php if ($error !== ''): ?>
        <div class="fc-alert fc-alert--error" role="alert" id="auth-alert"><p><?php echo $e($error); ?></p></div>
    <?php elseif ($flash !== ''): ?>
        <div class="fc-alert fc-alert--info" role="status" id="auth-alert"><p><?php echo $e($flash); ?></p></div>
    <?php endif; ?>

    <form class="fc-form" method="POST" action="<?php echo $e($url('/admin/login')); ?>" data-enhance-form>
        <input type="hidden" name="_token" value="<?php echo $e($token); ?>">
        <?php if ($redirect !== null): ?>
            <input type="hidden" name="redirect" value="<?php echo $e($redirect); ?>">
        <?php endif; ?>

        <?php echo $field(['id' => 'login', 'label' => 'Username or email', 'value' => $oldLogin, 'required' => true, 'autocomplete' => 'username', 'attrs' => ['autofocus' => $oldLogin === '', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'data-error' => 'Enter your username or email address.']]); ?>
        <?php echo $field(['id' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password', 'toggle' => true, 'attrs' => ['autofocus' => $oldLogin !== '', 'data-error' => 'Enter your password.']]); ?>

        <button type="submit" class="fc-btn fc-btn--primary fc-btn--block" data-busy-label="Logging in...">Log in</button>
    </form>

    <div class="fc-auth__alt">
        <?php if ($registrationEnabled): ?>
            <p>New here? <a href="<?php echo $e($registerHref); ?>">Create an account</a></p>
        <?php endif; ?>
        <p><a href="<?php echo $e($url('/resend-verification')); ?>">Resend the email verification link</a></p>
    </div>
</section>
