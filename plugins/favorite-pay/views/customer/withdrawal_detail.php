<?php
/**
 * Customer Withdrawal Detail & Status Timeline View
 *
 * @var \FavoriteCMS\Models\User $user
 * @var \FavoriteCMS\Pay\Domain\Withdrawal $withdrawal
 * @var bool $withdrawEnabled
 * @var string $csrfToken
 */
$status = $withdrawal->getStatus();
$badgeClass = $status->badgeClass();
$amountMajor = \FavoriteCMS\Pay\Support\DecimalFormatter::minorUnitToDecimal($withdrawal->getAmount()->getAmount(), 2);
$feeMajor = \FavoriteCMS\Pay\Support\DecimalFormatter::minorUnitToDecimal($withdrawal->getFee()->getAmount(), 2);
$netMajor = \FavoriteCMS\Pay\Support\DecimalFormatter::minorUnitToDecimal($withdrawal->getNetAmount()->getAmount(), 2);
$curr = $withdrawal->getCurrency();

// Determine timeline steps based on status
$stVal = $status->value;
if ($stVal === 'cancelled') {
    $steps = [
        ['label' => 'Requested', 'date' => $withdrawal->getCreatedAt(), 'state' => 'completed'],
        ['label' => 'Cancelled', 'date' => $withdrawal->getUpdatedAt(), 'state' => 'final-cancelled'],
    ];
} elseif ($stVal === 'rejected') {
    $steps = [
        ['label' => 'Requested', 'date' => $withdrawal->getCreatedAt(), 'state' => 'completed'],
        ['label' => 'Rejected', 'date' => $withdrawal->getUpdatedAt(), 'state' => 'final-rejected'],
    ];
} else {
    $steps = [
        [
            'label' => 'Requested',
            'date'  => $withdrawal->getCreatedAt(),
            'state' => 'completed',
        ],
        [
            'label' => 'Approved',
            'date'  => in_array($stVal, ['approved', 'processing', 'paid', 'failed'], true) ? ($withdrawal->getUpdatedAt() ?? 'Completed') : null,
            'state' => in_array($stVal, ['approved', 'processing', 'paid', 'failed'], true) ? 'completed' : 'pending',
        ],
        [
            'label' => 'Processing',
            'date'  => in_array($stVal, ['processing', 'paid', 'failed'], true) ? ($withdrawal->getUpdatedAt() ?? 'In Progress') : null,
            'state' => in_array($stVal, ['paid', 'failed'], true) ? 'completed' : ($stVal === 'processing' ? 'active' : 'pending'),
        ],
        [
            'label' => $stVal === 'failed' ? 'Failed' : 'Paid',
            'date'  => $withdrawal->getProcessedAt(),
            'state' => $stVal === 'paid' ? 'completed' : ($stVal === 'failed' ? 'final-rejected' : 'pending'),
        ],
    ];
}

$canCancel = in_array($stVal, ['pending', 'approved'], true);
?>

