<?php
/**
 * View Digital Product Overview
 *
 * Variables:
 * - $product      : object
 * - $details      : ?object
 * - $csrfToken    : string
 * - $flashSuccess : ?string
 * - $flashError   : ?string
 */
?>
<div class="fd-admin-wrap" style="max-width: 960px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
    <div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <div>
            <a href="/admin/page/favorite-digital" style="color: #2271b1; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 4px;">
                &larr; Back to Digital Products
            </a>
            <div style="display: flex; align-items: center; gap: 12px; margin-top: 6px;">
                <h2 style="font-size: 22px; font-weight: 600; color: #1e1e1e; margin: 0;">
                    <?php echo htmlspecialchars($product->title, ENT_QUOTES, 'UTF-8'); ?>
                </h2>
                <?php
                $statusStyles = [
                    'published' => 'background: #e7f7ed; color: #155724; border: 1px solid #c3e6cb;',
                    'draft'     => 'background: #fff3cd; color: #856404; border: 1px solid #ffeeba;',
                    'archived'  => 'background: #f8f9fa; color: #6c757d; border: 1px solid #e2e3e5;',
                ];
                $style = $statusStyles[$product->status] ?? $statusStyles['draft'];
                ?>
                <span style="display: inline-block; padding: 3px 10px; border-radius: 3px; font-size: 12px; font-weight: 600; text-transform: uppercase; <?php echo $style; ?>">
                    <?php echo htmlspecialchars($product->status, ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
            <div style="color: #646970; font-size: 13px; margin-top: 4px; font-family: monospace;">
                Slug: /<?php echo htmlspecialchars($product->slug, ENT_QUOTES, 'UTF-8'); ?> &bull; ID: #<?php echo (int)$product->id; ?>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 8px;">
            <a href="/admin/page/favorite-digital?action=edit&id=<?php echo (int)$product->id; ?>" style="display: inline-flex; align-items: center; background: #2271b1; color: #fff; text-decoration: none; padding: 8px 16px; border-radius: 4px; font-weight: 500; font-size: 13px;">
                Edit Product
            </a>

            <?php if ($product->status !== 'published'): ?>
                <form method="POST" action="/admin/page/favorite-digital" style="display: inline; margin: 0;">
                    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="publish">
                    <input type="hidden" name="id" value="<?php echo (int)$product->id; ?>">
                    <button type="submit" style="background: #28a745; color: #fff; border: none; padding: 8px 14px; border-radius: 4px; font-weight: 500; font-size: 13px; cursor: pointer;">
                        Publish
                    </button>
                </form>
            <?php endif; ?>

            <?php if ($product->status !== 'draft'): ?>
                <form method="POST" action="/admin/page/favorite-digital" style="display: inline; margin: 0;">
                    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="draft">
                    <input type="hidden" name="id" value="<?php echo (int)$product->id; ?>">
                    <button type="submit" style="background: #f0ad4e; color: #fff; border: none; padding: 8px 14px; border-radius: 4px; font-weight: 500; font-size: 13px; cursor: pointer;">
                        Move to Draft
                    </button>
                </form>
            <?php endif; ?>

            <?php if ($product->status !== 'archived'): ?>
                <form method="POST" action="/admin/page/favorite-digital" style="display: inline; margin: 0;">
                    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="archive">
                    <input type="hidden" name="id" value="<?php echo (int)$product->id; ?>">
                    <button type="submit" style="background: #6c757d; color: #fff; border: none; padding: 8px 14px; border-radius: 4px; font-weight: 500; font-size: 13px; cursor: pointer;">
                        Archive
                    </button>
                </form>
            <?php endif; ?>
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
        <!-- Left: Product Details & File Info -->
        <div style="display: flex; flex-direction: column; gap: 20px;">
            <!-- Description Card -->
            <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                <h3 style="font-size: 15px; font-weight: 600; margin: 0 0 12px 0; color: #1e1e1e; border-bottom: 1px solid #f0f0f1; padding-bottom: 8px;">Product Overview</h3>
                <?php if (!empty($product->short_description)): ?>
                    <div style="font-size: 14px; font-weight: 500; color: #3c434a; margin-bottom: 12px;">
                        <?php echo htmlspecialchars($product->short_description, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>
                <div style="font-size: 13px; color: #50575e; line-height: 1.6; white-space: pre-line;">
                    <?php echo !empty($product->description) ? htmlspecialchars($product->description, ENT_QUOTES, 'UTF-8') : '<em style="color: #8c8f94;">No full description provided.</em>'; ?>
                </div>
            </div>

            <!-- Downloadable Resource Information Card -->
            <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                <h3 style="font-size: 15px; font-weight: 600; margin: 0 0 16px 0; color: #1e1e1e; border-bottom: 1px solid #f0f0f1; padding-bottom: 8px;">Digital Asset Specifications</h3>

                <?php if (!empty($details->file_path)): ?>
                    <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                        <tr style="border-bottom: 1px solid #f0f0f1;">
                            <td style="padding: 8px 0; color: #646970; width: 140px; font-weight: 500;">Original Filename:</td>
                            <td style="padding: 8px 0; color: #1e1e1e; font-weight: 600;">
                                <?php echo htmlspecialchars($details->file_original_name ?? basename($details->file_path), ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                        </tr>
                        <tr style="border-bottom: 1px solid #f0f0f1;">
                            <td style="padding: 8px 0; color: #646970; font-weight: 500;">File Size:</td>
                            <td style="padding: 8px 0; color: #1e1e1e;">
                                <?php echo htmlspecialchars(number_format(((int)($details->file_size_bytes ?? 0)) / 1024, 2), ENT_QUOTES, 'UTF-8'); ?> KB
                                (<?php echo (int)($details->file_size_bytes ?? 0); ?> bytes)
                            </td>
                        </tr>
                        <tr style="border-bottom: 1px solid #f0f0f1;">
                            <td style="padding: 8px 0; color: #646970; font-weight: 500;">MIME Type:</td>
                            <td style="padding: 8px 0; color: #1e1e1e; font-family: monospace;">
                                <?php echo htmlspecialchars($details->file_mime ?? 'application/octet-stream', ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                        </tr>
                        <tr style="border-bottom: 1px solid #f0f0f1;">
                            <td style="padding: 8px 0; color: #646970; font-weight: 500;">SHA-256 Hash:</td>
                            <td style="padding: 8px 0; color: #1e1e1e; font-family: monospace; font-size: 12px; word-break: break-all;">
                                <?php echo htmlspecialchars($details->file_hash ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                        </tr>
                        <tr style="border-bottom: 1px solid #f0f0f1;">
                            <td style="padding: 8px 0; color: #646970; font-weight: 500;">Version:</td>
                            <td style="padding: 8px 0; color: #1e1e1e; font-weight: 600;">
                                v<?php echo htmlspecialchars($details->version ?? '1.0.0', ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                        </tr>
                        <tr style="border-bottom: 1px solid #f0f0f1;">
                            <td style="padding: 8px 0; color: #646970; font-weight: 500;">Max Downloads:</td>
                            <td style="padding: 8px 0; color: #1e1e1e;">
                                <?php echo !empty($details->max_downloads_per_user) ? (int)$details->max_downloads_per_user . ' per customer' : '<span style="color: #28a745;">Unlimited</span>'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 8px 0; color: #646970; font-weight: 500;">Download Expiry:</td>
                            <td style="padding: 8px 0; color: #1e1e1e;">
                                <?php echo !empty($details->download_expiry_days) ? (int)$details->download_expiry_days . ' days after purchase' : '<span style="color: #28a745;">Lifetime Access</span>'; ?>
                            </td>
                        </tr>
                    </table>
                <?php else: ?>
                    <div style="background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px; padding: 12px 14px; color: #856404; font-size: 13px;">
                        <strong>Notice:</strong> No digital downloadable asset is currently attached to this product.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right: Pricing & Entitlements -->
        <div style="display: flex; flex-direction: column; gap: 20px;">
            <!-- Pricing Overview Card -->
            <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                <h3 style="font-size: 15px; font-weight: 600; margin: 0 0 16px 0; color: #1e1e1e; border-bottom: 1px solid #f0f0f1; padding-bottom: 8px;">Pricing Breakdown</h3>

                <?php if (!empty($product->is_free)): ?>
                    <div style="background: #e7f7ed; border: 1px solid #c3e6cb; border-radius: 4px; padding: 12px; text-align: center; margin-bottom: 12px;">
                        <div style="font-size: 18px; font-weight: 700; color: #155724;">FREE (৳0.00)</div>
                        <div style="font-size: 12px; color: #155724;">Available to all users at no cost</div>
                    </div>
                <?php else: ?>
                    <div style="background: #f8f9fa; border: 1px solid #dcdcde; border-radius: 4px; padding: 14px; text-align: center; margin-bottom: 16px;">
                        <div style="font-size: 12px; color: #646970; text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px;">Current Selling Price</div>
                        <div style="font-size: 26px; font-weight: 700; color: #1e1e1e; margin: 4px 0;">
                            ৳<?php echo htmlspecialchars(number_format((float)$product->final_price, 2), ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <?php if ((float)$product->discount_percent > 0): ?>
                            <span style="display: inline-block; background: #fdf2f2; color: #d63638; border: 1px solid #f8d7da; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: 600;">
                                <?php echo htmlspecialchars(number_format((float)$product->discount_percent, 2), ENT_QUOTES, 'UTF-8'); ?>% Discount Applied
                            </span>
                        <?php endif; ?>
                    </div>

                    <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                        <tr style="border-bottom: 1px solid #f0f0f1;">
                            <td style="padding: 8px 0; color: #646970;">Original Price:</td>
                            <td style="padding: 8px 0; text-align: right; color: #1e1e1e; font-weight: 600;">
                                ৳<?php echo htmlspecialchars(number_format((float)$product->original_price, 2), ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                        </tr>
                        <tr style="border-bottom: 1px solid #f0f0f1;">
                            <td style="padding: 8px 0; color: #646970;">Discount:</td>
                            <td style="padding: 8px 0; text-align: right; color: #d63638; font-weight: 600;">
                                <?php echo htmlspecialchars(number_format((float)$product->discount_percent, 2), ENT_QUOTES, 'UTF-8'); ?>%
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 8px 0; color: #646970; font-weight: 600;">Derived Final Price:</td>
                            <td style="padding: 8px 0; text-align: right; color: #1e1e1e; font-weight: 700;">
                                ৳<?php echo htmlspecialchars(number_format((float)$product->final_price, 2), ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                        </tr>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Membership Access Card -->
            <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                <h3 style="font-size: 15px; font-weight: 600; margin: 0 0 12px 0; color: #1e1e1e; border-bottom: 1px solid #f0f0f1; padding-bottom: 8px;">Membership Inclusion</h3>
                <?php if (!empty($product->is_membership_eligible)): ?>
                    <div style="display: flex; align-items: flex-start; gap: 8px;">
                        <span style="color: #28a745; font-size: 16px;">✔</span>
                        <div style="font-size: 13px; color: #155724; line-height: 1.5;">
                            <strong>Included in Memberships</strong><br>
                            Members with active membership plan have automatic access to this product.
                        </div>
                    </div>
                <?php else: ?>
                    <div style="font-size: 13px; color: #646970;">
                        Not included in general memberships (standalone purchase only).
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

