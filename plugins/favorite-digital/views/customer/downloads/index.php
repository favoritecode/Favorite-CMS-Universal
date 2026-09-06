<?php
/**
 * Customer Digital Downloads View
 */
?>
<div class="container customer-downloads" style="max-width: 900px; margin: 30px auto; padding: 25px; border: 1px solid #e0e0e0; border-radius: 8px;">
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #333; padding-bottom: 15px;">
        <div>
            <h1 style="margin: 0; font-size: 24px;">My Digital Downloads</h1>
            <p style="margin: 5px 0 0 0; color: #666;">Access your purchased digital files and membership resources</p>
        </div>
        <div>
            <a href="/account/orders" class="button">&larr; My Orders</a>
        </div>
    </div>

    <?php if (empty($items)): ?>
        <div style="padding: 40px; text-align: center; color: #777;">
            <p style="font-size: 16px;">You do not have any downloadable digital files available yet.</p>
        </div>
    <?php else: ?>
        <table style="width: 100%; border-collapse: collapse; margin-top: 20px;">
            <thead>
                <tr style="border-bottom: 2px solid #ddd; text-align: left;">
                    <th style="padding: 12px;">Product</th>
                    <th style="padding: 12px;">Access Type</th>
                    <th style="padding: 12px; text-align: center;">Downloads Used</th>
                    <th style="padding: 12px; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 12px;">
                            <strong><?= htmlspecialchars($item['product_title'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <?php if (!empty($item['expires_at'])): ?>
                                <div style="font-size: 11px; color: #888;">Expires: <?= htmlspecialchars((string)$item['expires_at'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px;">
                            <?php if ($item['is_membership']): ?>
                                <span class="badge" style="background: #28a745; color: #fff; padding: 3px 8px; border-radius: 3px;">Membership</span>
                            <?php else: ?>
                                <span class="badge" style="background: #007bff; color: #fff; padding: 3px 8px; border-radius: 3px;"><?= strtoupper(htmlspecialchars($item['source_type'], ENT_QUOTES, 'UTF-8')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px; text-align: center;">
                            <?php if ($item['is_membership']): ?>
                                <span style="color: #28a745; font-weight: bold;">Unlimited</span>
                                <div style="font-size: 11px; color: #666;"><?= (int)$item['download_count'] ?> used</div>
                            <?php else: ?>
                                <strong><?= (int)$item['download_count'] ?> / <?= (int)$item['max_limit'] ?></strong>
                                <div style="font-size: 11px; color: <?= $item['remaining'] > 0 ? '#666' : '#c00' ?>;">
                                    <?= $item['remaining'] > 0 ? $item['remaining'] . ' remaining' : 'Limit reached' ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px; text-align: right;">
                            <?php if ($item['is_exhausted']): ?>
                                <button disabled style="padding: 6px 14px; background: #ccc; border: none; border-radius: 4px; color: #666; cursor: not-allowed;">
                                    Limit Reached
                                </button>
                            <?php else: ?>
                                <a href="/download/<?= htmlspecialchars($item['token'], ENT_QUOTES, 'UTF-8') ?>"
                                   class="button button-primary"
                                   style="display: inline-block; padding: 6px 14px; background: #0073aa; color: #fff; text-decoration: none; border-radius: 4px;">
                                    Download File
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
