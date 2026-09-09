<?php
/**
 * Customer Payment History View
 */
$queryParams = array_filter($filters ?? [], function($v) {
    return $v !== null && $v !== '';
});
$buildPageUrl = function(int $targetPage) use ($queryParams) {
    $params = array_merge($queryParams, ['page' => $targetPage]);
    return '/account/payments?' . http_build_query($params);
};
?>
<div class="fpay-card">
    <div class="fpay-card-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
        <div>
            <h2 class="fpay-card-title" style="margin: 0; font-size: 20px; font-weight: 700; color: #0f172a;">Payment & Checkout History</h2>
            <p style="margin: 4px 0 0; font-size: 13px; color: #64748b;">
                History of all payment intents, checkout attempts, and gateway transactions.
            </p>
        </div>
        <span style="font-size: 13px; color: #64748b;">
            Total: <strong><?php echo (int)$total; ?></strong> record(s)
        </span>
    </div>

    <!-- Filter Form -->
    <form method="GET" action="/account/payments" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin: 16px 0 24px;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; align-items: flex-end;">
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Status</label>
                <select name="status" style="width: 100%; padding: 8px 10px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff;">
                    <option value="">All Statuses</option>
                    <option value="succeeded" <?php echo (($filters['status'] ?? '') === 'succeeded') ? 'selected' : ''; ?>>Succeeded</option>
                    <option value="pending" <?php echo (($filters['status'] ?? '') === 'pending') ? 'selected' : ''; ?>>Pending / Processing</option>
                    <option value="failed" <?php echo (($filters['status'] ?? '') === 'failed') ? 'selected' : ''; ?>>Failed</option>
                    <option value="cancelled" <?php echo (($filters['status'] ?? '') === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">From Date</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($filters['date_from'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; padding: 7px 10px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff;">
            </div>
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">To Date</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($filters['date_to'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; padding: 7px 10px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff;">
            </div>
            <div style="display: flex; gap: 8px; justify-content: flex-end;">
                <a href="/account/payments" class="fpay-btn fpay-btn-secondary" style="text-decoration: none; padding: 7px 14px; font-size: 13px;">Reset</a>
                <button type="submit" class="fpay-btn fpay-btn-primary" style="padding: 7px 18px; font-size: 13px;">Filter</button>
            </div>
        </div>
    </form>

    <?php if (empty($payments)): ?>
        <div style="text-align: center; padding: 48px 20px; color: #64748b; background: #fff; border-radius: 8px; border: 1px dashed #cbd5e1;">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 12px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
            <p style="margin: 0; font-size: 15px; font-weight: 500;">No payment records found matching your criteria.</p>
            <?php if (!empty($queryParams)): ?>
                <p style="margin: 6px 0 0; font-size: 13px;"><a href="/account/payments" style="color: #2563eb; text-decoration: none;">Clear all filters</a></p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="fpay-table">
                <thead>
                    <tr>
                        <th>Payment ID</th>
                        <th>Date</th>
                        <th>Gateway / Method</th>
                        <th style="text-align: right;">Amount</th>
                        <th>Payment Status</th>
                        <th>Wallet Settlement</th>
                        <th style="text-align: center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $p): 
                        $txId = $p['transaction_id'] ?? '';
                        $amount = (float)($p['base_amount'] ?? 0);
                        $currency = $p['base_currency'] ?? 'BDT';
                        $status = strtolower($p['status'] ?? 'pending');
                        $gw = $p['gateway_id'] ?? $p['payment_method_type'] ?? 'Payment';
                        $date = substr((string)($p['created_at'] ?? ''), 0, 16);
                        $isSettled = ($status === 'succeeded');
                    ?>
                        <tr>
                            <td>
                                <a href="/account/payments/<?php echo urlencode($txId); ?>" style="color: #2563eb; font-weight: 700; text-decoration: none; font-family: monospace; font-size: 12px;">
                                    <?php echo htmlspecialchars($txId, ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                            </td>
                            <td style="color: #64748b; white-space: nowrap; font-size: 12px;">
                                <?php echo htmlspecialchars($date, ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td style="font-size: 13px; color: #334155;">
                                <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $gw)), ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td style="text-align: right; font-weight: 700; font-size: 13px; color: #0f172a; white-space: nowrap;">
                                <?php echo fpay_format_money($amount, $currency); ?>
                            </td>
                            <td>
                                <span class="fpay-badge fpay-badge-<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>" style="font-size: 11px;">
                                    <?php echo htmlspecialchars(strtoupper($status), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($isSettled): ?>
                                    <span style="color: #15803d; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                        &check; Credited
                                    </span>
                                <?php elseif ($status === 'pending'): ?>
                                    <span style="color: #d97706; font-size: 12px; font-weight: 500;">
                                        &bull; Pending Settlement
                                    </span>
                                <?php else: ?>
                                    <span style="color: #94a3b8; font-size: 12px;">
                                        &mdash; Not Credited
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center; white-space: nowrap;">
                                <a href="/account/payments/<?php echo urlencode($txId); ?>" class="fpay-btn fpay-btn-secondary fpay-btn-sm" style="font-size: 11px; padding: 3px 8px; text-decoration: none;">
                                    View Details
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="fpay-pagination" style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px; padding-top: 16px; border-top: 1px solid #e2e8f0;">
                <div style="font-size: 13px; color: #64748b;">
                    Showing page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?> (<?php echo (int)$total; ?> total records)
                </div>
                <div class="fpay-pagination-links" style="display: flex; gap: 8px;">
                    <?php if ($page > 1): ?>
                        <a href="<?php echo htmlspecialchars($buildPageUrl($page - 1), ENT_QUOTES, 'UTF-8'); ?>" class="fpay-btn fpay-btn-secondary fpay-btn-sm" style="text-decoration: none;">&larr; Previous</a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="<?php echo htmlspecialchars($buildPageUrl($page + 1), ENT_QUOTES, 'UTF-8'); ?>" class="fpay-btn fpay-btn-secondary fpay-btn-sm" style="text-decoration: none;">Next &rarr;</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
