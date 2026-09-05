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
    <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
        <div>
            <h1 class="page-title" style="margin: 0; font-size: 22px; font-weight: 700; color: var(--wp-text-main);">
                💳 Favorite Pay &mdash; Manual Payment Verification Queue
            </h1>
            <p style="margin: 4px 0 0 0; font-size: 13px; color: var(--wp-text-muted);">
                Authoritative verification queue for manual bKash, Nagad, Rocket, and Bank Wire transfers.
            </p>
        </div>
        <div>
            <span class="badge badge-info" style="font-size: 12px; padding: 5px 12px;">
                Authoritative Operator Panel
            </span>
        </div>
    </div>

    <!-- Filter Tabs (subsubsub style) -->
    <div style="margin-bottom: 18px; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px;">
        <ul class="subsubsub" style="list-style: none; margin: 0; padding: 0; font-size: 13px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center;">
            <li>
                <a href="/admin/page/favorite-pay?status=awaiting_verification" 
                   class="<?php echo $currentStatus === 'awaiting_verification' ? 'current' : ''; ?>"
                   style="<?php echo $currentStatus === 'awaiting_verification' ? 'font-weight: 700; color: #b45309; text-decoration: none;' : 'color: var(--wp-blue); text-decoration: none;'; ?>">
                    Awaiting Verification <span class="badge badge-warning" style="margin-left: 4px;"><?php echo (int)($counts['awaiting_verification'] ?? 0); ?></span>
                </a>
                <span style="color: #cbd5e1; margin-left: 8px;">|</span>
            </li>
            <li>
                <a href="/admin/page/favorite-pay?status=all" 
                   class="<?php echo $currentStatus === 'all' ? 'current' : ''; ?>"
                   style="<?php echo $currentStatus === 'all' ? 'font-weight: 700; color: var(--wp-text-main); text-decoration: none;' : 'color: var(--wp-blue); text-decoration: none;'; ?>">
                    All <span class="badge badge-secondary" style="margin-left: 4px;"><?php echo (int)($counts['all'] ?? 0); ?></span>
                </a>
                <span style="color: #cbd5e1; margin-left: 8px;">|</span>
            </li>
            <li>
                <a href="/admin/page/favorite-pay?status=succeeded" 
                   class="<?php echo $currentStatus === 'succeeded' ? 'current' : ''; ?>"
                   style="<?php echo $currentStatus === 'succeeded' ? 'font-weight: 700; color: #15803d; text-decoration: none;' : 'color: var(--wp-blue); text-decoration: none;'; ?>">
                    Succeeded <span class="badge badge-success" style="margin-left: 4px;"><?php echo (int)($counts['succeeded'] ?? 0); ?></span>
                </a>
                <span style="color: #cbd5e1; margin-left: 8px;">|</span>
            </li>
            <li>
                <a href="/admin/page/favorite-pay?status=failed" 
                   class="<?php echo $currentStatus === 'failed' ? 'current' : ''; ?>"
                   style="<?php echo $currentStatus === 'failed' ? 'font-weight: 700; color: #b91c1c; text-decoration: none;' : 'color: var(--wp-blue); text-decoration: none;'; ?>">
                    Failed / Rejected <span class="badge badge-danger" style="margin-left: 4px;"><?php echo (int)($counts['failed'] ?? 0); ?></span>
                </a>
            </li>
        </ul>
    </div>

    <!-- Search & Gateway Filter Bar -->
    <div class="card" style="margin-bottom: 20px; padding: 14px 18px;">
        <form method="GET" action="/admin/page/favorite-pay" style="margin: 0; display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($currentStatus, ENT_QUOTES, 'UTF-8'); ?>">

            <div style="min-width: 180px;">
                <select name="gateway_id" class="form-control" style="font-size: 13px;">
                    <option value="all">All Gateways</option>
                    <option value="manual_bkash" <?php echo $currentGateway === 'manual_bkash' ? 'selected' : ''; ?>>bKash Manual</option>
                    <option value="manual_nagad" <?php echo $currentGateway === 'manual_nagad' ? 'selected' : ''; ?>>Nagad Manual</option>
                    <option value="manual_bank" <?php echo $currentGateway === 'manual_bank' ? 'selected' : ''; ?>>Bank Transfer</option>
                    <option value="manual_bd" <?php echo $currentGateway === 'manual_bd' ? 'selected' : ''; ?>>Manual BD Generic</option>
                </select>
            </div>

            <div style="flex: 1; min-width: 240px; max-width: 380px;">
                <input type="text" 
                       name="search" 
                       class="form-control" 
                       placeholder="Search by TrxID or Transaction ID..." 
                       value="<?php echo htmlspecialchars($currentSearch, ENT_QUOTES, 'UTF-8'); ?>"
                       style="font-size: 13px;">
            </div>

            <button type="submit" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px;">
                <span>🔍</span> Filter Queue
            </button>

            <?php if ($currentSearch !== '' || $currentGateway !== 'all'): ?>
                <a href="/admin/page/favorite-pay?status=<?php echo urlencode($currentStatus); ?>" 
                   class="btn" 
                   style="font-size: 13px; text-decoration: none; color: var(--wp-text-muted); background: #f8fafc; border: 1px solid #e2e8f0;">
                    Clear Filters
                </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Attempts Table -->
    <div class="wp-table-wrap">
        <table class="wp-table">
            <thead>
                <tr>
                    <th style="width: 140px;">Attempt / Date</th>
                    <th>Transaction ID</th>
                    <th>Gateway</th>
                    <th>Amount</th>
                    <th>Customer TrxID</th>
                    <th>Sender Account</th>
                    <th style="width: 150px;">Status</th>
                    <th style="width: 100px; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; color: var(--wp-text-muted); padding: 48px 16px;">
                            <div style="font-size: 32px; margin-bottom: 8px;">💳</div>
                            <div style="font-size: 15px; font-weight: 600; color: var(--wp-text-main); margin-bottom: 4px;">No payment attempts found</div>
                            <div style="font-size: 12px; color: var(--wp-text-muted);">There are no payment attempts matching the current filter criteria.</div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $st = (string)($item['status'] ?? 'pending');
                        $amountMinor = (int)($item['amount'] ?? 0);
                        $formattedAmount = number_format($amountMinor / 100, 2) . ' ' . htmlspecialchars($item['currency'] ?? 'BDT', ENT_QUOTES, 'UTF-8');
                        
                        $badgeClass = match ($st) {
                            'awaiting_verification' => 'badge-warning',
                            'succeeded'             => 'badge-success',
                            'failed'                => 'badge-danger',
                            default                 => 'badge-secondary',
                        };
                        ?>
                        <tr>
                            <td>
                                <strong>
                                    <a href="/admin/page/favorite-pay?action=view&id=<?php echo urlencode((string)$item['attempt_id']); ?>" style="color: var(--wp-text-main); text-decoration: none; font-weight: 600;">
                                        <?php echo htmlspecialchars((string)$item['attempt_id'], ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </strong>
                                <div style="font-size: 11px; color: var(--wp-text-muted); margin-top: 2px;">
                                    <?php echo htmlspecialchars((string)($item['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </td>
                            <td style="font-family: monospace; font-size: 12px; color: #334155;">
                                <?php echo htmlspecialchars((string)$item['transaction_id'], ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td>
                                <span class="badge badge-secondary" style="font-size: 11px; font-weight: 600;">
                                    <?php echo htmlspecialchars((string)$item['gateway_id'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td style="font-weight: 700; color: var(--wp-text-main);">
                                <?php echo $formattedAmount; ?>
                            </td>
                            <td>
                                <span style="font-family: monospace; font-weight: 700; color: #0284c7; background: #f0f9ff; border: 1px solid #bae6fd; padding: 2px 8px; border-radius: 4px; font-size: 12px;">
                                    <?php echo htmlspecialchars((string)($item['provider_reference'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td style="color: #475569; font-size: 13px;">
                                <?php echo htmlspecialchars((string)($item['sender_account'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $badgeClass; ?>" style="display: inline-flex; align-items: center; gap: 5px;">
                                    <span style="width: 6px; height: 6px; border-radius: 50%; background: currentColor;"></span>
                                    <?php echo htmlspecialchars($statusLabels[$st] ?? ucfirst($st), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td style="text-align: right;">
                                <a href="/admin/page/favorite-pay?action=view&id=<?php echo urlencode((string)$item['attempt_id']); ?>" 
                                   class="btn btn-primary" 
                                   style="padding: 4px 10px; font-size: 12px; font-weight: 600;">
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
        <div style="display: flex; justify-content: flex-end; align-items: center; gap: 8px; margin-top: 20px; flex-wrap: wrap;">
            <span style="font-size: 12px; color: var(--wp-text-muted); margin-right: 8px;">
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
                   style="padding: 4px 10px; font-size: 12px;">
                    &laquo; Prev
                </a>
            <?php endif; ?>

            <?php if ($page < $totalPages): ?>
                <?php $baseParams['p'] = $page + 1; ?>
                <a href="/admin/page/favorite-pay?<?php echo http_build_query($baseParams); ?>" 
                   class="btn btn-secondary" 
                   style="padding: 4px 10px; font-size: 12px;">
                    Next &raquo;
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
