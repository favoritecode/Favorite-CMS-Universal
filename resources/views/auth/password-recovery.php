<?php $pageTitle = $reset ? 'Reset password' : 'Forgot password'; ?>
<section class="fc-auth__card" aria-labelledby="auth-title">
    <header>
        <p class="fc-auth__eyebrow">Account recovery</p>
        <h1 class="fc-auth__title" id="auth-title"><?php echo $e($pageTitle); ?></h1>
        <p class="fc-auth__subtitle"><?php echo $reset ? 'Choose a new password to get back to your account.' : 'Enter your account email and we will send you a link to reset your password.'; ?></p>
    </header>
    <?php if ($error !== ''): ?><div class="fc-alert fc-alert--error" role="alert"><?php echo $e($error); ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="fc-alert fc-alert--success" role="status"><?php echo $e($notice); ?></div><?php endif; ?>
    <form class="fc-form" method="POST" action="<?php echo $e($url($reset ? '/reset-password' : '/forgot-password')); ?>" data-enhance-form>
        <input type="hidden" name="_token" value="<?php echo $e($token); ?>">
        <?php if ($reset): ?>
            <input type="hidden" name="reset_token" id="reset-token" value="" disabled>
            <noscript><p>Paste the code from your reset link if prompted.</p><input name="reset_token" aria-label="Reset code" autocomplete="off"></noscript>
            <?php echo $field(['id' => 'password', 'type' => 'password', 'label' => 'New password', 'required' => true, 'autocomplete' => 'new-password', 'toggle' => true, 'hint' => '10–72 characters, including a letter and a number.', 'attrs' => ['minlength' => '10', 'maxlength' => '72']]); ?>
            <?php echo $field(['id' => 'password_confirm', 'type' => 'password', 'label' => 'Confirm new password', 'required' => true, 'autocomplete' => 'new-password', 'toggle' => true, 'attrs' => ['data-match' => 'password', 'data-match-message' => 'The passwords do not match.']]); ?>
        <?php else: ?>
            <?php echo $field(['id' => 'email', 'type' => 'text', 'label' => 'Email address or username', 'required' => true, 'autocomplete' => 'username email']); ?>
        <?php endif; ?>
        <button class="fc-btn fc-btn--primary fc-btn--block" type="submit" data-busy-label="<?php echo $reset ? 'Updating password...' : 'Sending reset link...'; ?>"><?php echo $reset ? 'Reset password' : 'Send reset link'; ?></button>
    </form>
    <p class="fc-auth__alt"><a href="<?php echo $e($url('/admin/login')); ?>">Back to login</a></p>
</section>
<?php if ($reset): ?>
<script>
(function () {
    var token = null;
    try {
        var params = new URLSearchParams(window.location.search);
        if (params.has('token') && /^[a-f0-9]{64}$/.test(params.get('token'))) {
            token = params.get('token');
        }
    } catch (e) {}
    if (!token && window.location.hash) {
        var match = /^[#?]token=([a-f0-9]{64})$/.exec(window.location.hash);
        if (match) {
            token = match[1];
        }
    }
    if (token) {
        var field = document.getElementById('reset-token');
        if (field) {
            field.value = token;
            field.disabled = false;
        }
        if (window.history && window.history.replaceState) {
            window.history.replaceState(null, '', window.location.pathname);
        }
    }
})();
</script>
<?php endif; ?>
