<?php
/**
 * Admin Single Order View
 */
?>
<div class="wrap">
    <h1>Order <?= htmlspecialchars((string)$order->order_number, ENT_QUOTES, 'UTF-8') ?></h1>
    <a href="/admin/page/favorite-digital-orders" class="button" style="margin-bottom: 15px;">&larr; Back to Orders</a>

    <?php if (!empty($flashSuccess)): ?>
        <div class="notice notice-success is-dismissible"><p><?= htmlspecialchars((string)$flashSuccess, ENT_QUOTES, 'UTF-8') ?></p></div>
    <?php endif; ?>
    <?php if (!empty($flashError)): ?>
        <div class="notice notice-error is-dismissible"><p><?= htmlspecialchars((string)$flashError, ENT_QUOTES, 'UTF-8') ?></p></div>
    <?php endif; ?>

    <div style="display: flex; gap: 20px; flex-wrap: wrap;">
        <!-- Left details column -->
        <div style="flex: 2; min-width: 320px;">
            <div class="postbox" style="padding: 15px;">
                <h2>Order Items</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Item / Product</th>
                            <th>Type</th>
                            <th style="text-align: right;">Unit Price</th>
                            <th style="text-align: right;">Discount</th>
                            <th style="text-align: right;">Final Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($order->items as $item): ?>
                            <?php $snapshot = $item->snapshot ?? []; ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars((string)($snapshot['title'] ?? 'Product #' . $item->product_id), ENT_QUOTES, 'UTF-8') ?></strong>
                                    <?php if (!empty($snapshot['slug'])): ?>
                                        <div style="font-size: 11px; color: #666;">Slug: <?= htmlspecialchars((string)$snapshot['slug'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($snapshot['attributes'])): ?>
                                        <details style="margin-top: 5px; font-size: 11px; color: #444;">
                                            <summary>Snapshot Attributes</summary>
                                            <pre style="margin: 3px 0; background: #f8f8f8; padding: 5px; border-radius: 3px;"><?= htmlspecialchars(json_encode($snapshot['attributes'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></pre>
                                        </details>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge"><?= strtoupper(htmlspecialchars((string)$item->product_type, ENT_QUOTES, 'UTF-8')) ?></span></td>
                                <td style="text-align: right;"><?= htmlspecialchars((string)$item->currency, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars((string)$item->unit_price, ENT_QUOTES, 'UTF-8') ?></td>
                                <td style="text-align: right;"><?= htmlspecialchars((string)$item->discount_percent, ENT_QUOTES, 'UTF-8') ?>%</td>
                                <td style="text-align: right; font-weight: bold;"><?= htmlspecialchars((string)$item->currency, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars((string)$item->final_price, ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div style="margin-top: 20px; text-align: right; border-top: 1px solid #ddd; padding-top: 10px;">
                    <p style="margin: 4px 0;"><strong>Subtotal:</strong> <?= htmlspecialchars((string)$order->currency, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars((string)$order->subtotal_amount, ENT_QUOTES, 'UTF-8') ?></p>
                    <p style="margin: 4px 0; color: #c00;"><strong>Discount:</strong> -<?= htmlspecialchars((string)$order->currency, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars((string)$order->discount_amount, ENT_QUOTES, 'UTF-8') ?></p>
                    <h3 style="margin: 6px 0;"><strong>Total:</strong> <?= htmlspecialchars((string)$order->currency, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars((string)$order->total_amount, ENT_QUOTES, 'UTF-8') ?></h3>
                </div>
            </div>

            <?php
            $totalPaidMinor = 0;
            if (!empty($order->payments)) {
                foreach ($order->payments as $p) {
                    if ($p->status === 'completed' || $p->status === 'paid') {
                        $totalPaidMinor += (int)round((float)$p->amount_paid * 100);
                    }
                }
            }
            $orderTotalMinor = (int)round((float)$order->total_amount * 100);
            $remainingMinor = max(0, $orderTotalMinor - $totalPaidMinor);
            $remainingFormatted = number_format($remainingMinor / 100, 2, '.', '');
            $paidFormatted = number_format($totalPaidMinor / 100, 2, '.', '');
            ?>

            <div class="postbox" style="padding: 15px; margin-top: 20px;">
                <h2>Payment Settlements</h2>
                <p>
                    <strong>Total Settled:</strong> ৳<?= htmlspecialchars($paidFormatted, ENT_QUOTES, 'UTF-8') ?> BDT &nbsp;|&nbsp;
                    <strong>Remaining Balance:</strong> ৳<?= htmlspecialchars($remainingFormatted, ENT_QUOTES, 'UTF-8') ?> BDT
                </p>

                <?php if (!empty($order->payments)): ?>
                    <table class="widefat fixed striped" style="margin-top: 10px;">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Method</th>
                                <th>Status</th>
                                <th style="text-align: right;">Amount</th>
                                <th>Transaction / Reference</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($order->payments as $pay): ?>
                                <tr>
                                    <td>#<?= (int)$pay->id ?></td>
                                    <td><strong><?= htmlspecialchars(strtoupper((string)$pay->payment_method), ENT_QUOTES, 'UTF-8') ?></strong></td>
                                    <td>
                                        <span class="badge badge-<?= htmlspecialchars((string)$pay->status, ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars(ucfirst((string)$pay->status), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right; font-weight: bold;">৳<?= htmlspecialchars((string)$pay->amount_paid, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?php if (!empty($pay->favorite_pay_tx_id)): ?>
                                            <code>FP: <?= htmlspecialchars((string)$pay->favorite_pay_tx_id, ENT_QUOTES, 'UTF-8') ?></code>
                                        <?php endif; ?>
                                        <?php if (!empty($pay->wallet_tx_id)): ?>
                                            <code>Wallet Tx: #<?= htmlspecialchars((string)$pay->wallet_tx_id, ENT_QUOTES, 'UTF-8') ?></code>
                                        <?php endif; ?>
                                        <?php if (empty($pay->favorite_pay_tx_id) && empty($pay->wallet_tx_id)): ?>
                                            <em>N/A</em>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars((string)$pay->created_at, ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p><em>No payment records found for this order.</em></p>
                <?php endif; ?>
            </div>

            <?php if (!empty($entitlements)): ?>
                <div class="postbox" style="padding: 15px; margin-top: 20px;">
                    <h2>Granted Entitlements & Access</h2>
                    <table class="widefat fixed striped" style="margin-top: 10px;">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Product / Item</th>
                                <th>Source Type</th>
                                <th>Status</th>
                                <th>Granted At</th>
                                <th>Expires At</th>
                                <th>Downloads</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($entitlements as $ent): ?>
                                <tr>
                                    <td>#<?= (int)$ent->id ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars((string)($ent->product_title ?? 'Product #' . $ent->product_id), ENT_QUOTES, 'UTF-8') ?></strong>
                                    </td>
                                    <td><span class="badge"><?= htmlspecialchars(strtoupper((string)$ent->source_type), ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td>
                                        <span class="badge badge-<?= htmlspecialchars((string)$ent->status, ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars(ucfirst((string)$ent->status), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars((string)$ent->granted_at, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= !empty($ent->expires_at) ? htmlspecialchars((string)$ent->expires_at, ENT_QUOTES, 'UTF-8') : '<em>Lifetime</em>' ?></td>
                                    <td><?= ($ent->product_type === 'digital') ? (isset($ent->download_count) ? (int)$ent->download_count . ' / 3 used' : '0 / 3 used') : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (!empty($order->notes)): ?>
                <div class="postbox" style="padding: 15px; margin-top: 20px;">
                    <h3>Order Notes</h3>
                    <p><?= nl2br(htmlspecialchars((string)$order->notes, ENT_QUOTES, 'UTF-8')) ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Right sidebar column -->
        <div style="flex: 1; min-width: 260px;">
            <div class="postbox" style="padding: 15px;">
                <h2>Order Overview</h2>
                <p><strong>Order ID:</strong> #<?= (int)$order->id ?></p>
                <p><strong>Customer ID:</strong> User #<?= (int)$order->user_id ?></p>
                <p><strong>Created:</strong> <?= htmlspecialchars((string)$order->created_at, ENT_QUOTES, 'UTF-8') ?></p>
                <p><strong>Last Updated:</strong> <?= htmlspecialchars((string)$order->updated_at, ENT_QUOTES, 'UTF-8') ?></p>
                
                <hr>
                <h3>Update Statuses</h3>
                <form method="post" action="/admin/page/favorite-digital-orders">
                    <input type="hidden" name="_token" value="<?= htmlspecialchars((string)$csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="id" value="<?= (int)$order->id ?>">

                    <div style="margin-bottom: 10px;">
                        <label><strong>Order Status:</strong></label><br>
                        <select name="status" style="width: 100%;">
                            <option value="pending" <?= ($order->status === 'pending') ? 'selected' : '' ?>>Pending</option>
                            <option value="processing" <?= ($order->status === 'processing') ? 'selected' : '' ?>>Processing</option>
                            <option value="completed" <?= ($order->status === 'completed') ? 'selected' : '' ?>>Completed</option>
                            <option value="failed" <?= ($order->status === 'failed') ? 'selected' : '' ?>>Failed</option>
                            <option value="cancelled" <?= ($order->status === 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
                            <option value="refunded" <?= ($order->status === 'refunded') ? 'selected' : '' ?>>Refunded</option>
                        </select>
                    </div>

                    <div style="margin-bottom: 10px;">
                        <label><strong>Payment Status:</strong></label><br>
                        <select name="payment_status" style="width: 100%;">
                            <option value="unpaid" <?= ($order->payment_status === 'unpaid') ? 'selected' : '' ?>>Unpaid</option>
                            <option value="pending" <?= ($order->payment_status === 'pending') ? 'selected' : '' ?>>Pending</option>
                            <option value="partially_paid" <?= ($order->payment_status === 'partially_paid') ? 'selected' : '' ?>>Partially Paid</option>
                            <option value="paid" <?= ($order->payment_status === 'paid') ? 'selected' : '' ?>>Paid</option>
                            <option value="failed" <?= ($order->payment_status === 'failed') ? 'selected' : '' ?>>Failed</option>
                            <option value="refunded" <?= ($order->payment_status === 'refunded') ? 'selected' : '' ?>>Refunded</option>
                        </select>
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label><strong>Fulfillment Status:</strong></label><br>
                        <select name="fulfillment_status" style="width: 100%;">
                            <option value="unfulfilled" <?= ($order->fulfillment_status === 'unfulfilled') ? 'selected' : '' ?>>Unfulfilled</option>
                            <option value="partially_fulfilled" <?= ($order->fulfillment_status === 'partially_fulfilled') ? 'selected' : '' ?>>Partially Fulfilled</option>
                            <option value="fulfilled" <?= ($order->fulfillment_status === 'fulfilled') ? 'selected' : '' ?>>Fulfilled</option>
                            <option value="cancelled" <?= ($order->fulfillment_status === 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>

                    <button type="submit" class="button button-primary" style="width: 100%;">Save Status Updates</button>
                </form>

                <?php if ($order->payment_status === 'paid' && $order->fulfillment_status !== 'fulfilled'): ?>
                    <div style="margin-top: 15px; border-top: 1px solid #ddd; padding-top: 15px;">
                        <form method="post" action="/admin/page/favorite-digital-orders">
                            <input type="hidden" name="_token" value="<?= htmlspecialchars((string)$csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="fulfill">
                            <input type="hidden" name="id" value="<?= (int)$order->id ?>">
                            <button type="submit" class="button button-secondary" style="width: 100%; font-weight: bold;">
                                ⚡ Fulfill / Retry Fulfillment
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
