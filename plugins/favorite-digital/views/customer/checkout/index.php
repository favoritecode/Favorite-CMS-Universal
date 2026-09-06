<?php
/**
 * Customer Checkout View
 *
 * @var object $order
 * @var string $walletBalance
 * @var string $remainingPayable
 * @var array  $availableGateways
 * @var int    $userId
 * @var string|null $flashError
 * @var string|null $flashSuccess
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout — Order #<?= htmlspecialchars($order->order_number, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, sans-serif; background: #f8fafc; color: #1e293b; margin: 0; padding: 24px; }
        .checkout-container { max-width: 800px; margin: 0 auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 32px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        h1 { font-size: 24px; margin-top: 0; margin-bottom: 20px; color: #0f172a; border-bottom: 2px solid #f1f5f9; padding-bottom: 12px; }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
        .alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
        .order-summary { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 24px; }
        .summary-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 14px; }
        .summary-row.total { font-size: 18px; font-weight: 700; color: #0f172a; border-top: 1px solid #cbd5e1; padding-top: 12px; margin-top: 12px; }
        .wallet-badge { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 12px 16px; border-radius: 8px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; font-weight: 600; }
        .payment-methods { margin-bottom: 24px; }
        .method-card { border: 2px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 12px; cursor: pointer; transition: border-color 0.2s; }
        .method-card:hover { border-color: #94a3b8; }
        .method-card.selected { border-color: #2563eb; background: #eff6ff; }
        .method-card label { display: flex; align-items: center; gap: 12px; cursor: pointer; font-weight: 600; font-size: 15px; }
        .method-desc { font-size: 13px; color: #64748b; margin-top: 4px; margin-left: 28px; font-weight: normal; }
        .field-group { margin-top: 16px; margin-left: 28px; }
        .field-group label { font-size: 13px; color: #475569; display: block; margin-bottom: 6px; }
        .field-group input, .field-group select { width: 100%; max-width: 320px; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; }
        .btn-pay { width: 100%; padding: 14px; background: #2563eb; color: #ffffff; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: background 0.2s; }
        .btn-pay:hover { background: #1d4ed8; }
        .items-list { margin-top: 12px; border-top: 1px dashed #cbd5e1; padding-top: 12px; }
        .item-row { display: flex; justify-content: space-between; font-size: 13px; color: #475569; margin-bottom: 4px; }
    </style>
</head>
<body>

<div class="checkout-container">
    <h1>Complete Checkout</h1>

    <?php if (!empty($flashError)): ?>
        <div class="alert alert-error"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if (!empty($flashSuccess)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="wallet-badge">
        <span>Customer Wallet Balance:</span>
        <span>৳<?= htmlspecialchars($walletBalance, ENT_QUOTES, 'UTF-8') ?> BDT</span>
    </div>

    <div class="order-summary">
        <div class="summary-row">
            <span>Order Number:</span>
            <strong>#<?= htmlspecialchars($order->order_number, ENT_QUOTES, 'UTF-8') ?></strong>
        </div>
        <div class="summary-row">
            <span>Subtotal:</span>
            <span>৳<?= htmlspecialchars($order->subtotal_amount, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php if ((float)$order->discount_amount > 0): ?>
            <div class="summary-row" style="color: #16a34a;">
                <span>Discount:</span>
                <span>-৳<?= htmlspecialchars($order->discount_amount, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>
        <div class="summary-row total">
            <span>Payable Amount:</span>
            <span>৳<?= htmlspecialchars($remainingPayable, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($order->currency ?? 'BDT', ENT_QUOTES, 'UTF-8') ?></span>
        </div>

        <?php if (!empty($order->items)): ?>
            <div class="items-list">
                <?php foreach ($order->items as $item): ?>
                    <div class="item-row">
                        <span><?= htmlspecialchars($item->snapshot['title'] ?? $item->title_snapshot ?? 'Item', ENT_QUOTES, 'UTF-8') ?> (x<?= (int)($item->quantity ?? $item->snapshot['quantity'] ?? 1) ?>)</span>
                        <span>৳<?= htmlspecialchars((string)$item->final_price, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <form method="POST" action="/checkout/<?= urlencode($order->order_number) ?>" id="checkoutForm">
        <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="pay">

        <?php if ((float)$remainingPayable <= 0.00): ?>
            <div class="alert alert-success">This is a free order. No payment gateway or wallet debit is required.</div>
            <button type="submit" class="btn-pay">Complete Order</button>
        <?php else: ?>
            <div class="payment-methods">
                <!-- Option 1: Wallet -->
                <?php $canUseWallet = (float)$walletBalance >= (float)$remainingPayable; ?>
                <div class="method-card <?= $canUseWallet ? 'selected' : '' ?>" id="card_wallet">
                    <label>
                        <input type="radio" name="payment_method" value="wallet" <?= $canUseWallet ? 'checked' : 'disabled' ?> onchange="onMethodChange('wallet')">
                        <span>Pay with Wallet Balance</span>
                    </label>
                    <div class="method-desc">
                        Deduct full ৳<?= htmlspecialchars($remainingPayable, ENT_QUOTES, 'UTF-8') ?> from your available ৳<?= htmlspecialchars($walletBalance, ENT_QUOTES, 'UTF-8') ?> balance.
                        <?php if (!$canUseWallet): ?>
                            <span style="color: #dc2626;">(Insufficient balance)</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Option 2: Favorite Pay -->
                <div class="method-card <?= !$canUseWallet ? 'selected' : '' ?>" id="card_favpay">
                    <label>
                        <input type="radio" name="payment_method" value="favorite_pay" <?= !$canUseWallet ? 'checked' : '' ?> onchange="onMethodChange('favorite_pay')">
                        <span>Online / Mobile Payment (Favorite Pay)</span>
                    </label>
                    <div class="method-desc">bKash, Nagad, Rocket, Bank Transfer, or Binance Pay.</div>
                    <div class="field-group" id="gateway_selector" style="<?= $canUseWallet ? 'display: none;' : '' ?>">
                        <label for="gateway_id">Select Payment Gateway:</label>
                        <select name="gateway_id" id="gateway_id">
                            <?php if (!empty($availableGateways)): ?>
                                <?php foreach ($availableGateways as $gw): ?>
                                    <option value="<?= htmlspecialchars($gw['id'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($gw['title'], ENT_QUOTES, 'UTF-8') ?> <?= !empty($gw['is_manual']) ? '(Manual)' : '(Instant)' ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="manual_bkash">bKash Personal / Manual</option>
                                <option value="manual_nagad">Nagad Personal / Manual</option>
                                <option value="manual_bank">Bank Wire Transfer</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

                <!-- Option 3: Mixed (Wallet + Favorite Pay) -->
                <?php if ((float)$walletBalance > 0 && (float)$walletBalance < (float)$remainingPayable): ?>
                    <div class="method-card" id="card_mixed">
                        <label>
                            <input type="radio" name="payment_method" value="mixed" onchange="onMethodChange('mixed')">
                            <span>Mixed: Wallet + Favorite Pay</span>
                        </label>
                        <div class="method-desc">Use all or part of your wallet balance and pay the remaining with a payment gateway.</div>
                        <div class="field-group" id="mixed_fields" style="display: none;">
                            <label for="wallet_amount">Wallet Amount to Use (max ৳<?= htmlspecialchars($walletBalance, ENT_QUOTES, 'UTF-8') ?>):</label>
                            <input type="number" step="0.01" min="1.00" max="<?= htmlspecialchars($walletBalance, ENT_QUOTES, 'UTF-8') ?>" name="wallet_amount" id="wallet_amount" value="<?= htmlspecialchars($walletBalance, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn-pay">Proceed to Payment</button>
        <?php endif; ?>
    </form>
</div>

<script>
function onMethodChange(method) {
    document.querySelectorAll('.method-card').forEach(function(el) {
        el.classList.remove('selected');
    });

    var card = document.getElementById('card_' + (method === 'favorite_pay' ? 'favpay' : method));
    if (card) {
        card.classList.add('selected');
    }

    var gwSelect = document.getElementById('gateway_selector');
    var mixedFields = document.getElementById('mixed_fields');

    if (method === 'wallet') {
        if (gwSelect) gwSelect.style.display = 'none';
        if (mixedFields) mixedFields.style.display = 'none';
    } else if (method === 'favorite_pay') {
        if (gwSelect) gwSelect.style.display = 'block';
        if (mixedFields) mixedFields.style.display = 'none';
    } else if (method === 'mixed') {
        if (gwSelect) gwSelect.style.display = 'block';
        if (mixedFields) mixedFields.style.display = 'block';
    }
}
</script>

</body>
</html>
