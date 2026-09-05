<?php
declare(strict_types=1);

/**
 * @var array $attempt
 * @var bool $canVerify
 * @var string $csrfToken
 */

$statusLabels = [
    'awaiting_verification' => 'Awaiting Verification',
    'succeeded'             => 'Succeeded (Approved)',
    'failed'                => 'Failed (Rejected)',
    'pending'               => 'Pending',
];

$st = (string)($attempt['status'] ?? 'pending');
$amountMinor = (int)($attempt['amount'] ?? 0);
$currency = htmlspecialchars($attempt['currency'] ?? 'BDT', ENT_QUOTES, 'UTF-8');
$formattedAmount = number_format($amountMinor / 100, 2) . ' ' . $currency;

$badgeClass = match ($st) {
    'awaiting_verification' => 'badge-warning',
    'succeeded'             => 'badge-success',
    'failed'                => 'badge-danger',
    default                 => 'badge-secondary',
};
?>
<style>
.fp-review-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 24px;
}
.fp-action-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 24px;
}
@media (max-width: 768px) {
    .fp-review-grid, .fp-action-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="favorite-pay-review" style="max-width: 980px; margin: 0 auto;">
    <!-- Breadcrumb -->
    <div style="margin-bottom: 16px;">
        <a href="/admin/page/favorite-pay" style="color: var(--wp-blue); text-decoration: none; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
            &larr; Back to Verification Queue
        </a>
    </div>

    <!-- Review Header -->
    <div class="card" style="margin-bottom: 24px; padding: 20px 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div>
                <h1 style="font-size: 22px; font-weight: 700; color: var(--wp-text-main); margin: 0 0 6px 0;">
                    Review Payment Attempt
                </h1>
                <div style="font-family: monospace; font-size: 13px; color: var(--wp-text-muted);">
                    Attempt ID: <strong style="color: var(--wp-text-main);"><?php echo htmlspecialchars((string)$attempt['attempt_id'], ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
            </div>
            <div>
                <span class="badge <?php echo $badgeClass; ?>" style="font-size: 13px; padding: 6px 14px; display: inline-flex; align-items: center; gap: 6px;">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background: currentColor;"></span>
                    <?php echo htmlspecialchars($statusLabels[$st] ?? ucfirst($st), ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Review Cards Grid -->
    <div class="fp-review-grid">
        
        <!-- Card 1: Transaction & Attempt Metadata -->
        <div class="card" style="padding: 20px;">
            <h3 style="font-size: 14px; font-weight: 700; color: var(--wp-text-main); margin: 0 0 14px 0; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                💳 Transaction Overview
            </h3>
            <table style="width: 100%; font-size: 13px; line-height: 2;">
                <tr>
                    <td style="color: var(--wp-text-muted); width: 140px;">Transaction ID:</td>
                    <td style="font-family: monospace; font-weight: 600; color: var(--wp-text-main);">
                        <?php echo htmlspecialchars((string)$attempt['transaction_id'], ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                </tr>
                <tr>
                    <td style="color: var(--wp-text-muted);">Gateway:</td>
                    <td>
                        <span class="badge badge-secondary" style="font-size: 11px; font-weight: 600;">
                            <?php echo htmlspecialchars((string)$attempt['gateway_id'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <td style="color: var(--wp-text-muted);">Amount:</td>
                    <td style="font-size: 16px; font-weight: 700; color: var(--wp-text-main);">
                        <?php echo $formattedAmount; ?>
                    </td>
                </tr>
                <tr>
                    <td style="color: var(--wp-text-muted);">Source Plugin:</td>
                    <td><?php echo htmlspecialchars((string)($attempt['source_plugin'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <tr>
                    <td style="color: var(--wp-text-muted);">Source Reference:</td>
                    <td><?php echo htmlspecialchars((string)($attempt['source_reference'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <tr>
                    <td style="color: var(--wp-text-muted);">Submitted At:</td>
                    <td><?php echo htmlspecialchars((string)($attempt['created_at'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
            </table>
        </div>

        <!-- Card 2: Customer Profile -->
        <div class="card" style="padding: 20px;">
            <h3 style="font-size: 14px; font-weight: 700; color: var(--wp-text-main); margin: 0 0 14px 0; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                👤 Customer Details
            </h3>
            <?php if (!empty($attempt['customer'])): ?>
                <?php $cust = $attempt['customer']; ?>
                <table style="width: 100%; font-size: 13px; line-height: 2;">
                    <tr>
                        <td style="color: var(--wp-text-muted); width: 120px;">User ID:</td>
                        <td style="font-weight: 600;">#<?php echo (int)$cust['id']; ?></td>
                    </tr>
                    <tr>
                        <td style="color: var(--wp-text-muted);">Username:</td>
                        <td style="font-weight: 600; color: var(--wp-text-main);">
                            <?php echo htmlspecialchars($cust['username'], ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="color: var(--wp-text-muted);">Email:</td>
                        <td>
                            <a href="mailto:<?php echo htmlspecialchars($cust['email'], ENT_QUOTES, 'UTF-8'); ?>" style="color: var(--wp-blue); font-weight: 500;">
                                <?php echo htmlspecialchars($cust['email'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </td>
                    </tr>
                </table>
            <?php else: ?>
                <div style="color: var(--wp-text-muted); font-size: 13px; line-height: 1.8;">
                    <?php if (!empty($attempt['user_id'])): ?>
                        Associated Core User ID: <strong>#<?php echo (int)$attempt['user_id']; ?></strong>
                    <?php else: ?>
                        Guest checkout (No registered Core user ID).
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Card 3: Submitted Manual Bangladesh Details -->
        <div class="card" style="padding: 20px;">
            <h3 style="font-size: 14px; font-weight: 700; color: var(--wp-text-main); margin: 0 0 14px 0; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                🇧🇩 Manual Payment Proof
            </h3>
            <table style="width: 100%; font-size: 13px; line-height: 2;">
                <tr>
                    <td style="color: var(--wp-text-muted); width: 140px;">Customer TrxID:</td>
                    <td>
                        <span style="font-family: monospace; font-size: 15px; font-weight: 700; color: #0284c7; background: #f0f9ff; padding: 4px 10px; border-radius: 4px; border: 1px solid #bae6fd;">
                            <?php echo htmlspecialchars((string)($attempt['provider_reference'] ?? 'Not submitted'), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <td style="color: var(--wp-text-muted);">Sender Account:</td>
                    <td style="font-weight: 600; color: var(--wp-text-main);">
                        <?php echo htmlspecialchars((string)($attempt['sender_account'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                </tr>
                <tr>
                    <td style="color: var(--wp-text-muted);">Customer Note:</td>
                    <td style="color: #334155; font-style: italic;">
                        <?php echo htmlspecialchars((string)($attempt['customer_notes'] ?? 'No notes provided by customer.'), ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Card 4: Verification Audit Trail -->
        <div class="card" style="padding: 20px;">
            <h3 style="font-size: 14px; font-weight: 700; color: var(--wp-text-main); margin: 0 0 14px 0; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                🛡️ Verification Audit Trail
            </h3>
            <table style="width: 100%; font-size: 13px; line-height: 2;">
                <tr>
                    <td style="color: var(--wp-text-muted); width: 140px;">Verification State:</td>
                    <td style="font-weight: 600; color: var(--wp-text-main);">
                        <?php echo htmlspecialchars($statusLabels[$st] ?? ucfirst($st), ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                </tr>
                <tr>
                    <td style="color: var(--wp-text-muted);">Verified By:</td>
                    <td style="font-weight: 500;">
                        <?php echo htmlspecialchars((string)($attempt['verifier_name'] ?? ($attempt['verified_by'] ? 'User #' . $attempt['verified_by'] : '—')), ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                </tr>
                <tr>
                    <td style="color: var(--wp-text-muted);">Verified At:</td>
                    <td>
                        <?php echo htmlspecialchars((string)($attempt['verified_at'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                </tr>
                <?php if (!empty($attempt['operator_notes'])): ?>
                    <tr>
                        <td style="color: var(--wp-text-muted);">Operator Notes:</td>
                        <td style="color: var(--wp-text-main);">
                            <?php echo htmlspecialchars((string)$attempt['operator_notes'], ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php if (!empty($attempt['error_message'])): ?>
                    <tr>
                        <td style="color: #b91c1c; font-weight: 600;">Rejection Reason:</td>
                        <td style="color: #b91c1c;">
                            <?php echo htmlspecialchars((string)$attempt['error_message'], ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <!-- Actions Section -->
    <?php if ($st === 'awaiting_verification'): ?>
        <?php if ($canVerify): ?>
            <div class="card" style="background: #f8fafc; padding: 24px; margin-bottom: 24px;">
                <h3 style="font-size: 15px; font-weight: 700; color: var(--wp-text-main); margin: 0 0 8px 0;">
                    Authoritative Operator Actions
                </h3>
                <p style="font-size: 13px; color: #475569; margin: 0 0 20px 0; line-height: 1.5;">
                    Carefully check your merchant MFS/Bank statement before taking action. Approving will transition the payment to <strong>SUCCEEDED</strong> and unlock customer entitlements. Rejecting will mark the attempt as <strong>FAILED</strong>.
                </p>

                <div class="fp-action-grid">
                    <!-- Approval Form -->
                    <div class="card" style="background: #fff; border-top: 4px solid #16a34a; padding: 20px;">
                        <h4 style="color: #15803d; font-size: 14px; font-weight: 700; margin: 0 0 12px 0;">
                            ✓ Approve Payment
                        </h4>
                        <form method="POST" action="/admin/page/favorite-pay">
                            <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="attempt_id" value="<?php echo htmlspecialchars((string)$attempt['attempt_id'], ENT_QUOTES, 'UTF-8'); ?>">

                            <div style="margin-bottom: 14px;">
                                <label for="approval_notes" style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 6px;">
                                    Verification Notes (Optional):
                                </label>
                                <textarea id="approval_notes" 
                                          name="operator_notes" 
                                          class="form-control" 
                                          style="width: 100%; min-height: 70px; font-size: 12px;"
                                          placeholder="e.g. Verified TrxID on bKash merchant portal"></textarea>
                            </div>

                            <button type="submit" 
                                    class="btn btn-primary" 
                                    onclick="return confirm('Are you sure you want to APPROVE this payment of <?php echo $formattedAmount; ?>? This action cannot be reversed.');"
                                    style="background: #16a34a; border-color: #15803d; color: #fff; width: 100%; justify-content: center; font-weight: 600;">
                                ✓ Confirm &amp; Approve Payment
                            </button>
                        </form>
                    </div>

                    <!-- Rejection Form -->
                    <div class="card" style="background: #fff; border-top: 4px solid #dc2626; padding: 20px;">
                        <h4 style="color: #b91c1c; font-size: 14px; font-weight: 700; margin: 0 0 12px 0;">
                            ✕ Reject Payment
                        </h4>
                        <form method="POST" action="/admin/page/favorite-pay">
                            <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="attempt_id" value="<?php echo htmlspecialchars((string)$attempt['attempt_id'], ENT_QUOTES, 'UTF-8'); ?>">

                            <div style="margin-bottom: 14px;">
                                <label for="rejection_reason" style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 6px;">
                                    Rejection Reason (Required):
                                </label>
                                <textarea id="rejection_reason" 
                                          name="reason" 
                                          class="form-control" 
                                          required 
                                          style="width: 100%; min-height: 70px; font-size: 12px;"
                                          placeholder="e.g. TrxID not found in statement or incorrect amount"></textarea>
                            </div>

                            <button type="submit" 
                                    class="btn btn-danger" 
                                    onclick="return confirm('Are you sure you want to REJECT this payment? The customer will be informed of the rejection.');"
                                    style="background: #dc2626; border-color: #b91c1c; color: #fff; width: 100%; justify-content: center; font-weight: 600;">
                                ✕ Confirm &amp; Reject Payment
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="notice notice-info" style="margin-bottom: 24px;">
                🔒 You have read-only view permission (<code>favorite_pay.payments.view</code>). Approval and rejection actions require <code>favorite_pay.payments.verify</code> capability.
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="card" style="background: #f8fafc; padding: 18px 22px; margin-bottom: 24px; display: flex; align-items: center; gap: 10px;">
            <span style="font-size: 20px;">ℹ️</span>
            <span style="font-size: 13px; color: #475569;">
                This payment attempt has already reached final status (<strong><?php echo htmlspecialchars($statusLabels[$st] ?? ucfirst($st), ENT_QUOTES, 'UTF-8'); ?></strong>) and can no longer be approved or rejected.
            </span>
        </div>
    <?php endif; ?>
</div>
