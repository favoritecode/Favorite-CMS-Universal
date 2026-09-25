<?php $pageTitle = 'Log out'; ?>
<section class="fc-auth__card" aria-labelledby="auth-title">
    <header>
        <p class="fc-auth__eyebrow">See you again soon</p>
        <h1 class="fc-auth__title" id="auth-title">Ready to log out?</h1>
        <p class="fc-auth__subtitle">You will be signed out of this browser. You can log back in whenever you need to.</p>
    </header>
    <form class="fc-form" method="POST" action="<?php echo $e($url($logoutAction)); ?>" data-enhance-form>
        <input type="hidden" name="_token" value="<?php echo $e($token); ?>">
        <?php if ($redirect !== null): ?><input type="hidden" name="redirect" value="<?php echo $e($redirect); ?>"><?php endif; ?>
        <button type="submit" class="fc-btn fc-btn--primary fc-btn--block" data-busy-label="Logging out...">Yes, log out</button>
        <a class="fc-btn fc-btn--secondary fc-btn--block" href="<?php echo $e($url('/')); ?>">Cancel and return to site</a>
    </form>
</section>
