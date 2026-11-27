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
    if (!empty($fcNotice['verified_notice'])) {
        $fcClient->markVerifiedNoticeShown();
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
        This website has not completed its automatic connection to Favorite CMS Central within the allowed 3 days,
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
<?php if (is_array($fcNotice) && !empty($fcNotice['license_alerts'])): ?>
<div class="notice notice-warning" role="status">
    <strong>License attention needed</strong>
    <ul style="margin: 6px 0 0 18px;">
    <?php foreach ($fcNotice['license_alerts'] as $fcAlert): ?>
        <li><code><?php echo $fcEsc((string)$fcAlert['id']); ?></code> &mdash; <?php echo $fcEsc((string)$fcAlert['label']); ?>. <?php echo $fcEsc((string)$fcAlert['detail']); ?>
            <a href="<?php echo $fcEsc(site_path('/admin/page/central-connection') . '?tab=' . (!empty($fcAlert['theme']) ? 'themes' : 'plugins') . '&focus=' . rawurlencode((string)$fcAlert['id'])); ?>">Buy a license</a></li>
    <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
<?php if (is_array($fcNotice) && !empty($fcNotice['verified_notice'])): ?>
<div class="notice notice-success" role="status">
    <strong>Verified.</strong> The Favorite CMS owner has verified this website. Your Free license is permanent and nothing more is needed.
</div>
<?php endif; ?>
<?php if (is_array($fcNotice) && !empty($fcNotice['unverified'])): ?>
<div class="notice notice-info" role="status">
    Your website is connected to Favorite CMS Central but is <strong>not verified yet</strong>.
    <?php if (($fcNotice['verify_hours_left'] ?? null) !== null): ?>It stays active for about <?php echo (int)$fcNotice['verify_hours_left']; ?> more hour(s) while the Favorite CMS owner verifies it.<?php endif; ?>
    If it is not verified in time, public access pauses. Install the latest official update (Software updates) and contact the Favorite CMS owner at favoriteweb.net if needed. Automatic domain verification is retried on its own.
</div>
<?php endif; ?>
<?php if (is_array($fcNotice) && !empty($fcNotice['welcome'])): ?>
<style>
/* The admin layout resets every margin, which would pin a dialog to the top-left corner: centre it explicitly and follow the admin light/dark variables. */
.fc-welcome{position:fixed;inset:0;margin:auto;width:min(30rem,calc(100% - 2rem));height:fit-content;max-height:calc(100vh - 2rem);overflow:auto;padding:1.75rem 1.75rem 1.5rem;border:1px solid var(--admin-border,#e2e8f0);border-radius:16px;background:var(--admin-surface-elevated,var(--admin-surface,#fff));color:var(--admin-text,#0f172a);box-shadow:0 24px 70px rgba(2,6,23,.45);text-align:center}
.fc-welcome::backdrop{background:rgba(2,6,23,.62)}
.fc-welcome__icon{font-size:2.6rem;line-height:1}
.fc-welcome h2{margin:.6rem 0 .4rem;font-size:1.4rem;color:var(--admin-text-heading,var(--admin-text,#0f172a))}
.fc-welcome p{margin:0 0 1.2rem;line-height:1.6;color:var(--admin-text,#0f172a)}
.fc-welcome button{min-width:9rem;padding:.65rem 1.4rem;border:0;border-radius:10px;font:inherit;font-weight:700;cursor:pointer;background:var(--admin-primary,#2563eb);color:#fff}
.fc-welcome button:hover{background:var(--admin-primary-hover,#1d4ed8)}
.fc-welcome button:focus-visible{outline:3px solid var(--admin-border-focus,#3b82f6);outline-offset:3px}
</style>
<dialog id="fc-welcome-dialog" class="fc-welcome" aria-labelledby="fc-welcome-title">
    <div class="fc-welcome__icon" aria-hidden="true">&#127881;</div>
    <h2 id="fc-welcome-title">Congratulations!</h2>
    <p>Your <strong>Favorite CMS Free License</strong> has been activated successfully. Your website is connected to CMS Central Control and no extra setup is needed.</p>
    <form method="dialog"><button type="submit" autofocus>Continue</button></form>
</dialog>
<script>
(function () {
    var d = document.getElementById('fc-welcome-dialog');
    if (!d) { return; }
    try { if (typeof d.showModal === 'function') { d.showModal(); } else { d.setAttribute('open', ''); } } catch (e) { d.setAttribute('open', ''); }
    d.addEventListener('click', function (e) { if (e.target === d) { d.close ? d.close() : d.removeAttribute('open'); } });
})();
</script>
<?php endif; ?>
