<?php
/**
 * Create Digital Product View
 *
 * Variables:
 * - $old       : array
 * - $csrfToken : string
 * - $flashError: ?string
 */
?>
<div class="fd-admin-wrap" style="max-width: 960px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
    <div style="margin-bottom: 20px;">
        <a href="/admin/page/favorite-digital" style="color: #2271b1; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 4px;">
            &larr; Back to Digital Products
        </a>
        <h2 style="font-size: 22px; font-weight: 600; color: #1e1e1e; margin: 8px 0 4px 0;">Add New Digital Product</h2>
        <p style="color: #646970; font-size: 13px; margin: 0;">Configure digital downloadable asset, pricing, and fulfillment terms.</p>
    </div>

    <?php if (!empty($flashError)): ?>
        <div style="background: #fdf2f2; border-left: 4px solid #dc3545; padding: 12px 16px; margin-bottom: 20px; border-radius: 4px; color: #721c24;">
            <strong>Error:</strong> <?php echo htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="/admin/page/favorite-digital" enctype="multipart/form-data">
        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="store">

        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
            <!-- Left Column: Core Product Info -->
            <div style="display: flex; flex-direction: column; gap: 20px;">
                <!-- Basic Info Card -->
                <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                    <h3 style="font-size: 15px; font-weight: 600; margin: 0 0 16px 0; color: #1e1e1e; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px;">General Details</h3>

                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            Product Title <span style="color: #dc3545;">*</span>
                        </label>
                        <input type="text" name="title" id="fd-title" required value="<?php echo htmlspecialchars((string)($old['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g., E-Commerce Mastery Course (eBook + Templates)" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 14px;">
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            URL Slug
                            <span style="font-weight: 400; color: #646970; font-size: 12px;">(optional, auto-generated from title)</span>
                        </label>
                        <input type="text" name="slug" id="fd-slug" value="<?php echo htmlspecialchars((string)($old['slug'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g., ecommerce-mastery-course" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px; font-family: monospace;">
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            Short Summary
                        </label>
                        <input type="text" name="short_description" value="<?php echo htmlspecialchars((string)($old['short_description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Brief one-line overview shown on product cards" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px;">
                    </div>

                    <div>
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            Full Description
                        </label>
                        <textarea name="description" rows="6" placeholder="Describe the digital asset, features, compatibility, etc." style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px; font-family: inherit;"><?php echo htmlspecialchars((string)($old['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                </div>

                <!-- Cover Image / Media Card -->
                <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                    <h3 style="font-size: 15px; font-weight: 600; margin: 0 0 16px 0; color: #1e1e1e; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px;">Cover Image / Media</h3>

                    <div style="margin-bottom: 14px;">
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            Upload Cover Image
                        </label>
                        <input type="file" name="cover_image" accept="image/jpeg,image/png,image/webp,image/gif,image/svg+xml" style="display: block; width: 100%; font-size: 13px; color: #50575e;">
                        <div style="font-size: 11px; color: #646970; margin-top: 4px;">
                            Supported formats: JPG, PNG, WEBP, GIF, SVG.
                        </div>
                    </div>

                    <div>
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            Or External Cover Image URL
                        </label>
                        <input type="url" name="cover_image_url" value="<?php echo htmlspecialchars((string)($old['cover_image_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://example.com/images/cover.jpg" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px;">
                    </div>
                </div>

                <!-- Digital File Upload Card -->
                <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                    <h3 style="font-size: 15px; font-weight: 600; margin: 0 0 16px 0; color: #1e1e1e; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px;">Downloadable Resource / File</h3>

                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            Resource Type <span style="color: #dc3545;">*</span>
                        </label>
                        <?php $currentResType = $old['resource_type'] ?? 'file'; ?>
                        <select name="resource_type" id="fd-resource-type" onchange="onResourceTypeChange()" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px; background: #fff;">
                            <option value="file" <?php echo $currentResType === 'file' ? 'selected' : ''; ?>>Uploaded File Only</option>
                            <option value="url" <?php echo $currentResType === 'url' ? 'selected' : ''; ?>>External Resource URL Only</option>
                            <option value="both" <?php echo $currentResType === 'both' ? 'selected' : ''; ?>>Both (Uploaded File + External URL)</option>
                        </select>
                    </div>

                    <div id="fd-url-block" style="margin-bottom: 16px; display: <?php echo in_array($currentResType, ['url', 'both'], true) ? 'block' : 'none'; ?>;">
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            External Resource URL <span style="color: #dc3545;">*</span>
                        </label>
                        <input type="url" name="resource_url" id="fd-resource-url" value="<?php echo htmlspecialchars((string)($old['resource_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://secure.example.com/assets/resource" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px;">
                        <div style="font-size: 11px; color: #646970; margin-top: 4px;">
                            Must use safe HTTP/HTTPS. Customers receive protected access via entitlement verification.
                        </div>
                    </div>

                    <div id="fd-file-block" style="margin-bottom: 16px; display: <?php echo in_array($currentResType, ['file', 'both'], true) ? 'block' : 'none'; ?>;">
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            Upload Digital File
                        </label>
                        <input type="file" name="digital_file" style="display: block; width: 100%; font-size: 13px; color: #50575e;">
                        <div style="font-size: 12px; color: #646970; margin-top: 6px;">
                            Supported formats: <code>.zip</code>, <code>.pdf</code>, <code>.epub</code>, <code>.mp3</code>, <code>.mp4</code>, <code>.json</code>, <code>.csv</code>, <code>.docx</code>, <code>.xlsx</code>. Server scripts (.php, .exe, .sh) are strictly prohibited.
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                        <div>
                            <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                                Version Tag
                            </label>
                            <input type="text" name="version" value="<?php echo htmlspecialchars((string)($old['version'] ?? '1.0.0'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="1.0.0" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px;">
                        </div>
                        <div>
                            <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                                Max Downloads / User
                            </label>
                            <input type="number" name="max_downloads_per_user" min="0" value="<?php echo htmlspecialchars((string)($old['max_downloads_per_user'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Leave blank for unlimited" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px;">
                        </div>
                    </div>

                    <div>
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            Download Link Expiry (Days)
                        </label>
                        <input type="number" name="download_expiry_days" min="0" value="<?php echo htmlspecialchars((string)($old['download_expiry_days'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Leave blank for lifetime access" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px;">
                    </div>
                </div>
            </div>

            <!-- Right Column: Pricing & Publication -->
            <div style="display: flex; flex-direction: column; gap: 20px;">
                <!-- Pricing Card -->
                <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                    <h3 style="font-size: 15px; font-weight: 600; margin: 0 0 16px 0; color: #1e1e1e; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px;">Pricing & Discounts</h3>

                    <div style="margin-bottom: 14px;">
                        <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 13px; color: #1e1e1e; cursor: pointer;">
                            <input type="checkbox" name="is_free" id="fd-is-free" value="1" <?php echo !empty($old['is_free']) ? 'checked' : ''; ?>>
                            Free Product (৳0.00)
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
                        <div style="font-size: 12px; color: #646970; margin-bottom: 2px;">Calculated Selling Price:</div>
                        <div id="fd-selling-price" style="font-size: 20px; font-weight: 700; color: #1e1e1e;">৳0.00</div>
                        <div id="fd-discount-badge" style="font-size: 11px; color: #d63638; font-weight: 600; margin-top: 2px; display: none;"></div>
                    </div>
                </div>

                <!-- Settings & Publish Card -->
                <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                    <h3 style="font-size: 15px; font-weight: 600; margin: 0 0 16px 0; color: #1e1e1e; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px;">Publishing & Access</h3>

                    <div style="margin-bottom: 14px;">
                        <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 13px; color: #1e1e1e; cursor: pointer;">
                            <input type="checkbox" name="is_membership_eligible" value="1" <?php echo !empty($old['is_membership_eligible']) ? 'checked' : ''; ?>>
                            Include in Active Memberships
                        </label>
                        <div style="font-size: 12px; color: #646970; margin-left: 24px; margin-top: 2px;">
                            Active membership holders can download this product without separate purchase.
                        </div>
                    </div>

                    <div style="margin-bottom: 18px;">
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            Status
                        </label>
                        <select name="status" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px; background: #fff;">
                            <option value="draft" <?php echo (($old['status'] ?? 'draft') === 'draft') ? 'selected' : ''; ?>>Draft (Hidden from store)</option>
                            <option value="published" <?php echo (($old['status'] ?? '') === 'published') ? 'selected' : ''; ?>>Published (Live in store)</option>
                        </select>
                        <div style="font-size: 11px; color: #8c8f94; margin-top: 4px;">
                            * Note: Publishing requires a digital file to be attached.
                        </div>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <button type="submit" style="width: 100%; background: #2271b1; color: #fff; border: none; padding: 10px 16px; border-radius: 4px; font-weight: 600; font-size: 14px; cursor: pointer;">
                            Create Digital Product
                        </button>
                        <a href="/admin/page/favorite-digital" style="text-align: center; color: #646970; text-decoration: none; font-size: 13px; padding: 6px;">
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

    // Auto-generate slug on title blur if empty
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

function onResourceTypeChange() {
    var sel = document.getElementById('fd-resource-type');
    var urlBlock = document.getElementById('fd-url-block');
    var fileBlock = document.getElementById('fd-file-block');
    if (!sel) return;
    var val = sel.value;
    if (urlBlock) {
        urlBlock.style.display = (val === 'url' || val === 'both') ? 'block' : 'none';
    }
    if (fileBlock) {
        fileBlock.style.display = (val === 'file' || val === 'both') ? 'block' : 'none';
    }
}
</script>

