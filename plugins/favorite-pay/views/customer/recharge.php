<?php
/**
 * Customer Recharge View
 */
?>
<div style="max-width: 680px; margin: 0 auto;">
    <div class="fpay-card">
        <div class="fpay-card-header">
            <h2 class="fpay-card-title">Add Funds to Your Wallet</h2>
        </div>

        <?php if (!empty($isSuspended)): ?>
            <div class="fpay-alert fpay-alert-warning" style="margin-bottom: 0;">
                Your account is currently suspended. Recharging balance is disabled.
            </div>
        <?php else: ?>
            <form action="/account/recharge" method="POST" id="fpay-recharge-form">
                <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                <!-- Amount Input -->
                <div style="margin-bottom: 24px;">
                    <label for="recharge_amount" style="display: block; font-weight: 700; font-size: 15px; margin-bottom: 8px; color: #0f172a;">
                        Recharge Amount (<?php echo htmlspecialchars($primaryCurrency, ENT_QUOTES, 'UTF-8'); ?>)
                    </label>
                    <div style="position: relative; display: flex; align-items: center;">
                        <span style="position: absolute; left: 16px; font-size: 20px; font-weight: 700; color: #64748b;">
                            <?php echo match(strtoupper($primaryCurrency)) { 'BDT' => '৳', 'USD' => '$', 'EUR' => '€', default => $primaryCurrency }; ?>
                        </span>
                        <input type="number" 
                               name="amount" 
                               id="recharge_amount" 
                               step="any" 
                               min="1" 
                               required 
                               placeholder="e.g. 500" 
                               style="width: 100%; padding: 14px 14px 14px 44px; font-size: 20px; font-weight: 700; border: 2px solid #cbd5e1; border-radius: 8px; outline: none; box-sizing: border-box;"
                               onfocus="this.style.borderColor='#2563eb'"
                               onblur="this.style.borderColor='#cbd5e1'">
                    </div>
                    <small style="display: block; margin-top: 6px; color: #64748b; font-size: 13px;">
                        Funds are credited strictly in your wallet's primary accounting currency (<strong><?php echo htmlspecialchars($primaryCurrency, ENT_QUOTES, 'UTF-8'); ?></strong>).
                    </small>
                </div>

                <!-- Payment Method Selection -->
                <div style="margin-bottom: 28px;">
                    <label style="display: block; font-weight: 700; font-size: 15px; margin-bottom: 12px; color: #0f172a;">
                        Select Payment Method
                    </label>

                    <?php if (empty($gateways)): ?>
                        <div class="fpay-alert fpay-alert-warning">
                            No payment gateways are currently available. Please contact administrator.
                        </div>
                    <?php else: ?>
                        <div style="display: flex; flex-direction: column; gap: 12px;">
                            <?php $first = true; foreach ($gateways as $id => $gw): ?>
                                <label style="display: flex; align-items: flex-start; gap: 14px; padding: 16px; border: 2px solid #e2e8f0; border-radius: 10px; cursor: pointer; transition: all 0.15s ease;"
                                       class="fpay-gateway-option"
                                       onclick="document.querySelectorAll('.fpay-gateway-option').forEach(el => el.style.borderColor='#e2e8f0'); this.style.borderColor='#2563eb';">
                                    <input type="radio" 
                                           name="gateway_id" 
                                           value="<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>"
                                           <?php if ($first) { echo 'checked'; $first = false; } ?>
                                           style="margin-top: 4px; accent-color: #2563eb;">
                                    <div style="flex: 1;">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                            <strong style="font-size: 15px; color: #0f172a;">
                                                <?php echo htmlspecialchars($gw['title'], ENT_QUOTES, 'UTF-8'); ?>
                                            </strong>
                                            <?php if (!empty($gw['is_manual'])): ?>
                                                <span class="fpay-badge fpay-badge-active" style="font-size: 11px;">Manual Verification</span>
                                            <?php else: ?>
                                                <span class="fpay-badge fpay-badge-success" style="font-size: 11px;">Instant Automated</span>
                                            <?php endif; ?>
                                        </div>
                                        <p style="margin: 0; font-size: 13px; color: #64748b; line-height: 1.4;">
                                            <?php echo htmlspecialchars($gw['description'], ENT_QUOTES, 'UTF-8'); ?>
                                        </p>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <button type="submit" class="fpay-btn fpay-btn-primary" style="width: 100%; justify-content: center; padding: 14px; font-size: 16px;" <?php if (empty($gateways)) echo 'disabled'; ?>>
                        Proceed to Payment &rarr;
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
