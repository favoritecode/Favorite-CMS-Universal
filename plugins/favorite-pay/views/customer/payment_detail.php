<?php
/**
 * Customer Payment Receipt / Details View
 */
$txId = $payment['transaction_id'] ?? '';
$status = strtolower($payment['status'] ?? 'pending');
$baseAmount = (int)($payment['base_amount'] ?? 0);
$baseCurrency = $payment['base_currency'] ?? 'BDT';
$chargeAmount = (int)($payment['charge_amount'] ?? $baseAmount);
$chargeCurrency = $payment['charge_currency'] ?? $baseCurrency;
$gatewayTitle = $payment['gateway_title'] ?? 'Online Payment';
$createdAt = $payment['created_at'] ?? '';
$completedAt = $payment['completed_at'] ?? null;
$walletSettled = !empty($payment['wallet_settled']);
$attempts = $payment['attempts'] ?? [];
?>

<div style="max-width: 720px; margin: 0 auto;">
    <div style="margin-bottom: 16px;">
        <a href="/account/payments" style="color: #2563eb; text-decoration: none; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
            &larr; Back to Payment History
        </a>
    </div>

    <div class="fpay-card">
        <div class="fpay-card-header">
            <div>
                <span style="font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b;">
                    Payment Receipt
                </span>
                <h2 class="fpay-card-title" style="font-family: monospace; margin-top: 4px;">
                    <?php echo htmlspecialchars($txId, ENT_QUOTES, 'UTF-8'); ?>
                </h2>
            </div>
            <div>
                <span class="fpay-badge fpay-badge-<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>" style="font-size: 14px; padding: 6px 14px;">
                    <?php echo htmlspecialchars(strtoupper($status), ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
        </div>

        <!-- Key Financial Values -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px; background: #f8fafc; padding: 18px; border-radius: 10px; border: 1px solid #e2e8f0;">
            <div>
                <span style="display: block; font-size: 13px; color: #64748b; margin-bottom: 2px;">Total Accounting Amount</span>
                <span style="font-size: 24px; font-weight: 800; color: #0f172a;">
                    <?php echo fpay_format_money($baseAmount, $baseCurrency); ?>
                </span>
            </div>
            <div>
                <span style="display: block; font-size: 13px; color: #64748b; margin-bottom: 2px;">Wallet Settlement</span>
                <?php if ($walletSettled): ?>
                    <span class="fpay-badge fpay-badge-success" style="margin-top: 4px;">Credited to Wallet</span>
                <?php else: ?>
                    <span class="fpay-badge fpay-badge-pending" style="margin-top: 4px;">Pending Settlement</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Detailed Key-Value Rows -->
        <table class="fpay-table" style="margin-bottom: 24px;">
            <tbody>
                <tr>
                    <td style="width: 200px; color: #64748b; font-weight: 500;">Payment Method</td>
                    <td style="font-weight: 600; color: #0f172a;">
                        <?php echo htmlspecialchars($gatewayTitle, ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                </tr>
                <?php if ($chargeCurrency !== $baseCurrency): ?>
                    <tr>
                        <td style="color: #64748b; font-weight: 500;">Acquiring Charge</td>
                        <td style="font-weight: 600; color: #0f172a;">
                            <?php echo fpay_format_money($chargeAmount, $chargeCurrency); ?>
                        </td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <td style="color: #64748b; font-weight: 500;">Initiated At</td>
                    <td><?php echo htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <?php if ($completedAt): ?>
                    <tr>
                        <td style="color: #64748b; font-weight: 500;">Completed At</td>
                        <td><?php echo htmlspecialchars($completedAt, ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Attempts & Submission References (Without Private Secrets) -->
        <?php if (!empty($attempts)): ?>
            <h3 style="font-size: 15px; font-weight: 700; color: #0f172a; margin: 0 0 12px;">Verification Attempts &amp; References</h3>
            <table class="fpay-table">
                <thead>
                    <tr>
                        <th>Attempt ID</th>
                        <th>Reference / TrxID</th>
                        <th>Status</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($attempts as $att): ?>
                        <tr>
                            <td style="font-family: monospace; font-size: 13px;"><?php echo htmlspecialchars($att['attempt_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><strong><?php echo htmlspecialchars($att['provider_reference'] ?? 'Pending', ENT_QUOTES, 'UTF-8'); ?></strong></td>
                            <td>
                                <span class="fpay-badge fpay-badge-<?php echo htmlspecialchars(strtolower($att['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars(strtoupper($att['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td style="color: #64748b; font-size: 13px;"><?php echo htmlspecialchars($att['created_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
