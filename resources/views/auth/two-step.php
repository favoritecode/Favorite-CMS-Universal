<section class="fc-auth__card">
<h1 class="fc-auth__title">Verify your sign-in</h1>
<p class="fc-auth__subtitle"><?= $e($method==='email'?'Enter the code sent to your account email, or a recovery code.':'Enter a code from your authenticator app, or a recovery code.') ?></p>
<?php if($error): ?><div class="fc-alert fc-alert--error" role="alert"><?= $e($error) ?></div><?php endif ?>
<form class="fc-form" method="post" action="<?= $e($url('/admin/two-step')) ?>">
<input type="hidden" name="_token" value="<?= $e($token) ?>">
<label for="two-step-code">Verification code</label><input class="fc-input" id="two-step-code" name="code" type="text" autocomplete="one-time-code" maxlength="32" required autofocus>
<button type="submit" class="fc-btn fc-btn--primary fc-btn--block">Verify &amp; sign in</button></form>
<form class="fc-form" style="margin-top:20px;display:grid;gap:10px" method="post" action="<?= $e($url('/admin/two-step')) ?>"><input type="hidden" name="_token" value="<?= $e($token) ?>"><?php if($method==='email'): ?><button type="submit" class="fc-btn fc-btn--ghost fc-btn--block" name="action" value="resend">Resend email code</button><?php endif ?><button type="submit" class="fc-btn fc-btn--secondary fc-btn--block" name="action" value="cancel"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 5-7 7 7 7M5 12h14"/></svg>Back to sign in</button></form>
</section>
