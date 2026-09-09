<?php
/**
 * Admin Withdrawals Queue & Settings View
 *
 * @var array $items
 * @var int $total
 * @var array $counts
 * @var array $summary
 * @var string $currentStatus
 * @var string $currentMethod
 * @var string $currentSearch
 * @var string $currentDateFrom
 * @var string $currentDateTo
 * @var int $page
 * @var int $totalPages
 * @var array $settings
 * @var string $primaryCurrency
 * @var string $csrfToken
 * @var bool $canManage
 */
$isEnabled = !empty($settings['enabled']);
$minAmt = (float)($settings['min_amount'] ?? 500.0);
$maxMonthlyCount = (int)($settings['max_monthly_count'] ?? 5);
$currentStatus = $currentStatus ?? 'all';
$currentMethod = $currentMethod ?? 'all';
$currentSearch = $currentSearch ?? '';
$currentDateFrom = $currentDateFrom ?? '';
$currentDateTo = $currentDateTo ?? '';

$sumCounts = $summary['counts'] ?? [];
$sumTotals = $summary['totals'] ?? [];
$curr = htmlspecialchars($primaryCurrency, ENT_QUOTES, 'UTF-8');
$totalGrossDec = \FavoriteCMS\Pay\Support\DecimalFormatter::minorUnitToDecimal($sumTotals['gross_cents'] ?? 0, 2);
$totalFeeDec   = \FavoriteCMS\Pay\Support\DecimalFormatter::minorUnitToDecimal($sumTotals['fee_cents'] ?? 0, 2);
$totalNetDec   = \FavoriteCMS\Pay\Support\DecimalFormatter::minorUnitToDecimal($sumTotals['net_cents'] ?? 0, 2);
$paidGrossDec  = \FavoriteCMS\Pay\Support\DecimalFormatter::minorUnitToDecimal($sumTotals['paid_gross_cents'] ?? 0, 2);
$paidNetDec    = \FavoriteCMS\Pay\Support\DecimalFormatter::minorUnitToDecimal($sumTotals['paid_net_cents'] ?? 0, 2);

$exportQuery = http_build_query([
    'action'    => 'export_csv',
    'status'    => $currentStatus,
    'method'    => $currentMethod,
    'search'    => $currentSearch,
    'date_from' => $currentDateFrom,
    'date_to'   => $currentDateTo,
]);
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
                <label style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 4px;">Minimum Withdrawal (<?php echo htmlspecialchars($primaryCurrency, ENT_QUOTES, 'UTF-8'); ?>)</label>
                <input type="number" step="0.01" min="0" name="min_amount" value="<?php echo $minAmt; ?>" style="padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 6px; width: 140px; font-size: 14px;" />
            </div>

            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 4px;">Maximum Withdrawals Per Month</label>
                <input type="number" step="1" min="1" name="max_monthly_count" value="<?php echo $maxMonthlyCount; ?>" style="padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 6px; width: 140px; font-size: 14px;" />
            </div>

            <div>
                <button type="submit" class="button button-primary">Save Settings</button>
            </div>
        </form>
    <?php endif; ?>
</div>

