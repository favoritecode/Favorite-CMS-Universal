<?php
/**
 * Customer Wallet Withdrawal View
 *
 * @var \FavoriteCMS\Models\User $user
 * @var \FavoriteCMS\Pay\Domain\Money $balance
 * @var string $primaryCurrency
 * @var array $settings
 * @var array $recentWithdrawals
 * @var string $csrfToken
 * @var bool $isSuspended
 * @var int $monthlyCount
 * @var int $maxMonthlyCount
 * @var int $remainingMonthly
 */
$minAmount = (float)($settings['min_amount'] ?? 500.0);
$maxMonthlyCount = (int)($settings['max_monthly_count'] ?? 5);
$monthlyCount = $monthlyCount ?? 0;
$remainingMonthly = $remainingMonthly ?? max(0, $maxMonthlyCount - $monthlyCount);
$availableMajor = $balance->getAmount() / 100.0;
$isBalanceTooLow = ($availableMajor < $minAmount);
$isMonthlyLimitReached = ($remainingMonthly <= 0);
$isFormDisabled = $isSuspended || $isBalanceTooLow || $isMonthlyLimitReached;
?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 28px;">
    <!-- Balance Card -->
    <div class="fpay-card" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #ffffff; border: none;">
        <div style="font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; margin-bottom: 8px;">
            Available Balance for Withdrawal
        </div>
        <div style="font-size: 34px; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 8px;">
            ৳<?php echo \FavoriteCMS\Pay\Support\DecimalFormatter::minorUnitToDecimal($balance->getAmount(), 2); ?>
        </div>
        <div style="font-size: 13px; color: #cbd5e1;">
            Primary Currency: <strong><?php echo htmlspecialchars($primaryCurrency, ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
    </div>

    <!-- Withdrawal Policy Card -->
    <div class="fpay-card" style="display: flex; flex-direction: column; justify-content: space-between;">
        <div>
            <div style="font-size: 13px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px;">
                Payout Policy & Limits
            </div>
            <ul style="margin: 0; padding-left: 20px; font-size: 14px; color: #475569; line-height: 1.6;">
                <li>Minimum withdrawal: <strong>৳<?php echo number_format($minAmount, 2); ?></strong></li>
                <li>Withdrawals this month: <strong><?php echo $monthlyCount; ?> / <?php echo $maxMonthlyCount; ?></strong></li>
                <li>Remaining this month: <strong><?php echo $remainingMonthly; ?></strong></li>
                <li>Hold policy: Requested funds are reserved on hold until payout completion.</li>
            </ul>
        </div>
    </div>
</div>

<!-- Warning / Limit Alerts -->
<?php if ($isSuspended): ?>
    <div class="fpay-alert fpay-alert-warning" style="margin-bottom: 20px;">
        <span>Your account is suspended. Withdrawal requests cannot be created at this time.</span>
    </div>
<?php elseif ($isMonthlyLimitReached): ?>
    <div class="fpay-alert fpay-alert-warning" style="margin-bottom: 20px; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 14px 18px; border-radius: 8px;">
        <strong>Notice:</strong> Your monthly withdrawal limit has been reached. You can request another withdrawal next month.
    </div>
<?php elseif ($isBalanceTooLow): ?>
    <div class="fpay-alert fpay-alert-info" style="margin-bottom: 20px; background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; padding: 14px 18px; border-radius: 8px;">
        <strong>Notice:</strong> Your available balance is below the minimum withdrawal amount of ৳<?php echo number_format($minAmount, 2); ?>.
    </div>
<?php endif; ?>

<!-- Withdrawal Form Card -->
<div class="fpay-card">
    <div class="fpay-card-header">
        <h2 class="fpay-card-title">Request a Payout</h2>
    </div>

    <form action="/account/withdraw" method="POST" id="fpay-withdrawal-form" style="max-width: 640px;">
        <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />

        <div style="margin-bottom: 20px;">
            <label for="amount" style="display: block; font-weight: 600; font-size: 14px; margin-bottom: 8px; color: #1e293b;">
                Withdrawal Amount (<?php echo htmlspecialchars($primaryCurrency, ENT_QUOTES, 'UTF-8'); ?>) *
            </label>
            <input type="number" step="0.01" min="<?php echo $minAmount; ?>" id="amount" name="amount" required placeholder="e.g. 500.00" <?php echo $isFormDisabled ? 'disabled' : ''; ?> style="width: 100%; padding: 12px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 16px; box-sizing: border-box; <?php echo $isFormDisabled ? 'background: #f1f5f9; cursor: not-allowed;' : ''; ?>" />
        </div>

        <div style="margin-bottom: 20px;">
            <label for="method" style="display: block; font-weight: 600; font-size: 14px; margin-bottom: 8px; color: #1e293b;">
                Payout Method *
            </label>
            <select id="method" name="method" required <?php echo $isFormDisabled ? 'disabled' : ''; ?> style="width: 100%; padding: 12px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 15px; box-sizing: border-box; background: #ffffff; <?php echo $isFormDisabled ? 'background: #f1f5f9; cursor: not-allowed;' : ''; ?>">
                <option value="bkash">bKash (Mobile Banking)</option>
                <option value="nagad">Nagad (Mobile Banking)</option>
                <option value="rocket">Rocket (Mobile Banking)</option>
                <option value="bank_transfer">Bank Transfer (Domestic Bank)</option>
            </select>
        </div>

        <!-- Mobile Banking Fields -->
        <div id="mobile-wallet-fields" style="margin-bottom: 20px;">
            <label for="account_number" style="display: block; font-weight: 600; font-size: 14px; margin-bottom: 8px; color: #1e293b;">
                Personal Mobile Wallet Number (11 digits) *
            </label>
            <input type="text" id="account_number" name="account_number" placeholder="e.g. 01700000000" <?php echo $isFormDisabled ? 'disabled' : ''; ?> style="width: 100%; padding: 12px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 15px; box-sizing: border-box; <?php echo $isFormDisabled ? 'background: #f1f5f9; cursor: not-allowed;' : ''; ?>" />
        </div>

        <!-- Bank Transfer Fields -->
        <div id="bank-transfer-fields" style="display: none; margin-bottom: 20px; background: #f8fafc; padding: 18px; border-radius: 8px; border: 1px solid #e2e8f0;">
            <div style="margin-bottom: 14px;">
                <label for="bank_name" style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #334155;">Bank Name *</label>
                <input type="text" id="bank_name" name="bank_name" placeholder="e.g. Dutch-Bangla Bank, Islami Bank" <?php echo $isFormDisabled ? 'disabled' : ''; ?> style="width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; box-sizing: border-box;" />
            </div>
            <div style="margin-bottom: 14px;">
                <label for="bank_account_name" style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #334155;">Account Holder Name *</label>
                <input type="text" id="bank_account_name" name="account_name" placeholder="e.g. Md. Rahman" <?php echo $isFormDisabled ? 'disabled' : ''; ?> style="width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; box-sizing: border-box;" />
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label for="bank_account_number" style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #334155;">Bank Account Number *</label>
                    <input type="text" id="bank_account_number" name="bank_account_number" placeholder="Account Number" <?php echo $isFormDisabled ? 'disabled' : ''; ?> style="width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; box-sizing: border-box;" />
                </div>
                <div>
                    <label for="branch_name" style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #334155;">Branch Name / Routing</label>
                    <input type="text" id="branch_name" name="branch_name" placeholder="e.g. Motijheel Branch" <?php echo $isFormDisabled ? 'disabled' : ''; ?> style="width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; box-sizing: border-box;" />
                </div>
            </div>
        </div>

        <button type="submit" class="fpay-btn fpay-btn-primary" <?php echo $isFormDisabled ? 'disabled' : ''; ?> style="padding: 12px 28px; font-size: 15px; <?php echo $isFormDisabled ? 'opacity: 0.6; cursor: not-allowed;' : ''; ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
            Submit Withdrawal Request
        </button>
    </form>
</div>

<!-- Recent Withdrawals Table -->
<div class="fpay-card">
    <div class="fpay-card-header">
        <h2 class="fpay-card-title">Withdrawal Request History</h2>
    </div>

    <?php if (empty($recentWithdrawals)): ?>
        <p style="color: #64748b; font-size: 14px; margin: 0;">No withdrawal requests found.</p>
    <?php else: ?>
        <table class="fpay-table">
            <thead>
                <tr>
                    <th>Date / Time</th>
                    <th>ID</th>
                    <th>Method</th>
                    <th>Destination</th>
                    <th style="text-align: right;">Amount</th>
                    <th>Status</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentWithdrawals as $item): ?>
                    <?php
                    $stat = $item->getStatus();
                    $badgeClass = $stat->badgeClass();
                    $amountDec = \FavoriteCMS\Pay\Support\DecimalFormatter::minorUnitToDecimal($item->getAmount()->getAmount(), 2);
                    ?>
                    <tr>
                        <td style="color: #64748b; white-space: nowrap;">
                            <?php echo htmlspecialchars($item->getCreatedAt(), ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td>
                            <strong style="font-family: monospace;"><?php echo htmlspecialchars($item->getId(), ENT_QUOTES, 'UTF-8'); ?></strong>
                        </td>
                        <td style="text-transform: capitalize;">
                            <?php echo htmlspecialchars(str_replace('_', ' ', $item->getMethod()), ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td>
                            <span style="font-family: monospace; color: #475569;"><?php echo htmlspecialchars($item->getDestinationMasked(), ENT_QUOTES, 'UTF-8'); ?></span>
                        </td>
                        <td style="text-align: right; font-weight: 700; color: #0f172a;">
                            ৳<?php echo $amountDec; ?>
                        </td>
                        <td>
                            <span class="fpay-badge <?php echo $badgeClass; ?>">
                                <?php echo htmlspecialchars($stat->label(), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>
                        <td style="text-align: right;">
                            <a href="/account/withdrawals/<?php echo urlencode($item->getId()); ?>" class="fpay-btn fpay-btn-secondary fpay-btn-sm">
                                Details &rarr;
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var methodSelect = document.getElementById('method');
    var mobileFields = document.getElementById('mobile-wallet-fields');
    var bankFields = document.getElementById('bank-transfer-fields');
    var accInput = document.getElementById('account_number');

    if (methodSelect && mobileFields && bankFields) {
        function toggleFields() {
            var val = methodSelect.value;
            if (val === 'bank_transfer') {
                mobileFields.style.display = 'none';
                bankFields.style.display = 'block';
                if (accInput) accInput.removeAttribute('required');
            } else {
                mobileFields.style.display = 'block';
                bankFields.style.display = 'none';
                if (accInput) accInput.setAttribute('required', 'required');
            }
        }
        methodSelect.addEventListener('change', toggleFields);
        toggleFields();
    }
});
</script>
