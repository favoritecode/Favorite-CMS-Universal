<?php
/**
 * Customer Refund History View
 *
 * @var array  $refunds
 * @var int    $total
 * @var int    $page
 * @var int    $perPage
 * @var int    $totalPages
 * @var string $totalRefunded
 * @var array  $wallet
 * @var int    $userId
 * @var string $activeTab
 */

$buildRefundsUrl = function (array $overrides = []) use ($page): string {
    $current = ['page' => $page ?: 1];
    $merged = array_merge($current, $overrides);
    $params = [];
    if (isset($merged['page']) && (int)$merged['page'] > 1) {
        $params['page'] = (int)$merged['page'];
    }
    return '/account/refunds' . (!empty($params) ? '?' . http_build_query($params) : '');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Refunds — Favorite Digital</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; background: #f8fafc; color: #1e293b; margin: 0; padding: 0; line-height: 1.5; }
        .refunds-wrap { max-width: 1100px; margin: 0 auto; padding: 0 16px 48px; }

        .refunds-header { margin-bottom: 24px; }
        .refunds-header h1 { margin: 0 0 4px; font-size: 26px; font-weight: 800; color: #0f172a; }
        .refunds-header p { margin: 0; font-size: 14px; color: #64748b; }

        /* Summary Banner */
        .summary-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px 24px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; }
        .summary-metric { display: flex; align-items: baseline; gap: 10px; }
        .metric-amount { font-size: 28px; font-weight: 800; color: #0f172a; }
        .metric-label { font-size: 13px; color: #64748b; font-weight: 600; }
        .destination-note { font-size: 13px; color: #059669; font-weight: 700; background: #ecfdf5; padding: 6px 12px; border-radius: 20px; border: 1px solid #a7f3d0; }

        /* Refunds Table */
        .table-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
        .refund-table { width: 100%; border-collapse: collapse; text-align: left; }
        .refund-table th { background: #f8fafc; padding: 12px 16px; font-size: 12px; font-weight: 700; text-transform: uppercase; color: #64748b; border-bottom: 1px solid #e2e8f0; }
        .refund-table td { padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #f1f5f9; }
        .refund-table tr:last-child td { border-bottom: none; }

        .badge-refund { font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 3px 8px; border-radius: 4px; background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .destination-pill { font-size: 12px; font-weight: 600; color: #0f172a; display: inline-flex; align-items: center; gap: 4px; }

        /* Empty State */
        .empty-state { background: #ffffff; border: 1px dashed #cbd5e1; border-radius: 12px; padding: 48px 24px; text-align: center; }
        .empty-icon { font-size: 48px; margin-bottom: 12px; }
        .empty-title { font-size: 18px; font-weight: 700; color: #0f172a; margin-bottom: 6px; }
        .empty-desc { font-size: 14px; color: #64748b; max-width: 440px; margin: 0 auto; }

        /* Pagination */
        .pagination { display: flex; justify-content: center; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 24px; }
        .page-link { padding: 8px 14px; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; color: #334155; text-decoration: none; font-size: 13px; font-weight: 600; }
        .page-link.active { background: #0f172a; color: #fff; border-color: #0f172a; }
        .page-link:hover:not(.active) { background: #f1f5f9; }
    </style>
</head>
<body>

<?php include __DIR__ . '/nav.php'; ?>

<div class="refunds-wrap">
    <header class="refunds-header">
        <h1>Refund History</h1>
        <p>Review refunds issued for your orders. All valid refunds are automatically credited to your Favorite Digital Wallet.</p>
    </header>

    <div class="summary-card">
        <div>
            <div class="metric-label">Total Refunded Credit</div>
            <div class="summary-metric">
                <span class="metric-amount">৳<?= htmlspecialchars($totalRefunded, ENT_QUOTES, 'UTF-8') ?></span>
                <span style="font-size: 14px; color: #64748b;">BDT</span>
            </div>
        </div>
        <div>
            <span class="destination-note">
                👛 Refund Destination: Favorite Digital Wallet
            </span>
        </div>
    </div>

    <?php if (empty($refunds)): ?>
        <div class="empty-state">
            <div class="empty-icon">🧾</div>
            <div class="empty-title">No Refund Records Found</div>
            <div class="empty-desc">You do not have any processed refunds on your account.</div>
        </div>
    <?php else: ?>
        <div class="table-card">
            <table class="refund-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Order</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Destination</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($refunds as $ref): ?>
                        <tr>
                            <td><?= htmlspecialchars(substr((string)$ref->processed_at, 0, 10), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if (!empty($ref->order_number) && $ref->order_number !== 'N/A'): ?>
                                    <a href="/account/orders/<?= urlencode((string)$ref->order_number) ?>" style="font-weight: 700; color: #2563eb; text-decoration: none;">
                                        <?= htmlspecialchars((string)$ref->order_number, ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color: #64748b;">Order #<?= (int)$ref->order_id ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong style="color: #0f172a;"><?= htmlspecialchars((string)$ref->currency, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars((string)$ref->refund_amount, ENT_QUOTES, 'UTF-8') ?></strong>
                            </td>
                            <td>
                                <span class="badge-refund">
                                    <?= strtoupper(htmlspecialchars((string)$ref->status, ENT_QUOTES, 'UTF-8')) ?>
                                </span>
                            </td>
                            <td>
                                <span class="destination-pill">
                                    👛 <?= htmlspecialchars((string)$ref->destination_name, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td style="color: #64748b; font-size: 13px;">
                                <?= htmlspecialchars((string)($ref->reason ?? 'Order cancellation / Refund'), ENT_QUOTES, 'UTF-8') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Refunds Pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= htmlspecialchars($buildRefundsUrl(['page' => $page - 1]), ENT_QUOTES, 'UTF-8') ?>" class="page-link">&larr; Prev</a>
                <?php endif; ?>

                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <a href="<?= htmlspecialchars($buildRefundsUrl(['page' => $p]), ENT_QUOTES, 'UTF-8') ?>"
                       class="page-link <?= $p === $page ? 'active' : '' ?>">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="<?= htmlspecialchars($buildRefundsUrl(['page' => $page + 1]), ENT_QUOTES, 'UTF-8') ?>" class="page-link">Next &rarr;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

</body>
</html>
