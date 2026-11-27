<?php
declare(strict_types=1);
/**
 * License badge for an extension row/card.
 * Expects $lic (Client::describeLicense) and $buyUrl. Renders nothing for free, unmanaged extensions.
 */
if (empty($lic['managed'])) { return; }
$fcMap = ['ok' => 'success', 'info' => 'info', 'warn' => 'warning', 'bad' => 'danger'];
$fcV = $fcMap[$lic['tone'] ?? 'info'] ?? 'info';
$fcE = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?>
<div class="fc-license fc-license--<?php echo $fcV; ?>" role="status" style="margin-top: 8px; padding: 8px 10px; border: 1px solid var(--admin-<?php echo $fcV; ?>-border); background: var(--admin-<?php echo $fcV; ?>-bg); color: var(--admin-<?php echo $fcV; ?>-text); border-radius: 6px; font-size: 12px; line-height: 1.5; display: flex; gap: 6px 12px; align-items: center; flex-wrap: wrap;">
    <strong><?php echo $fcE((string)($lic['short'] ?? $lic['label'] ?? '')); ?></strong>
    <?php if (($lic['detail'] ?? '') !== ''): ?><span><?php echo $fcE((string)$lic['detail']); ?></span><?php endif; ?>
    <?php if (!empty($lic['buy']) || !empty($lic['manage'])): ?>
        <a href="<?php echo $fcE((string)($buyUrl ?? '#')); ?>" style="font-weight: 700; text-decoration: underline; color: inherit;"><?php echo !empty($lic['buy']) ? 'Buy a license' : 'Manage license'; ?></a>
    <?php endif; ?>
</div>
