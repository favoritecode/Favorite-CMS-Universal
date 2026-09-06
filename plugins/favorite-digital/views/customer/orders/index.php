<?php
/**
 * Customer "My Orders" View
 */
?>
<div class="container customer-orders-container" style="max-width: 900px; margin: 30px auto; padding: 20px;">
    <h1>My Orders</h1>
    <p>View your purchase history and order receipts.</p>

    <?php if (empty($orders)): ?>
        <div class="alert alert-info" style="padding: 15px; background: #eef; border-radius: 4px;">
            You have not placed any orders yet.
        </div>
    <?php else: ?>
        <table class="table" style="width: 100%; border-collapse: collapse; margin-top: 15px;">
            <thead>
                <tr style="border-bottom: 2px solid #ccc; text-align: left;">
                    <th style="padding: 10px;">Order #</th>
                    <th style="padding: 10px;">Date</th>
                    <th style="padding: 10px;">Status</th>
                    <th style="padding: 10px;">Payment</th>
                    <th style="padding: 10px; text-align: right;">Total</th>
                    <th style="padding: 10px; text-align: center;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 10px;"><strong><?= htmlspecialchars((string)$order->order_number, ENT_QUOTES, 'UTF-8') ?></strong></td>
                        <td style="padding: 10px;"><?= htmlspecialchars(substr((string)$order->created_at, 0, 10), ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="padding: 10px;"><span class="badge"><?= strtoupper(htmlspecialchars((string)$order->status, ENT_QUOTES, 'UTF-8')) ?></span></td>
                        <td style="padding: 10px;"><span class="badge"><?= strtoupper(htmlspecialchars((string)$order->payment_status, ENT_QUOTES, 'UTF-8')) ?></span></td>
                        <td style="padding: 10px; text-align: right; font-weight: bold;"><?= htmlspecialchars((string)$order->currency, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars((string)$order->total_amount, ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="padding: 10px; text-align: center;">
                            <a href="/account/orders/<?= urlencode((string)$order->order_number) ?>" class="btn btn-sm btn-primary">View Receipt</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
