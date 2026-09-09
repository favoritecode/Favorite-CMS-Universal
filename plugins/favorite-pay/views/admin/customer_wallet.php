<?php
/**
 * Admin Customer Wallet & Financial Dossier View
 *
 * @var \FavoriteCMS\Models\User|null $currentUser
 * @var int $targetUserId
 * @var string $username
 * @var string $email
 * @var string $status
 * @var string $primaryCurrency
 * @var \FavoriteCMS\Pay\Domain\Money $available
 * @var \FavoriteCMS\Pay\Domain\Money $held
 * @var \FavoriteCMS\Pay\Domain\Money $total
 * @var array<\FavoriteCMS\Pay\Domain\LedgerEntry> $ledgerEntries
 * @var array $recharges
 * @var array<\FavoriteCMS\Pay\Domain\Withdrawal> $withdrawals
 */

use FavoriteCMS\Pay\Domain\Money;

$curr = htmlspecialchars($primaryCurrency, ENT_QUOTES, 'UTF-8');
$formatMoney = function(int $cents) use ($curr) {
    return $curr . ' ' . number_format($cents / 100, 2);
};

$uStatus = strtolower((string)$status);
?>

<div class="wrap" style="max-width: 1280px; margin: 20px auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <!-- Navigation Back Link & Header -->
    <div style="margin-bottom: 20px;">
        <a href="/admin/page/favorite-pay-dashboard" style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: #2563eb; text-decoration: none; font-weight: 600; margin-bottom: 12px;">
            &larr; Back to Financial Dashboard
        </a>
        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
            <div>
                <h1 style="margin: 0; font-size: 24px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 10px;">
                    <span>👤</span> Customer Financial Dossier &mdash; <?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>
                </h1>
                <p style="margin: 6px 0 0; color: #64748b; font-size: 13px;">
                    Authoritative balance state, ledger audit history, recharges, and payouts for User #<?php echo (int)$targetUserId; ?>. Read-only.
                </p>
            </div>
            <?php if (!empty($canViewWithdrawals)): ?>
            <div style="display: flex; gap: 10px; align-items: center;">
                <a href="/admin/page/favorite-pay-withdrawals?user_id=<?php echo (int)$targetUserId; ?>" class="button button-secondary" style="font-size: 13px;">
                    Filter Withdrawals Queue &rarr;
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Customer Profile Info Bar -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px 22px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div style="display: flex; gap: 24px; align-items: center; flex-wrap: wrap;">
            <div>
                <span style="font-size: 11px; text-transform: uppercase; font-weight: 600; color: #64748b; display: block;">User ID</span>
                <strong style="font-size: 16px; color: #0f172a;">#<?php echo (int)$targetUserId; ?></strong>
            </div>
            <div>
                <span style="font-size: 11px; text-transform: uppercase; font-weight: 600; color: #64748b; display: block;">Username</span>
                <strong style="font-size: 16px; color: #0f172a;"><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div>
                <span style="font-size: 11px; text-transform: uppercase; font-weight: 600; color: #64748b; display: block;">Email</span>
                <span style="font-size: 15px; color: #334155;"><?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div>
                <span style="font-size: 11px; text-transform: uppercase; font-weight: 600; color: #64748b; display: block;">Account Status</span>
                <span style="display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 12px; font-weight: 700; text-transform: uppercase; background: <?php echo $uStatus === 'active' ? '#dcfce7' : ($uStatus === 'suspended' ? '#fef3c7' : '#fee2e2'); ?>; color: <?php echo $uStatus === 'active' ? '#15803d' : ($uStatus === 'suspended' ? '#b45309' : '#b91c1c'); ?>;">
                    <?php echo htmlspecialchars($uStatus, ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
        </div>
        <div style="font-size: 12px; color: #64748b; background: #f8fafc; padding: 6px 12px; border-radius: 6px; border: 1px solid #e2e8f0;">
            Primary Accounting Currency: <strong style="color: #0f172a;"><?php echo $curr; ?></strong>
        </div>
    </div>

    <!-- Authoritative Customer Balance Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-bottom: 28px;">
        <div style="background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); color: #fff; padding: 20px; border-radius: 10px; box-shadow: 0 2px 4px rgba(37,99,235,0.15);">
            <span style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #bfdbfe; display: block; margin-bottom: 6px;">
                Total Account Balance
            </span>
            <div style="font-size: 28px; font-weight: 800; line-height: 1.2;">
                <?php echo $formatMoney($total->getAmount()); ?>
            </div>
            <span style="font-size: 12px; color: #dbeafe; display: block; margin-top: 6px;">
                Spendable + Active Holds
            </span>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-left: 4px solid #16a34a; padding: 20px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <span style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #16a34a; display: block; margin-bottom: 6px;">
                Spendable Available
            </span>
            <div style="font-size: 28px; font-weight: 800; color: #15803d; line-height: 1.2;">
                <?php echo $formatMoney($available->getAmount()); ?>
            </div>
            <span style="font-size: 12px; color: #64748b; display: block; margin-top: 6px;">
                Available for withdrawal or platform purchases
            </span>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-left: 4px solid #d97706; padding: 20px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <span style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #d97706; display: block; margin-bottom: 6px;">
                On Hold in Withdrawals
            </span>
            <div style="font-size: 28px; font-weight: 800; color: #b45309; line-height: 1.2;">
                <?php echo $formatMoney($held->getAmount()); ?>
            </div>
            <span style="font-size: 12px; color: #64748b; display: block; margin-top: 6px;">
                Reserved across pending & processing payouts
            </span>
        </div>
    </div>

    <!-- Section 1: Recent Ledger Transactions -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 22px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <h3 style="margin: 0 0 16px; font-size: 16px; font-weight: 700; color: #0f172a; display: flex; justify-content: space-between; align-items: center;">
            <span>Immutable Ledger Audit History</span>
            <span style="font-size: 11px; font-weight: 600; color: #64748b; background: #f1f5f9; padding: 2px 8px; border-radius: 4px;">Latest Records</span>
        </h3>

        <?php if (empty($ledgerEntries)): ?>
            <div style="text-align: center; padding: 28px 16px; color: #64748b; background: #f8fafc; border-radius: 8px; border: 1px dashed #cbd5e1;">
                <p style="margin: 0; font-size: 14px;">No ledger entries recorded for this customer.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="wp-list-table widefat fixed striped" style="margin: 0;">
                    <thead>
                        <tr>
                            <th>Date / Time</th>
                            <th>Type</th>
                            <th>Direction</th>
                            <th>Reference</th>
                            <th>Description</th>
                            <th style="text-align: right;">Amount</th>
                            <th style="text-align: right;">Balance After</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ledgerEntries as $e): 
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
                                <td style="font-size: 13px; color: #334155;">
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

    <!-- Section 2: Recent Recharges & Inbound Payments -->
    <?php if (!empty($canViewPayments)): ?>
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 22px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <h3 style="margin: 0 0 16px; font-size: 16px; font-weight: 700; color: #0f172a;">
            Customer Recharges & Inbound Payments
        </h3>

        <?php if (empty($recharges)): ?>
            <div style="text-align: center; padding: 28px 16px; color: #64748b; background: #f8fafc; border-radius: 8px; border: 1px dashed #cbd5e1;">
                <p style="margin: 0; font-size: 14px;">No recharge transactions recorded for this customer.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="wp-list-table widefat fixed striped" style="margin: 0;">
                    <thead>
                        <tr>
                            <th>Date / Time</th>
                            <th>Transaction ID</th>
                            <th>Gateway</th>
                            <th>Status</th>
                            <th style="text-align: right;">Credited Amount</th>
                            <th style="text-align: right;">Gateway Charged</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recharges as $rc): 
                            $rcStatus = strtolower((string)($rc['status'] ?? 'pending'));
                            $isSucc = ($rcStatus === 'succeeded');
                            $bAmt = (int)($rc['base_amount'] ?? 0);
                            $cAmt = (int)($rc['charge_amount'] ?? 0);
                            $bCurr = htmlspecialchars($rc['base_currency'] ?? $primaryCurrency, ENT_QUOTES, 'UTF-8');
                            $cCurr = htmlspecialchars($rc['charge_currency'] ?? $primaryCurrency, ENT_QUOTES, 'UTF-8');
                        ?>
                            <tr>
                                <td style="color: #64748b; font-size: 12px; white-space: nowrap;">
                                    <?php echo htmlspecialchars($rc['created_at'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td style="font-family: monospace; font-size: 12px; color: #334155;">
                                    <?php echo htmlspecialchars($rc['transaction_id'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td style="font-size: 13px; color: #475569;">
                                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)($rc['gateway_id'] ?? '—'))), ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td>
                                    <span style="display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: 700; text-transform: uppercase; background: <?php echo $isSucc ? '#dcfce7' : ($rcStatus === 'pending' ? '#fef3c7' : '#fee2e2'); ?>; color: <?php echo $isSucc ? '#15803d' : ($rcStatus === 'pending' ? '#b45309' : '#b91c1c'); ?>;">
                                        <?php echo htmlspecialchars($rcStatus, ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td style="text-align: right; font-weight: 700; color: #15803d;">
                                    <?php echo $bCurr . ' ' . number_format($bAmt / 100, 2); ?>
                                </td>
                                <td style="text-align: right; font-weight: 600; color: #0f172a;">
                                    <?php echo $cCurr . ' ' . number_format($cAmt / 100, 2); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Section 3: Recent Customer Withdrawals -->
    <?php if (!empty($canViewWithdrawals)): ?>
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 22px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <h3 style="margin: 0 0 16px; font-size: 16px; font-weight: 700; color: #0f172a;">
            Customer Withdrawal Requests
        </h3>

        <?php if (empty($withdrawals)): ?>
            <div style="text-align: center; padding: 28px 16px; color: #64748b; background: #f8fafc; border-radius: 8px; border: 1px dashed #cbd5e1;">
                <p style="margin: 0; font-size: 14px;">No withdrawal requests recorded for this customer.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="wp-list-table widefat fixed striped" style="margin: 0;">
                    <thead>
                        <tr>
                            <th>Date / Time</th>
                            <th>Reference</th>
                            <th>Method</th>
                            <th>Destination</th>
                            <th>Status</th>
                            <th style="text-align: right;">Gross Requested</th>
                            <th style="text-align: right;">Fee</th>
                            <th style="text-align: right;">Net Payable</th>
                            <th style="text-align: center; width: 100px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($withdrawals as $w): 
                            $stVal = $w->getStatus()->value;
                            $stLabel = ucfirst($stVal);
                            $grossMoney = $w->getAmount();
                            $feeMoney = $w->getFee();
                            $netMoney = $w->getNetAmount();
                            
                            $badgeColor = match ($stVal) {
                                'paid' => ['bg' => '#dcfce7', 'text' => '#15803d'],
                                'pending' => ['bg' => '#fef3c7', 'text' => '#b45309'],
                                'approved' => ['bg' => '#e0e7ff', 'text' => '#4338ca'],
                                'processing' => ['bg' => '#e0f2fe', 'text' => '#0369a1'],
                                'rejected', 'failed' => ['bg' => '#fee2e2', 'text' => '#b91c1c'],
                                default => ['bg' => '#f1f5f9', 'text' => '#475569'],
                            };
                        ?>
                            <tr>
                                <td style="color: #64748b; font-size: 12px; white-space: nowrap;">
                                    <?php echo htmlspecialchars($w->getCreatedAt(), ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td style="font-family: monospace; font-size: 12px; color: #334155;">
                                    <?php echo htmlspecialchars($w->getId(), ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td style="font-size: 13px; color: #475569;">
                                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $w->getMethod())), ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td style="font-size: 12px; color: #475569; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    <?php echo htmlspecialchars($w->getDestinationMasked(), ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td>
                                    <span style="display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: 700; text-transform: uppercase; background: <?php echo $badgeColor['bg']; ?>; color: <?php echo $badgeColor['text']; ?>;">
                                        <?php echo htmlspecialchars($stLabel, ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td style="text-align: right; font-weight: 700; color: #0f172a;">
                                    <?php echo $formatMoney($grossMoney->getAmount()); ?>
                                </td>
                                <td style="text-align: right; font-size: 12px; color: #64748b;">
                                    <?php echo $formatMoney($feeMoney->getAmount()); ?>
                                </td>
                                <td style="text-align: right; font-weight: 700; color: #2563eb;">
                                    <?php echo $formatMoney($netMoney->getAmount()); ?>
                                </td>
                                <td style="text-align: center;">
                                    <a href="/admin/page/favorite-pay-withdrawals?id=<?php echo urlencode($w->getId()); ?>" class="button button-small">
                                        View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
