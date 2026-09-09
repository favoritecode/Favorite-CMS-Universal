<?php
/**
 * Customer Wallet Dashboard View (/account/wallet)
 */
$currTotal = isset($totalBalance) ? $totalBalance : $balance;
$currAvailable = isset($availableBalance) ? $availableBalance : $balance;
$currHeld = isset($heldBalance) ? $heldBalance : new \FavoriteCMS\Pay\Domain\Money(0, $currency);

$totalAmount = $currTotal->getAmount();
$availableAmount = $currAvailable->getAmount();
$heldAmount = $currHeld->getAmount();
$baseCurrency = $currency ?? 'BDT';
?>

<!-- 1. Balance Overview Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 18px; margin-bottom: 24px;">
    <!-- Card 1: Total Balance -->
    <div class="fpay-card" style="background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); color: #ffffff; border: none; padding: 22px;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
            <span style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #bfdbfe;">
                Total Wallet Balance
            </span>
            <span class="fpay-badge" style="background: rgba(255,255,255,0.2); color: #ffffff; font-size: 11px;">
                <?php echo htmlspecialchars(strtoupper($walletStatus ?? 'active'), ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </div>
        <div style="font-size: 32px; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 6px; line-height: 1.2;">
            <?php echo fpay_format_money($totalAmount, $baseCurrency); ?>
        </div>
        <div style="font-size: 12px; color: #dbeafe;">
            Primary Currency: <strong><?php echo htmlspecialchars($baseCurrency, ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
    </div>

    <!-- Card 2: Spendable / Available Balance -->
    <div class="fpay-card" style="background: #ffffff; border: 1px solid #e2e8f0; padding: 22px; display: flex; flex-direction: column; justify-content: space-between;">
        <div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <span style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #15803d;">
                    Spendable Available Balance
                </span>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            </div>
            <div style="font-size: 28px; font-weight: 800; color: #15803d; letter-spacing: -0.02em; margin-bottom: 6px;">
                <?php echo fpay_format_money($availableAmount, $baseCurrency); ?>
            </div>
            <p style="font-size: 12px; color: #64748b; margin: 0;">
                Funds immediately available for checkout, purchases, or withdrawal requests.
            </p>
        </div>
    </div>

    <!-- Card 3: Held in Withdrawals -->
    <div class="fpay-card" style="background: <?php echo $heldAmount > 0 ? '#fffbeb' : '#ffffff'; ?>; border: 1px solid <?php echo $heldAmount > 0 ? '#fde68a' : '#e2e8f0'; ?>; padding: 22px; display: flex; flex-direction: column; justify-content: space-between;">
        <div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <span style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: <?php echo $heldAmount > 0 ? '#b45309' : '#64748b'; ?>;">
                    Held in Withdrawals
                </span>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="<?php echo $heldAmount > 0 ? '#d97706' : '#94a3b8'; ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            </div>
            <div style="font-size: 28px; font-weight: 800; color: <?php echo $heldAmount > 0 ? '#b45309' : '#64748b'; ?>; letter-spacing: -0.02em; margin-bottom: 6px;">
                <?php echo fpay_format_money($heldAmount, $baseCurrency); ?>
            </div>
            <p style="font-size: 12px; color: <?php echo $heldAmount > 0 ? '#92400e' : '#64748b'; ?>; margin: 0 0 8px;">
                Held funds are temporarily reserved for pending withdrawals.
            </p>
        </div>
        <?php if ($heldAmount > 0 && !empty($withdrawEnabled)): ?>
            <div>
                <a href="/account/withdraw" style="font-size: 12px; font-weight: 600; color: #b45309; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                    View Pending Withdrawals &rarr;
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- 2. Quick Actions Bar -->
<div class="fpay-card" style="margin-bottom: 24px; padding: 18px 24px;">
    <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 14px;">
        <div>
            <strong style="font-size: 15px; color: #0f172a; display: block;">Quick Actions</strong>
            <span style="font-size: 13px; color: #64748b;">Manage your wallet balance and review history</span>
        </div>
        <div style="display: flex; flex-wrap: wrap; gap: 10px;">
            <?php if (empty($isSuspended)): ?>
                <a href="/account/recharge" class="fpay-btn fpay-btn-primary fpay-btn-sm" style="display: inline-flex; align-items: center; gap: 6px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg>
                    Recharge Balance
                </a>
            <?php endif; ?>

            <?php if (!empty($withdrawEnabled) && empty($isSuspended)): ?>
                <a href="/account/withdraw" class="fpay-btn fpay-btn-secondary fpay-btn-sm" style="display: inline-flex; align-items: center; gap: 6px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                    Withdraw Funds
                </a>
            <?php endif; ?>

            <a href="/account/transactions" class="fpay-btn fpay-btn-secondary fpay-btn-sm" style="display: inline-flex; align-items: center; gap: 6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                Transactions Ledger
            </a>

            <a href="/account/payments" class="fpay-btn fpay-btn-secondary fpay-btn-sm" style="display: inline-flex; align-items: center; gap: 6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                Payment History
            </a>
        </div>
    </div>
</div>

<!-- 3. Wallet Activity Summary (Lifetime Historical Statistics) -->
<?php if (!empty($summary)): ?>
<div class="fpay-card" style="margin-bottom: 24px;">
    <div class="fpay-card-header">
        <div>
            <h2 class="fpay-card-title">Lifetime Wallet Activity Summary</h2>
            <p style="margin: 4px 0 0; font-size: 13px; color: #64748b;">
                Historical totals across all completed recharges, payments, and withdrawals.
            </p>
        </div>
        <span style="font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b; background: #f1f5f9; padding: 4px 10px; border-radius: 6px;">
            Cumulative
        </span>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; padding: 6px 0;">
        <div style="background: #f8fafc; padding: 14px; border-radius: 8px; border: 1px solid #e2e8f0;">
            <span style="display: block; font-size: 12px; color: #64748b; margin-bottom: 4px;">Total Recharges</span>
            <strong style="font-size: 18px; color: #15803d; display: block;">
                <?php echo fpay_format_money($summary['total_recharge_amount']->getAmount(), $baseCurrency); ?>
            </strong>
            <span style="font-size: 11px; color: #94a3b8;"><?php echo (int)$summary['successful_recharge_count']; ?> successful payment(s)</span>
        </div>

        <div style="background: #f8fafc; padding: 14px; border-radius: 8px; border: 1px solid #e2e8f0;">
            <span style="display: block; font-size: 12px; color: #64748b; margin-bottom: 4px;">Total Withdrawals</span>
            <strong style="font-size: 18px; color: #0f172a; display: block;">
                <?php echo fpay_format_money($summary['total_withdrawal_amount']->getAmount(), $baseCurrency); ?>
            </strong>
            <span style="font-size: 11px; color: #94a3b8;"><?php echo (int)$summary['total_withdrawal_count']; ?> total request(s)</span>
        </div>

        <div style="background: #f8fafc; padding: 14px; border-radius: 8px; border: 1px solid #e2e8f0;">
            <span style="display: block; font-size: 12px; color: #64748b; margin-bottom: 4px;">Paid Payouts</span>
            <strong style="font-size: 18px; color: #2563eb; display: block;">
                <?php echo fpay_format_money($summary['paid_withdrawal_amount']->getAmount(), $baseCurrency); ?>
            </strong>
            <span style="font-size: 11px; color: #94a3b8;"><?php echo (int)$summary['paid_withdrawal_count']; ?> completed payout(s)</span>
        </div>

        <div style="background: #f8fafc; padding: 14px; border-radius: 8px; border: 1px solid #e2e8f0;">
            <span style="display: block; font-size: 12px; color: #64748b; margin-bottom: 4px;">Withdrawal Fees</span>
            <strong style="font-size: 18px; color: #475569; display: block;">
                <?php echo fpay_format_money($summary['total_withdrawal_fee']->getAmount(), $baseCurrency); ?>
            </strong>
            <span style="font-size: 11px; color: #94a3b8;">Processing fees paid</span>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 4. Recent Activity -->
<div class="fpay-card">
    <div class="fpay-card-header">
        <div>
            <h2 class="fpay-card-title">Recent Wallet Activity</h2>
            <p style="margin: 4px 0 0; font-size: 13px; color: #64748b;">
                Latest transactions from your immutable ledger.
            </p>
        </div>
        <a href="/account/transactions" class="fpay-btn fpay-btn-secondary fpay-btn-sm">
            View All Transactions &rarr;
        </a>
    </div>

    <?php if (empty($recentLedger)): ?>
        <div style="text-align: center; padding: 48px 20px; color: #64748b;">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 12px;"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
            <p style="margin: 0 0 12px; font-size: 15px; font-weight: 500;">No wallet activity recorded yet.</p>
            <?php if (empty($isSuspended)): ?>
                <a href="/account/recharge" class="fpay-btn fpay-btn-primary fpay-btn-sm">Make Your First Deposit</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <table class="fpay-table">
            <thead>
                <tr>
                    <th>Date / Time</th>
                    <th>Type</th>
                    <th>Description</th>
                    <th>Reference</th>
                    <th style="text-align: right;">Amount</th>
                    <th style="text-align: right;">Balance After</th>
                    <th style="text-align: center;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentLedger as $entry): 
                    $type = $entry->getType();
                    $isCredit = $type === 'credit' || $type === 'release';
                    $entryAmount = $entry->getAmount();
                    $balAfter = $entry->getBalanceAfter();
                    $refId = $entry->getReferenceId();
                    $refType = $entry->getReferenceType();

                    // Resolve safe cross-link
                    $crossLinkUrl = null;
                    $crossLinkLabel = null;
                    if ($refType === 'payment') {
                        $crossLinkUrl = '/account/payments/' . urlencode($refId);
                        $crossLinkLabel = 'View Payment';
                    } elseif ($refType === 'withdrawal' || str_starts_with($refId, 'wd_')) {
                        $crossLinkUrl = '/account/withdrawals/' . urlencode($refId);
                        $crossLinkLabel = 'View Withdrawal';
                    } elseif (str_starts_with($refId, 'hold:wd_')) {
                        $crossLinkUrl = '/account/withdrawals/' . urlencode(substr($refId, 5));
                        $crossLinkLabel = 'View Withdrawal';
                    }
                ?>
                    <tr>
                        <td style="color: #64748b; white-space: nowrap; font-size: 13px;">
                            <?php echo htmlspecialchars($entry->getCreatedAt(), ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td>
                            <span class="fpay-badge <?php echo $isCredit ? 'fpay-badge-success' : ($type === 'hold' ? 'fpay-badge-warning' : 'fpay-badge-failed'); ?>">
                                <?php echo htmlspecialchars(strtoupper($type), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($entry->getDescription(), ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td>
                            <?php if ($crossLinkUrl !== null): ?>
                                <a href="<?php echo htmlspecialchars($crossLinkUrl, ENT_QUOTES, 'UTF-8'); ?>" style="color: #2563eb; text-decoration: none; font-weight: 600; font-family: monospace; font-size: 12px;">
                                    <?php echo htmlspecialchars($refId, ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                            <?php else: ?>
                                <span style="color: #64748b; font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars($refId, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right; font-weight: 700; color: <?php echo $isCredit ? '#15803d' : ($type === 'hold' ? '#d97706' : '#b91c1c'); ?>;">
                            <?php echo ($isCredit ? '+' : '-') . fpay_format_money($entryAmount->getAmount(), $entryAmount->getCurrency()); ?>
                        </td>
                        <td style="text-align: right; font-weight: 600; color: #1e293b;">
                            <?php echo fpay_format_money($balAfter->getAmount(), $balAfter->getCurrency()); ?>
                        </td>
                        <td style="text-align: center; white-space: nowrap;">
                            <a href="/account/transactions/<?php echo urlencode($entry->getId()); ?>" class="fpay-btn fpay-btn-secondary fpay-btn-sm" style="padding: 4px 8px; font-size: 12px;">
                                Details
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
