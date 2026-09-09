<?php
/**
 * Admin Financial Dashboard & Wallet Monitoring View
 *
 * @var array $walletOverview
 * @var array $range
 * @var array|null $actionableQueue
 * @var array|null $withdrawalSummary
 * @var array|null $rechargeSummary
 * @var array $financialFlow
 * @var string $searchQuery
 * @var array $searchedWallets
 * @var array $recentActivity
 * @var string $primaryCurrency
 * @var bool $canViewPayments
 * @var bool $canViewWithdrawals
 * @var \FavoriteCMS\Models\User|null $currentUser
 */

use FavoriteCMS\Pay\Support\DecimalFormatter;

$curr = htmlspecialchars($primaryCurrency, ENT_QUOTES, 'UTF-8');
$formatMoney = function(int $cents) use ($curr) {
    return $curr . ' ' . number_format($cents / 100, 2);
};

$activePeriod = $range['period'] ?? 'this_month';
$currentFrom = $range['date_from'] ?? '';
$currentTo = $range['date_to'] ?? '';
$periodLabel = htmlspecialchars($range['periodLabel'] ?? 'Selected Period', ENT_QUOTES, 'UTF-8');

$wdTotals = ($withdrawalSummary !== null) ? ($withdrawalSummary['totals'] ?? []) : [];
$wdCounts = ($withdrawalSummary !== null) ? ($withdrawalSummary['counts'] ?? []) : [];
?>

