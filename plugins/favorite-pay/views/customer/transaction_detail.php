<?php
/**
 * Customer Transaction Detail View
 */
$type = $entry->getType();
$isCredit = $type === 'credit' || $type === 'release';
$entryAmount = $entry->getAmount();
$balAfter = $entry->getBalanceAfter();
$refType = $entry->getReferenceType();
$refId = $entry->getReferenceId();
?>

<div class="fpay-card" style="margin-bottom: 24px;">
    <div class="fpay-card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <div style="margin-bottom: 8px;">
                <a href="/account/transactions" style="text-decoration: none; color: #64748b; font-size: 13px; font-weight: 500;">
                    &larr; Back to Transactions Ledger
                </a>
            </div>
            <h2 class="fpay-card-title" style="margin: 0; font-size: 20px; font-weight: 700; color: #0f172a;">
                Transaction Details
            </h2>
        </div>
        <div>
            <span class="fpay-badge <?php echo $isCredit ? 'fpay-badge-success' : ($type === 'hold' ? 'fpay-badge-warning' : 'fpay-badge-failed'); ?>" style="font-size: 13px; padding: 6px 14px;">
                <?php echo htmlspecialchars(strtoupper($type), ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </div>
    </div>

    <!-- Amount & Direction Banner -->
    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin: 20px 0; text-align: center;">
        <span style="font-size: 13px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 4px;">
            <?php echo $isCredit ? 'Balance Inflow (Credit)' : 'Balance Outflow (Debit)'; ?>
        </span>
        <div style="font-size: 32px; font-weight: 800; color: <?php echo $isCredit ? '#15803d' : '#b91c1c'; ?>;">
            <?php echo ($isCredit ? '+' : '-') . fpay_format_money($entryAmount->getAmount(), $entryAmount->getCurrency()); ?>
        </div>
        <div style="font-size: 13px; color: #64748b; margin-top: 6px;">
            Wallet Balance After Transaction: <strong style="color: #0f172a;"><?php echo fpay_format_money($balAfter->getAmount(), $balAfter->getCurrency()); ?></strong>
        </div>
    </div>

    <!-- Entry Details Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div style="padding: 14px; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px;">
            <div style="font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase;">Entry ID</div>
            <div style="font-size: 13px; font-family: monospace; color: #0f172a; margin-top: 4px; word-break: break-all;">
                <?php echo htmlspecialchars($entry->getId(), ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>

        <div style="padding: 14px; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px;">
            <div style="font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase;">Timestamp</div>
            <div style="font-size: 13px; color: #0f172a; margin-top: 4px; font-weight: 500;">
                <?php echo htmlspecialchars($entry->getCreatedAt(), ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>

        <div style="padding: 14px; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px;">
            <div style="font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase;">Transaction Type</div>
            <div style="font-size: 13px; color: #0f172a; margin-top: 4px; font-weight: 600;">
                <?php echo htmlspecialchars(ucfirst($type), ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>

        <div style="padding: 14px; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px;">
            <div style="font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase;">Reference Type</div>
            <div style="font-size: 13px; color: #0f172a; margin-top: 4px; font-weight: 600;">
                <?php echo !empty($refType) ? htmlspecialchars(ucfirst($refType), ENT_QUOTES, 'UTF-8') : 'None'; ?>
            </div>
        </div>
    </div>

    <!-- Description -->
    <div style="padding: 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; margin-bottom: 24px;">
        <div style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; margin-bottom: 6px;">Description</div>
        <div style="font-size: 14px; color: #334155; line-height: 1.5;">
            <?php echo htmlspecialchars($entry->getDescription(), ENT_QUOTES, 'UTF-8'); ?>
        </div>
    </div>

    <!-- Related Resource Cross-Link -->
    <?php if ($refType === 'payment' && !empty($refId)): ?>
        <div style="border: 1px solid #bfdbfe; background: #eff6ff; border-radius: 8px; padding: 18px; margin-top: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                <div>
                    <h3 style="margin: 0; font-size: 15px; font-weight: 700; color: #1e40af;">Linked Payment / Recharge</h3>
                    <p style="margin: 4px 0 0; font-size: 13px; color: #3b82f6;">
                        Payment Reference: <code style="background: #dbeafe; padding: 2px 6px; border-radius: 4px; font-weight: 600; color: #1e3a8a;"><?php echo htmlspecialchars($refId, ENT_QUOTES, 'UTF-8'); ?></code>
                        <?php if ($relatedPayment): ?>
                            &bull; Status: <strong style="text-transform: capitalize;"><?php echo htmlspecialchars($relatedPayment->getStatus(), ENT_QUOTES, 'UTF-8'); ?></strong>
                        <?php endif; ?>
                    </p>
                </div>
                <a href="/account/payments/<?php echo urlencode($refId); ?>" class="fpay-btn fpay-btn-primary" style="text-decoration: none; padding: 8px 16px; font-size: 13px;">
                    View Payment Details &rarr;
                </a>
            </div>
        </div>
    <?php elseif ($refType === 'withdrawal' && !empty($refId)): ?>
        <div style="border: 1px solid #fed7aa; background: #fff7ed; border-radius: 8px; padding: 18px; margin-top: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                <div>
                    <h3 style="margin: 0; font-size: 15px; font-weight: 700; color: #9a3412;">Linked Withdrawal Request</h3>
                    <p style="margin: 4px 0 0; font-size: 13px; color: #ea580c;">
                        Withdrawal Reference: <code style="background: #ffedd5; padding: 2px 6px; border-radius: 4px; font-weight: 600; color: #7c2d12;"><?php echo htmlspecialchars($refId, ENT_QUOTES, 'UTF-8'); ?></code>
                        <?php if ($relatedWithdrawal): ?>
                            &bull; Status: <strong style="text-transform: capitalize;"><?php echo htmlspecialchars($relatedWithdrawal->getStatus(), ENT_QUOTES, 'UTF-8'); ?></strong>
                        <?php endif; ?>
                    </p>
                </div>
                <a href="/account/withdrawals/<?php echo urlencode($refId); ?>" class="fpay-btn fpay-btn-primary" style="text-decoration: none; padding: 8px 16px; font-size: 13px;">
                    View Withdrawal Request &rarr;
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>
