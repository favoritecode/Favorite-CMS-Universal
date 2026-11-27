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
    <div class="fpay-card" style="padding: 22px; display: flex; flex-direction: column; justify-content: space-between;">
        <div>
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                <span style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--accent, #2563eb);">
                    Total Wallet Balance
                </span>
                <span class="fpay-badge fpay-badge-success" style="font-size: 11px;">
                    <?php echo htmlspecialchars(strtoupper($walletStatus ?? 'active'), ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
            <div style="font-size: 28px; font-weight: 800; color: var(--heading, #0f172a); letter-spacing: -0.02em; margin-bottom: 6px; line-height: 1.2;">
                <?php echo fpay_format_money($totalAmount, $baseCurrency); ?>
            </div>
            <div style="font-size: 12px; color: var(--muted, #64748b);">
                Primary Currency: <strong style="color: var(--heading, #0f172a);"><?php echo htmlspecialchars($baseCurrency, ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
        </div>
    </div>

    <!-- Card 2: Spendable / Available Balance -->
    <div class="fpay-card" style="background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); padding: 22px; display: flex; flex-direction: column; justify-content: space-between;">
        <div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <span style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--success, #15803d);">
                    Spendable Available Balance
                </span>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--success, #16a34a);"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            </div>
            <div style="font-size: 28px; font-weight: 800; color: var(--success, #15803d); letter-spacing: -0.02em; margin-bottom: 6px;">
                <?php echo fpay_format_money($availableAmount, $baseCurrency); ?>
            </div>
            <p style="font-size: 12px; color: var(--muted, #64748b); margin: 0;">
                Funds immediately available for checkout, purchases, or withdrawal requests.
            </p>
        </div>
    </div>

    <!-- Card 3: Held in Withdrawals -->
    <div class="fpay-card" style="background: <?php echo $heldAmount > 0 ? 'rgba(245, 158, 11, 0.12)' : 'var(--surface, #ffffff)'; ?>; border: 1px solid <?php echo $heldAmount > 0 ? 'rgba(245, 158, 11, 0.3)' : 'var(--border, #e2e8f0)'; ?>; padding: 22px; display: flex; flex-direction: column; justify-content: space-between;">
        <div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <span style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: <?php echo $heldAmount > 0 ? 'var(--warning, #d97706)' : 'var(--muted, #64748b)'; ?>;">
                    Held in Withdrawals
                </span>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: <?php echo $heldAmount > 0 ? 'var(--warning, #d97706)' : 'var(--muted, #94a3b8)'; ?>;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            </div>
            <div style="font-size: 28px; font-weight: 800; color: <?php echo $heldAmount > 0 ? 'var(--warning, #d97706)' : 'var(--muted, #64748b)'; ?>; letter-spacing: -0.02em; margin-bottom: 6px;">
                <?php echo fpay_format_money($heldAmount, $baseCurrency); ?>
            </div>
            <p style="font-size: 12px; color: <?php echo $heldAmount > 0 ? 'var(--warning, #d97706)' : 'var(--muted, #64748b)'; ?>; margin: 0 0 8px;">
                Held funds are temporarily reserved for pending withdrawals.
            </p>
        </div>
        <?php if ($heldAmount > 0 && !empty($withdrawEnabled)): ?>
            <div>
                <a href="/account/withdraw" style="font-size: 12px; font-weight: 600; color: var(--warning, #d97706); text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
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
            <strong style="font-size: 15px; color: var(--heading, #0f172a); display: block;">Quick Actions</strong>
            <span style="font-size: 13px; color: var(--muted, #64748b);">Manage your wallet balance and review history</span>
        </div>
        <div style="display: flex; flex-wrap: wrap; gap: 10px;">
            <?php if (empty($isSuspended)): ?>
                <a href="#recharge-wallet" class="fpay-btn fpay-btn-primary fpay-btn-sm" style="display: inline-flex; align-items: center; gap: 6px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg>
                    Recharge Wallet
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

<!-- 3. Recharge Wallet Form Card -->
<div class="fpay-card" id="recharge-wallet" style="margin-bottom: 24px;">
    <div class="fpay-card-header">
        <div>
            <h2 class="fpay-card-title">Recharge Wallet</h2>
            <p style="margin: 4px 0 0; font-size: 13px; color: var(--muted, #64748b);">
                Add funds directly to your wallet balance using enabled payment methods.
            </p>
        </div>
    </div>

    <?php if (!empty($isSuspended)): ?>
        <div class="fpay-alert fpay-alert-warning" style="margin-bottom: 0;">
            Your account is currently suspended. Recharging balance is disabled.
        </div>
    <?php else: ?>
        <form action="/account/wallet" method="POST" id="fpay-recharge-form">
            <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8'); ?>">

            <!-- Amount Input -->
            <div style="margin-bottom: 24px;">
                <label for="recharge_amount" style="display: block; font-weight: 700; font-size: 15px; margin-bottom: 8px; color: var(--heading, #0f172a);">
                    Recharge Amount (<?php echo htmlspecialchars($primaryCurrency ?? $baseCurrency, ENT_QUOTES, 'UTF-8'); ?>)
                </label>
                <div style="position: relative; display: flex; align-items: center;">
                    <span style="position: absolute; left: 16px; font-size: 20px; font-weight: 700; color: var(--muted, #64748b);">
                        <?php echo htmlspecialchars(class_exists(\FavoriteCMS\Core\Currency::class) ? \FavoriteCMS\Core\Currency::getSymbol($primaryCurrency ?? $baseCurrency) : ($primaryCurrency ?? $baseCurrency), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <input type="number" 
                           name="amount" 
                           id="recharge_amount" 
                           step="any" 
                           min="1" 
                           required 
                           placeholder="e.g. 500" 
                           style="width: 100%; padding: 14px 14px 14px 44px; font-size: 20px; font-weight: 700; border: 2px solid var(--border-strong, #cbd5e1); background: var(--surface, #ffffff); color: var(--heading, #0f172a); border-radius: 8px; outline: none; box-sizing: border-box;"
                           onfocus="this.style.borderColor='var(--accent, #2563eb)'"
                           onblur="this.style.borderColor='var(--border-strong, #cbd5e1)'">
                </div>
                <small style="display: block; margin-top: 6px; color: var(--muted, #64748b); font-size: 13px;">
                    Funds are credited strictly in your wallet's primary accounting currency (<strong><?php echo htmlspecialchars($primaryCurrency ?? $baseCurrency, ENT_QUOTES, 'UTF-8'); ?></strong>).
                </small>
            </div>

            <!-- Payment Method Selection -->
            <div style="margin-bottom: 28px;">
                <label style="display: block; font-weight: 700; font-size: 15px; margin-bottom: 12px; color: var(--heading, #0f172a);">
                    Select Payment Method
                </label>

                <?php if (empty($gateways)): ?>
                    <div class="fpay-alert fpay-alert-warning">
                        No payment gateways are currently available. Please contact administrator.
                    </div>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <?php $first = true; foreach ($gateways as $id => $gw): ?>
                            <label style="display: flex; align-items: flex-start; gap: 14px; padding: 16px; border: 2px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); border-radius: 10px; cursor: pointer; transition: all 0.15s ease;"
                                   class="fpay-gateway-option"
                                   onclick="document.querySelectorAll('.fpay-gateway-option').forEach(el => el.style.borderColor='var(--border, #e2e8f0)'); this.style.borderColor='var(--accent, #2563eb)';">
                                <input type="radio" 
                                       name="gateway_id" 
                                       value="<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>"
                                       <?php if ($first) { echo 'checked'; $first = false; } ?>
                                       style="margin-top: 4px; accent-color: var(--accent, #2563eb);">
                                <div style="flex: 1;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                        <strong style="font-size: 15px; color: var(--heading, #0f172a);">
                                             <?php echo htmlspecialchars($gw['title'], ENT_QUOTES, 'UTF-8'); ?>
                                        </strong>
                                        <?php if (!empty($gw['is_manual'])): ?>
                                            <span class="fpay-badge fpay-badge-active" style="font-size: 11px;">Manual Verification</span>
                                        <?php else: ?>
                                            <span class="fpay-badge fpay-badge-success" style="font-size: 11px;">Instant Automated</span>
                                        <?php endif; ?>
                                    </div>
                                    <p style="margin: 0; font-size: 13px; color: var(--muted, #64748b); line-height: 1.4;">
                                        <?php echo htmlspecialchars($gw['description'], ENT_QUOTES, 'UTF-8'); ?>
                                    </p>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div>
                <button type="submit" class="fpay-btn fpay-btn-primary" style="width: 100%; justify-content: center; padding: 14px; font-size: 16px;" <?php if (empty($gateways)) echo 'disabled'; ?>>
                    Proceed to Payment &rarr;
                </button>
            </div>
        </form>
    <?php endif; ?>
</div>

<!-- Recent Recharge Activity -->
<?php if (!empty($recentRecharges)): ?>
<div class="fpay-card" style="margin-bottom: 24px;">
    <div class="fpay-card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h3 class="fpay-card-title" style="font-size: 17px; margin: 0;">Recent Recharge Activity</h3>
            <p style="margin: 4px 0 0; font-size: 13px; color: var(--muted, #64748b);">
                Your latest wallet recharge requests and gateway transactions.
            </p>
        </div>
        <a href="/account/payments" class="fpay-btn fpay-btn-secondary fpay-btn-sm" style="text-decoration: none;">
            All Payments &rarr;
        </a>
    </div>

    <div style="overflow-x: auto;">
        <table class="fpay-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Reference</th>
                    <th>Method</th>
                    <th style="text-align: right;">Amount Paid</th>
                    <th style="text-align: right;">Credited to Wallet</th>
                    <th>Status</th>
                    <th style="text-align: center;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentRecharges as $r): 
                    $status = $r['status'] ?? 'unknown';
                    $txId = $r['transaction_id'] ?? $r['id'] ?? '';
                    $curPaid = $r['charge_currency'] ?? $r['currency'] ?? $primaryCurrency;
                    $amtPaid = (int)($r['charge_amount'] ?? $r['amount'] ?? 0);
                    $curAcc = $r['base_currency'] ?? $r['accounting_currency'] ?? $curPaid;
                    $amtAcc = (int)($r['base_amount'] ?? $r['accounting_amount'] ?? $amtPaid);
                    $rate = (float)($r['exchange_rate'] ?? 1.0);
                    $isDiff = strtoupper($curPaid) !== strtoupper($curAcc);
                    $gwLabel = $r['gateway_title'] ?? $r['gateway_name'] ?? $r['gateway_id'] ?? 'Online';
                ?>
                    <tr>
                        <td style="font-size: 12px; color: var(--muted, #64748b); white-space: nowrap;">
                            <?php echo htmlspecialchars(fpay_format_datetime($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td>
                            <a href="/account/payments/<?php echo urlencode($txId); ?>" style="font-family: monospace; font-size: 12px; font-weight: 600; color: var(--accent, #2563eb); text-decoration: none;">
                                <?php echo htmlspecialchars($txId, ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </td>
                        <td style="font-size: 12px; color: var(--text, #334155);">
                            <?php echo htmlspecialchars($gwLabel, ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td style="text-align: right; font-weight: 600; font-size: 13px; color: var(--heading, #0f172a); white-space: nowrap;">
                            <?php echo fpay_format_money($amtPaid, $curPaid); ?>
                        </td>
                        <td style="text-align: right; font-weight: 700; font-size: 13px; color: var(--success, #15803d); white-space: nowrap;">
                            <?php echo fpay_format_money($amtAcc, $curAcc); ?>
                            <?php if ($isDiff && $rate > 0): ?>
                                <div style="font-size: 10px; color: var(--muted, #64748b); font-weight: normal;">
                                    @ 1 <?php echo htmlspecialchars($curPaid, ENT_QUOTES, 'UTF-8'); ?> = <?php echo rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.'); ?> <?php echo htmlspecialchars($curAcc, ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($status === 'succeeded'): ?>
                                <span class="fpay-badge fpay-badge-success" style="font-size: 11px;">Credited</span>
                            <?php elseif ($status === 'pending'): ?>
                                <span class="fpay-badge fpay-badge-warning" style="font-size: 11px;">Pending</span>
                            <?php elseif ($status === 'failed'): ?>
                                <span class="fpay-badge fpay-badge-failed" style="font-size: 11px;">Failed</span>
                            <?php else: ?>
                                <span class="fpay-badge fpay-badge-secondary" style="font-size: 11px;"><?php echo htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center; white-space: nowrap;">
                            <a href="/account/payments/<?php echo urlencode($txId); ?>" class="fpay-btn fpay-btn-secondary" style="padding: 3px 8px; font-size: 11px; text-decoration: none;">
                                View
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- 3. Wallet Activity Summary (Lifetime Historical Statistics) -->
<?php if (!empty($summary)): ?>
<div class="fpay-card" style="margin-bottom: 24px;">
    <div class="fpay-card-header">
        <div>
            <h2 class="fpay-card-title">Lifetime Wallet Activity Summary</h2>
            <p style="margin: 4px 0 0; font-size: 13px; color: var(--muted, #64748b);">
                Historical totals across all completed recharges, payments, and withdrawals.
            </p>
        </div>
        <span style="font-size: 11px; font-weight: 600; text-transform: uppercase; color: var(--muted, #64748b); background: var(--surface-muted, #f1f5f9); padding: 4px 10px; border-radius: 6px;">
            Cumulative
        </span>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; padding: 6px 0;">
        <div style="background: var(--surface-muted, #f8fafc); padding: 14px; border-radius: 8px; border: 1px solid var(--border, #e2e8f0);">
            <span style="display: block; font-size: 12px; color: var(--muted, #64748b); margin-bottom: 4px;">Total Recharges</span>
            <strong style="font-size: 18px; color: var(--success, #15803d); display: block;">
                <?php echo fpay_format_money($summary['total_recharge_amount']->getAmount(), $baseCurrency); ?>
            </strong>
            <span style="font-size: 11px; color: var(--muted, #94a3b8);"><?php echo (int)$summary['successful_recharge_count']; ?> successful payment(s)</span>
        </div>

        <div style="background: var(--surface-muted, #f8fafc); padding: 14px; border-radius: 8px; border: 1px solid var(--border, #e2e8f0);">
            <span style="display: block; font-size: 12px; color: var(--muted, #64748b); margin-bottom: 4px;">Total Withdrawals</span>
            <strong style="font-size: 18px; color: var(--heading, #0f172a); display: block;">
                <?php echo fpay_format_money($summary['total_withdrawal_amount']->getAmount(), $baseCurrency); ?>
            </strong>
            <span style="font-size: 11px; color: var(--muted, #94a3b8);"><?php echo (int)$summary['total_withdrawal_count']; ?> total request(s)</span>
        </div>

        <div style="background: var(--surface-muted, #f8fafc); padding: 14px; border-radius: 8px; border: 1px solid var(--border, #e2e8f0);">
            <span style="display: block; font-size: 12px; color: var(--muted, #64748b); margin-bottom: 4px;">Paid Payouts</span>
            <strong style="font-size: 18px; color: var(--accent, #2563eb); display: block;">
                <?php echo fpay_format_money($summary['paid_withdrawal_amount']->getAmount(), $baseCurrency); ?>
            </strong>
            <span style="font-size: 11px; color: var(--muted, #94a3b8);"><?php echo (int)$summary['paid_withdrawal_count']; ?> completed payout(s)</span>
        </div>

        <div style="background: var(--surface-muted, #f8fafc); padding: 14px; border-radius: 8px; border: 1px solid var(--border, #e2e8f0);">
            <span style="display: block; font-size: 12px; color: var(--muted, #64748b); margin-bottom: 4px;">Withdrawal Fees</span>
            <strong style="font-size: 18px; color: var(--muted, #475569); display: block;">
                <?php echo fpay_format_money($summary['total_withdrawal_fee']->getAmount(), $baseCurrency); ?>
            </strong>
            <span style="font-size: 11px; color: var(--muted, #94a3b8);">Processing fees paid</span>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 4. Recent Activity -->
<div class="fpay-card">
    <div class="fpay-card-header">
        <div>
            <h2 class="fpay-card-title">Recent Wallet Activity</h2>
            <p style="margin: 4px 0 0; font-size: 13px; color: var(--muted, #64748b);">
                Latest transactions from your immutable ledger.
            </p>
        </div>
        <a href="/account/transactions" class="fpay-btn fpay-btn-secondary fpay-btn-sm">
            View All Transactions &rarr;
        </a>
    </div>

    <?php if (empty($recentLedger)): ?>
        <div style="text-align: center; padding: 48px 20px; color: var(--muted, #64748b);">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 12px; stroke: var(--border-strong, #cbd5e1);"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
            <p style="margin: 0 0 12px; font-size: 15px; font-weight: 500;">No wallet activity recorded yet.</p>
            <?php if (empty($isSuspended)): ?>
                <a href="#recharge-wallet" class="fpay-btn fpay-btn-primary fpay-btn-sm">Make Your First Deposit</a>
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
                        <td style="color: var(--muted, #64748b); white-space: nowrap; font-size: 13px;">
                            <?php echo htmlspecialchars(fpay_format_datetime($entry->getCreatedAt()), ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td>
                            <span class="fpay-badge <?php echo $isCredit ? 'fpay-badge-success' : ($type === 'hold' ? 'fpay-badge-warning' : 'fpay-badge-failed'); ?>">
                                <?php echo htmlspecialchars(strtoupper($type), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>
                        <td style="color: var(--text, #1e293b);">
                            <?php echo htmlspecialchars($entry->getDescription(), ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td>
                            <?php if ($crossLinkUrl !== null): ?>
                                <a href="<?php echo htmlspecialchars($crossLinkUrl, ENT_QUOTES, 'UTF-8'); ?>" style="color: var(--accent, #2563eb); text-decoration: none; font-weight: 600; font-family: monospace; font-size: 12px;">
                                    <?php echo htmlspecialchars($refId, ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                            <?php else: ?>
                                <span style="color: var(--muted, #64748b); font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars($refId, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right; font-weight: 700; color: <?php echo $isCredit ? 'var(--success, #15803d)' : ($type === 'hold' ? 'var(--warning, #d97706)' : 'var(--danger, #b91c1c)'); ?>;">
                            <?php echo ($isCredit ? '+' : '-') . fpay_format_money($entryAmount->getAmount(), $entryAmount->getCurrency()); ?>
                        </td>
                        <td style="text-align: right; font-weight: 600; color: var(--heading, #0f172a);">
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
