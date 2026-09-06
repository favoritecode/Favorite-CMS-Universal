<?php
/**
 * Customer Order Receipt View
 */
?>
<div class="container customer-order-detail" style="max-width: 800px; margin: 30px auto; padding: 25px; border: 1px solid #e0e0e0; border-radius: 8px;">
    <a href="/account/orders" style="display: inline-block; margin-bottom: 15px;">&larr; Back to My Orders</a>
    
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #333; padding-bottom: 15px;">
        <div>
            <h1 style="margin: 0; font-size: 24px;">Order Receipt</h1>
            <p style="margin: 5px 0 0 0; color: #666;">Order #<?= htmlspecialchars((string)$order->order_number, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <div style="text-align: right;">
            <p style="margin: 0; font-size: 14px; color: #666;">Placed on: <?= htmlspecialchars((string)$order->created_at, ENT_QUOTES, 'UTF-8') ?></p>
            <p style="margin: 5px 0 0 0;">
                <span class="badge badge-<?= htmlspecialchars((string)$order->status, ENT_QUOTES, 'UTF-8') ?>"><?= strtoupper(htmlspecialchars((string)$order->status, ENT_QUOTES, 'UTF-8')) ?></span>
            </p>
        </div>
    </div>

    <table style="width: 100%; border-collapse: collapse; margin-top: 20px;">
        <thead>
            <tr style="border-bottom: 1px solid #ddd; text-align: left;">
                <th style="padding: 10px;">Item</th>
                <th style="padding: 10px;">Type</th>
                <th style="padding: 10px; text-align: right;">Price</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($order->items as $item): ?>
                <?php $snapshot = $item->snapshot ?? []; ?>
                <tr style="border-bottom: 1px solid #eee;">
                    <td style="padding: 10px;">
                        <strong><?= htmlspecialchars((string)($snapshot['title'] ?? 'Product #' . $item->product_id), ENT_QUOTES, 'UTF-8') ?></strong>
                    </td>
                    <td style="padding: 10px;"><?= strtoupper(htmlspecialchars((string)$item->product_type, ENT_QUOTES, 'UTF-8')) ?></td>
                    <td style="padding: 10px; text-align: right; font-weight: bold;"><?= htmlspecialchars((string)$item->currency, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars((string)$item->final_price, ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div style="margin-top: 20px; text-align: right; border-top: 2px solid #ddd; padding-top: 15px;">
        <p style="margin: 4px 0;">Subtotal: <?= htmlspecialchars((string)$order->currency, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars((string)$order->subtotal_amount, ENT_QUOTES, 'UTF-8') ?></p>
        <?php if ((float)$order->discount_amount > 0): ?>
            <p style="margin: 4px 0; color: #c00;">Discount: -<?= htmlspecialchars((string)$order->currency, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars((string)$order->discount_amount, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <h2 style="margin: 8px 0; font-size: 20px;">Total: <?= htmlspecialchars((string)$order->currency, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars((string)$order->total_amount, ENT_QUOTES, 'UTF-8') ?></h2>
    </div>
</div>
