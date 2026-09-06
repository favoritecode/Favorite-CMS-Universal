<?php
/**
 * Admin Orders List View
 */
?>
<div class="wrap">
    <h1 class="wp-heading-inline">Favorite Digital — Orders</h1>
    <hr class="wp-header-end">

    <?php if (!empty($flashSuccess)): ?>
        <div class="notice notice-success is-dismissible"><p><?= htmlspecialchars((string)$flashSuccess, ENT_QUOTES, 'UTF-8') ?></p></div>
    <?php endif; ?>
    <?php if (!empty($flashError)): ?>
        <div class="notice notice-error is-dismissible"><p><?= htmlspecialchars((string)$flashError, ENT_QUOTES, 'UTF-8') ?></p></div>
    <?php endif; ?>

    <form method="get" action="/admin/page/favorite-digital-orders" style="margin: 15px 0; display: flex; gap: 10px; align-items: center;">
        <input type="text" name="search" placeholder="Search Order # or Notes..." value="<?= htmlspecialchars((string)($search ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="regular-text">
        <select name="status">
            <option value="all" <?= ($statusFilter === 'all') ? 'selected' : '' ?>>All Order Statuses</option>
            <option value="pending" <?= ($statusFilter === 'pending') ? 'selected' : '' ?>>Pending</option>
            <option value="processing" <?= ($statusFilter === 'processing') ? 'selected' : '' ?>>Processing</option>
            <option value="completed" <?= ($statusFilter === 'completed') ? 'selected' : '' ?>>Completed</option>
            <option value="failed" <?= ($statusFilter === 'failed') ? 'selected' : '' ?>>Failed</option>
            <option value="cancelled" <?= ($statusFilter === 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
            <option value="refunded" <?= ($statusFilter === 'refunded') ? 'selected' : '' ?>>Refunded</option>
        </select>
        <select name="payment_status">
            <option value="all" <?= ($paymentFilter === 'all') ? 'selected' : '' ?>>All Payment Statuses</option>
            <option value="unpaid" <?= ($paymentFilter === 'unpaid') ? 'selected' : '' ?>>Unpaid</option>
            <option value="pending" <?= ($paymentFilter === 'pending') ? 'selected' : '' ?>>Pending</option>
            <option value="partially_paid" <?= ($paymentFilter === 'partially_paid') ? 'selected' : '' ?>>Partially Paid</option>
            <option value="paid" <?= ($paymentFilter === 'paid') ? 'selected' : '' ?>>Paid</option>
            <option value="failed" <?= ($paymentFilter === 'failed') ? 'selected' : '' ?>>Failed</option>
            <option value="refunded" <?= ($paymentFilter === 'refunded') ? 'selected' : '' ?>>Refunded</option>
        </select>
        <select name="fulfillment_status">
            <option value="all" <?= ($fulfillmentFilter === 'all') ? 'selected' : '' ?>>All Fulfillment Statuses</option>
            <option value="unfulfilled" <?= ($fulfillmentFilter === 'unfulfilled') ? 'selected' : '' ?>>Unfulfilled</option>
            <option value="partially_fulfilled" <?= ($fulfillmentFilter === 'partially_fulfilled') ? 'selected' : '' ?>>Partially Fulfilled</option>
            <option value="fulfilled" <?= ($fulfillmentFilter === 'fulfilled') ? 'selected' : '' ?>>Fulfilled</option>
            <option value="cancelled" <?= ($fulfillmentFilter === 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
        </select>
        <button type="submit" class="button">Filter</button>
        <?php if (!empty($search) || $statusFilter !== 'all' || $paymentFilter !== 'all' || $fulfillmentFilter !== 'all'): ?>
            <a href="/admin/page/favorite-digital-orders" class="button">Reset</a>
        <?php endif; ?>
    </form>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width: 180px;">Order #</th>
                <th style="width: 100px;">Customer ID</th>
                <th>Order Status</th>
                <th>Payment Status</th>
                <th>Fulfillment Status</th>
                <th style="text-align: right; width: 120px;">Total</th>
                <th style="width: 150px;">Created Date</th>
                <th style="width: 100px; text-align: center;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($orders)): ?>
                <tr>
                    <td colspan="8" style="text-align: center; padding: 20px; color: #666;">No orders found matching criteria.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td>
                            <strong><a href="/admin/page/favorite-digital-orders?action=view&id=<?= (int)$order->id ?>"><?= htmlspecialchars((string)$order->order_number, ENT_QUOTES, 'UTF-8') ?></a></strong>
                            <?php if (!empty($order->notes)): ?>
                                <div style="font-size: 11px; color: #666; font-style: italic;"><?= htmlspecialchars((string)$order->notes, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </td>
                        <td>User #<?= (int)$order->user_id ?></td>
                        <td><span class="badge badge-<?= htmlspecialchars((string)$order->status, ENT_QUOTES, 'UTF-8') ?>"><?= strtoupper(htmlspecialchars((string)$order->status, ENT_QUOTES, 'UTF-8')) ?></span></td>
                        <td><span class="badge badge-pay-<?= htmlspecialchars((string)$order->payment_status, ENT_QUOTES, 'UTF-8') ?>"><?= strtoupper(htmlspecialchars((string)$order->payment_status, ENT_QUOTES, 'UTF-8')) ?></span></td>
                        <td><span class="badge badge-ful-<?= htmlspecialchars((string)$order->fulfillment_status, ENT_QUOTES, 'UTF-8') ?>"><?= strtoupper(htmlspecialchars((string)$order->fulfillment_status, ENT_QUOTES, 'UTF-8')) ?></span></td>
                        <td style="text-align: right; font-weight: bold;"><?= htmlspecialchars((string)$order->currency, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars((string)$order->total_amount, ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$order->created_at, ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="text-align: center;">
                            <a href="/admin/page/favorite-digital-orders?action=view&id=<?= (int)$order->id ?>" class="button button-small">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if (($totalPages ?? 1) > 1): ?>
        <div class="tablenav" style="margin-top: 15px;">
            <div class="tablenav-pages">
                <span class="displaying-num"><?= (int)$total ?> orders</span>
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <?php if ($p === (int)$page): ?>
                        <span class="button button-primary disabled"><?= $p ?></span>
                    <?php else: ?>
                        <a href="/admin/page/favorite-digital-orders?page=<?= $p ?>&status=<?= urlencode((string)$statusFilter) ?>&payment_status=<?= urlencode((string)$paymentFilter) ?>&fulfillment_status=<?= urlencode((string)$fulfillmentFilter) ?>&search=<?= urlencode((string)$search) ?>" class="button"><?= $p ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
