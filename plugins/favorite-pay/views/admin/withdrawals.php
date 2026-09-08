<?php
/**
 * Admin Withdrawals Queue & Settings View
 */
$isEnabled = !empty($settings['enabled']);
$minAmt = (float)($settings['min_amount'] ?? 100.0);
$maxAmt = (float)($settings['max_amount'] ?? 500000.0);
?>

<style>
.fpay-admin-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
}
.fpay-admin-badge-pending { background: #fef3c7; color: #b45309; }
.fpay-admin-badge-approved { background: #e0e7ff; color: #4338ca; }
.fpay-admin-badge-processing { background: #e0f2fe; color: #0369a1; }
.fpay-admin-badge-paid { background: #dcfce7; color: #15803d; }
.fpay-admin-badge-rejected, .fpay-admin-badge-failed { background: #fee2e2; color: #b91c1c; }
.fpay-admin-badge-cancelled { background: #f1f5f9; color: #475569; }

.fpay-settings-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 24px;
}
</style>

<!-- Settings Box -->
<div class="fpay-settings-box">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <div>
            <h3 style="margin: 0 0 4px; font-size: 16px; font-weight: 700; color: #0f172a;">Withdrawal &amp; Payout Settings</h3>
            <p style="margin: 0; font-size: 13px; color: #64748b;">Master control for customer wallet payouts</p>
        </div>
        <div>
            <span style="font-size: 12px; font-weight: 700; padding: 4px 12px; border-radius: 20px; <?php echo $isEnabled ? 'background: #dcfce7; color: #166534;' : 'background: #fee2e2; color: #991b1b;'; ?>">
                <?php echo $isEnabled ? '● WITHDRAWALS ACTIVE' : '○ WITHDRAWALS DISABLED'; ?>
            </span>
        </div>
    </div>

    <?php if ($canManage): ?>
        <form method="POST" action="/admin/page/favorite-pay-withdrawals" style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end;">
            <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
            <input type="hidden" name="action" value="update_settings" />

            <div style="display: flex; align-items: center; gap: 8px; padding-bottom: 8px;">
                <input type="checkbox" id="enabled" name="enabled" value="1" <?php echo $isEnabled ? 'checked' : ''; ?> style="width: 18px; height: 18px; cursor: pointer;" />
                <label for="enabled" style="font-size: 14px; font-weight: 600; color: #1e293b; cursor: pointer;">Enable Customer Withdrawals</label>
            </div>

            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 4px;">Min Amount (<?php echo htmlspecialchars($primaryCurrency, ENT_QUOTES, 'UTF-8'); ?>)</label>
                <input type="number" step="0.01" min="1" name="min_amount" value="<?php echo $minAmt; ?>" style="padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 6px; width: 120px; font-size: 14px;" />
            </div>

            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 4px;">Max Amount (<?php echo htmlspecialchars($primaryCurrency, ENT_QUOTES, 'UTF-8'); ?>)</label>
                <input type="number" step="0.01" min="1" name="max_amount" value="<?php echo $maxAmt; ?>" style="padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 6px; width: 140px; font-size: 14px;" />
            </div>

            <div>
                <button type="submit" class="button button-primary">Save Settings</button>
            </div>
        </form>
    <?php endif; ?>
</div>

<!-- Filters Bar -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px;">
    <div style="display: flex; gap: 6px; flex-wrap: wrap;">
        <?php
        $statuses = [
            'all'        => 'All (' . $total . ')',
            'pending'    => 'Pending (' . ($counts['pending'] ?? 0) . ')',
            'approved'   => 'Approved (' . ($counts['approved'] ?? 0) . ')',
            'processing' => 'Processing (' . ($counts['processing'] ?? 0) . ')',
            'paid'       => 'Paid (' . ($counts['paid'] ?? 0) . ')',
            'rejected'   => 'Rejected (' . ($counts['rejected'] ?? 0) . ')',
            'failed'     => 'Failed (' . ($counts['failed'] ?? 0) . ')',
        ];
        foreach ($statuses as $st => $label):
            $isActive = ($currentStatus === $st);
        ?>
            <a href="/admin/page/favorite-pay-withdrawals?status=<?php echo urlencode($st); ?>" class="button <?php echo $isActive ? 'button-primary' : ''; ?>" style="font-size: 12px;">
                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div>
        <form method="GET" action="/admin/page/favorite-pay-withdrawals" style="display: flex; gap: 6px;">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($currentStatus, ENT_QUOTES, 'UTF-8'); ?>" />
            <input type="text" name="search" placeholder="Search ID or account..." value="<?php echo htmlspecialchars($currentSearch, ENT_QUOTES, 'UTF-8'); ?>" style="padding: 5px 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px;" />
            <button type="submit" class="button">Search</button>
        </form>
    </div>
</div>

<!-- Requests Table -->
<table class="wp-list-table widefat fixed striped">
    <thead>
        <tr>
            <th style="width: 140px;">Date</th>
            <th style="width: 150px;">Withdrawal ID</th>
            <th style="width: 80px;">User ID</th>
            <th style="width: 110px;">Method</th>
            <th>Destination</th>
            <th style="width: 110px; text-align: right;">Amount</th>
            <th style="width: 100px;">Status</th>
            <th style="width: 220px; text-align: right;">Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($items)): ?>
            <tr>
                <td colspan="8" style="text-align: center; color: #64748b; padding: 24px;">No withdrawal requests found.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($items as $item): ?>
                <?php
                $st = $item->getStatus();
                $badgeClass = 'fpay-admin-badge-' . $st->value;
                $amtDec = \FavoriteCMS\Pay\Support\DecimalFormatter::minorUnitToDecimal($item->getAmount()->getAmount(), 2);
                ?>
                <tr>
                    <td style="color: #64748b; font-size: 12px;"><?php echo htmlspecialchars($item->getCreatedAt(), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><strong style="font-family: monospace;"><?php echo htmlspecialchars($item->getId(), ENT_QUOTES, 'UTF-8'); ?></strong></td>
                    <td>#<?php echo $item->getUserId(); ?></td>
                    <td style="text-transform: capitalize;"><?php echo htmlspecialchars(str_replace('_', ' ', $item->getMethod()), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><span style="font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars($item->getDestinationMasked(), ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td style="text-align: right; font-weight: 700;">৳<?php echo $amtDec; ?></td>
                    <td><span class="fpay-admin-badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($st->label(), ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td style="text-align: right;">
                        <?php if ($canManage): ?>
                            <?php if ($st === \FavoriteCMS\Pay\Domain\WithdrawalStatus::PENDING): ?>
                                <form method="POST" action="/admin/page/favorite-pay-withdrawals" style="display: inline;">
                                    <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
                                    <input type="hidden" name="withdrawal_id" value="<?php echo htmlspecialchars($item->getId(), ENT_QUOTES, 'UTF-8'); ?>" />
                                    <input type="hidden" name="action" value="approve" />
                                    <button type="submit" class="button button-small button-primary" onclick="return confirm('Approve this withdrawal?');">Approve</button>
                                </form>
                                <form method="POST" action="/admin/page/favorite-pay-withdrawals" style="display: inline;">
                                    <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
                                    <input type="hidden" name="withdrawal_id" value="<?php echo htmlspecialchars($item->getId(), ENT_QUOTES, 'UTF-8'); ?>" />
                                    <input type="hidden" name="action" value="reject" />
                                    <button type="submit" class="button button-small" style="color: #b91c1c;" onclick="return confirm('Reject and release wallet hold?');">Reject</button>
                                </form>
                            <?php elseif ($st === \FavoriteCMS\Pay\Domain\WithdrawalStatus::APPROVED): ?>
                                <form method="POST" action="/admin/page/favorite-pay-withdrawals" style="display: inline;">
                                    <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
                                    <input type="hidden" name="withdrawal_id" value="<?php echo htmlspecialchars($item->getId(), ENT_QUOTES, 'UTF-8'); ?>" />
                                    <input type="hidden" name="action" value="start_processing" />
                                    <button type="submit" class="button button-small button-primary">Start Processing</button>
                                </form>
                            <?php elseif ($st === \FavoriteCMS\Pay\Domain\WithdrawalStatus::PROCESSING): ?>
                                <form method="POST" action="/admin/page/favorite-pay-withdrawals" style="display: inline;">
                                    <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
                                    <input type="hidden" name="withdrawal_id" value="<?php echo htmlspecialchars($item->getId(), ENT_QUOTES, 'UTF-8'); ?>" />
                                    <input type="hidden" name="action" value="mark_paid" />
                                    <button type="submit" class="button button-small button-primary" onclick="return confirm('Confirm payment? This permanently debits the held amount.');">Mark Paid</button>
                                </form>
                                <form method="POST" action="/admin/page/favorite-pay-withdrawals" style="display: inline;">
                                    <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
                                    <input type="hidden" name="withdrawal_id" value="<?php echo htmlspecialchars($item->getId(), ENT_QUOTES, 'UTF-8'); ?>" />
                                    <input type="hidden" name="action" value="mark_failed" />
                                    <button type="submit" class="button button-small" style="color: #b91c1c;" onclick="return confirm('Mark as failed and release wallet hold?');">Fail</button>
                                </form>
                            <?php else: ?>
                                <span style="color: #94a3b8; font-size: 11px;">Completed</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>