<div class="wrap" style="max-width: 1280px; margin: 20px auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <!-- Dashboard Header -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
        <div>
            <h1 style="margin: 0; font-size: 24px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 10px;">
                <span>💳</span> Favorite Pay &mdash; Financial Dashboard & Monitoring
            </h1>
            <p style="margin: 6px 0 0; color: #64748b; font-size: 13px;">
                Real-time, authoritative monitoring of customer wallets, recharges, payouts, and financial movements.
            </p>
        </div>
        <?php if (!empty($canViewWithdrawals) && !empty($actionableQueue)): ?>
            <div style="display: flex; gap: 10px; align-items: center;">
                <a href="/admin/page/favorite-pay-withdrawals" class="button button-primary" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;">
                    <span>📋</span> Manage Withdrawals Queue (<?php echo (int)($actionableQueue['pending_count'] + $actionableQueue['approved_count'] + $actionableQueue['processing_count']); ?> active) &rarr;
                </a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Date Range Filter Bar -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 20px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <form method="GET" action="/admin/page/favorite-pay-dashboard" style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 14px;">
            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                <span style="font-size: 13px; font-weight: 700; color: #334155; margin-right: 4px;">Period:</span>
                
                <?php
                $presets = [
                    'today'          => 'Today',
                    'last_7_days'    => 'Last 7 Days',
                    'last_30_days'   => 'Last 30 Days',
                    'this_month'     => 'This Month',
                    'previous_month' => 'Previous Month',
                ];
                foreach ($presets as $k => $lbl):
                    $isActive = ($activePeriod === $k);
                ?>
                    <a href="/admin/page/favorite-pay-dashboard?period=<?php echo urlencode($k); ?>"
                       class="button <?php echo $isActive ? 'button-primary' : ''; ?>"
                       style="font-size: 12px; padding: 2px 10px; height: 28px; line-height: 26px;">
                        <?php echo htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Custom Date Range -->
            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                <span style="font-size: 12px; color: #64748b;">Custom:</span>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($currentFrom, ENT_QUOTES, 'UTF-8'); ?>" style="padding: 3px 8px; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 4px;" placeholder="From">
                <span style="color: #94a3b8;">&ndash;</span>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($currentTo, ENT_QUOTES, 'UTF-8'); ?>" style="padding: 3px 8px; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 4px;" placeholder="To">
                <button type="submit" class="button button-secondary" style="font-size: 12px; height: 28px; line-height: 26px;">Filter</button>
                <a href="/admin/page/favorite-pay-dashboard" class="button" style="font-size: 12px; height: 28px; line-height: 26px; color: #64748b;">Reset</a>
            </div>
        </form>

        <div style="margin-top: 10px; font-size: 12px; color: #64748b; display: flex; align-items: center; gap: 6px;">
            <span>Current Period Selection:</span>
            <strong style="color: #0f172a; background: #f1f5f9; padding: 2px 8px; border-radius: 4px;"><?php echo $periodLabel; ?></strong>
        </div>
    </div>

    <!-- 1. AUTHORITATIVE CURRENT WALLET SNAPSHOT -->
    <div style="margin-bottom: 24px;">
        <div style="margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center;">
            <h2 style="font-size: 15px; font-weight: 700; color: #1e293b; text-transform: uppercase; letter-spacing: 0.05em; margin: 0;">
                Current Global Wallet Balances (Live Snapshot)
            </h2>
            <span style="font-size: 11px; color: #64748b; background: #e2e8f0; padding: 2px 8px; border-radius: 4px;">Instant Authoritative</span>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 16px;">
            <!-- Total Customer Balances -->
            <div style="background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); color: #fff; padding: 20px; border-radius: 10px; box-shadow: 0 2px 4px rgba(37,99,235,0.15);">
                <span style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #bfdbfe; display: block; margin-bottom: 6px;">
                    Total Customer Funds
                </span>
                <div style="font-size: 26px; font-weight: 800; line-height: 1.2;">
                    <?php echo $formatMoney($walletOverview['total_balance']->getAmount()); ?>
                </div>
                <span style="font-size: 12px; color: #dbeafe; display: block; margin-top: 6px;">
                    Spendable + Currently Reserved Holds
                </span>
            </div>

            <!-- Spendable Available -->
            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-left: 4px solid #16a34a; padding: 20px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <span style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #16a34a; display: block; margin-bottom: 6px;">
                    Spendable Available Balance
                </span>
                <div style="font-size: 26px; font-weight: 800; color: #15803d; line-height: 1.2;">
                    <?php echo $formatMoney($walletOverview['available_balance']->getAmount()); ?>
                </div>
                <span style="font-size: 12px; color: #64748b; display: block; margin-top: 6px;">
                    Immediately available for customer use
                </span>
            </div>

            <!-- Held in Active Withdrawals -->
            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-left: 4px solid #d97706; padding: 20px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <span style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #d97706; display: block; margin-bottom: 6px;">
                    Funds Held in Withdrawals
                </span>
                <div style="font-size: 26px; font-weight: 800; color: #b45309; line-height: 1.2;">
                    <?php if (!empty($canViewWithdrawals)): ?>
                        <?php echo $formatMoney($walletOverview['held_balance']->getAmount()); ?>
                    <?php else: ?>
                        <span style="font-size: 18px; color: #94a3b8; font-weight: 500;">Protected</span>
                    <?php endif; ?>
                </div>
                <span style="font-size: 12px; color: #64748b; display: block; margin-top: 6px;">
                    <?php echo !empty($canViewWithdrawals) ? 'Locked across pending & processing payouts' : 'Requires withdrawal view permission'; ?>
                </span>
            </div>

            <!-- Total Wallets -->
            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-left: 4px solid #6366f1; padding: 20px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <span style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #6366f1; display: block; margin-bottom: 6px;">
                    Customer Wallets
                </span>
                <div style="font-size: 26px; font-weight: 800; color: #312e81; line-height: 1.2;">
                    <?php echo (int)$walletOverview['total_wallets']; ?>
                </div>
                <span style="font-size: 12px; color: #64748b; display: block; margin-top: 6px;">
                    Total registered customer balance accounts
                </span>
            </div>
        </div>
    </div>

    <?php if (!empty($canViewWithdrawals) && !empty($actionableQueue)): ?>
        <!-- 2. ACTIONABLE WITHDRAWAL WORK QUEUE BANNER -->
        <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 18px 22px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                <div>
                    <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #92400e; display: flex; align-items: center; gap: 8px;">
                        <span>⚠️</span> Actionable Withdrawal Queue
                    </h3>
                    <p style="margin: 4px 0 0; font-size: 13px; color: #b45309;">
                        Requests requiring administrative review, payout dispatch, or confirmation.
                    </p>
                </div>
                <div style="display: flex; gap: 16px; align-items: center; flex-wrap: wrap;">
                    <div style="text-align: center; background: #fff; padding: 6px 14px; border-radius: 6px; border: 1px solid #fef3c7;">
                        <span style="display: block; font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase;">Pending</span>
                        <strong style="font-size: 18px; color: #b45309;"><?php echo (int)$actionableQueue['pending_count']; ?></strong>
                    </div>
                    <div style="text-align: center; background: #fff; padding: 6px 14px; border-radius: 6px; border: 1px solid #fef3c7;">
                        <span style="display: block; font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase;">Approved</span>
                        <strong style="font-size: 18px; color: #4338ca;"><?php echo (int)$actionableQueue['approved_count']; ?></strong>
                    </div>
                    <div style="text-align: center; background: #fff; padding: 6px 14px; border-radius: 6px; border: 1px solid #fef3c7;">
                        <span style="display: block; font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase;">Processing</span>
                        <strong style="font-size: 18px; color: #0369a1;"><?php echo (int)$actionableQueue['processing_count']; ?></strong>
                    </div>
                    <div style="text-align: center; background: #fff; padding: 6px 14px; border-radius: 6px; border: 1px solid #fef3c7;">
                        <span style="display: block; font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase;">Held in Queue</span>
                        <strong style="font-size: 18px; color: #b45309;"><?php echo $formatMoney($actionableQueue['total_held']->getAmount()); ?></strong>
                    </div>
                    <a href="/admin/page/favorite-pay-withdrawals?status=pending" class="button button-primary" style="font-weight: 600; font-size: 13px;">
                        Open Queue &rarr;
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- 3. FINANCIAL MOVEMENT FLOW & PERIOD METRICS -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 24px;">
        <!-- Card 1: Period Financial Flow -->
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 22px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <h3 style="margin: 0 0 16px; font-size: 16px; font-weight: 700; color: #0f172a; display: flex; justify-content: space-between; align-items: center;">
                <span>Financial Flow</span>
                <span style="font-size: 11px; font-weight: 600; color: #64748b; background: #f1f5f9; padding: 2px 8px; border-radius: 4px;">For Selected Period</span>
            </h3>

            <div style="display: flex; flex-direction: column; gap: 14px;">
                <!-- Recharge Volume -->
                <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 12px; border-bottom: 1px dashed #e2e8f0;">
                    <div>
                        <strong style="font-size: 14px; color: #15803d; display: block;">Recharge Volume (Inflow)</strong>
                        <span style="font-size: 12px; color: #64748b;">Credited to customer balances</span>
                    </div>
                    <?php if (!empty($canViewPayments)): ?>
                        <strong style="font-size: 18px; color: #15803d;">
                            +<?php echo $formatMoney($financialFlow['recharge_volume']->getAmount()); ?>
                        </strong>
                    <?php else: ?>
                        <span style="color: #94a3b8; font-size: 13px; font-style: italic;">Requires payment view permission</span>
                    <?php endif; ?>
                </div>

                <!-- Withdrawal Outflow -->
                <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 12px; border-bottom: 1px dashed #e2e8f0;">
                    <div>
                        <strong style="font-size: 14px; color: #b91c1c; display: block;">Paid Withdrawal Volume (Outflow)</strong>
                        <span style="font-size: 12px; color: #64748b;">Net funds finalized & paid out</span>
                    </div>
                    <?php if (!empty($canViewWithdrawals)): ?>
                        <strong style="font-size: 18px; color: #b91c1c;">
                            -<?php echo $formatMoney($financialFlow['paid_withdrawal']->getAmount()); ?>
                        </strong>
                    <?php else: ?>
                        <span style="color: #94a3b8; font-size: 13px; font-style: italic;">Protected</span>
                    <?php endif; ?>
                </div>

                <!-- Fees -->
                <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 12px; border-bottom: 1px dashed #e2e8f0;">
                    <div>
                        <strong style="font-size: 14px; color: #475569; display: block;">Withdrawal Fees Recorded</strong>
                        <span style="font-size: 12px; color: #64748b;">Processing fees charged</span>
                    </div>
                    <?php if (!empty($canViewWithdrawals)): ?>
                        <strong style="font-size: 18px; color: #475569;">
                            <?php echo $formatMoney($financialFlow['recorded_fees']->getAmount()); ?>
                        </strong>
                    <?php else: ?>
                        <span style="color: #94a3b8; font-size: 13px; font-style: italic;">Protected</span>
                    <?php endif; ?>
                </div>

                <!-- Net Movement -->
                <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 4px;">
                    <div>
                        <strong style="font-size: 14px; color: #0f172a; display: block;">Net Platform Movement</strong>
                        <span style="font-size: 12px; color: #64748b;">Recharge Volume minus Paid Payouts</span>
                    </div>
                    <?php if (!empty($canViewPayments) && !empty($canViewWithdrawals)): ?>
                        <?php $netAmt = $financialFlow['net_flow']->getAmount(); ?>
                        <strong style="font-size: 20px; color: <?php echo $netAmt >= 0 ? '#15803d' : '#b91c1c'; ?>;">
                            <?php echo ($netAmt >= 0 ? '+' : '-') . $formatMoney(abs($netAmt)); ?>
                        </strong>
                    <?php else: ?>
                        <span style="color: #94a3b8; font-size: 13px; font-style: italic;">Requires full financial view</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!empty($canViewPayments) && !empty($rechargeSummary)): ?>
            <!-- Card 2: Period Recharge & Payment Activity -->
            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 22px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <h3 style="margin: 0 0 16px; font-size: 16px; font-weight: 700; color: #0f172a; display: flex; justify-content: space-between; align-items: center;">
                    <span>Recharge & Payment Activity</span>
                    <span style="font-size: 11px; font-weight: 600; color: #64748b; background: #f1f5f9; padding: 2px 8px; border-radius: 4px;">For Selected Period</span>
                </h3>

                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 16px; text-align: center;">
                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 12px;">
                        <span style="font-size: 11px; font-weight: 600; color: #166534; text-transform: uppercase; display: block;">Succeeded</span>
                        <strong style="font-size: 22px; color: #15803d;"><?php echo (int)$rechargeSummary['succeeded_count']; ?></strong>
                    </div>
                    <div style="background: #fffbeb; border: 1px solid #fef3c7; border-radius: 8px; padding: 12px;">
                        <span style="font-size: 11px; font-weight: 600; color: #92400e; text-transform: uppercase; display: block;">Pending</span>
                        <strong style="font-size: 22px; color: #b45309;"><?php echo (int)$rechargeSummary['pending_count']; ?></strong>
                    </div>
                    <div style="background: #fef2f2; border: 1px solid #fee2e2; border-radius: 8px; padding: 12px;">
                        <span style="font-size: 11px; font-weight: 600; color: #991b1b; text-transform: uppercase; display: block;">Failed</span>
                        <strong style="font-size: 22px; color: #b91c1c;"><?php echo (int)$rechargeSummary['failed_count']; ?></strong>
                    </div>
                </div>

                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <span style="font-size: 13px; color: #475569;">Total Credited Accounting Amount:</span>
                        <strong style="font-size: 15px; color: #15803d;"><?php echo $formatMoney($rechargeSummary['credited_base_cents']); ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-size: 13px; color: #475569;">Total Gateway Attempts / Intents:</span>
                        <strong style="font-size: 15px; color: #0f172a;"><?php echo (int)$rechargeSummary['total_count']; ?> total</strong>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($canViewWithdrawals) && !empty($withdrawalSummary)): ?>
        <!-- 4. PERIOD WITHDRAWALS BREAKDOWN -->
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 22px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <h3 style="margin: 0 0 16px; font-size: 16px; font-weight: 700; color: #0f172a; display: flex; justify-content: space-between; align-items: center;">
                <span>Withdrawal Activity Breakdown</span>
                <span style="font-size: 11px; font-weight: 600; color: #64748b; background: #f1f5f9; padding: 2px 8px; border-radius: 4px;">For Selected Period</span>
            </h3>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; margin-bottom: 18px; text-align: center;">
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px;">
                    <span style="font-size: 11px; color: #64748b; display: block;">Total Requests</span>
                    <strong style="font-size: 18px; color: #0f172a;"><?php echo (int)($wdCounts['all'] ?? 0); ?></strong>
                </div>
                <div style="background: #fef3c7; border: 1px solid #fde68a; border-radius: 6px; padding: 10px;">
                    <span style="font-size: 11px; color: #b45309; display: block;">Pending</span>
                    <strong style="font-size: 18px; color: #b45309;"><?php echo (int)($wdCounts['pending'] ?? 0); ?></strong>
                </div>
                <div style="background: #e0e7ff; border: 1px solid #c7d2fe; border-radius: 6px; padding: 10px;">
                    <span style="font-size: 11px; color: #4338ca; display: block;">Approved</span>
                    <strong style="font-size: 18px; color: #4338ca;"><?php echo (int)($wdCounts['approved'] ?? 0); ?></strong>
                </div>
                <div style="background: #e0f2fe; border: 1px solid #bae6fd; border-radius: 6px; padding: 10px;">
                    <span style="font-size: 11px; color: #0369a1; display: block;">Processing</span>
                    <strong style="font-size: 18px; color: #0369a1;"><?php echo (int)($wdCounts['processing'] ?? 0); ?></strong>
                </div>
                <div style="background: #dcfce7; border: 1px solid #bbf7d0; border-radius: 6px; padding: 10px;">
                    <span style="font-size: 11px; color: #15803d; display: block;">Paid</span>
                    <strong style="font-size: 18px; color: #15803d;"><?php echo (int)($wdCounts['paid'] ?? 0); ?></strong>
                </div>
                <div style="background: #fee2e2; border: 1px solid #fecaca; border-radius: 6px; padding: 10px;">
                    <span style="font-size: 11px; color: #b91c1c; display: block;">Rejected</span>
                    <strong style="font-size: 18px; color: #b91c1c;"><?php echo (int)($wdCounts['rejected'] ?? 0); ?></strong>
                </div>
                <div style="background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px;">
                    <span style="font-size: 11px; color: #475569; display: block;">Cancelled</span>
                    <strong style="font-size: 18px; color: #475569;"><?php echo (int)($wdCounts['cancelled'] ?? 0); ?></strong>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
                <div>
                    <span style="font-size: 12px; color: #64748b; display: block;">Total Gross Requested:</span>
                    <strong style="font-size: 16px; color: #0f172a;"><?php echo $formatMoney($wdTotals['gross_cents'] ?? 0); ?></strong>
                </div>
                <div>
                    <span style="font-size: 12px; color: #64748b; display: block;">Total Fees Recorded:</span>
                    <strong style="font-size: 16px; color: #475569;"><?php echo $formatMoney($wdTotals['fee_cents'] ?? 0); ?></strong>
                </div>
                <div>
                    <span style="font-size: 12px; color: #64748b; display: block;">Total Net Requested:</span>
                    <strong style="font-size: 16px; color: #2563eb;"><?php echo $formatMoney($wdTotals['net_cents'] ?? 0); ?></strong>
                </div>
                <div>
                    <span style="font-size: 12px; color: #64748b; display: block;">Total Paid Out (Net):</span>
                    <strong style="font-size: 16px; color: #15803d;"><?php echo $formatMoney($wdTotals['paid_net_cents'] ?? 0); ?></strong>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- 5. CUSTOMER WALLET SEARCH & QUICK LOOKUP -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 22px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; margin-bottom: 16px;">
            <div>
                <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #0f172a;">
                    Customer Wallet Lookup & Search
                </h3>
                <p style="margin: 4px 0 0; font-size: 12px; color: #64748b;">
                    Inspect customer balance accounts, active holds, and financial dossiers. Read-only.
                </p>
            </div>

            <form method="GET" action="/admin/page/favorite-pay-dashboard" style="display: flex; gap: 8px; align-items: center;">
                <input type="text" name="search" placeholder="Search User ID, Username, Email..." value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>" style="padding: 6px 12px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px; width: 280px;">
                <button type="submit" class="button button-primary" style="font-size: 13px;">Search</button>
                <?php if ($searchQuery !== ''): ?>
                    <a href="/admin/page/favorite-pay-dashboard" class="button" style="color: #64748b; font-size: 13px;">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (empty($searchedWallets)): ?>
            <div style="text-align: center; padding: 32px 16px; color: #64748b; background: #f8fafc; border-radius: 8px; border: 1px dashed #cbd5e1;">
                <p style="margin: 0; font-size: 14px;">No customer wallets found matching your search.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="wp-list-table widefat fixed striped" style="margin: 0;">
                    <thead>
                        <tr>
                            <th style="width: 70px;">User ID</th>
                            <th>Customer</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th style="text-align: right;">Spendable Available</th>
                            <th style="text-align: right;">On Hold</th>
                            <th style="text-align: right;">Total Balance</th>
                            <th style="text-align: center; width: 100px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($searchedWallets as $w): 
                            $uid = (int)$w['user_id'];
                            $availMoney = $w['available_balance'];
                            $heldMoney = $w['held_balance'];
                            $totalMoney = $w['total_balance'];
                            $uStatus = strtolower((string)$w['status']);
                        ?>
                            <tr>
                                <td><strong>#<?php echo $uid; ?></strong></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($w['username'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                </td>
                                <td style="color: #64748b;"><?php echo htmlspecialchars($w['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <span style="display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; background: <?php echo $uStatus === 'active' ? '#dcfce7' : ($uStatus === 'suspended' ? '#fef3c7' : '#fee2e2'); ?>; color: <?php echo $uStatus === 'active' ? '#15803d' : ($uStatus === 'suspended' ? '#b45309' : '#b91c1c'); ?>;">
                                        <?php echo htmlspecialchars($uStatus, ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td style="text-align: right; font-weight: 700; color: #15803d;">
                                    <?php echo $formatMoney($availMoney->getAmount()); ?>
                                </td>
                                <td style="text-align: right; font-weight: 600; color: <?php echo $heldMoney->getAmount() > 0 ? '#b45309' : '#94a3b8'; ?>;">
                                    <?php if (!empty($canViewWithdrawals)): ?>
                                        <?php echo $formatMoney($heldMoney->getAmount()); ?>
                                    <?php else: ?>
                                        <span style="color: #94a3b8;">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right; font-weight: 800; color: #0f172a;">
                                    <?php echo $formatMoney($totalMoney->getAmount()); ?>
                                </td>
                                <td style="text-align: center;">
                                    <a href="/admin/page/favorite-pay-dashboard?action=customer&user_id=<?php echo $uid; ?>" class="button button-small">
                                        View Detail
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- 6. GLOBAL RECENT FINANCIAL ACTIVITY -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 22px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <h3 style="margin: 0 0 16px; font-size: 16px; font-weight: 700; color: #0f172a;">
            Recent Global Ledger Movements
        </h3>

        <?php if (empty($recentActivity)): ?>
            <div style="text-align: center; padding: 32px 16px; color: #64748b; background: #f8fafc; border-radius: 8px; border: 1px dashed #cbd5e1;">
                <p style="margin: 0; font-size: 14px;">No recent financial ledger entries recorded.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="wp-list-table widefat fixed striped" style="margin: 0;">
                    <thead>
                        <tr>
                            <th>Date / Time</th>
                            <th>Customer</th>
                            <th>Type</th>
                            <th>Direction</th>
                            <th>Reference</th>
                            <th>Description</th>
                            <th style="text-align: right;">Amount</th>
                            <th style="text-align: right;">Balance After</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentActivity as $act): 
                            $e = $act['entry'];
                            $type = strtolower($e->getType());
                            $isCredit = ($type === 'credit' || $type === 'release');
                            $amt = $e->getAmount();
                            $balAfter = $e->getBalanceAfter();
                        ?>
                            <tr>
                                <td style="color: #64748b; font-size: 12px; white-space: nowrap;">
                                    <?php echo htmlspecialchars($e->getCreatedAt(), ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td>
                                    <a href="/admin/page/favorite-pay-dashboard?action=customer&user_id=<?php echo (int)$e->getUserId(); ?>" style="text-decoration: none; font-weight: 600; color: #2563eb;">
                                        <?php echo htmlspecialchars($act['username'], ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </td>
                                <td>
                                    <span style="display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: 700; text-transform: uppercase; background: <?php echo $isCredit ? '#dcfce7' : ($type === 'hold' ? '#fef3c7' : '#fee2e2'); ?>; color: <?php echo $isCredit ? '#15803d' : ($type === 'hold' ? '#b45309' : '#b91c1c'); ?>;">
                                        <?php echo htmlspecialchars(strtoupper($type), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="font-size: 12px; font-weight: 600; color: <?php echo $isCredit ? '#15803d' : '#b91c1c'; ?>;">
                                        <?php echo $isCredit ? 'Inflow' : 'Outflow'; ?>
                                    </span>
                                </td>
                                <td style="font-family: monospace; font-size: 12px; color: #475569;">
                                    <?php echo htmlspecialchars($e->getReferenceId() ?: '—', ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td style="font-size: 13px; color: #334155; max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    <?php echo htmlspecialchars($e->getDescription(), ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td style="text-align: right; font-weight: 700; font-size: 13px; color: <?php echo $isCredit ? '#15803d' : '#b91c1c'; ?>; white-space: nowrap;">
                                    <?php echo ($isCredit ? '+' : '-') . $formatMoney($amt->getAmount()); ?>
                                </td>
                                <td style="text-align: right; font-weight: 600; font-size: 13px; color: #0f172a; white-space: nowrap;">
                                    <?php echo $formatMoney($balAfter->getAmount()); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
