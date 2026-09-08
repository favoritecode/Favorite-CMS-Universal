<?php
/**
 * Customer Payment History View
 */
?>
<div class="fpay-card">
    <div class="fpay-card-header">
        <h2 class="fpay-card-title">All Customer Payments</h2>
        <span style="font-size: 13px; color: #64748b;">
            Total: <strong><?php echo (int)$total; ?></strong> record(s)
        </span>
    </div>

    <?php if (empty($payments)): ?>
        <div style="text-align: center; padding: 48px 20px; color: #64748b;">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 12px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
            <p style="margin: 0; font-size: 15px; font-weight: 500;">No payment records found.</p>
        </div>
    <?php else: ?>
        <table class="fpay-table">
            <thead>
                <tr>
                    <th>Payment ID</th>
                    <th>Date</th>
                    <th>Gateway / Method</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th style="text-align: center;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $p): 
                    $txId = $p['transaction_id'] ?? '';
                    $amount = (int)($p['base_amount'] ?? 0);
                    $currency = $p['base_currency'] ?? 'BDT';
                    $status = strtolower($p['status'] ?? 'pending');
                    $gw = $p['gateway_id'] ?? $p['payment_method_type'] ?? 'Payment';
                    $date = substr((string)($p['created_at'] ?? ''), 0, 16);
                ?>
                    <tr>
                        <td>
                            <a href="/account/payments/<?php echo urlencode($txId); ?>" style="color: #2563eb; font-weight: 700; text-decoration: none; font-family: monospace;">
                                <?php echo htmlspecialchars($txId, ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </td>
                        <td style="color: #64748b; white-space: nowrap;">
                            <?php echo htmlspecialchars($date, ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $gw)), ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td style="font-weight: 700; color: #0f172a;">
                            <?php echo fpay_format_money($amount, $currency); ?>
                        </td>
                        <td>
                            <span class="fpay-badge fpay-badge-<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars(strtoupper($status), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>
                        <td style="text-align: center;">
                            <a href="/account/payments/<?php echo urlencode($txId); ?>" class="fpay-btn fpay-btn-secondary fpay-btn-sm">
                                View Details
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="fpay-pagination">
                <div>Page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?></div>
                <div class="fpay-pagination-links">
                    <?php if ($page > 1): ?>
                        <a href="/account/payments?page=<?php echo $page - 1; ?>" class="fpay-btn fpay-btn-secondary fpay-btn-sm">&larr; Previous</a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="/account/payments?page=<?php echo $page + 1; ?>" class="fpay-btn fpay-btn-secondary fpay-btn-sm">Next &rarr;</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
