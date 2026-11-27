<?php
/**
 * Customer Manual Payment Instructions & TrxID Submission View
 */
$baseMoney = $intent->getBaseAmount();
$chargeMoney = $intent->getChargeAmount();
$instructions = $gatewayConfig['instructions'] ?? 'Please transfer the exact amount and submit your TrxID below.';
$accountNumber = $gatewayConfig['account_number'] ?? '';
$accountName = $gatewayConfig['account_name'] ?? '';
$accountType = $gatewayConfig['account_type'] ?? '';
$bankName = $gatewayConfig['bank_name'] ?? '';
$branchName = $gatewayConfig['branch_name'] ?? '';
$routingNo = $gatewayConfig['routing_no'] ?? '';

$gwId = $gateway ? $gateway->getId() : '';
$senderPlaceholder = match ($gwId) {
    'manual_bkash' => 'e.g. 017XXXXXXXX',
    'manual_nagad' => 'e.g. 018XXXXXXXX',
    'manual_rocket' => 'e.g. 019XXXXXXXX',
    'manual_bank' => 'e.g. Bank Account No / IBAN',
    default => 'e.g. 017XXXXXXXX or Account Number',
};
?>

<div style="max-width: 680px; margin: 0 auto;">
    <div class="fpay-card">
        <div class="fpay-card-header">
            <div>
                <h2 class="fpay-card-title"><?php echo htmlspecialchars($gateway ? $gateway->getTitle() : 'Manual Payment', ENT_QUOTES, 'UTF-8'); ?></h2>
                <p style="margin: 4px 0 0; font-size: 13px; color: var(--muted, #64748b);">
                    Payment Intent ID: <strong><?php echo htmlspecialchars($intent->getId(), ENT_QUOTES, 'UTF-8'); ?></strong>
                </p>
            </div>
            <div style="text-align: right;">
                <span style="font-size: 12px; color: var(--muted, #64748b); display: block;">Total Due:</span>
                <span style="font-size: 22px; font-weight: 800; color: var(--heading, #0f172a);">
                    <?php echo fpay_format_money($baseMoney->getAmount(), $baseMoney->getCurrency()); ?>
                </span>
            </div>
        </div>

        <!-- Instructions Box -->
        <div style="background: var(--surface-muted, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 10px; padding: 18px; margin-bottom: 24px;">
            <h3 style="font-size: 15px; font-weight: 700; margin: 0 0 10px; color: var(--heading, #0f172a);">Payment Details &amp; Instructions</h3>
            
            <?php if (!empty($accountNumber)): ?>
                <div style="margin-bottom: 8px; font-size: 14px;">
                    <span style="color: var(--muted, #64748b);">Account Number:</span>
                    <strong style="color: var(--heading, #0f172a); font-size: 16px; margin-left: 6px; letter-spacing: 0.05em; font-family: monospace;">
                        <?php echo htmlspecialchars($accountNumber, ENT_QUOTES, 'UTF-8'); ?>
                    </strong>
                    <?php if (!empty($accountType)): ?>
                        <span class="fpay-badge fpay-badge-active" style="margin-left: 8px; font-size: 11px;"><?php echo htmlspecialchars($accountType, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($accountName)): ?>
                <div style="margin-bottom: 8px; font-size: 14px;">
                    <span style="color: var(--muted, #64748b);">Account Name:</span>
                    <strong style="color: var(--heading, #0f172a); margin-left: 6px;"><?php echo htmlspecialchars($accountName, ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
            <?php endif; ?>

            <?php if (!empty($bankName)): ?>
                <div style="margin-bottom: 8px; font-size: 14px;">
                    <span style="color: var(--muted, #64748b);">Bank:</span>
                    <strong style="color: var(--heading, #0f172a); margin-left: 6px;"><?php echo htmlspecialchars($bankName, ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php if (!empty($branchName)): ?>
                        <span style="color: var(--muted, #64748b);">(Branch: <?php echo htmlspecialchars($branchName, ENT_QUOTES, 'UTF-8'); ?>)</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($routingNo)): ?>
                <div style="margin-bottom: 8px; font-size: 14px;">
                    <span style="color: var(--muted, #64748b);">Routing No:</span>
                    <strong style="color: var(--heading, #0f172a); margin-left: 6px;"><?php echo htmlspecialchars($routingNo, ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
            <?php endif; ?>

            <div style="margin-top: 12px; font-size: 14px; color: var(--text, #334155); line-height: 1.5; border-top: 1px dashed var(--border-strong, #cbd5e1); padding-top: 12px;">
                <?php echo nl2br(htmlspecialchars($instructions, ENT_QUOTES, 'UTF-8')); ?>
            </div>
        </div>

        <!-- Submission Form -->
        <form action="/account/recharge/manual" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="intent_id" value="<?php echo htmlspecialchars($intent->getId(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="intent" value="<?php echo htmlspecialchars($intent->getId(), ENT_QUOTES, 'UTF-8'); ?>">

            <div style="margin-bottom: 20px;">
                <label for="trx_id" style="display: block; font-weight: 700; font-size: 14px; margin-bottom: 6px; color: var(--heading, #0f172a);">
                    Transaction Reference / TrxID <span style="color: var(--danger, #dc2626);">*</span>
                </label>
                <input type="text" 
                       name="trx_id" 
                       id="trx_id" 
                       required 
                       placeholder="e.g. 9J4K2L8M1N" 
                       style="width: 100%; padding: 12px; font-size: 15px; font-family: monospace; border: 1px solid var(--border-strong, #cbd5e1); background: var(--surface, #ffffff); color: var(--text, #1e293b); border-radius: 6px; box-sizing: border-box;"
                       autocomplete="off">
                <small style="color: var(--muted, #64748b); font-size: 12px; margin-top: 4px; display: block;">
                    Enter the exact transaction ID provided in your SMS or deposit receipt.
                </small>
            </div>

            <div style="margin-bottom: 20px;">
                <label for="sender_number" style="display: block; font-weight: 700; font-size: 14px; margin-bottom: 6px; color: var(--heading, #0f172a);">
                    Sender Mobile / Account Number <span style="color: var(--danger, #dc2626);">*</span>
                </label>
                <input type="text" 
                       name="sender_number" 
                       id="sender_number" 
                       required 
                       placeholder="<?php echo htmlspecialchars($senderPlaceholder, ENT_QUOTES, 'UTF-8'); ?>" 
                       style="width: 100%; padding: 12px; font-size: 15px; border: 1px solid var(--border-strong, #cbd5e1); background: var(--surface, #ffffff); color: var(--text, #1e293b); border-radius: 6px; box-sizing: border-box;"
                       autocomplete="off">
                <small style="color: var(--muted, #64748b); font-size: 12px; margin-top: 4px; display: block;">
                    Enter the mobile or bank account number used to make this transfer.
                </small>
            </div>

            <div style="margin-bottom: 20px;">
                <label for="payment_proof" style="display: block; font-weight: 700; font-size: 14px; margin-bottom: 6px; color: var(--heading, #0f172a);">
                    Payment Proof / Attached Document (Optional)
                </label>
                <input type="file" 
                       name="payment_proof" 
                       id="payment_proof" 
                       accept=".jpg,.jpeg,.png,.webp,.pdf" 
                       style="width: 100%; padding: 10px; font-size: 14px; border: 1px solid var(--border-strong, #cbd5e1); border-radius: 6px; box-sizing: border-box; background: var(--surface, #ffffff); color: var(--text, #1e293b);">
                <small style="color: var(--muted, #64748b); font-size: 12px; margin-top: 4px; display: block;">
                    Supported formats: JPG, PNG, WEBP, PDF (Max 10MB). Upload payment screenshot or deposit slip.
                </small>
            </div>

            <div style="margin-bottom: 24px;">
                <label for="notes" style="display: block; font-weight: 700; font-size: 14px; margin-bottom: 6px; color: var(--heading, #0f172a);">
                    Additional Notes / Remarks (Optional)
                </label>
                <textarea name="notes" 
                          id="notes" 
                          rows="2" 
                          placeholder="Any extra information regarding this payment..." 
                          style="width: 100%; padding: 10px 12px; font-size: 14px; border: 1px solid var(--border-strong, #cbd5e1); background: var(--surface, #ffffff); color: var(--text, #1e293b); border-radius: 6px; box-sizing: border-box;"></textarea>
            </div>

            <div style="display: flex; gap: 12px;">
                <button type="submit" class="fpay-btn fpay-btn-primary" style="flex: 1; justify-content: center; padding: 12px;">
                    Submit for Verification &rarr;
                </button>
                <a href="/account/wallet" class="fpay-btn fpay-btn-secondary" style="padding: 12px;">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>
