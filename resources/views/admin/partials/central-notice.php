<?php
declare(strict_types=1);
/**
 * Connection notice and one-time welcome dialog for administrators.
 * Local-only (no network request) and must never interrupt an admin page.
 */
$fcNotice = null;
try {
    $fcClient = new \FavoriteCMS\Central\Client();
    $fcNotice = $fcClient->adminNotice();
    if (!empty($fcNotice['welcome'])) {
        $fcClient->markWelcomeShown();
    }
} catch (\Throwable) {
    $fcNotice = null;
}
$fcEsc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
if (is_array($fcNotice) && !empty($fcNotice['pending'])):
    $fcRecovery = function_exists('site_path') ? site_path('/admin/connection-status') : '/admin/connection-status';
?>
<div class="notice notice-warning" role="status">
    <?php if (!empty($fcNotice['restricted'])): ?>
        <strong>Public access is paused.</strong>
        This website has not completed its automatic connection to Favorite CMS Central within the allowed 24 hours,
        so visitors currently see a verification notice. Your content and settings are safe and administrator access stays available.
        The connection is retried automatically; open connection recovery to retry now.
    <?php else: ?>
        <strong>Your website is running normally.</strong>
        Its automatic connection to Favorite CMS Central is not complete yet, so your free license is not shown as active.
        The connection is retried automatically. It must complete within about <?php echo (int)($fcNotice['grace_hours_left'] ?? 0); ?> hour(s);
        otherwise public access is paused until it does. No license key is needed.
    <?php endif; ?>
    <?php if (($fcNotice['reason'] ?? '') !== ''): ?>
        <br><small>Reason: <?php echo $fcEsc((string)$fcNotice['reason']); ?><?php echo ($fcNotice['code'] ?? '') !== '' ? ' (' . $fcEsc((string)$fcNotice['code']) . ')' : ''; ?></small>
    <?php endif; ?>
    <br><a href="<?php echo $fcEsc($fcRecovery); ?>">Open connection recovery</a>
    &middot; <a href="<?php echo $fcEsc(function_exists('site_path') ? site_path('/admin/updates') : '/admin/updates'); ?>">Software updates</a>
</div>
<?php endif; ?>
<?php if (is_array($fcNotice) && !empty($fcNotice['unverified'])): ?>
<div class="notice notice-info" role="status">
    Your website is connected to Favorite CMS Central. Automatic domain verification is not possible on this hosting right now; this does not affect your website and is retried automatically.
</div>
<?php endif; ?>
<?php if (is_array($fcNotice) && !empty($fcNotice['welcome'])): ?>
<dialog id="fc-welcome-dialog" aria-labelledby="fc-welcome-title" style="max-width:30rem;width:calc(100% - 2rem);border:0;border-radius:14px;padding:1.75rem;box-shadow:0 20px 60px rgba(0,0,0,.35);background:var(--admin-card-bg,#fff);color:var(--admin-text,#192b48)">
    <div style="font-size:2.25rem;line-height:1" aria-hidden="true">&#127881;</div>
    <h2 id="fc-welcome-title" style="margin:.5rem 0 .25rem;font-size:1.35rem">Congratulations!</h2>
    <p style="margin:.25rem 0 1rem">Your <strong>Favorite CMS Free License</strong> has been activated successfully. Your website is connected to CMS Central Control and no extra setup is needed.</p>
    <form method="dialog"><button class="button button-primary" autofocus>Continue</button></form>
</dialog>
<script>
(function () {
    var d = document.getElementById('fc-welcome-dialog');
    if (!d) { return; }
    try { if (typeof d.showModal === 'function') { d.showModal(); } else { d.setAttribute('open', ''); } } catch (e) { d.setAttribute('open', ''); }
})();
</script>
<?php endif; ?>
