<?php
/**
 * Customer Recharge View
 */
?>
<div style="max-width: 760px; margin: 0 auto;">
    <!-- Balance Header Summary -->
    <div class="fpay-card" style="margin-bottom: 20px; padding: 18px 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div>
                <span style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase;">Spendable Available Balance</span>
                <div style="font-size: 24px; font-weight: 800; color: #15803d; margin-top: 2px;">
                    <?php echo fpay_format_money($availableBalance->getAmount(), $availableBalance->getCurrency()); ?>
                </div>
            </div>
            <?php if ($heldBalance->getAmount() > 0): ?>
                <div style="text-align: center;">
                    <span style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase;">Held in Withdrawals</span>
                    <div style="font-size: 18px; font-weight: 700; color: #d97706; margin-top: 2px;">
                        <?php echo fpay_format_money($heldBalance->getAmount(), $heldBalance->getCurrency()); ?>
                    </div>
                </div>
                <div style="text-align: right;">
                    <span style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase;">Total Balance</span>
                    <div style="font-size: 18px; font-weight: 700; color: #0f172a; margin-top: 2px;">
                        <?php echo fpay_format_money($totalBalance->getAmount(), $totalBalance->getCurrency()); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recharge Form Card -->
    <div class="fpay-card" style="margin-bottom: 24px;">
        <div class="fpay-card-header">
            <h2 class="fpay-card-title">Add Funds to Your Wallet</h2>
        </div>

        <?php if (!empty($isSuspended)): ?>
            <div class="fpay-alert fpay-alert-warning" style="margin-bottom: 0;">
                Your account is currently suspended. Recharging balance is disabled.
            </div>
        <?php else: ?>
            <form action="/account/recharge" method="POST" id="fpay-recharge-form">
                <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                <!-- Amount Input -->
                <div style="margin-bottom: 24px;">
                    <label for="recharge_amount" style="display: block; font-weight: 700; font-size: 15px; margin-bottom: 8px; color: #0f172a;">
                        Recharge Amount (<?php echo htmlspecialchars($primaryCurrency, ENT_QUOTES, 'UTF-8'); ?>)
                    </label>
                    <div style="position: relative; display: flex; align-items: center;">
                        <span style="position: absolute; left: 16px; font-size: 20px; font-weight: 700; color: #64748b;">
                            <?php echo match(strtoupper($primaryCurrency)) { 'BDT' => '৳', 'USD' => '$', 'EUR' => '€', default => $primaryCurrency }; ?>
                        </span>
                        <input type="number" 
                               name="amount" 
                               id="recharge_amount" 
                               step="any" 
                               min="1" 
                               required 
                               placeholder="e.g. 500" 
                               style="width: 100%; padding: 14px 14px 14px 44px; font-size: 20px; font-weight: 700; border: 2px solid #cbd5e1; border-radius: 8px; outline: none; box-sizing: border-box;"
                               onfocus="this.style.borderColor='#2563eb'"
                               onblur="this.style.borderColor='#cbd5e1'">
                    </div>
                    <small style="display: block; margin-top: 6px; color: #64748b; font-size: 13px;">
                        Funds are credited strictly in your wallet's primary accounting currency (<strong><?php echo htmlspecialchars($primaryCurrency, ENT_QUOTES, 'UTF-8'); ?></strong>).
                    </small>
                </div>

                <!-- Payment Method Selection -->
                <div style="margin-bottom: 28px;">
                    <label style="display: block; font-weight: 700; font-size: 15px; margin-bottom: 12px; color: #0f172a;">
                        Select Payment Method
                    </label>

                    <?php if (empty($gateways)): ?>
                        <div class="fpay-alert fpay-alert-warning">
                            No payment gateways are currently available. Please contact administrator.
                        </div>
                    <?php else: ?>
                        <div style="display: flex; flex-direction: column; gap: 12px;">
                            <?php $first = true; foreach ($gateways as $id => $gw): ?>
                                <label style="display: flex; align-items: flex-start; gap: 14px; padding: 16px; border: 2px solid #e2e8f0; border-radius: 10px; cursor: pointer; transition: all 0.15s ease;"
                                       class="fpay-gateway-option"
                                       onclick="document.querySelectorAll('.fpay-gateway-option').forEach(el => el.style.borderColor='#e2e8f0'); this.style.borderColor='#2563eb';">
                                    <input type="radio" 
                                           name="gateway_id" 
                                           value="<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>"
                                           <?php if ($first) { echo 'checked'; $first = false; } ?>
                                           style="margin-top: 4px; accent-color: #2563eb;">
                                    <div style="flex: 1;">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                            <strong style="font-size: 15px; color: #0f172a;">
                                                 <?php echo htmlspecialchars($gw['title'], ENT_QUOTES, 'UTF-8'); ?>
                                            </strong>
                                            <?php if (!empty($gw['is_manual'])): ?>
                                                <span class="fpay-badge fpay-badge-active" style="font-size: 11px;">Manual Verification</span>
                                            <?php else: ?>
                                                <span class="fpay-badge fpay-badge-success" style="font-size: 11px;">Instant Automated</span>
                                            <?php endif; ?>
                                        </div>
                                        <p style="margin: 0; font-size: 13px; color: #64748b; line-height: 1.4;">
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
    <div class="fpay-card">
        <div class="fpay-card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h3 class="fpay-card-title" style="font-size: 17px; margin: 0;">Recent Recharge Activity</h3>
            <a href="/account/payments" style="font-size: 13px; color: #2563eb; text-decoration: none; font-weight: 500;">
                All Payments &rarr;
            </a>
        </div>

        <?php if (empty($recentRecharges)): ?>
            <div style="text-align: center; padding: 32px 16px; color: #64748b;">
                <p style="margin: 0; font-size: 14px;">No recent recharge requests found.</p>
            </div>
        <?php else: ?>
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
                                <td style="font-size: 12px; color: #64748b; white-space: nowrap;">
                                    <?php echo htmlspecialchars($r['created_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td>
                                    <a href="/account/payments/<?php echo urlencode($txId); ?>" style="font-family: monospace; font-size: 12px; font-weight: 600; color: #2563eb; text-decoration: none;">
                                        <?php echo htmlspecialchars($txId, ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </td>
                                <td style="font-size: 12px; color: #334155;">
                                    <?php echo htmlspecialchars($gwLabel, ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td style="text-align: right; font-weight: 600; font-size: 13px; color: #0f172a; white-space: nowrap;">
                                    <?php echo fpay_format_money($amtPaid, $curPaid); ?>
                                </td>
                                <td style="text-align: right; font-weight: 700; font-size: 13px; color: #15803d; white-space: nowrap;">
                                    <?php echo fpay_format_money($amtAcc, $curAcc); ?>
                                    <?php if ($isDiff && $rate > 0): ?>
                                        <div style="font-size: 10px; color: #64748b; font-weight: normal;">
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
        <?php endif; ?>
    </div>
</div>