<!-- Filters Bar -->
<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 20px;">
    <!-- Status Tabs -->
    <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; border-bottom: 1px solid #f1f5f9; padding-bottom: 12px;">
        <?php
        $statuses = [
            'all'        => 'All (' . (int)($sumCounts['total'] ?? $total) . ')',
            'pending'    => 'Pending (' . (int)($sumCounts['pending'] ?? ($counts['pending'] ?? 0)) . ')',
            'approved'   => 'Approved (' . (int)($sumCounts['approved'] ?? ($counts['approved'] ?? 0)) . ')',
            'processing' => 'Processing (' . (int)($sumCounts['processing'] ?? ($counts['processing'] ?? 0)) . ')',
            'paid'       => 'Paid (' . (int)($sumCounts['paid'] ?? ($counts['paid'] ?? 0)) . ')',
            'rejected'   => 'Rejected (' . (int)($sumCounts['rejected'] ?? ($counts['rejected'] ?? 0)) . ')',
            'failed'     => 'Failed (' . (int)($sumCounts['failed'] ?? ($counts['failed'] ?? 0)) . ')',
            'cancelled'  => 'Cancelled (' . (int)($sumCounts['cancelled'] ?? ($counts['cancelled'] ?? 0)) . ')',
        ];
        foreach ($statuses as $st => $label):
            $isActive = ($currentStatus === $st);
        ?>
            <a href="/admin/page/favorite-pay-withdrawals?status=<?php echo urlencode($st); ?>&method=<?php echo urlencode($currentMethod); ?>&search=<?php echo urlencode($currentSearch); ?>&date_from=<?php echo urlencode($currentDateFrom); ?>&date_to=<?php echo urlencode($currentDateTo); ?>" class="button <?php echo $isActive ? 'button-primary' : ''; ?>" style="font-size: 12px;">
                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Additional Filter Controls -->
    <form method="GET" action="/admin/page/favorite-pay-withdrawals" style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($currentStatus, ENT_QUOTES, 'UTF-8'); ?>" />

        <!-- Method Filter -->
        <select name="method" style="padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px;">
            <option value="all" <?php echo $currentMethod === 'all' ? 'selected' : ''; ?>>All Methods</option>
            <option value="bkash_personal" <?php echo ($currentMethod === 'bkash_personal' || $currentMethod === 'bkash') ? 'selected' : ''; ?>>bKash Personal</option>
            <option value="bkash_agent" <?php echo $currentMethod === 'bkash_agent' ? 'selected' : ''; ?>>bKash Agent</option>
            <option value="nagad_personal" <?php echo ($currentMethod === 'nagad_personal' || $currentMethod === 'nagad') ? 'selected' : ''; ?>>Nagad Personal</option>
            <option value="nagad_agent" <?php echo $currentMethod === 'nagad_agent' ? 'selected' : ''; ?>>Nagad Agent</option>
            <option value="rocket_personal" <?php echo ($currentMethod === 'rocket_personal' || $currentMethod === 'rocket') ? 'selected' : ''; ?>>Rocket Personal</option>
            <option value="rocket_agent" <?php echo $currentMethod === 'rocket_agent' ? 'selected' : ''; ?>>Rocket Agent</option>
            <option value="bank_transfer" <?php echo $currentMethod === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
        </select>

        <!-- Date Range Filter -->
        <div style="display: flex; align-items: center; gap: 4px;">
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($currentDateFrom, ENT_QUOTES, 'UTF-8'); ?>" style="padding: 5px 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px;" title="From Date" />
            <span style="color: #94a3b8;">&ndash;</span>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($currentDateTo, ENT_QUOTES, 'UTF-8'); ?>" style="padding: 5px 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px;" title="To Date" />
        </div>

        <!-- Customer / ID Search Input -->
        <input type="text" name="search" placeholder="Search ID, customer, TrxID..." value="<?php echo htmlspecialchars($currentSearch, ENT_QUOTES, 'UTF-8'); ?>" style="flex: 1; min-width: 180px; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px;" />

        <button type="submit" class="button">Filter</button>
        <?php if ($currentStatus !== 'all' || $currentMethod !== 'all' || $currentSearch !== '' || $currentDateFrom !== '' || $currentDateTo !== ''): ?>
            <a href="/admin/page/favorite-pay-withdrawals" class="button" style="color: #64748b;">Reset</a>
        <?php endif; ?>

        <!-- Export CSV Button -->
        <a href="/admin/page/favorite-pay-withdrawals?<?php echo $exportQuery; ?>" class="button" style="background: #10b981; color: #ffffff; border-color: #059669; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
            <span>&darr; Export CSV</span>
        </a>
    </form>
