<?php
/**
 * @var \Closure    $e
 * @var \Closure    $field
 * @var \Closure    $url
 * @var string      $siteName
 * @var string      $token
 * @var string|null $redirect   Validated local return path (Phase 1 SafeRedirect)
 * @var string|null $error
 * @var array       $old
 * @var bool        $registrationEnabled
 */
$pageTitle = 'Create an account - ' . $siteName;
$loginHref = $url('/admin/login') . ($redirect !== null ? '?redirect=' . rawurlencode($redirect) : '');
$disabled = !$registrationEnabled;
?>
<section class="fc-auth__card" aria-labelledby="auth-title">
    <header>
        <h1 class="fc-auth__title" id="auth-title">Create your account</h1>
        <p class="fc-auth__subtitle">Join <?php echo $e($siteName); ?> to comment and take part.</p>
    </header>

    <?php if (!empty($error)): ?>
        <div class="fc-alert <?php echo $disabled ? 'fc-alert--warning' : 'fc-alert--error'; ?>" role="alert" id="auth-alert"><p><?php echo $e($error); ?></p></div>
    <?php endif; ?>

    <form class="fc-form" method="POST" action="<?php echo $e($url('/register')); ?>" data-enhance-form>
        <input type="hidden" name="_token" value="<?php echo $e($token); ?>">
        <?php if ($redirect !== null): ?>
            <input type="hidden" name="redirect" value="<?php echo $e($redirect); ?>">
        <?php endif; ?>

        <?php echo $field(['id' => 'username', 'label' => 'Username', 'value' => $old['username'] ?? '', 'required' => true, 'autocomplete' => 'username', 'hint' => '3-30 characters: letters, numbers, dots, dashes or underscores.', 'attrs' => ['autofocus' => !$disabled, 'autocapitalize' => 'none', 'spellcheck' => 'false', 'pattern' => '[a-zA-Z0-9_.\-]{3,30}', 'disabled' => $disabled, 'data-error' => 'Use 3-30 letters, numbers, dots, dashes or underscores.']]); ?>
        <?php echo $field(['id' => 'name', 'label' => 'Display name', 'value' => $old['name'] ?? '', 'optional' => true, 'autocomplete' => 'name', 'hint' => 'Shown next to your comments. Defaults to your username.', 'attrs' => ['disabled' => $disabled]]); ?>
        <?php echo $field(['id' => 'email', 'label' => 'Email address', 'type' => 'email', 'value' => $old['email'] ?? '', 'required' => true, 'autocomplete' => 'email', 'attrs' => ['disabled' => $disabled, 'data-error' => 'Enter a valid email address.']]); ?>
        <?php echo $field(['id' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'toggle' => true, 'describedby' => ['register-password-rules'], 'attrs' => ['minlength' => '8', 'disabled' => $disabled, 'data-error' => 'Use at least 8 characters.']]); ?>
        <?php echo $field(['id' => 'password_confirmation', 'label' => 'Confirm password', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'toggle' => true, 'attrs' => ['minlength' => '8', 'disabled' => $disabled, 'data-match' => 'password', 'data-match-message' => 'The passwords do not match.', 'data-error' => 'Re-enter your password.']]); ?>

        <ul class="fc-rules" id="register-password-rules" data-password-rules="password" data-password-confirm="password_confirmation" aria-label="Password requirements">
            <li data-rule="min:8">At least 8 characters<span class="fc-visually-hidden" data-rule-state></span></li>
            <li data-rule="match">Both passwords match<span class="fc-visually-hidden" data-rule-state></span></li>
        </ul>

        <button type="submit" class="fc-btn fc-btn--primary fc-btn--block" data-busy-label="Creating account..."<?php echo $disabled ? ' disabled' : ''; ?>>Create account</button>
    </form>

    <div class="fc-auth__alt">
        <p>Already have an account? <a href="<?php echo $e($loginHref); ?>">Log in</a></p>
    </div>
</section>
