<?php
/**
 * View Package / Bundle Overview
 *
 * Variables:
 * - $product                 : object
 * - $package                 : ?object
 * - $items                   : array of objects
 * - $combinedIndividualPrice : float
 * - $csrfToken               : string
 * - $flashSuccess            : ?string
 * - $flashError              : ?string
 */
$itemsCount = count($items);
$savings = $combinedIndividualPrice - (float)$product->final_price;
?>
<div class="fd-admin-wrap" style="max-width: 960px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
    <div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <div>
            <a href="/admin/page/favorite-digital-packages" style="color: #2271b1; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 4px;">
                &larr; Back to Packages
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
            <a href="/admin/page/favorite-digital-packages?action=edit&id=<?php echo (int)$product->id; ?>" style="display: inline-flex; align-items: center; background: #2271b1; color: #fff; text-decoration: none; padding: 8px 16px; border-radius: 4px; font-weight: 500; font-size: 13px;">
                Edit Package
            </a>

            <?php if ($product->status !== 'published'): ?>
                <form method="POST" action="/admin/page/favorite-digital-packages" style="display: inline; margin: 0;">
                    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="publish">
                    <input type="hidden" name="id" value="<?php echo (int)$product->id; ?>">
                    <button type="submit" style="background: #28a745; color: #fff; border: none; padding: 8px 14px; border-radius: 4px; font-weight: 500; font-size: 13px; cursor: pointer;">
                        Publish
                    </button>
                </form>
            <?php endif; ?>

            <?php if ($product->status !== 'draft'): ?>
                <form method="POST" action="/admin/page/favorite-digital-packages" style="display: inline; margin: 0;">
                    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="draft">
                    <input type="hidden" name="id" value="<?php echo (int)$product->id; ?>">
                    <button type="submit" style="background: #ffc107; color: #212529; border: none; padding: 8px 14px; border-radius: 4px; font-weight: 500; font-size: 13px; cursor: pointer;">
                        Set to Draft
                    </button>
                </form>
            <?php endif; ?>

            <?php if ($product->status !== 'archived'): ?>
                <form method="POST" action="/admin/page/favorite-digital-packages" style="display: inline; margin: 0;" onsubmit="return confirm('Archive this package?');">
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
        <!-- Left: Included Products Table & Description -->
        <div style="display: flex; flex-direction: column; gap: 20px;">
            <!-- Included Products Table Card -->
            <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f0f0f1; padding-bottom: 12px; margin-bottom: 16px;">
                    <h3 style="font-size: 16px; font-weight: 600; margin: 0; color: #1e1e1e;">
                        Bundled Products &amp; Services (<?php echo $itemsCount; ?>)
                    </h3>
                    <a href="/admin/page/favorite-digital-packages?action=edit&id=<?php echo (int)$product->id; ?>" style="color: #2271b1; text-decoration: none; font-size: 13px; font-weight: 500;">
                        Manage Items &rarr;
                    </a>
                </div>

                <?php if (empty($items)): ?>
                    <div style="background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px; padding: 14px; color: #856404; font-size: 13px;">
                        This package has no items bundled. <a href="/admin/page/favorite-digital-packages?action=edit&id=<?php echo (int)$product->id; ?>" style="color: #533f03; font-weight: 600;">Add items now</a> before publishing.
                    </div>
                <?php else: ?>
                    <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                        <thead>
                            <tr style="background: #f6f7f7; text-align: left; border-bottom: 1px solid #dcdcde;">
                                <th style="padding: 10px 12px; font-weight: 600; width: 50px;">#</th>
                                <th style="padding: 10px 12px; font-weight: 600;">Title</th>
                                <th style="padding: 10px 12px; font-weight: 600; width: 80px;">Type</th>
                                <th style="padding: 10px 12px; font-weight: 600; width: 70px;">Status</th>
                                <th style="padding: 10px 12px; font-weight: 600; width: 90px; text-align: right;">Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <?php
                                $isDigital = ($item->product_type === 'digital');
                                $badgeStyle = $isDigital
                                    ? 'background: #e8f0fe; color: #1967d2;'
                                    : 'background: #fce8e6; color: #c5221f;';
                                ?>
                                <tr style="border-bottom: 1px solid #f0f0f1;">
                                    <td style="padding: 12px; color: #646970; font-family: monospace; font-size: 12px;">
                                        #<?php echo (int)$item->sort_order; ?>
                                    </td>
                                    <td style="padding: 12px;">
                                        <div style="font-weight: 600; color: #1e1e1e;">
                                            <?php echo htmlspecialchars($item->title, ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                        <div style="font-size: 11px; color: #646970; font-family: monospace;">
                                            /<?php echo htmlspecialchars($item->slug, ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                    </td>
                                    <td style="padding: 12px;">
                                        <span style="display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: 600; text-transform: uppercase; <?php echo $badgeStyle; ?>">
                                            <?php echo htmlspecialchars($item->product_type, ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 12px; font-size: 12px; text-transform: capitalize; color: #646970;">
                                        <?php echo htmlspecialchars($item->status, ENT_QUOTES, 'UTF-8'); ?>
                                    </td>
                                    <td style="padding: 12px; text-align: right; font-weight: 600;">
                                        ৳<?php echo htmlspecialchars(number_format((float)$item->final_price, 2), ENT_QUOTES, 'UTF-8'); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: #f6f7f7; border-top: 2px solid #dcdcde;">
                                <td colspan="4" style="padding: 10px 12px; font-weight: 600; text-align: right;">
                                    Combined Individual Items Value:
                                </td>
                                <td style="padding: 10px 12px; font-weight: 700; text-align: right; color: #1e1e1e;">
                                    ৳<?php echo htmlspecialchars(number_format($combinedIndividualPrice, 2), ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Description Card -->
            <?php if (!empty($product->description)): ?>
                <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                    <h3 style="font-size: 15px; font-weight: 600; margin: 0 0 12px 0; color: #1e1e1e; border-bottom: 1px solid #f0f0f1; padding-bottom: 8px;">
                        Package Description
                    </h3>
                    <div style="color: #2c3338; font-size: 13px; line-height: 1.6; white-space: pre-line;">
                        <?php echo htmlspecialchars($product->description, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Right: Pricing & Meta Sidebar -->
        <div style="display: flex; flex-direction: column; gap: 20px;">
            <!-- Pricing Summary Card -->
            <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                <h3 style="font-size: 15px; font-weight: 600; margin: 0 0 16px 0; color: #1e1e1e; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px;">
                    Bundle Pricing
                </h3>

                <div style="margin-bottom: 16px;">
                    <div style="font-size: 12px; color: #646970;">Package Selling Price</div>
                    <div style="font-size: 26px; font-weight: 700; color: #1e1e1e; margin: 2px 0;">
                        <?php if ((bool)$product->is_free): ?>
                            <span style="color: #28a745;">৳0.00 (Free)</span>
                        <?php else: ?>
                            ৳<?php echo htmlspecialchars(number_format((float)$product->final_price, 2), ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </div>

                    <?php if (!(bool)$product->is_free && (float)$product->discount_percent > 0): ?>
                        <div style="display: flex; align-items: center; gap: 8px; font-size: 13px; margin-top: 4px;">
                            <span style="text-decoration: line-through; color: #8c8f94;">
                                ৳<?php echo htmlspecialchars(number_format((float)$product->original_price, 2), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <span style="background: #fce8e6; color: #c5221f; padding: 1px 6px; border-radius: 3px; font-weight: 600; font-size: 11px;">
                                <?php echo htmlspecialchars(number_format((float)$product->discount_percent, 2), ENT_QUOTES, 'UTF-8'); ?>% OFF
                            </span>
                        </div>
                    <?php endif; ?>
                </div>

                <div style="background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; padding: 12px; font-size: 12px; display: flex; flex-direction: column; gap: 8px;">
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #646970;">Items Bought Separately:</span>
                        <strong style="color: #1e1e1e;">৳<?php echo htmlspecialchars(number_format($combinedIndividualPrice, 2), ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>

                    <?php if ($savings > 0): ?>
                        <div style="display: flex; justify-content: space-between; border-top: 1px dashed #c3c4c7; padding-top: 6px;">
                            <span style="color: #155724; font-weight: 600;">Customer Package Savings:</span>
                            <strong style="color: #155724;">৳<?php echo htmlspecialchars(number_format($savings, 2), ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Metadata Card -->
            <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                <h3 style="font-size: 15px; font-weight: 600; margin: 0 0 16px 0; color: #1e1e1e; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px;">
                    Bundle Information
                </h3>

                <div style="display: flex; flex-direction: column; gap: 10px; font-size: 13px;">
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #646970;">Package ID:</span>
                        <strong style="color: #1e1e1e;">#<?php echo (int)$product->id; ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #646970;">Bundle Type:</span>
                        <strong style="color: #1e1e1e; text-transform: capitalize;"><?php echo htmlspecialchars($package->package_type ?? 'bundle', ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #646970;">Total Items:</span>
                        <strong style="color: #1e1e1e;"><?php echo $itemsCount; ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #646970;">Created:</span>
                        <span style="color: #1e1e1e; font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars((string)$product->created_at, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #646970;">Updated:</span>
                        <span style="color: #1e1e1e; font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars((string)$product->updated_at, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
