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

$badgeStyle = match ($st) {
    'awaiting_verification' => 'background: #fef3c7; color: #92400e; border: 1px solid #fcd34d;',
    'succeeded'             => 'background: #dcfce7; color: #166534; border: 1px solid #86efac;',
    'failed'                => 'background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5;',
    default                 => 'background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;',
};
?>
<div class="favorite-pay-review" style="max-width: 900px; margin: 0 auto;">
    <!-- Breadcrumb -->
    <div style="margin-bottom: 16px;">
        <a href="/admin/page/favorite-pay" style="color: #2271b1; text-decoration: none; font-size: 13px; font-weight: 500;">
            &larr; Back to Verification Queue
        </a>
    </div>

    <!-- Review Header -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; border-bottom: 1px solid #e2e8f0; padding-bottom: 16px;">
        <div>
            <h1 style="font-size: 22px; font-weight: 700; color: #0f172a; margin: 0 0 6px 0;">
                Review Payment Attempt
            </h1>
            <div style="font-family: monospace; font-size: 13px; color: #64748b;">
                Attempt ID: <strong><?php echo htmlspecialchars((string)$attempt['attempt_id'], ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
        </div>
        <div>
            <span style="display: inline-block; padding: 5px 12px; border-radius: 16px; font-size: 12px; font-weight: 700; <?php echo $badgeStyle; ?>">
                ● <?php echo htmlspecialchars($statusLabels[$st] ?? ucfirst($st), ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </div>
    </div>

    <!-- Review Cards Grid -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
        
        <!-- Card 1: Transaction & Attempt Metadata -->
        <div style="background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 20px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
            <h3 style="font-size: 14px; font-weight: 700; color: #334155; margin: 0 0 14px 0; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px;">
                💳 Transaction Overview
            </h3>
            <table style="width: 100%; font-size: 13px; line-height: 1.8;">
                <tr>
                    <td style="color: #64748b; width: 140px;">Transaction ID:</td>
                    <td style="font-family: monospace; font-weight: 600; color: #0f172a;">
                        <?php echo htmlspecialchars((string)$attempt['transaction_id'], ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                </tr>
                <tr>
                    <td style="color: #64748b;">Gateway:</td>
                    <td>
                        <span style="display: inline-block; padding: 1px 8px; background: #e2e8f0; border-radius: 3px; font-size: 11px; font-weight: 600;">
                            <?php echo htmlspecialchars((string)$attempt['gateway_id'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <td style="color: #64748b;">Amount:</td>
                    <td style="font-size: 16px; font-weight: 700; color: #0f172a;">
                        <?php echo $formattedAmount; ?>
                    </td>
                </tr>
                <tr>
                    <td style="color: #64748b;">Source Plugin:</td>
                    <td><?php echo htmlspecialchars((string)($attempt['source_plugin'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <tr>
                    <td style="color: #64748b;">Source Reference:</td>
                    <td><?php echo htmlspecialchars((string)($attempt['source_reference'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <tr>
                    <td style="color: #64748b;">Submitted At:</td>
                    <td><?php echo htmlspecialchars((string)($attempt['created_at'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
            </table>
        </div>

        <!-- Card 2: Customer Profile -->
        <div style="background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 20px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
            <h3 style="font-size: 14px; font-weight: 700; color: #334155; margin: 0 0 14px 0; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px;">
                👤 Customer Details
            </h3>
            <?php if (!empty($attempt['customer'])): ?>
                <?php $cust = $attempt['customer']; ?>
                <table style="width: 100%; font-size: 13px; line-height: 1.8;">
                    <tr>
                        <td style="color: #64748b; width: 120px;">User ID:</td>
                        <td style="font-weight: 600;">#<?php echo (int)$cust['id']; ?></td>
                    </tr>
                    <tr>
                        <td style="color: #64748b;">Username:</td>
                        <td style="font-weight: 600; color: #0f172a;">
                            <?php echo htmlspecialchars($cust['username'], ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="color: #64748b;">Email:</td>
                        <td>
                            <a href="mailto:<?php echo htmlspecialchars($cust['email'], ENT_QUOTES, 'UTF-8'); ?>" style="color: #2271b1;">
                                <?php echo htmlspecialchars($cust['email'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </td>
                    </tr>
                </table>
            <?php else: ?>
                <div style="color: #64748b; font-size: 13px; line-height: 1.6;">
                    <?php if (!empty($attempt['user_id'])): ?>
                        Associated Core User ID: <strong>#<?php echo (int)$attempt['user_id']; ?></strong>
                    <?php else: ?>
                        Guest checkout (No registered Core user ID).
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Card 3: Submitted Manual Bangladesh Details -->
        <div style="background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 20px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
            <h3 style="font-size: 14px; font-weight: 700; color: #334155; margin: 0 0 14px 0; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px;">
                🇧🇩 Manual Payment Proof
            </h3>
            <table style="width: 100%; font-size: 13px; line-height: 1.8;">
                <tr>
                    <td style="color: #64748b; width: 140px;">Customer TrxID:</td>
                    <td>
                        <span style="font-family: monospace; font-size: 15px; font-weight: 700; color: #0369a1; background: #f0f9ff; padding: 3px 8px; border-radius: 4px; border: 1px solid #bae6fd;">
                            <?php echo htmlspecialchars((string)($attempt['provider_reference'] ?? 'Not submitted'), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <td style="color: #64748b;">Sender Account:</td>
                    <td style="font-weight: 600; color: #0f172a;">
                        <?php echo htmlspecialchars((string)($attempt['sender_account'] ?? '&mdash;'), ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                </tr>
                <tr>
                    <td style="color: #64748b;">Customer Note:</td>
                    <td style="color: #334155; font-style: italic;">
                        <?php echo htmlspecialchars((string)($attempt['customer_notes'] ?? 'No notes provided by customer.'), ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Card 4: Verification Audit Trail -->
        <div style="background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 20px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
            <h3 style="font-size: 14px; font-weight: 700; color: #334155; margin: 0 0 14px 0; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px;">
                🛡️ Verification Audit Trail
            </h3>
            <table style="width: 100%; font-size: 13px; line-height: 1.8;">
                <tr>
                    <td style="color: #64748b; width: 140px;">Verification State:</td>
                    <td style="font-weight: 600; color: #0f172a;">
                        <?php echo htmlspecialchars($statusLabels[$st] ?? ucfirst($st), ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                </tr>
                <tr>
                    <td style="color: #64748b;">Verified By:</td>
                    <td style="font-weight: 500;">
                        <?php echo htmlspecialchars((string)($attempt['verifier_name'] ?? ($attempt['verified_by'] ? 'User #' . $attempt['verified_by'] : '&mdash;')), ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                </tr>
                <tr>
                    <td style="color: #64748b;">Verified At:</td>
                    <td>
                        <?php echo htmlspecialchars((string)($attempt['verified_at'] ?? '&mdash;'), ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                </tr>
                <?php if (!empty($attempt['operator_notes'])): ?>
                    <tr>
                        <td style="color: #64748b;">Operator Notes:</td>
                        <td style="color: #0f172a;">
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
            <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <h3 style="font-size: 15px; font-weight: 700; color: #0f172a; margin: 0 0 16px 0;">
                    Authoritative Operator Actions
                </h3>
                <p style="font-size: 13px; color: #475569; margin: 0 0 20px 0; line-height: 1.5;">
                    Carefully check your merchant MFS/Bank statement before taking action. Approving will transition the payment to <strong>SUCCEEDED</strong> and unlock customer entitlements. Rejecting will mark the attempt as <strong>FAILED</strong>.
                </p>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                    <!-- Approval Form -->
                    <div style="background: #fff; border: 1px solid #86efac; border-radius: 6px; padding: 18px;">
                        <h4 style="color: #15803d; font-size: 14px; font-weight: 700; margin: 0 0 10px 0;">
                            ✓ Approve Payment
                        </h4>
                        <form method="POST" action="/admin/page/favorite-pay">
                            <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="attempt_id" value="<?php echo htmlspecialchars((string)$attempt['attempt_id'], ENT_QUOTES, 'UTF-8'); ?>">

                            <div style="margin-bottom: 12px;">
                                <label for="approval_notes" style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 4px;">
                                    Verification Notes (Optional):
                                </label>
                                <textarea id="approval_notes" 
                                          name="operator_notes" 
                                          class="form-control" 
                                          style="width: 100%; min-height: 60px; font-size: 12px; padding: 6px 10px; border: 1px solid #c3c4c7; border-radius: 4px;"
                                          placeholder="e.g. Verified TrxID on bKash merchant portal"></textarea>
                            </div>

                            <button type="submit" 
                                    class="btn btn-primary" 
                                    onclick="return confirm('Are you sure you want to APPROVE this payment of <?php echo $formattedAmount; ?>? This action cannot be reversed.');"
                                    style="background: #16a34a; border-color: #15803d; color: #fff; padding: 7px 16px; font-weight: 600; font-size: 13px; border-radius: 4px; cursor: pointer;">
                                ✓ Confirm &amp; Approve Payment
                            </button>
                        </form>
                    </div>

                    <!-- Rejection Form -->
                    <div style="background: #fff; border: 1px solid #fca5a5; border-radius: 6px; padding: 18px;">
                        <h4 style="color: #b91c1c; font-size: 14px; font-weight: 700; margin: 0 0 10px 0;">
                            ✕ Reject Payment
                        </h4>
                        <form method="POST" action="/admin/page/favorite-pay">
                            <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="attempt_id" value="<?php echo htmlspecialchars((string)$attempt['attempt_id'], ENT_QUOTES, 'UTF-8'); ?>">

                            <div style="margin-bottom: 12px;">
                                <label for="rejection_reason" style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 4px;">
                                    Rejection Reason (Required):
                                </label>
                                <textarea id="rejection_reason" 
                                          name="reason" 
                                          class="form-control" 
                                          required 
                                          style="width: 100%; min-height: 60px; font-size: 12px; padding: 6px 10px; border: 1px solid #c3c4c7; border-radius: 4px;"
                                          placeholder="e.g. TrxID not found in statement or incorrect amount"></textarea>
                            </div>

                            <button type="submit" 
                                    class="btn btn-danger" 
                                    onclick="return confirm('Are you sure you want to REJECT this payment? The customer will be informed of the rejection.');"
                                    style="background: #dc2626; border-color: #b91c1c; color: #fff; padding: 7px 16px; font-weight: 600; font-size: 13px; border-radius: 4px; cursor: pointer;">
                                ✕ Confirm &amp; Reject Payment
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div style="background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 6px; padding: 16px; color: #475569; font-size: 13px;">
                🔒 You have read-only view permission (<code>favorite_pay.payments.view</code>). Approval and rejection actions require <code>favorite_pay.payments.verify</code> capability.
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 16px; color: #475569; font-size: 13px; display: flex; align-items: center; gap: 8px;">
            <span>ℹ️</span>
            <span>This payment attempt has already reached final status (<strong><?php echo htmlspecialchars($statusLabels[$st] ?? ucfirst($st), ENT_QUOTES, 'UTF-8'); ?></strong>) and can no longer be approved or rejected.</span>
        </div>
    <?php endif; ?>
</div>
