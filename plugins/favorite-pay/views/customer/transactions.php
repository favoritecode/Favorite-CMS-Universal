<?php
/**
 * Customer Wallet Transactions Ledger View
 */
$balanceAmount = $balance->getAmount();
$balanceCurrency = $balance->getCurrency();
?>

<div class="fpay-card">
    <div class="fpay-card-header">
        <div>
            <h2 class="fpay-card-title">Wallet Financial Ledger</h2>
            <p style="margin: 4px 0 0; font-size: 13px; color: #64748b;">
                Immutable ledger of all balance credits, debits, holds, and settlements.
            </p>
        </div>
        <div style="text-align: right;">
            <span style="font-size: 12px; color: #64748b; display: block;">Current Balance:</span>
            <strong style="font-size: 20px; color: #15803d;">
                <?php echo fpay_format_money($balanceAmount, $balanceCurrency); ?>
            </strong>
        </div>
    </div>

    <?php if (empty($entries)): ?>
        <div style="text-align: center; padding: 48px 20px; color: #64748b;">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 12px;"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
            <p style="margin: 0; font-size: 15px; font-weight: 500;">No ledger entries found.</p>
        </div>
    <?php else: ?>
        <table class="fpay-table">
            <thead>
                <tr>
                    <th>Entry ID</th>
                    <th>Date / Time</th>
                    <th>Type</th>
                    <th>Description</th>
                    <th>Reference</th>
                    <th style="text-align: right;">Amount</th>
                    <th style="text-align: right;">Balance After</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($entries as $entry): 
                    $type = $entry->getType();
                    $isCredit = $type === 'credit' || $type === 'release';
                    $entryAmount = $entry->getAmount();
                    $balAfter = $entry->getBalanceAfter();
                ?>
                    <tr>
                        <td style="font-family: monospace; font-size: 12px; color: #64748b;">
                            <?php echo htmlspecialchars($entry->getId(), ENT_QUOTES, 'UTF-8'); ?>
                        </td>
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
                        <td style="text-align: right; font-weight: 600; color: #0f172a;">
                            <?php echo fpay_format_money($balAfter->getAmount(), $balAfter->getCurrency()); ?>
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
                        <a href="/account/transactions?page=<?php echo $page - 1; ?>" class="fpay-btn fpay-btn-secondary fpay-btn-sm">&larr; Previous</a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="/account/transactions?page=<?php echo $page + 1; ?>" class="fpay-btn fpay-btn-secondary fpay-btn-sm">Next &rarr;</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
