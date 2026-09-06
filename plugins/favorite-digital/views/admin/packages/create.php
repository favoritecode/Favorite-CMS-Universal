<?php
/**
 * Create Package / Bundle View
 *
 * Variables:
 * - $availableProducts : array of objects (eligible digital products and services)
 * - $old               : array
 * - $csrfToken         : string
 * - $flashError        : ?string
 */
$selectedItemIds = (array)($old['included_items'] ?? []);
?>
<div class="fd-admin-wrap" style="max-width: 960px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
    <div style="margin-bottom: 20px;">
        <a href="/admin/page/favorite-digital-packages" style="color: #2271b1; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 4px;">
            &larr; Back to Packages
        </a>
        <h2 style="font-size: 22px; font-weight: 600; color: #1e1e1e; margin: 8px 0 4px 0;">Create Package / Bundle</h2>
        <p style="color: #646970; font-size: 13px; margin: 0;">Group existing Digital Products and Services into a sellable discount bundle.</p>
    </div>

    <?php if (!empty($flashError)): ?>
        <div style="background: #fdf2f2; border-left: 4px solid #dc3545; padding: 12px 16px; margin-bottom: 20px; border-radius: 4px; color: #721c24;">
            <strong>Error:</strong> <?php echo htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="/admin/page/favorite-digital-packages">
        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="store">

        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
            <!-- Left Column: Core Info & Content Selection -->
            <div style="display: flex; flex-direction: column; gap: 20px;">
                <!-- General Info Card -->
                <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                    <h3 style="font-size: 15px; font-weight: 600; margin: 0 0 16px 0; color: #1e1e1e; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px;">Package Details</h3>

                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            Package Title <span style="color: #dc3545;">*</span>
                        </label>
                        <input type="text" name="title" id="fd-title" required value="<?php echo htmlspecialchars((string)($old['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g., Ultimate Web Developer Starter Pack" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 14px;">
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            URL Slug
                            <span style="font-weight: 400; color: #646970; font-size: 12px;">(optional, auto-generated from title)</span>
                        </label>
                        <input type="text" name="slug" id="fd-slug" value="<?php echo htmlspecialchars((string)($old['slug'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g., web-developer-starter-pack" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px; font-family: monospace;">
                    </div>

                    <div>
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            Description
                        </label>
                        <textarea name="description" rows="4" placeholder="Explain what is included in this bundle and the value savings..." style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px; font-family: inherit;"><?php echo htmlspecialchars((string)($old['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                </div>

                <!-- Content Selection Card: Products to Include -->
                <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px; margin-bottom: 16px;">
                        <h3 style="font-size: 15px; font-weight: 600; margin: 0; color: #1e1e1e;">Included Products &amp; Services</h3>
                        <span style="font-size: 12px; color: #646970;">Select items to bundle</span>
                    </div>

                    <?php if (empty($availableProducts)): ?>
                        <div style="background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px; padding: 12px 14px; color: #856404; font-size: 13px;">
                            No active digital products or services are available yet. Create some first, or save this package as a draft and add items later.
                        </div>
                    <?php else: ?>
                        <div style="max-height: 280px; overflow-y: auto; border: 1px solid #dcdcde; border-radius: 4px;">
                            <?php foreach ($availableProducts as $prod): ?>
                                <?php
                                $isChecked = in_array((int)$prod->id, array_map('intval', $selectedItemIds), true);
                                $isDigital = ($prod->product_type === 'digital');
                                $badgeStyle = $isDigital
                                    ? 'background: #e8f0fe; color: #1967d2;'
                                    : 'background: #fce8e6; color: #c5221f;';
                                ?>
                                <label style="display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-bottom: 1px solid #f0f0f1; cursor: pointer; background: <?php echo $isChecked ? '#f0f7ff' : '#fff'; ?>;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <input type="checkbox" name="included_items[]" value="<?php echo (int)$prod->id; ?>" <?php echo $isChecked ? 'checked' : ''; ?>>
                                        <div>
                                            <div style="font-weight: 600; font-size: 13px; color: #1e1e1e;">
                                                <?php echo htmlspecialchars($prod->title, ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                            <div style="font-size: 11px; color: #646970; font-family: monospace;">
                                                /<?php echo htmlspecialchars($prod->slug, ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <span style="display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: 600; text-transform: uppercase; <?php echo $badgeStyle; ?>">
                                            <?php echo htmlspecialchars($prod->product_type, ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                        <span style="font-weight: 600; font-size: 12px; color: #1e1e1e; min-width: 60px; text-align: right;">
                                            ৳<?php echo htmlspecialchars(number_format((float)$prod->final_price, 2), ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div style="font-size: 11px; color: #646970; margin-top: 6px;">
                            * Packages and memberships cannot be included. Duplicate selections are automatically prevented.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Column: Pricing & Publication -->
            <div style="display: flex; flex-direction: column; gap: 20px;">
                <!-- Pricing Card -->
                <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                    <h3 style="font-size: 15px; font-weight: 600; margin: 0 0 16px 0; color: #1e1e1e; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px;">Package Pricing</h3>

                    <div style="margin-bottom: 14px;">
                        <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 13px; color: #1e1e1e; cursor: pointer;">
                            <input type="checkbox" name="is_free" id="fd-is-free" value="1" <?php echo !empty($old['is_free']) ? 'checked' : ''; ?>>
                            Free Package (৳0.00)
                        </label>
                    </div>

                    <div id="fd-pricing-fields">
                        <div style="margin-bottom: 14px;">
                            <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                                Catalog Original Price (৳)
                            </label>
                            <input type="number" step="0.01" min="0" name="original_price" id="fd-original-price" value="<?php echo htmlspecialchars((string)($old['original_price'] ?? '0.00'), ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 14px;">
                        </div>

                        <div style="margin-bottom: 14px;">
                            <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                                Discount Percentage (%)
                            </label>
                            <input type="number" step="0.01" min="0" max="100" name="discount_percent" id="fd-discount-percent" value="<?php echo htmlspecialchars((string)($old['discount_percent'] ?? '0.00'), ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 14px;">
                        </div>
                    </div>

                    <!-- Calculated Live Selling Price Preview -->
                    <div style="background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; padding: 12px; margin-top: 8px;">
                        <div style="font-size: 12px; color: #646970; margin-bottom: 2px;">Calculated Package Selling Price:</div>
                        <div id="fd-selling-price" style="font-size: 20px; font-weight: 700; color: #1e1e1e;">৳0.00</div>
                        <div id="fd-discount-badge" style="font-size: 11px; color: #d63638; font-weight: 600; margin-top: 2px; display: none;"></div>
                    </div>
                </div>

                <!-- Settings & Publish Card -->
                <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                    <h3 style="font-size: 15px; font-weight: 600; margin: 0 0 16px 0; color: #1e1e1e; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px;">Publication</h3>

                    <div style="margin-bottom: 18px;">
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            Status
                        </label>
                        <select name="status" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px; background: #fff;">
                            <option value="draft" <?php echo (($old['status'] ?? 'draft') === 'draft') ? 'selected' : ''; ?>>Draft (Hidden)</option>
                            <option value="published" <?php echo (($old['status'] ?? '') === 'published') ? 'selected' : ''; ?>>Published (Live)</option>
                        </select>
                        <div style="font-size: 11px; color: #8c8f94; margin-top: 4px;">
                            * Note: Publishing requires at least one included item.
                        </div>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <button type="submit" style="width: 100%; background: #2271b1; color: #fff; border: none; padding: 10px 16px; border-radius: 4px; font-weight: 600; font-size: 14px; cursor: pointer;">
                            Create Package
                        </button>
                        <a href="/admin/page/favorite-digital-packages" style="text-align: center; color: #646970; text-decoration: none; font-size: 13px; padding: 6px;">
                            Cancel
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
(function() {
    var titleInput = document.getElementById('fd-title');
    var slugInput = document.getElementById('fd-slug');
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

    if (titleInput && slugInput) {
        titleInput.addEventListener('blur', function() {
            if (!slugInput.value.trim()) {
                var s = titleInput.value.toLowerCase()
                    .replace(/[^a-z0-9\s-]/g, '')
                    .replace(/\s+/g, '-')
                    .replace(/-+/g, '-')
                    .replace(/^-+|-+$/g, '');
                slugInput.value = s;
            }
        });
    }
})();
</script>

