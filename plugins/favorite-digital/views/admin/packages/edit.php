<?php
/**
 * Edit Package / Bundle View
 *
 * Variables:
 * - $product           : object (from favorite_digital_products)
 * - $package           : ?object (from favorite_digital_packages)
 * - $items             : array of objects (from favorite_digital_package_items joined with products)
 * - $availableProducts : array of objects (eligible products not yet in package)
 * - $old               : ?array
 * - $csrfToken         : string
 * - $flashError        : ?string
 * - $flashSuccess      : ?string
 */
$title = $old['title'] ?? $product->title;
$slug = $old['slug'] ?? $product->slug;
$desc = $old['description'] ?? $product->description;
$origPrice = $old['original_price'] ?? $product->original_price;
$discPercent = $old['discount_percent'] ?? $product->discount_percent;
$isFree = isset($old['is_free']) ? (bool)$old['is_free'] : (bool)$product->is_free;
$status = $old['status'] ?? $product->status;
$packageType = $old['package_type'] ?? ($package->package_type ?? 'bundle');
$itemsCount = count($items);
?>
<div class="fd-admin-wrap" style="max-width: 960px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
    <div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <div>
            <a href="/admin/page/favorite-digital-packages" style="color: #2271b1; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 4px;">
                &larr; Back to Packages
            </a>
            <h2 style="font-size: 22px; font-weight: 600; color: #1e1e1e; margin: 8px 0 4px 0;">
                Edit Package / Bundle #<?php echo (int)$product->id; ?>
            </h2>
            <div style="font-size: 13px; color: #646970;">
                Editing: <strong style="color: #1e1e1e;"><?php echo htmlspecialchars($product->title, ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
        </div>
        <div style="display: flex; gap: 8px;">
            <a href="/admin/page/favorite-digital-packages?action=view&id=<?php echo (int)$product->id; ?>" style="display: inline-flex; align-items: center; padding: 6px 12px; background: #f6f7f7; border: 1px solid #8c8f94; border-radius: 4px; color: #2271b1; text-decoration: none; font-size: 13px; font-weight: 500;">
                View Overview
            </a>
        </div>
    </div>

    <?php if (!empty($flashSuccess)): ?>
        <div style="background: #e7f7ed; border-left: 4px solid #28a745; padding: 12px 16px; margin-bottom: 20px; border-radius: 4px; color: #155724;">
            <strong>Success:</strong> <?php echo htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($flashError)): ?>
        <div style="background: #fdf2f2; border-left: 4px solid #dc3545; padding: 12px 16px; margin-bottom: 20px; border-radius: 4px; color: #721c24;">
            <strong>Error:</strong> <?php echo htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
        <!-- Left Column: Details & Items -->
        <div style="display: flex; flex-direction: column; gap: 20px;">
            <!-- Main Details Form -->
            <form method="POST" action="/admin/page/favorite-digital-packages" id="fd-edit-form">
                <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?php echo (int)$product->id; ?>">
                <input type="hidden" name="package_type" value="<?php echo htmlspecialchars($packageType, ENT_QUOTES, 'UTF-8'); ?>">

                <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                    <h3 style="font-size: 15px; font-weight: 600; margin: 0 0 16px 0; color: #1e1e1e; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px;">Package Details</h3>

                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            Package Title <span style="color: #dc3545;">*</span>
                        </label>
                        <input type="text" name="title" required value="<?php echo htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 14px;">
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            URL Slug <span style="color: #dc3545;">*</span>
                        </label>
                        <input type="text" name="slug" required value="<?php echo htmlspecialchars((string)$slug, ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px; font-family: monospace;">
                    </div>

                    <div>
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            Description
                        </label>
                        <textarea name="description" rows="4" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px; font-family: inherit;"><?php echo htmlspecialchars((string)$desc, ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                </div>
            </form>

            <!-- Included Products / Items Management Card -->
            <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px; margin-bottom: 16px;">
                    <h3 style="font-size: 15px; font-weight: 600; margin: 0; color: #1e1e1e;">
                        Included Items in Package (<?php echo $itemsCount; ?>)
                    </h3>
                    <span style="font-size: 12px; color: #646970;">Cannot bundle nested packages or memberships</span>
                </div>

                <?php if (empty($items)): ?>
                    <div style="background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px; padding: 14px; color: #856404; font-size: 13px; margin-bottom: 16px;">
                        <strong>Notice:</strong> This package currently has 0 items. It cannot be published until at least one valid product or service is added.
                    </div>
                <?php else: ?>
                    <table style="width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 20px;">
                        <thead>
                            <tr style="background: #f6f7f7; text-align: left; border-bottom: 1px solid #dcdcde;">
                                <th style="padding: 8px 10px; font-weight: 600; width: 60px;">Order</th>
                                <th style="padding: 8px 10px; font-weight: 600;">Product</th>
                                <th style="padding: 8px 10px; font-weight: 600; width: 80px;">Type</th>
                                <th style="padding: 8px 10px; font-weight: 600; width: 80px; text-align: right;">Price</th>
                                <th style="padding: 8px 10px; font-weight: 600; width: 140px; text-align: center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $idx => $item): ?>
                                <?php
                                $isDigital = ($item->product_type === 'digital');
                                $badgeStyle = $isDigital
                                    ? 'background: #e8f0fe; color: #1967d2;'
                                    : 'background: #fce8e6; color: #c5221f;';
                                ?>
                                <tr style="border-bottom: 1px solid #f0f0f1;">
                                    <td style="padding: 10px; color: #646970; font-family: monospace; font-size: 12px;">
                                        #<?php echo (int)$item->sort_order; ?>
                                    </td>
                                    <td style="padding: 10px;">
                                        <strong style="color: #1e1e1e;"><?php echo htmlspecialchars($item->title, ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <div style="font-size: 11px; color: #646970; font-family: monospace;">
                                            /<?php echo htmlspecialchars($item->slug, ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                    </td>
                                    <td style="padding: 10px;">
                                        <span style="display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: 600; text-transform: uppercase; <?php echo $badgeStyle; ?>">
                                            <?php echo htmlspecialchars($item->product_type, ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 10px; text-align: right; font-weight: 600;">
                                        ৳<?php echo htmlspecialchars(number_format((float)$item->final_price, 2), ENT_QUOTES, 'UTF-8'); ?>
                                    </td>
                                    <td style="padding: 10px; text-align: center;">
                                        <div style="display: inline-flex; align-items: center; gap: 4px;">
                                            <!-- Move Up -->
                                            <?php if ($idx > 0): ?>
                                                <?php
                                                $newOrder = [];
                                                foreach ($items as $k => $it) {
                                                    if ($k === $idx - 1) {
                                                        $newOrder[] = (int)$items[$idx]->included_product_id;
                                                    } elseif ($k === $idx) {
                                                        $newOrder[] = (int)$items[$idx - 1]->included_product_id;
                                                    } else {
                                                        $newOrder[] = (int)$it->included_product_id;
                                                    }
                                                }
                                                ?>
                                                <form method="POST" action="/admin/page/favorite-digital-packages" style="display: inline; margin: 0;">
                                                    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="action" value="reorder_items">
                                                    <input type="hidden" name="id" value="<?php echo (int)$product->id; ?>">
                                                    <?php foreach ($newOrder as $ordId): ?>
                                                        <input type="hidden" name="item_ids[]" value="<?php echo $ordId; ?>">
                                                    <?php endforeach; ?>
                                                    <button type="submit" title="Move Up" style="background: #f6f7f7; border: 1px solid #c3c4c7; border-radius: 3px; cursor: pointer; padding: 2px 6px; font-size: 11px;">
                                                        &uarr;
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- Move Down -->
                                            <?php if ($idx < $itemsCount - 1): ?>
                                                <?php
                                                $newOrder = [];
                                                foreach ($items as $k => $it) {
                                                    if ($k === $idx) {
                                                        $newOrder[] = (int)$items[$idx + 1]->included_product_id;
                                                    } elseif ($k === $idx + 1) {
                                                        $newOrder[] = (int)$items[$idx]->included_product_id;
                                                    } else {
                                                        $newOrder[] = (int)$it->included_product_id;
                                                    }
                                                }
                                                ?>
                                                <form method="POST" action="/admin/page/favorite-digital-packages" style="display: inline; margin: 0;">
                                                    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="action" value="reorder_items">
                                                    <input type="hidden" name="id" value="<?php echo (int)$product->id; ?>">
                                                    <?php foreach ($newOrder as $ordId): ?>
                                                        <input type="hidden" name="item_ids[]" value="<?php echo $ordId; ?>">
                                                    <?php endforeach; ?>
                                                    <button type="submit" title="Move Down" style="background: #f6f7f7; border: 1px solid #c3c4c7; border-radius: 3px; cursor: pointer; padding: 2px 6px; font-size: 11px;">
                                                        &darr;
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- Remove Item -->
                                            <form method="POST" action="/admin/page/favorite-digital-packages" style="display: inline; margin: 0;" onsubmit="return confirm('Remove this product from the bundle?');">
                                                <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="remove_item">
                                                <input type="hidden" name="id" value="<?php echo (int)$product->id; ?>">
                                                <input type="hidden" name="included_product_id" value="<?php echo (int)$item->included_product_id; ?>">
                                                <button type="submit" title="Remove from Package" style="background: #fff; border: 1px solid #dc3545; color: #dc3545; border-radius: 3px; cursor: pointer; padding: 2px 6px; font-size: 11px;">
                                                    &times;
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <!-- Add New Product to Package Form -->
                <div style="background: #f6f7f7; border: 1px dashed #c3c4c7; border-radius: 4px; padding: 14px;">
                    <h4 style="font-size: 13px; font-weight: 600; margin: 0 0 10px 0; color: #1e1e1e;">Add Product or Service to Package</h4>
                    <?php if (empty($availableProducts)): ?>
                        <div style="font-size: 12px; color: #646970;">
                            All eligible active products and services are already included in this package.
                        </div>
                    <?php else: ?>
                        <form method="POST" action="/admin/page/favorite-digital-packages" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                            <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="add_item">
                            <input type="hidden" name="id" value="<?php echo (int)$product->id; ?>">

                            <select name="included_product_id" required style="flex: 1; min-width: 250px; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px; background: #fff;">
                                <option value="">-- Select Product or Service to Add --</option>
                                <?php foreach ($availableProducts as $ap): ?>
                                    <option value="<?php echo (int)$ap->id; ?>">
                                        [<?php echo strtoupper($ap->product_type); ?>] <?php echo htmlspecialchars($ap->title, ENT_QUOTES, 'UTF-8'); ?> (৳<?php echo number_format((float)$ap->final_price, 2); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <button type="submit" style="background: #2271b1; color: #fff; border: none; padding: 8px 16px; border-radius: 4px; font-size: 13px; font-weight: 600; cursor: pointer;">
                                + Add Item
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Column: Pricing & Publication -->
        <div style="display: flex; flex-direction: column; gap: 20px;">
            <!-- Pricing Card (forms part of fd-edit-form) -->
            <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                <h3 style="font-size: 15px; font-weight: 600; margin: 0 0 16px 0; color: #1e1e1e; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px;">Package Pricing</h3>

                <div style="margin-bottom: 14px;">
                    <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 13px; color: #1e1e1e; cursor: pointer;">
                        <input type="checkbox" name="is_free" form="fd-edit-form" id="fd-is-free" value="1" <?php echo $isFree ? 'checked' : ''; ?>>
                        Free Package (৳0.00)
                    </label>
                </div>

                <div id="fd-pricing-fields">
                    <div style="margin-bottom: 14px;">
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            Catalog Original Price (৳)
                        </label>
                        <input type="number" step="0.01" min="0" name="original_price" form="fd-edit-form" id="fd-original-price" value="<?php echo htmlspecialchars((string)$origPrice, ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 14px;">
                    </div>

                    <div style="margin-bottom: 14px;">
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            Discount Percentage (%)
                        </label>
                        <input type="number" step="0.01" min="0" max="100" name="discount_percent" form="fd-edit-form" id="fd-discount-percent" value="<?php echo htmlspecialchars((string)$discPercent, ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 14px;">
                    </div>
                </div>

                <!-- Calculated Live Selling Price Preview -->
                <div style="background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; padding: 12px; margin-top: 8px;">
                    <div style="font-size: 12px; color: #646970; margin-bottom: 2px;">Calculated Package Selling Price:</div>
                    <div id="fd-selling-price" style="font-size: 20px; font-weight: 700; color: #1e1e1e;">
                        ৳<?php echo htmlspecialchars(number_format((float)$product->final_price, 2), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div id="fd-discount-badge" style="font-size: 11px; color: #d63638; font-weight: 600; margin-top: 2px; display: none;"></div>
                </div>
            </div>

            <!-- Settings & Publication Card -->
            <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                <h3 style="font-size: 15px; font-weight: 600; margin: 0 0 16px 0; color: #1e1e1e; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px;">Publication</h3>

                <div style="margin-bottom: 18px;">
                    <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                        Status
                    </label>
                    <select name="status" form="fd-edit-form" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px; background: #fff;">
                        <option value="draft" <?php echo ($status === 'draft') ? 'selected' : ''; ?>>Draft (Hidden)</option>
                        <option value="published" <?php echo ($status === 'published') ? 'selected' : ''; ?>>Published (Live)</option>
                        <option value="archived" <?php echo ($status === 'archived') ? 'selected' : ''; ?>>Archived (Retired)</option>
                    </select>
                    <div style="font-size: 11px; color: #8c8f94; margin-top: 4px;">
                        * Note: A published package must contain at least one valid item.
                    </div>
                </div>

                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <button type="submit" form="fd-edit-form" style="width: 100%; background: #2271b1; color: #fff; border: none; padding: 10px 16px; border-radius: 4px; font-weight: 600; font-size: 14px; cursor: pointer;">
                        Save Package Changes
                    </button>
                    <a href="/admin/page/favorite-digital-packages" style="text-align: center; color: #646970; text-decoration: none; font-size: 13px; padding: 6px;">
                        Cancel
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var origInput = document.getElementById('fd-original-price');
    var discInput = document.getElementById('fd-discount-percent');
    var isFreeInput = document.getElementById('fd-is-free');
    var priceDisplay = document.getElementById('fd-selling-price');
    var badgeDisplay = document.getElementById('fd-discount-badge');
    var pricingFields = document.getElementById('fd-pricing-fields');

    function calculateSellingPrice() {
        if (isFreeInput.checked) {
            priceDisplay.textContent = '৳0.00 (Free)';
            badgeDisplay.style.display = 'none';
            pricingFields.style.opacity = '0.5';
            return;
        }

        pricingFields.style.opacity = '1';
        var orig = parseFloat(origInput.value) || 0;
        var disc = parseFloat(discInput.value) || 0;

        if (disc < 0) disc = 0;
        if (disc > 100) disc = 100;
        if (orig < 0) orig = 0;

        var finalP = orig * (1 - (disc / 100));
        priceDisplay.textContent = '৳' + finalP.toFixed(2);

        if (disc > 0) {
            badgeDisplay.textContent = disc.toFixed(2) + '% Discount Applied (Save ৳' + (orig - finalP).toFixed(2) + ')';
            badgeDisplay.style.display = 'block';
        } else {
            badgeDisplay.style.display = 'none';
        }
    }

    if (origInput && discInput && isFreeInput) {
        origInput.addEventListener('input', calculateSellingPrice);
        discInput.addEventListener('input', calculateSellingPrice);
        isFreeInput.addEventListener('change', calculateSellingPrice);
        calculateSellingPrice();
    }
})();
</script>
