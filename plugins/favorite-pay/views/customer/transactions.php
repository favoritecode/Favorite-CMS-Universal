<?php
/**
 * Customer Wallet Transactions Ledger View
 */
$balanceAmount = $balance->getAmount();
$balanceCurrency = $balance->getCurrency();

$queryParams = array_filter($filters ?? [], function($v) {
    return $v !== null && $v !== '';
});
$buildPageUrl = function(int $targetPage) use ($queryParams) {
    $params = array_merge($queryParams, ['page' => $targetPage]);
    return '/account/transactions?' . http_build_query($params);
};
?>

<div class="fpay-card" style="margin-bottom: 24px;">
    <div class="fpay-card-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
        <div>
            <h2 class="fpay-card-title" style="margin: 0; font-size: 20px; font-weight: 700; color: #0f172a;">Wallet Transaction Ledger</h2>
            <p style="margin: 4px 0 0; font-size: 13px; color: #64748b;">
                Immutable historical ledger of all balance credits, debits, holds, and settlements.
            </p>
        </div>
        <div style="text-align: right;">
            <span style="font-size: 12px; color: #64748b; display: block;">Spendable Balance:</span>
            <strong style="font-size: 22px; color: #15803d;">
                <?php echo fpay_format_money($balanceAmount, $balanceCurrency); ?>
            </strong>
        </div>
    </div>

    <!-- Filter Form -->
    <form method="GET" action="/account/transactions" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin: 16px 0 24px;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; align-items: flex-end;">
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Type</label>
                <select name="type" style="width: 100%; padding: 8px 10px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff;">
                    <option value="">All Types</option>
                    <option value="credit" <?php echo (($filters['type'] ?? '') === 'credit') ? 'selected' : ''; ?>>Credit</option>
                    <option value="debit" <?php echo (($filters['type'] ?? '') === 'debit') ? 'selected' : ''; ?>>Debit</option>
                    <option value="hold" <?php echo (($filters['type'] ?? '') === 'hold') ? 'selected' : ''; ?>>Hold</option>
                    <option value="release" <?php echo (($filters['type'] ?? '') === 'release') ? 'selected' : ''; ?>>Release</option>
                    <option value="finalize" <?php echo (($filters['type'] ?? '') === 'finalize') ? 'selected' : ''; ?>>Finalize</option>
                </select>
            </div>
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Direction</label>
                <select name="direction" style="width: 100%; padding: 8px 10px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff;">
                    <option value="">All Directions</option>
                    <option value="credit" <?php echo (($filters['direction'] ?? '') === 'credit') ? 'selected' : ''; ?>>Credit (Inflow)</option>
                    <option value="debit" <?php echo (($filters['direction'] ?? '') === 'debit') ? 'selected' : ''; ?>>Debit (Outflow)</option>
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
            <div style="grid-column: span 2;">
                <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Search</label>
                <input type="text" name="search" placeholder="Search reference, description, or entry ID..." value="<?php echo htmlspecialchars($filters['search'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; padding: 7px 10px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff;">
            </div>
        </div>
        <div style="margin-top: 14px; display: flex; gap: 8px; justify-content: flex-end;">
            <a href="/account/transactions" class="fpay-btn fpay-btn-secondary" style="text-decoration: none; padding: 7px 14px; font-size: 13px;">Reset Filters</a>
            <button type="submit" class="fpay-btn fpay-btn-primary" style="padding: 7px 18px; font-size: 13px;">Apply Filters</button>
        </div>
    </form>

    <?php if (empty($entries)): ?>
        <div style="text-align: center; padding: 48px 20px; color: #64748b; background: #fff; border-radius: 8px; border: 1px dashed #cbd5e1;">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 12px;"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
            <p style="margin: 0; font-size: 15px; font-weight: 500;">No ledger entries found matching your criteria.</p>
            <?php if (!empty($queryParams)): ?>
                <p style="margin: 6px 0 0; font-size: 13px;"><a href="/account/transactions" style="color: #2563eb; text-decoration: none;">Clear all filters</a></p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="fpay-table">
                <thead>
                    <tr>
                        <th>Date / Time</th>
                        <th>Reference</th>
                        <th>Type</th>
                        <th>Direction</th>
                        <th>Description</th>
                        <th style="text-align: right;">Amount</th>
                        <th style="text-align: right;">Balance After</th>
                        <th style="text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $entry): 
                        $type = $entry->getType();
                        $isCredit = $type === 'credit' || $type === 'release';
                        $entryAmount = $entry->getAmount();
                        $balAfter = $entry->getBalanceAfter();
                        $refType = $entry->getReferenceType();
                        $refId = $entry->getReferenceId();
                    ?>
                        <tr>
                            <td style="color: #64748b; white-space: nowrap; font-size: 13px;">
                                <?php echo htmlspecialchars($entry->getCreatedAt(), ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td>
                                <?php if ($refType === 'payment' && !empty($refId)): ?>
                                    <a href="/account/payments/<?php echo urlencode($refId); ?>" style="color: #2563eb; text-decoration: none; font-weight: 600; font-family: monospace; font-size: 12px;">
                                        <?php echo htmlspecialchars($refId, ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                <?php elseif ($refType === 'withdrawal' && !empty($refId)): ?>
                                    <a href="/account/withdrawals/<?php echo urlencode($refId); ?>" style="color: #2563eb; text-decoration: none; font-weight: 600; font-family: monospace; font-size: 12px;">
                                        <?php echo htmlspecialchars($refId, ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                <?php elseif (!empty($refId)): ?>
                                    <span style="font-family: monospace; font-size: 12px; color: #64748b;"><?php echo htmlspecialchars($refId, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php else: ?>
                                    <span style="color: #94a3b8;">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="fpay-badge <?php echo $isCredit ? 'fpay-badge-success' : ($type === 'hold' ? 'fpay-badge-warning' : 'fpay-badge-failed'); ?>" style="font-size: 11px;">
                                    <?php echo htmlspecialchars(strtoupper($type), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td>
                                <span style="font-size: 12px; font-weight: 600; color: <?php echo $isCredit ? '#15803d' : '#b91c1c'; ?>;">
                                    <?php echo $isCredit ? 'Credit (In)' : 'Debit (Out)'; ?>
                                </span>
                            </td>
                            <td style="font-size: 13px; color: #334155; max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <?php echo htmlspecialchars($entry->getDescription(), ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td style="text-align: right; font-weight: 700; font-size: 13px; color: <?php echo $isCredit ? '#15803d' : '#b91c1c'; ?>; white-space: nowrap;">
                                <?php echo ($isCredit ? '+' : '-') . fpay_format_money($entryAmount->getAmount(), $entryAmount->getCurrency()); ?>
                            </td>
                            <td style="text-align: right; font-weight: 600; font-size: 13px; color: #0f172a; white-space: nowrap;">
                                <?php echo fpay_format_money($balAfter->getAmount(), $balAfter->getCurrency()); ?>
                            </td>
                            <td style="text-align: center; white-space: nowrap;">
                                <a href="/account/transactions/<?php echo urlencode($entry->getId()); ?>" class="fpay-btn fpay-btn-secondary" style="padding: 4px 8px; font-size: 11px; text-decoration: none;">
                                    Details
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
                    Showing page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?> (<?php echo (int)$total; ?> total entries)
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
