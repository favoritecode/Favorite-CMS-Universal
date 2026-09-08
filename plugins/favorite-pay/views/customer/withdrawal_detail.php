<?php
/**
 * Customer Withdrawal Detail View
 *
 * @var \FavoriteCMS\Models\User $user
 * @var \FavoriteCMS\Pay\Domain\Withdrawal $withdrawal
 * @var bool $isSuspended
 */
$status = $withdrawal->getStatus();
$badgeClass = $status->badgeClass();
$amountMajor = \FavoriteCMS\Pay\Support\DecimalFormatter::minorUnitToDecimal($withdrawal->getAmount()->getAmount(), 2);
$feeMajor = \FavoriteCMS\Pay\Support\DecimalFormatter::minorUnitToDecimal($withdrawal->getFee()->getAmount(), 2);
$netMajor = \FavoriteCMS\Pay\Support\DecimalFormatter::minorUnitToDecimal($withdrawal->getNetAmount()->getAmount(), 2);
$curr = $withdrawal->getCurrency();
?>

<div style="max-width: 680px; margin: 0 auto;">
    <div style="margin-bottom: 20px;">
        <a href="/account/withdraw" style="color: #2563eb; text-decoration: none; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
            &larr; Back to Withdrawals
        </a>
    </div>

    <div class="fpay-card">
        <div class="fpay-card-header">
            <div>
                <h1 class="fpay-card-title" style="font-size: 20px;">Withdrawal Request</h1>
                <span style="font-family: monospace; font-size: 13px; color: #64748b;"><?php echo htmlspecialchars($withdrawal->getId(), ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div>
                <span class="fpay-badge <?php echo $badgeClass; ?>" style="font-size: 13px; padding: 6px 12px;">
                    <?php echo htmlspecialchars($status->label(), ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px; background: #f8fafc; padding: 18px; border-radius: 10px; border: 1px solid #e2e8f0;">
            <div>
                <div style="font-size: 12px; color: #64748b; text-transform: uppercase; font-weight: 600; margin-bottom: 4px;">Requested Amount</div>
                <div style="font-size: 20px; font-weight: 800; color: #0f172a;"><?php echo $curr . ' ' . $amountMajor; ?></div>
            </div>
            <div>
                <div style="font-size: 12px; color: #64748b; text-transform: uppercase; font-weight: 600; margin-bottom: 4px;">Net Payable</div>
                <div style="font-size: 20px; font-weight: 800; color: #15803d;"><?php echo $curr . ' ' . $netMajor; ?></div>
            </div>
            <div>
                <div style="font-size: 12px; color: #64748b; text-transform: uppercase; font-weight: 600; margin-bottom: 4px;">Processing Fee</div>
                <div style="font-size: 15px; font-weight: 600; color: #475569;"><?php echo $curr . ' ' . $feeMajor; ?></div>
            </div>
            <div>
                <div style="font-size: 12px; color: #64748b; text-transform: uppercase; font-weight: 600; margin-bottom: 4px;">Payout Method</div>
                <div style="font-size: 15px; font-weight: 600; color: #0f172a; text-transform: capitalize;"><?php echo htmlspecialchars(str_replace('_', ' ', $withdrawal->getMethod()), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>

        <div style="border-top: 1px solid #f1f5f9; padding-top: 18px; margin-bottom: 20px;">
            <h3 style="font-size: 15px; font-weight: 700; color: #1e293b; margin: 0 0 12px;">Destination Details</h3>
            <div style="font-size: 14px; color: #334155; line-height: 1.6;">
                <div>Masked Account: <strong style="font-family: monospace;"><?php echo htmlspecialchars($withdrawal->getDestinationMasked(), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <?php if ($withdrawal->getTransactionReference()): ?>
                    <div style="margin-top: 6px;">Transaction Reference / TrxID: <strong style="font-family: monospace; color: #2563eb;"><?php echo htmlspecialchars($withdrawal->getTransactionReference(), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <?php endif; ?>
            </div>
        </div>

        <div style="border-top: 1px solid #f1f5f9; padding-top: 18px; margin-bottom: 20px;">
            <h3 style="font-size: 15px; font-weight: 700; color: #1e293b; margin: 0 0 12px;">Timeline</h3>
            <div style="font-size: 13px; color: #64748b; line-height: 1.8;">
                <div>Requested on: <strong><?php echo htmlspecialchars($withdrawal->getCreatedAt(), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <?php if ($withdrawal->getUpdatedAt()): ?>
                    <div>Last update: <strong><?php echo htmlspecialchars($withdrawal->getUpdatedAt(), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <?php endif; ?>
                <?php if ($withdrawal->getProcessedAt()): ?>
                    <div>Completed on: <strong><?php echo htmlspecialchars($withdrawal->getProcessedAt(), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($withdrawal->getOperatorNotes()): ?>
            <div style="border-top: 1px solid #f1f5f9; padding-top: 18px; margin-bottom: 20px;">
                <h3 style="font-size: 15px; font-weight: 700; color: #1e293b; margin: 0 0 8px;">Processing Notes</h3>
                <div style="background: #f8fafc; border-left: 3px solid #cbd5e1; padding: 12px 14px; font-size: 14px; color: #475569;">
                    <?php echo nl2br(htmlspecialchars($withdrawal->getOperatorNotes(), ENT_QUOTES, 'UTF-8')); ?>
                </div>
            </div>
        <?php endif; ?>

        <div style="display: flex; gap: 12px; margin-top: 24px;">
            <a href="/account/withdraw" class="fpay-btn fpay-btn-secondary">
                Back to Withdrawals
            </a>
            <a href="/account/wallet" class="fpay-btn fpay-btn-secondary">
                View My Wallet
            </a>
        </div>
    </div>
</div>