<style>
.fpay-timeline-container {
    display: flex;
    justify-content: space-between;
    position: relative;
    margin: 24px 0 32px;
    padding: 0 10px;
}
.fpay-timeline-track {
    position: absolute;
    top: 14px;
    left: 20px;
    right: 20px;
    height: 3px;
    background: #e2e8f0;
    z-index: 1;
}
.fpay-timeline-step {
    position: relative;
    z-index: 2;
    text-align: center;
    flex: 1;
}
.fpay-step-circle {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    margin: 0 auto 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 700;
}
.fpay-step-circle.completed {
    background: #15803d;
    color: #ffffff;
}
.fpay-step-circle.active {
    background: #0369a1;
    color: #ffffff;
    box-shadow: 0 0 0 4px #e0f2fe;
}
.fpay-step-circle.pending {
    background: #ffffff;
    border: 2px solid #cbd5e1;
    color: #94a3b8;
}
.fpay-step-circle.final-cancelled {
    background: #475569;
    color: #ffffff;
}
.fpay-step-circle.final-rejected {
    background: #b91c1c;
    color: #ffffff;
}
.fpay-step-label {
    font-size: 13px;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 2px;
}
.fpay-step-date {
    font-size: 11px;
    color: #64748b;
}
</style>

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

        <!-- Status Timeline -->
        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px 16px 10px; margin-bottom: 24px;">
            <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 12px; text-align: center;">
                Status Timeline
            </div>
            <div class="fpay-timeline-container">
                <div class="fpay-timeline-track"></div>
                <?php foreach ($steps as $stIdx => $stp): ?>
                    <div class="fpay-timeline-step">
                        <div class="fpay-step-circle <?php echo $stp['state']; ?>">
                            <?php if ($stp['state'] === 'completed'): ?>
                                ✓
                            <?php elseif ($stp['state'] === 'final-cancelled' || $stp['state'] === 'final-rejected'): ?>
                                ✕
                            <?php else: ?>
                                <?php echo ($stIdx + 1); ?>
                            <?php endif; ?>
                        </div>
                        <div class="fpay-step-label"><?php echo htmlspecialchars($stp['label'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php if (!empty($stp['date'])): ?>
                            <div class="fpay-step-date"><?php echo htmlspecialchars(substr($stp['date'], 0, 10), ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Financial Breakdown -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px; background: #ffffff; padding: 18px; border-radius: 10px; border: 1px solid #e2e8f0;">
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

        <!-- Destination Details -->
        <div style="border-top: 1px solid #f1f5f9; padding-top: 18px; margin-bottom: 20px;">
            <h3 style="font-size: 15px; font-weight: 700; color: #1e293b; margin: 0 0 12px;">Destination Details</h3>
            <div style="font-size: 14px; color: #334155; line-height: 1.6;">
                <div>Masked Account: <strong style="font-family: monospace;"><?php echo htmlspecialchars($withdrawal->getDestinationMasked(), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <?php if ($withdrawal->getTransactionReference()): ?>
                    <div style="margin-top: 6px;">Transaction Reference / TrxID: <strong style="font-family: monospace; color: #2563eb;"><?php echo htmlspecialchars($withdrawal->getTransactionReference(), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Timestamps -->
        <div style="border-top: 1px solid #f1f5f9; padding-top: 18px; margin-bottom: 24px;">
            <h3 style="font-size: 15px; font-weight: 700; color: #1e293b; margin: 0 0 12px;">Activity Dates</h3>
            <div style="font-size: 13px; color: #64748b; line-height: 1.8;">
                <div>Submitted: <strong><?php echo htmlspecialchars($withdrawal->getCreatedAt(), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <?php if ($withdrawal->getUpdatedAt()): ?>
                    <div>Last update: <strong><?php echo htmlspecialchars($withdrawal->getUpdatedAt(), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <?php endif; ?>
                <?php if ($withdrawal->getProcessedAt()): ?>
                    <div>Completed: <strong><?php echo htmlspecialchars($withdrawal->getProcessedAt(), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Notifications & Event Updates -->
        <?php if (!empty($notifications)): ?>
            <div style="margin-bottom: 24px;">
                <div style="font-size: 13px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 12px;">
                    Activity &amp; Updates
                </div>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <?php foreach ($notifications as $notif): ?>
                        <div style="padding: 12px 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; display: flex; justify-content: space-between; align-items: center; gap: 12px;">
                            <div>
                                <div style="font-size: 14px; font-weight: 600; color: #0f172a;">
                                    <?php echo htmlspecialchars((string)($notif['title'] ?? 'Update'), ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <div style="font-size: 13px; color: #475569; margin-top: 2px;">
                                    <?php echo htmlspecialchars((string)($notif['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </div>
                            <div style="font-size: 12px; color: #94a3b8; white-space: nowrap;">
                                <?php echo htmlspecialchars((string)($notif['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Actions -->
        <div style="display: flex; gap: 12px; justify-content: space-between; align-items: center; border-top: 1px solid #f1f5f9; padding-top: 20px;">
            <div style="display: flex; gap: 10px;">
                <a href="/account/withdraw" class="fpay-btn fpay-btn-secondary">
                    Back to Withdrawals
                </a>
                <a href="/account/wallet" class="fpay-btn fpay-btn-secondary">
                    View Wallet
                </a>
            </div>

            <?php if ($canCancel): ?>
                <div>
                    <form method="POST" action="/account/withdrawals/<?php echo urlencode($withdrawal->getId()); ?>" onsubmit="return confirm('Are you sure you want to cancel this withdrawal request? Held funds will be released back to your available balance.');">
                        <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
                        <input type="hidden" name="action" value="cancel" />
                        <button type="submit" class="fpay-btn" style="background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; font-size: 13px;">
                            Cancel Request
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
