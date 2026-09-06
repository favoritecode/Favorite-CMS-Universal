<?php
/**
 * Edit Digital Product View
 *
 * Variables:
 * - $product      : object
 * - $details      : ?object
 * - $old          : ?array
 * - $csrfToken    : string
 * - $flashError   : ?string
 * - $flashSuccess : ?string
 */
$title = $old['title'] ?? $product->title;
$slug = $old['slug'] ?? $product->slug;
$shortDesc = $old['short_description'] ?? $product->short_description;
$desc = $old['description'] ?? $product->description;
$origPrice = $old['original_price'] ?? $product->original_price;
$discPercent = $old['discount_percent'] ?? $product->discount_percent;
$isFree = isset($old['is_free']) ? (bool)$old['is_free'] : (bool)$product->is_free;
$status = $old['status'] ?? $product->status;
$isMembership = isset($old['is_membership_eligible']) ? (bool)$old['is_membership_eligible'] : (bool)$product->is_membership_eligible;

$version = $old['version'] ?? ($details->version ?? '1.0.0');
$maxDownloads = $old['max_downloads_per_user'] ?? ($details->max_downloads_per_user ?? '');
$expiryDays = $old['download_expiry_days'] ?? ($details->download_expiry_days ?? '');
?>
<div class="fd-admin-wrap" style="max-width: 960px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
    <div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <div>
            <a href="/admin/page/favorite-digital" style="color: #2271b1; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 4px;">
                &larr; Back to Digital Products
            </a>
            <h2 style="font-size: 22px; font-weight: 600; color: #1e1e1e; margin: 8px 0 4px 0;">
                Edit Digital Product #<?php echo (int)$product->id; ?>
            </h2>
            <div style="font-size: 13px; color: #646970;">
                Editing: <strong style="color: #1e1e1e;"><?php echo htmlspecialchars($product->title, ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
        </div>
        <div style="display: flex; gap: 8px;">
            <a href="/admin/page/favorite-digital?action=view&id=<?php echo (int)$product->id; ?>" style="display: inline-flex; align-items: center; padding: 6px 12px; background: #f6f7f7; border: 1px solid #8c8f94; border-radius: 4px; color: #2271b1; text-decoration: none; font-size: 13px; font-weight: 500;">
                View Details
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

    <form method="POST" action="/admin/page/favorite-digital" enctype="multipart/form-data">
        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?php echo (int)$product->id; ?>">

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
                        <input type="text" name="title" id="fd-title" required value="<?php echo htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 14px;">
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            URL Slug
                        </label>
                        <input type="text" name="slug" id="fd-slug" value="<?php echo htmlspecialchars((string)$slug, ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px; font-family: monospace;">
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            Short Summary
                        </label>
                        <input type="text" name="short_description" value="<?php echo htmlspecialchars((string)($shortDesc ?? ''), ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px;">
                    </div>

                    <div>
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            Full Description
                        </label>
                        <textarea name="description" rows="6" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px; font-family: inherit;"><?php echo htmlspecialchars((string)($desc ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                </div>

                <!-- Digital File Card -->
                <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                    <h3 style="font-size: 15px; font-weight: 600; margin: 0 0 16px 0; color: #1e1e1e; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px;">Downloadable Resource / File</h3>

                    <!-- Current File Information -->
                    <?php if (!empty($details->file_path)): ?>
                        <div style="background: #f8f9fa; border: 1px solid #e2e3e5; border-radius: 4px; padding: 12px 14px; margin-bottom: 16px;">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                                <div style="font-weight: 600; font-size: 13px; color: #155724; display: flex; align-items: center; gap: 6px;">
                                    <span>✔</span> Active File Configured
                                </div>
                                <span style="font-size: 11px; background: #e8f0fe; color: #1967d2; padding: 2px 6px; border-radius: 3px; font-family: monospace;">
                                    <?php echo htmlspecialchars($details->file_mime ?? 'application/octet-stream', ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </div>
                            <div style="font-size: 13px; color: #1e1e1e; font-weight: 500; word-break: break-all;">
                                <?php echo htmlspecialchars($details->file_original_name ?? basename($details->file_path), ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <div style="font-size: 12px; color: #646970; margin-top: 4px;">
                                Size: <?php echo htmlspecialchars(number_format(((int)($details->file_size_bytes ?? 0)) / 1024, 1), ENT_QUOTES, 'UTF-8'); ?> KB
                                <?php if (!empty($details->file_hash)): ?>
                                    &bull; SHA-256: <code style="font-size: 11px;"><?php echo htmlspecialchars(substr($details->file_hash, 0, 16) . '...', ENT_QUOTES, 'UTF-8'); ?></code>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px; padding: 12px 14px; margin-bottom: 16px; color: #856404; font-size: 13px;">
                            <strong>Warning:</strong> No downloadable file has been uploaded for this digital product yet. It cannot be published until a file is attached.
                        </div>
                    <?php endif; ?>

                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            <?php echo !empty($details->file_path) ? 'Replace File (Leave empty to keep current file)' : 'Upload Digital File'; ?>
                        </label>
                        <input type="file" name="digital_file" style="display: block; width: 100%; font-size: 13px; color: #50575e;">
                        <div style="font-size: 12px; color: #646970; margin-top: 6px;">
                            Supported formats: <code>.zip</code>, <code>.pdf</code>, <code>.epub</code>, <code>.mp3</code>, <code>.mp4</code>, <code>.json</code>, <code>.csv</code>.
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                        <div>
                            <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                                Version Tag
                            </label>
                            <input type="text" name="version" value="<?php echo htmlspecialchars((string)$version, ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px;">
                        </div>
                        <div>
                            <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                                Max Downloads / User
                            </label>
                            <input type="number" name="max_downloads_per_user" min="0" value="<?php echo htmlspecialchars((string)$maxDownloads, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Leave blank for unlimited" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px;">
                        </div>
                    </div>

                    <div>
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            Download Link Expiry (Days)
                        </label>
                        <input type="number" name="download_expiry_days" min="0" value="<?php echo htmlspecialchars((string)$expiryDays, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Leave blank for lifetime access" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px;">
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
                            <input type="checkbox" name="is_free" id="fd-is-free" value="1" <?php echo $isFree ? 'checked' : ''; ?>>
                            Free Product (৳0.00)
                        </label>
                    </div>

                    <div id="fd-pricing-fields">
                        <div style="margin-bottom: 14px;">
                            <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                                Catalog Original Price (৳)
                            </label>
                            <input type="number" step="0.01" min="0" name="original_price" id="fd-original-price" value="<?php echo htmlspecialchars((string)$origPrice, ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 14px;">
                        </div>

                        <div style="margin-bottom: 14px;">
                            <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                                Discount Percentage (%)
                            </label>
                            <input type="number" step="0.01" min="0" max="100" name="discount_percent" id="fd-discount-percent" value="<?php echo htmlspecialchars((string)$discPercent, ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 14px;">
                        </div>
                    </div>

                    <!-- Calculated Live Selling Price Preview -->
                    <div style="background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; padding: 12px; margin-top: 8px;">
                        <div style="font-size: 12px; color: #646970; margin-bottom: 2px;">Calculated Selling Price:</div>
                        <div id="fd-selling-price" style="font-size: 20px; font-weight: 700; color: #1e1e1e;">
                            ৳<?php echo htmlspecialchars(number_format((float)$product->final_price, 2), ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <div id="fd-discount-badge" style="font-size: 11px; color: #d63638; font-weight: 600; margin-top: 2px; display: none;"></div>
                    </div>
                </div>

                <!-- Settings & Save Card -->
                <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                    <h3 style="font-size: 15px; font-weight: 600; margin: 0 0 16px 0; color: #1e1e1e; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px;">Publishing & Access</h3>

                    <div style="margin-bottom: 14px;">
                        <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 13px; color: #1e1e1e; cursor: pointer;">
                            <input type="checkbox" name="is_membership_eligible" value="1" <?php echo $isMembership ? 'checked' : ''; ?>>
                            Include in Active Memberships
                        </label>
                    </div>

                    <div style="margin-bottom: 18px;">
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            Status
                        </label>
                        <select name="status" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px; background: #fff;">
                            <option value="draft" <?php echo ($status === 'draft') ? 'selected' : ''; ?>>Draft (Hidden)</option>
                            <option value="published" <?php echo ($status === 'published') ? 'selected' : ''; ?>>Published (Live)</option>
                            <option value="archived" <?php echo ($status === 'archived') ? 'selected' : ''; ?>>Archived (Retired)</option>
                        </select>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <button type="submit" style="width: 100%; background: #2271b1; color: #fff; border: none; padding: 10px 16px; border-radius: 4px; font-weight: 600; font-size: 14px; cursor: pointer;">
                            Save Changes
                        </button>
                        <a href="/admin/page/favorite-digital?action=view&id=<?php echo (int)$product->id; ?>" style="text-align: center; color: #646970; text-decoration: none; font-size: 13px; padding: 6px;">
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

