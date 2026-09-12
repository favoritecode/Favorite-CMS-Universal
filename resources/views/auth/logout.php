<?php $pageTitle = 'Log out'; ?>
<section class="fc-auth__card">
    <h1 class="fc-auth__title">Log out</h1>
    <form method="POST" action="<?php echo $e($url($logoutAction)); ?>">
        <input type="hidden" name="_token" value="<?php echo $e($token); ?>">
        <?php if ($redirect !== null): ?><input type="hidden" name="redirect" value="<?php echo $e($redirect); ?>"><?php endif; ?>
        <button type="submit" class="fc-btn fc-btn--primary">Log out</button>
    </form>
</section>
