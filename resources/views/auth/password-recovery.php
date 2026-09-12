<?php $pageTitle = $reset ? 'Reset password' : 'Forgot password'; ?>
<section class="fc-auth__card" aria-labelledby="auth-title">
    <header><h1 class="fc-auth__title" id="auth-title"><?php echo $e($pageTitle); ?></h1></header>
    <?php if ($error !== ''): ?><div class="fc-alert fc-alert--error" role="alert"><?php echo $e($error); ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="fc-alert fc-alert--success" role="status"><?php echo $e($notice); ?></div><?php endif; ?>
    <form class="fc-form" method="POST" action="<?php echo $e($url($reset ? '/reset-password' : '/forgot-password')); ?>" data-enhance-form>
        <input type="hidden" name="_token" value="<?php echo $e($token); ?>">
        <?php if ($reset): ?>
            <input type="hidden" name="reset_token" id="reset-token" value="" disabled>
            <noscript><p>Paste the code after “#token=” in your reset link.</p><input name="reset_token" aria-label="Reset code" autocomplete="off" required></noscript>
            <?php echo $field(['id' => 'password', 'type' => 'password', 'label' => 'New password', 'required' => true, 'autocomplete' => 'new-password', 'hint' => '10–72 characters, including a letter and a number.', 'attrs' => ['minlength' => '10', 'maxlength' => '72']]); ?>
            <?php echo $field(['id' => 'password_confirm', 'type' => 'password', 'label' => 'Confirm new password', 'required' => true, 'autocomplete' => 'new-password']); ?>
        <?php else: ?>
            <?php echo $field(['id' => 'email', 'type' => 'email', 'label' => 'Email address', 'required' => true, 'autocomplete' => 'email']); ?>
        <?php endif; ?>
        <button class="fc-btn fc-btn--primary fc-btn--block" type="submit"><?php echo $reset ? 'Reset password' : 'Send reset link'; ?></button>
    </form>
    <p class="fc-auth__alt"><a href="<?php echo $e($url('/admin/login')); ?>">Back to login</a></p>
</section>
<?php if ($reset): ?>
<script>
(function () {
    var match = /^#token=([a-f0-9]{64})$/.exec(window.location.hash);
    if (match) {
        var field = document.getElementById('reset-token');
        field.value = match[1]; field.disabled = false;
        window.history.replaceState(null, '', window.location.pathname);
    }
})();
</script>
<?php endif; ?>