</div>

<!-- Summary Statistics Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 22px;">
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px;">
        <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 4px;">Total Requests</div>
        <div style="font-size: 22px; font-weight: 800; color: #0f172a;"><?php echo (int)($sumCounts['total'] ?? 0); ?></div>
        <div style="font-size: 11px; color: #64748b; margin-top: 4px; display: flex; flex-wrap: wrap; gap: 4px;">
            <span style="color: #b45309;">Pending: <?php echo (int)($sumCounts['pending'] ?? 0); ?></span> &bull;
            <span style="color: #4338ca;">Appr: <?php echo (int)($sumCounts['approved'] ?? 0); ?></span> &bull;
            <span style="color: #0369a1;">Proc: <?php echo (int)($sumCounts['processing'] ?? 0); ?></span> &bull;
            <span style="color: #15803d;">Paid: <?php echo (int)($sumCounts['paid'] ?? 0); ?></span>
        </div>
    </div>

    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px;">
        <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 4px;">Total Requested Gross</div>
        <div style="font-size: 22px; font-weight: 800; color: #0f172a;"><?php echo $curr . ' ' . $totalGrossDec; ?></div>
        <div style="font-size: 11px; color: #64748b; margin-top: 4px;">Gross amount of filtered requests</div>
    </div>

    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px;">
        <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 4px;">Total Processing Fees</div>
        <div style="font-size: 22px; font-weight: 800; color: #475569;"><?php echo $curr . ' ' . $totalFeeDec; ?></div>
        <div style="font-size: 11px; color: #64748b; margin-top: 4px;">Platform processing deductions</div>
    </div>

    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px;">
        <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 4px;">Total Net Payout</div>
        <div style="font-size: 22px; font-weight: 800; color: #1e40af;"><?php echo $curr . ' ' . $totalNetDec; ?></div>
        <div style="font-size: 11px; color: #64748b; margin-top: 4px;">Customer net entitlement</div>
    </div>

    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px;">
        <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 4px;">Total Paid Out</div>
        <div style="font-size: 22px; font-weight: 800; color: #15803d;"><?php echo $curr . ' ' . $paidNetDec; ?></div>
        <div style="font-size: 11px; color: #15803d; font-weight: 600; margin-top: 4px;">Paid: <?php echo (int)($sumCounts['paid'] ?? 0); ?> requests</div>
    </div>
</div>

