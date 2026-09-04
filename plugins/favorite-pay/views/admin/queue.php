<?php
declare(strict_types=1);

/**
 * @var array $items
 * @var int $total
 * @var int $page
 * @var int $totalPages
 * @var array $counts
 * @var string $currentStatus
 * @var string $currentGateway
 * @var string $currentSearch
 */

$statusLabels = [
    'awaiting_verification' => 'Awaiting Verification',
    'succeeded'             => 'Succeeded',
    'failed'                => 'Failed',
    'pending'               => 'Pending',
];
?>
<div class="favorite-pay-admin">
    <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h1 class="page-title" style="margin: 0; font-size: 22px; font-weight: 600; color: #1e293b;">
            💳 Favorite Pay &mdash; Manual Payment Verification Queue
        </h1>
        <div>
            <span style="font-size: 13px; color: #64748b;">
                Authoritative Operator Panel
            </span>
        </div>
    </div>

    <!-- Filter Tabs (subsubsub style) -->
    <ul class="subsubsub" style="list-style: none; margin: 0 0 16px 0; padding: 0; font-size: 13px; color: #64748b;">
        <li style="display: inline-block;">
            <a href="/admin/page/favorite-pay?status=awaiting_verification" 
               class="<?php echo $currentStatus === 'awaiting_verification' ? 'current' : ''; ?>"
               style="<?php echo $currentStatus === 'awaiting_verification' ? 'font-weight: 700; color: #b45309;' : 'color: #2271b1; text-decoration: none;'; ?>">
                Awaiting Verification (<?php echo (int)($counts['awaiting_verification'] ?? 0); ?>)
            </a> |
        </li>
        <li style="display: inline-block;">
            <a href="/admin/page/favorite-pay?status=all" 
               class="<?php echo $currentStatus === 'all' ? 'current' : ''; ?>"
               style="<?php echo $currentStatus === 'all' ? 'font-weight: 700; color: #000;' : 'color: #2271b1; text-decoration: none;'; ?>">
                All (<?php echo (int)($counts['all'] ?? 0); ?>)
            </a> |
        </li>
        <li style="display: inline-block;">
            <a href="/admin/page/favorite-pay?status=succeeded" 
               class="<?php echo $currentStatus === 'succeeded' ? 'current' : ''; ?>"
               style="<?php echo $currentStatus === 'succeeded' ? 'font-weight: 700; color: #15803d;' : 'color: #2271b1; text-decoration: none;'; ?>">
                Succeeded (<?php echo (int)($counts['succeeded'] ?? 0); ?>)
            </a> |
        </li>
        <li style="display: inline-block;">
            <a href="/admin/page/favorite-pay?status=failed" 
               class="<?php echo $currentStatus === 'failed' ? 'current' : ''; ?>"
               style="<?php echo $currentStatus === 'failed' ? 'font-weight: 700; color: #b91c1c;' : 'color: #2271b1; text-decoration: none;'; ?>">
                Failed / Rejected (<?php echo (int)($counts['failed'] ?? 0); ?>)
            </a>
        </li>
    </ul>

    <!-- Search & Gateway Filter Bar -->
    <form method="GET" action="/admin/page/favorite-pay" style="margin-bottom: 16px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($currentStatus, ENT_QUOTES, 'UTF-8'); ?>">

        <select name="gateway_id" class="form-control" style="max-width: 200px; padding: 6px 10px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px;">
            <option value="all">All Gateways</option>
            <option value="manual_bkash" <?php echo $currentGateway === 'manual_bkash' ? 'selected' : ''; ?>>bKash Manual</option>
            <option value="manual_nagad" <?php echo $currentGateway === 'manual_nagad' ? 'selected' : ''; ?>>Nagad Manual</option>
            <option value="manual_bank" <?php echo $currentGateway === 'manual_bank' ? 'selected' : ''; ?>>Bank Transfer</option>
            <option value="manual_bd" <?php echo $currentGateway === 'manual_bd' ? 'selected' : ''; ?>>Manual BD Generic</option>
        </select>

        <input type="text" 
               name="search" 
               class="form-control" 
               placeholder="Search by TrxID or Transaction ID..." 
               value="<?php echo htmlspecialchars($currentSearch, ENT_QUOTES, 'UTF-8'); ?>"
               style="max-width: 320px; padding: 6px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px;">

        <button type="submit" class="btn btn-secondary" style="padding: 6px 14px; font-size: 13px; background: #f0f0f1; border: 1px solid #8c8f94; border-radius: 3px; cursor: pointer;">
            Filter Queue
        </button>

        <?php if ($currentSearch !== '' || $currentGateway !== 'all'): ?>
            <a href="/admin/page/favorite-pay?status=<?php echo urlencode($currentStatus); ?>" 
               class="btn" 
               style="padding: 6px 12px; font-size: 13px; text-decoration: none; color: #64748b;">
                Clear Filters
            </a>
        <?php endif; ?>
    </form>

    <!-- Attempts Table -->
    <div class="wp-table-wrap" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; overflow-x: auto; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
        <table class="wp-table" style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13px;">
            <thead>
                <tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                    <th style="padding: 10px 14px; font-weight: 600; color: #334155;">Attempt / Date</th>
                    <th style="padding: 10px 14px; font-weight: 600; color: #334155;">Transaction ID</th>
                    <th style="padding: 10px 14px; font-weight: 600; color: #334155;">Gateway</th>
                    <th style="padding: 10px 14px; font-weight: 600; color: #334155;">Amount</th>
                    <th style="padding: 10px 14px; font-weight: 600; color: #334155;">Customer TrxID</th>
                    <th style="padding: 10px 14px; font-weight: 600; color: #334155;">Sender Account</th>
                    <th style="padding: 10px 14px; font-weight: 600; color: #334155;">Status</th>
                    <th style="padding: 10px 14px; font-weight: 600; color: #334155; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; color: #64748b; padding: 36px 16px;">
                            <div style="font-size: 15px; font-weight: 500; margin-bottom: 4px;">No payment attempts found</div>
                            <div style="font-size: 12px; color: #94a3b8;">There are no payment attempts matching the current filter criteria.</div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $st = (string)($item['status'] ?? 'pending');
                        $amountMinor = (int)($item['amount'] ?? 0);
                        $formattedAmount = number_format($amountMinor / 100, 2) . ' ' . htmlspecialchars($item['currency'] ?? 'BDT', ENT_QUOTES, 'UTF-8');
                        
                        $badgeStyle = match ($st) {
                            'awaiting_verification' => 'background: #fef3c7; color: #92400e; border: 1px solid #fcd34d;',
                            'succeeded'             => 'background: #dcfce7; color: #166534; border: 1px solid #86efac;',
                            'failed'                => 'background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5;',
                            default                 => 'background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;',
                        };
                        ?>
                        <tr style="border-bottom: 1px solid #f1f5f9; transition: background 0.1s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">
                            <td style="padding: 12px 14px;">
                                <strong>
                                    <a href="/admin/page/favorite-pay?action=view&id=<?php echo urlencode((string)$item['attempt_id']); ?>" style="color: #0f172a; text-decoration: none;">
                                        <?php echo htmlspecialchars((string)$item['attempt_id'], ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </strong>
                                <div style="font-size: 11px; color: #64748b; margin-top: 2px;">
                                    <?php echo htmlspecialchars((string)($item['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </td>
                            <td style="padding: 12px 14px; font-family: monospace; font-size: 12px; color: #334155;">
                                <?php echo htmlspecialchars((string)$item['transaction_id'], ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td style="padding: 12px 14px;">
                                <span style="display: inline-block; padding: 2px 8px; background: #e2e8f0; border-radius: 3px; font-size: 11px; font-weight: 500; color: #334155;">
                                    <?php echo htmlspecialchars((string)$item['gateway_id'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td style="padding: 12px 14px; font-weight: 600; color: #0f172a;">
                                <?php echo $formattedAmount; ?>
                            </td>
                            <td style="padding: 12px 14px; font-family: monospace; font-weight: 600; color: #0369a1;">
                                <?php echo htmlspecialchars((string)($item['provider_reference'] ?? '&mdash;'), ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td style="padding: 12px 14px; color: #475569;">
                                <?php echo htmlspecialchars((string)($item['sender_account'] ?? '&mdash;'), ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td style="padding: 12px 14px;">
                                <span style="display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; <?php echo $badgeStyle; ?>">
                                    <?php echo htmlspecialchars($statusLabels[$st] ?? ucfirst($st), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td style="padding: 12px 14px; text-align: right;">
                                <a href="/admin/page/favorite-pay?action=view&id=<?php echo urlencode((string)$item['attempt_id']); ?>" 
                                   class="btn btn-primary" 
                                   style="padding: 4px 10px; font-size: 12px; background: #2271b1; color: #fff; text-decoration: none; border-radius: 3px; font-weight: 500;">
                                    Review &rarr;
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div style="display: flex; justify-content: flex-end; align-items: center; gap: 8px; margin-top: 16px;">
            <span style="font-size: 12px; color: #64748b; margin-right: 8px;">
                <?php echo (int)$total; ?> attempts &bull; Page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?>
            </span>
            <?php
            $baseParams = [
                'status'     => $currentStatus,
                'gateway_id' => $currentGateway,
                'search'     => $currentSearch,
            ];
            ?>
            <?php if ($page > 1): ?>
                <?php $baseParams['p'] = $page - 1; ?>
                <a href="/admin/page/favorite-pay?<?php echo http_build_query($baseParams); ?>" 
                   class="btn btn-secondary" 
                   style="padding: 4px 10px; font-size: 12px; background: #fff; border: 1px solid #8c8f94; border-radius: 3px; text-decoration: none; color: #2271b1;">
                    &laquo; Prev
                </a>
            <?php endif; ?>

            <?php if ($page < $totalPages): ?>
                <?php $baseParams['p'] = $page + 1; ?>
                <a href="/admin/page/favorite-pay?<?php echo http_build_query($baseParams); ?>" 
                   class="btn btn-secondary" 
                   style="padding: 4px 10px; font-size: 12px; background: #fff; border: 1px solid #8c8f94; border-radius: 3px; text-decoration: none; color: #2271b1;">
                    Next &raquo;
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
