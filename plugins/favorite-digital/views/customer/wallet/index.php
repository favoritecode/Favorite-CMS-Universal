<?php
/**
 * Customer Digital Wallet Hub View
 *
 * @var object $wallet
 * @var string $balance
 * @var string $currency
 * @var array $regularLimits
 * @var array|null $binanceLimits
 * @var array $availableGateways
 * @var array $transactions
 * @var int $totalTransactions
 * @var int $page
 * @var int $perPage
 * @var int $totalPages
 * @var array $recharges
 * @var string $csrfToken
 * @var int $userId
 * @var string|null $flashError
 * @var string|null $flashSuccess
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Digital Wallet — Favorite CMS</title>
    <style>
        .fav-wallet-container {
            max-width: 1100px;
            margin: 0 auto 40px;
            padding: 0 16px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            color: #1e293b;
        }
        .fav-alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 500;
        }
        .fav-alert-success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .fav-alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .fav-wallet-header {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 32px;
        }
        @media (max-width: 768px) {
            .fav-wallet-header {
                grid-template-columns: 1fr;
            }
        }
        .fav-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }
        .fav-card-title {
            font-size: 18px;
            font-weight: 700;
            margin: 0 0 16px;
            color: #0f172a;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .fav-balance-display {
            display: flex;
            align-items: baseline;
            gap: 8px;
            margin: 12px 0;
        }
        .fav-balance-currency {
            font-size: 20px;
            font-weight: 600;
            color: #64748b;
        }
        .fav-balance-val {
            font-size: 40px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.5px;
        }
        .fav-status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .fav-status-active {
            background: #ecfdf5;
            color: #059669;
        }
        .fav-status-suspended {
            background: #fef2f2;
            color: #dc2626;
        }
        .fav-status-pending {
            background: #fffbeb;
            color: #d97706;
        }
        .fav-status-completed, .fav-status-succeeded {
            background: #ecfdf5;
            color: #059669;
        }
        .fav-status-failed {
            background: #fef2f2;
            color: #dc2626;
        }
        .fav-status-expired {
            background: #f1f5f9;
            color: #64748b;
        }
        .fav-form-group {
            margin-bottom: 16px;
        }
        .fav-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 6px;
            color: #334155;
        }
        .fav-input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }
        .fav-input-prefix {
            position: absolute;
            left: 14px;
            font-size: 16px;
            font-weight: 600;
            color: #64748b;
        }
        .fav-input {
            width: 100%;
            padding: 10px 14px 10px 36px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            color: #0f172a;
            box-sizing: border-box;
            transition: border-color 0.15s;
        }
        .fav-input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }
        .fav-quick-amounts {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 8px;
        }
        .fav-quick-btn {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            cursor: pointer;
            transition: all 0.15s;
        }
        .fav-quick-btn:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
        .fav-gateways-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 10px;
            margin-top: 8px;
        }
        .fav-gateway-label {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            color: #334155;
            transition: all 0.15s;
        }
        .fav-gateway-label:hover {
            border-color: #94a3b8;
            background: #f8fafc;
        }
        .fav-gateway-label input[type="radio"]:checked + span {
            color: #2563eb;
        }
        .fav-gateway-label:has(input[type="radio"]:checked) {
            border-color: #2563eb;
            background: #eff6ff;
        }
        .fav-btn-primary {
            display: inline-block;
            width: 100%;
            background: #2563eb;
            color: #ffffff;
            font-size: 15px;
            font-weight: 700;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            box-sizing: border-box;
            transition: background 0.15s;
        }
        .fav-btn-primary:hover {
            background: #1d4ed8;
        }
        .fav-btn-primary:disabled {
            background: #94a3b8;
            cursor: not-allowed;
        }
        .fav-btn-sm {
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 600;
            border-radius: 6px;
            text-decoration: none;
            display: inline-block;
            cursor: pointer;
            border: none;
        }
        .fav-table-wrap {
            overflow-x: auto;
            margin-top: 16px;
        }
        .fav-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 14px;
        }
        .fav-table th {
            background: #f8fafc;
            padding: 12px 16px;
            font-weight: 600;
            color: #475569;
            border-bottom: 1px solid #e2e8f0;
        }
        .fav-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
        }
        .fav-table tr:hover td {
            background: #f8fafc;
        }
        .fav-badge-type {
            display: inline-flex;
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .fav-badge-recharge, .fav-badge-credit {
            background: #ecfdf5;
            color: #059669;
        }
        .fav-badge-refund_credit {
            background: #eff6ff;
            color: #2563eb;
        }
        .fav-badge-debit {
            background: #f1f5f9;
            color: #475569;
        }
        .fav-badge-reversal {
            background: #fffbeb;
            color: #d97706;
        }
        .fav-amount-credit {
            color: #059669;
            font-weight: 700;
        }
        .fav-amount-debit {
            color: #0f172a;
            font-weight: 700;
        }
        .fav-pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 20px;
        }
        .fav-page-link {
            padding: 6px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            text-decoration: none;
            color: #475569;
            font-size: 13px;
            font-weight: 600;
        }
        .fav-page-link.active {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
        }
    </style>
</head>
<body>

<?php
$activeTab = 'wallet';
include __DIR__ . '/../account/nav.php';
?>

<div class="fav-wallet-container">

    <?php if ($flashSuccess): ?>
        <div class="fav-alert fav-alert-success" role="alert">
            <?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($flashError): ?>
        <div class="fav-alert fav-alert-error" role="alert">
            <?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="fav-wallet-header">
        <!-- Balance Card -->
        <div class="fav-card">
            <div class="fav-card-title">
                <span>👛 Digital Wallet Balance</span>
                <span class="fav-status-badge <?= $wallet->status === 'active' ? 'fav-status-active' : 'fav-status-suspended' ?>">
                    <?= htmlspecialchars(ucfirst((string)$wallet->status), ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
            <div class="fav-balance-display">
                <span class="fav-balance-currency"><?= htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="fav-balance-val"><?= htmlspecialchars($balance, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <p style="color: #64748b; font-size: 13px; margin: 8px 0 0; line-height: 1.5;">
                ✨ Your wallet balance <strong>never expires</strong>. Recharged funds and refund credits remain safe in your account and can be used immediately during checkout for any digital product, package, or service.
            </p>
        </div>

        <!-- Recharge Card -->
        <div class="fav-card">
            <h2 class="fav-card-title" style="margin-bottom: 12px;">⚡ Recharge Wallet</h2>
            <?php if ($wallet->status !== 'active'): ?>
                <div class="fav-alert fav-alert-error">
                    Your wallet is currently suspended. Recharges are disabled.
                </div>
            <?php else: ?>
                <form action="/account/wallet/recharge" method="POST">
                    <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="fav-form-group">
                        <label for="recharge_amount" class="fav-label">Recharge Amount (<?= htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') ?>)</label>
                        <div class="fav-input-wrap">
                            <span class="fav-input-prefix">৳</span>
                            <input 
                                type="text" 
                                id="recharge_amount" 
                                name="amount" 
                                class="fav-input" 
                                placeholder="500.00" 
                                required 
                                pattern="^\d+(\.\d{1,2})?$"
                                autocomplete="off"
                            >
                        </div>
                        <div class="fav-quick-amounts">
                            <button type="button" class="fav-quick-btn" onclick="setRechargeAmount('100.00')">৳100</button>
                            <button type="button" class="fav-quick-btn" onclick="setRechargeAmount('500.00')">৳500</button>
                            <button type="button" class="fav-quick-btn" onclick="setRechargeAmount('1000.00')">৳1,000</button>
                            <button type="button" class="fav-quick-btn" onclick="setRechargeAmount('2000.00')">৳2,000</button>
                            <button type="button" class="fav-quick-btn" onclick="setRechargeAmount('5000.00')">৳5,000</button>
                        </div>
                        <p style="font-size: 12px; color: #64748b; margin: 6px 0 0;">
                            Limits: Min ৳<?= htmlspecialchars($regularLimits['min'], ENT_QUOTES, 'UTF-8') ?> — Max ৳<?= htmlspecialchars($regularLimits['max'], ENT_QUOTES, 'UTF-8') ?>
                            <?php if ($binanceLimits): ?>
                                (Binance Pay Min: ৳<?= htmlspecialchars($binanceLimits['min'], ENT_QUOTES, 'UTF-8') ?> eq. 1 USD)
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="fav-form-group">
                        <label class="fav-label">Payment Method</label>
                        <?php if (empty($availableGateways)): ?>
                            <p style="font-size: 13px; color: #64748b;">No payment methods currently available.</p>
                        <?php else: ?>
                            <div class="fav-gateways-grid">
                                <?php foreach ($availableGateways as $gw): ?>
                                    <label class="fav-gateway-label">
                                        <input type="radio" name="gateway_id" value="<?= htmlspecialchars($gw['id'], ENT_QUOTES, 'UTF-8') ?>" required>
                                        <span><?= htmlspecialchars($gw['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="fav-btn-primary" <?= empty($availableGateways) ? 'disabled' : '' ?>>
                        Proceed to Payment
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Transaction History (Ledger) -->
    <section class="fav-card" style="margin-bottom: 32px;" aria-label="Wallet Transaction History">
        <h2 class="fav-card-title">
            <span>📜 Wallet Transaction Ledger</span>
            <span style="font-size: 13px; font-weight: 500; color: #64748b;"><?= (int)$totalTransactions ?> Total Records</span>
        </h2>

        <?php if (empty($transactions)): ?>
            <p style="color: #64748b; font-size: 14px; text-align: center; padding: 24px 0;">
                No wallet transactions yet.
            </p>
        <?php else: ?>
            <div class="fav-table-wrap">
                <table class="fav-table" aria-label="Wallet Transaction History">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Balance After</th>
                            <th>Reference / Description</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $tx): ?>
                            <?php
                            $isCredit = in_array($tx->type, ['recharge', 'refund_credit', 'credit', 'reversal'], true);
                            $typeLabel = match ($tx->type) {
                                'recharge'      => 'Recharge',
                                'refund_credit' => 'Refund',
                                'debit'         => 'Purchase',
                                'reversal'      => 'Reversal',
                                default         => ucfirst($tx->type),
                            };
                            ?>
                            <tr>
                                <td style="white-space: nowrap; font-size: 13px; color: #64748b;">
                                    <?= htmlspecialchars($tx->created_at, ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <span class="fav-badge-type fav-badge-<?= htmlspecialchars($tx->type, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td class="<?= $isCredit ? 'fav-amount-credit' : 'fav-amount-debit' ?>">
                                    <?= $isCredit ? '+' : '-' ?>৳<?= htmlspecialchars($tx->amount, ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td style="font-weight: 600;">
                                    ৳<?= htmlspecialchars($tx->balance_after, ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td style="max-width: 320px;">
                                    <div style="font-weight: 600; font-size: 13px; color: #0f172a;">
                                        <?= htmlspecialchars($tx->description ?: 'Wallet ledger entry', ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <div style="font-size: 11px; color: #64748b; font-family: monospace;">
                                        <?= htmlspecialchars($tx->reference_id, ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="fav-status-badge fav-status-completed">
                                        Completed
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <nav class="fav-pagination" aria-label="Transaction Ledger Pagination">
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <a href="/account/wallet?page=<?= $p ?>" class="fav-page-link <?= $p === $page ? 'active' : '' ?>">
                            <?= $p ?>
                        </a>
                    <?php endfor; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <!-- Recharge History -->
    <section class="fav-card" aria-label="Recent Recharge History">
        <h2 class="fav-card-title">
            <span>💳 Recent Recharge History</span>
        </h2>

        <?php if (empty($recharges)): ?>
            <p style="color: #64748b; font-size: 14px; text-align: center; padding: 24px 0;">
                No recent recharge records found.
            </p>
        <?php else: ?>
            <div class="fav-table-wrap">
                <table class="fav-table" aria-label="Recent Recharge History">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Reference</th>
                            <th>Method</th>
                            <th>Wallet Credit</th>
                            <th>Payment Amount</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recharges as $rc): ?>
                            <tr>
                                <td style="white-space: nowrap; font-size: 13px; color: #64748b;">
                                    <?= htmlspecialchars($rc->created_at, ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td style="font-family: monospace; font-size: 12px;">
                                    <?= htmlspecialchars($rc->transaction_id, ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars(ucwords(str_replace(['_', '-'], ' ', $rc->gateway_id)), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td style="font-weight: 700; color: #059669;">
                                    +৳<?= htmlspecialchars($rc->wallet_amount, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($rc->wallet_currency, ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td style="font-weight: 600;">
                                    <?= htmlspecialchars($rc->charge_amount, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($rc->charge_currency, ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <span class="fav-status-badge fav-status-<?= htmlspecialchars($rc->status, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($rc->status_label, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($rc->status === 'pending' || $rc->status === 'awaiting_verification'): ?>
                                        <a href="/account/wallet/recharge/manual?intent_id=<?= urlencode($rc->transaction_id) ?>" class="fav-btn-sm" style="background: #2563eb; color: #fff;">
                                            Submit TrxID
                                        </a>
                                    <?php elseif ($rc->status === 'failed' || $rc->status === 'expired'): ?>
                                        <form action="/account/wallet/recharge/retry" method="POST" style="display:inline;">
                                            <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="intent_id" value="<?= htmlspecialchars($rc->transaction_id, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="gateway_id" value="<?= htmlspecialchars($rc->gateway_id, ENT_QUOTES, 'UTF-8') ?>">
                                            <button type="submit" class="fav-btn-sm" style="background: #e2e8f0; color: #334155;">
                                                Retry
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 13px;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

</div>

<script>
function setRechargeAmount(val) {
    var el = document.getElementById('recharge_amount');
    if (el) {
        el.value = val;
    }
}
</script>

</body>
</html>