<!-- Requests Table -->
<table class="wp-list-table widefat fixed striped">
    <thead>
        <tr>
            <th style="width: 130px;">Date</th>
            <th style="width: 140px;">Withdrawal ID</th>
            <th style="width: 80px;">User</th>
            <th style="width: 110px;">Method</th>
            <th>Destination / Reference</th>
            <th style="width: 90px; text-align: right;">Gross</th>
            <th style="width: 90px; text-align: right;">Net</th>
            <th style="width: 95px;">Status</th>
            <th style="width: 250px; text-align: right;">Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($items)): ?>
            <tr>
                <td colspan="9" style="text-align: center; color: #64748b; padding: 24px;">No withdrawal requests found matching the criteria.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($items as $item): ?>
                <?php
                $st = $item->getStatus();
                $badgeClass = 'fpay-admin-badge-' . $st->value;
                $amtDec = \FavoriteCMS\Pay\Support\DecimalFormatter::minorUnitToDecimal($item->getAmount()->getAmount(), 2);
                $netDec = \FavoriteCMS\Pay\Support\DecimalFormatter::minorUnitToDecimal($item->getNetAmount()->getAmount(), 2);
                ?>
                <tr>
                    <td style="color: #64748b; font-size: 12px;"><?php echo htmlspecialchars($item->getCreatedAt(), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <a href="/admin/page/favorite-pay-withdrawals?id=<?php echo urlencode($item->getId()); ?>" style="font-family: monospace; font-weight: 700; color: #2563eb; text-decoration: none;">
                            <?php echo htmlspecialchars($item->getId(), ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    </td>
                    <td>#<?php echo $item->getUserId(); ?></td>
                    <td style="text-transform: capitalize; font-size: 13px;"><?php echo htmlspecialchars(str_replace('_', ' ', $item->getMethod()), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <span style="font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars($item->getDestinationMasked(), ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php if ($item->getTransactionReference()): ?>
                            <div style="font-size: 11px; color: #2563eb; font-family: monospace;">Ref: <?php echo htmlspecialchars($item->getTransactionReference(), ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right; color: #64748b;">৳<?php echo $amtDec; ?></td>
                    <td style="text-align: right; font-weight: 700; color: #0f172a;">৳<?php echo $netDec; ?></td>
                    <td><span class="fpay-admin-badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($st->label(), ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td style="text-align: right;">
                        <a href="/admin/page/favorite-pay-withdrawals?id=<?php echo urlencode($item->getId()); ?>" class="button button-small" style="margin-right: 4px;">View</a>

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
                                    <input type="hidden" name="action" value="process" />
                                    <button type="submit" class="button button-small button-primary">Process</button>
                                </form>
                                <form method="POST" action="/admin/page/favorite-pay-withdrawals" style="display: inline;">
                                    <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
                                    <input type="hidden" name="withdrawal_id" value="<?php echo htmlspecialchars($item->getId(), ENT_QUOTES, 'UTF-8'); ?>" />
                                    <input type="hidden" name="action" value="cancel" />
                                    <button type="submit" class="button button-small" style="color: #b91c1c;" onclick="return confirm('Cancel and release wallet hold?');">Cancel</button>
                                </form>
                            <?php elseif ($st === \FavoriteCMS\Pay\Domain\WithdrawalStatus::PROCESSING): ?>
                                <form method="POST" action="/admin/page/favorite-pay-withdrawals" style="display: inline;">
                                    <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
                                    <input type="hidden" name="withdrawal_id" value="<?php echo htmlspecialchars($item->getId(), ENT_QUOTES, 'UTF-8'); ?>" />
                                    <input type="hidden" name="action" value="mark_paid" />
                                    <button type="submit" class="button button-small button-primary" onclick="return confirm('Confirm payout? This permanently debits the held amount.');">Mark Paid</button>
                                </form>
                                <form method="POST" action="/admin/page/favorite-pay-withdrawals" style="display: inline;">
                                    <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
                                    <input type="hidden" name="withdrawal_id" value="<?php echo htmlspecialchars($item->getId(), ENT_QUOTES, 'UTF-8'); ?>" />
                                    <input type="hidden" name="action" value="mark_failed" />
                                    <button type="submit" class="button button-small" style="color: #b91c1c;" onclick="return confirm('Mark as failed and release wallet hold?');">Fail</button>
                                </form>
                            <?php else: ?>
                                <span style="color: #94a3b8; font-size: 11px;">Done</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
    <div style="margin-top: 16px; display: flex; justify-content: space-between; align-items: center;">
        <span style="font-size: 13px; color: #64748b;">Page <?php echo $page; ?> of <?php echo $totalPages; ?> (Total: <?php echo $total; ?>)</span>
        <div style="display: flex; gap: 4px;">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <a href="/admin/page/favorite-pay-withdrawals?p=<?php echo $p; ?>&status=<?php echo urlencode($currentStatus); ?>&method=<?php echo urlencode($currentMethod); ?>&search=<?php echo urlencode($currentSearch); ?>&date_from=<?php echo urlencode($currentDateFrom); ?>&date_to=<?php echo urlencode($currentDateTo); ?>" class="button <?php echo $p === $page ? 'button-primary' : ''; ?>" style="font-size: 12px; padding: 2px 8px;">
                    <?php echo $p; ?>
                </a>
            <?php endfor; ?>
        </div>
    </div>
<?php endif; ?>
