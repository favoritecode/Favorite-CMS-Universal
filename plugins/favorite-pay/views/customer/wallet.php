<?php
/**
 * Customer Wallet View
 */
$balanceAmount = $balance->getAmount();
$balanceCurrency = $balance->getCurrency();
?>

<!-- Wallet Summary Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 28px;">
    <div class="fpay-card" style="background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); color: #ffffff; border: none;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <span style="font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #bfdbfe;">
                Available Wallet Balance
            </span>
            <span class="fpay-badge" style="background: rgba(255,255,255,0.2); color: #ffffff;">
                <?php echo htmlspecialchars(strtoupper($walletStatus), ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </div>
        <div style="font-size: 36px; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 8px;">
            <?php echo fpay_format_money($balanceAmount, $balanceCurrency); ?>
        </div>
        <div style="font-size: 13px; color: #dbeafe;">
            Primary Accounting Currency: <strong><?php echo htmlspecialchars($balanceCurrency, ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
    </div>

    <div class="fpay-card" style="display: flex; flex-direction: column; justify-content: space-between;">
        <div>
            <div style="font-size: 13px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px;">
                Instant Balance Recharge
            </div>
            <p style="font-size: 14px; color: #475569; margin: 0 0 16px;">
                Add funds securely using local mobile banking (bKash, Nagad, Rocket, Bank Transfer) or cryptocurrency via Binance Pay.
            </p>
        </div>
        <div>
            <?php if (empty($isSuspended)): ?>
                <a href="/account/recharge" class="fpay-btn fpay-btn-primary" style="width: 100%; justify-content: center; box-sizing: border-box;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg>
                    Recharge Balance Now
                </a>
            <?php else: ?>
                <button class="fpay-btn fpay-btn-secondary" disabled style="width: 100%; justify-content: center; opacity: 0.6; cursor: not-allowed;">
                    Recharge Disabled (Suspended)
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Recent Activity -->
<div class="fpay-card">
    <div class="fpay-card-header">
        <h2 class="fpay-card-title">Recent Wallet Activity</h2>
        <a href="/account/transactions" class="fpay-btn fpay-btn-secondary fpay-btn-sm">
            View All Transactions &rarr;
        </a>
    </div>

    <?php if (empty($recentLedger)): ?>
        <div style="text-align: center; padding: 40px 20px; color: #64748b;">
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
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentLedger as $entry): 
                    $type = $entry->getType();
                    $isCredit = $type === 'credit' || $type === 'release';
                    $entryAmount = $entry->getAmount();
                    $balAfter = $entry->getBalanceAfter();
                ?>
                    <tr>
                        <td style="color: #64748b; white-space: nowrap;">
                            <?php echo htmlspecialchars($entry->getCreatedAt(), ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td>
                            <span class="fpay-badge <?php echo $isCredit ? 'fpay-badge-success' : 'fpay-badge-failed'; ?>">
                                <?php echo htmlspecialchars(strtoupper($type), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($entry->getDescription(), ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td>
                            <?php if ($entry->getReferenceType() === 'payment'): ?>
                                <a href="/account/payments/<?php echo urlencode($entry->getReferenceId()); ?>" style="color: #2563eb; text-decoration: none; font-weight: 600;">
                                    <?php echo htmlspecialchars($entry->getReferenceId(), ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                            <?php else: ?>
                                <span style="color: #64748b;"><?php echo htmlspecialchars($entry->getReferenceId(), ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right; font-weight: 700; color: <?php echo $isCredit ? '#15803d' : '#b91c1c'; ?>;">
                            <?php echo ($isCredit ? '+' : '-') . fpay_format_money($entryAmount->getAmount(), $entryAmount->getCurrency()); ?>
                        </td>
                        <td style="text-align: right; font-weight: 600; color: #1e293b;">
                            <?php echo fpay_format_money($balAfter->getAmount(), $balAfter->getCurrency()); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
