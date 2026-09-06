<?php
/**
 * Digital Products List View
 *
 * Variables:
 * - $products     : array of objects
 * - $statusFilter : ?string
 * - $search       : ?string
 * - $counts       : array
 * - $csrfToken    : string
 * - $flashSuccess : ?string
 * - $flashError   : ?string
 */
?>
<div class="fd-admin-wrap" style="max-width: 1200px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
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

    <!-- Top Action Bar -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 20px;">
        <div style="display: flex; gap: 8px; align-items: center;">
            <span style="font-size: 20px; font-weight: 600; color: #1e1e1e;">Digital Products</span>
            <span style="background: #f0f0f1; color: #50575e; padding: 3px 8px; border-radius: 12px; font-size: 13px; font-weight: 500;">
                <?php echo (int)($counts['all'] ?? count($products)); ?> total
            </span>
        </div>
        <div>
            <a href="/admin/page/favorite-digital?action=create" style="display: inline-flex; align-items: center; gap: 6px; background: #2271b1; color: #fff; text-decoration: none; padding: 8px 16px; border-radius: 4px; font-weight: 500; font-size: 14px;">
                <span>+</span> Add New Digital Product
            </a>
        </div>
    </div>

    <!-- Status Filters & Search Bar -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
        <!-- Status Tabs -->
        <div style="display: flex; gap: 8px; font-size: 13px;">
            <a href="/admin/page/favorite-digital" style="text-decoration: none; padding: 4px 10px; border-radius: 4px; <?php echo ($statusFilter === null) ? 'background: #2271b1; color: #fff; font-weight: 600;' : 'color: #2271b1; background: #f6f7f7;'; ?>">
                All (<?php echo (int)($counts['all'] ?? 0); ?>)
            </a>
            <a href="/admin/page/favorite-digital?status=published" style="text-decoration: none; padding: 4px 10px; border-radius: 4px; <?php echo ($statusFilter === 'published') ? 'background: #2271b1; color: #fff; font-weight: 600;' : 'color: #2271b1; background: #f6f7f7;'; ?>">
                Published (<?php echo (int)($counts['published'] ?? 0); ?>)
            </a>
            <a href="/admin/page/favorite-digital?status=draft" style="text-decoration: none; padding: 4px 10px; border-radius: 4px; <?php echo ($statusFilter === 'draft') ? 'background: #2271b1; color: #fff; font-weight: 600;' : 'color: #2271b1; background: #f6f7f7;'; ?>">
                Draft (<?php echo (int)($counts['draft'] ?? 0); ?>)
            </a>
            <a href="/admin/page/favorite-digital?status=archived" style="text-decoration: none; padding: 4px 10px; border-radius: 4px; <?php echo ($statusFilter === 'archived') ? 'background: #2271b1; color: #fff; font-weight: 600;' : 'color: #2271b1; background: #f6f7f7;'; ?>">
                Archived (<?php echo (int)($counts['archived'] ?? 0); ?>)
            </a>
        </div>

        <!-- Search Form -->
        <form method="GET" action="/admin/page/favorite-digital" style="display: flex; gap: 6px; margin: 0;">
            <?php if ($statusFilter): ?>
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>">
            <?php endif; ?>
            <input type="text" name="q" value="<?php echo htmlspecialchars((string)($search ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search products..." style="padding: 6px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px; width: 220px;">
            <button type="submit" style="padding: 6px 12px; background: #f6f7f7; border: 1px solid #8c8f94; border-radius: 4px; cursor: pointer; font-size: 13px;">Search</button>
            <?php if (!empty($search)): ?>
                <a href="/admin/page/favorite-digital<?php echo $statusFilter ? '?status=' . urlencode($statusFilter) : ''; ?>" style="padding: 6px 10px; color: #646970; text-decoration: none; font-size: 13px; align-self: center;">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Products Table -->
    <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; overflow-x: auto; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
        <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13px;">
            <thead>
                <tr style="background: #f8f9fa; border-bottom: 1px solid #c3c4c7; color: #50575e; font-weight: 600;">
                    <th style="padding: 12px 14px; width: 60px;">ID</th>
                    <th style="padding: 12px 14px;">Product</th>
                    <th style="padding: 12px 14px; width: 130px;">Price</th>
                    <th style="padding: 12px 14px; width: 90px;">Version</th>
                    <th style="padding: 12px 14px; width: 110px;">Membership</th>
                    <th style="padding: 12px 14px; width: 95px;">Status</th>
                    <th style="padding: 12px 14px; width: 180px; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="7" style="padding: 32px 14px; text-align: center; color: #646970;">
                            No digital products found.
                            <a href="/admin/page/favorite-digital?action=create" style="color: #2271b1; text-decoration: underline; margin-left: 6px;">Add your first digital product</a>.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($products as $p): ?>
                        <tr style="border-bottom: 1px solid #f0f0f1;">
                            <td style="padding: 12px 14px; color: #646970; font-family: monospace;">
                                #<?php echo (int)$p->id; ?>
                            </td>
                            <td style="padding: 12px 14px;">
                                <div style="font-weight: 600; font-size: 14px; margin-bottom: 2px;">
                                    <a href="/admin/page/favorite-digital?action=view&id=<?php echo (int)$p->id; ?>" style="color: #2271b1; text-decoration: none;">
                                        <?php echo htmlspecialchars($p->title, ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </div>
                                <div style="color: #646970; font-size: 12px; font-family: monospace;">
                                    /<?php echo htmlspecialchars($p->slug, ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </td>
                            <td style="padding: 12px 14px;">
                                <?php if (!empty($p->is_free)): ?>
                                    <span style="display: inline-block; background: #e7f7ed; color: #155724; font-weight: 600; padding: 2px 6px; border-radius: 3px; font-size: 12px;">FREE (৳0)</span>
                                <?php else: ?>
                                    <div style="font-weight: 600; color: #1e1e1e;">
                                        ৳<?php echo htmlspecialchars(number_format((float)$p->final_price, 2), ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                    <?php if ((float)$p->discount_percent > 0): ?>
                                        <div style="font-size: 11px; color: #8c8f94; text-decoration: line-through;">
                                            ৳<?php echo htmlspecialchars(number_format((float)$p->original_price, 2), ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                        <div style="font-size: 11px; color: #d63638; font-weight: 600;">
                                            -<?php echo htmlspecialchars(number_format((float)$p->discount_percent, 2), ENT_QUOTES, 'UTF-8'); ?>%
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 14px; color: #50575e; font-size: 12px;">
                                v<?php echo htmlspecialchars($p->version ?? '1.0.0', ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td style="padding: 12px 14px;">
                                <?php if (!empty($p->is_membership_eligible)): ?>
                                    <span style="display: inline-block; background: #e8f0fe; color: #1967d2; font-size: 11px; font-weight: 600; padding: 2px 6px; border-radius: 3px;">Included</span>
                                <?php else: ?>
                                    <span style="color: #8c8f94; font-size: 12px;">No</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 14px;">
                                <?php
                                $statusStyles = [
                                    'published' => 'background: #e7f7ed; color: #155724; border: 1px solid #c3e6cb;',
                                    'draft'     => 'background: #fff3cd; color: #856404; border: 1px solid #ffeeba;',
                                    'archived'  => 'background: #f8f9fa; color: #6c757d; border: 1px solid #e2e3e5;',
                                ];
                                $style = $statusStyles[$p->status] ?? $statusStyles['draft'];
                                ?>
                                <span style="display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; text-transform: uppercase; <?php echo $style; ?>">
                                    <?php echo htmlspecialchars($p->status, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td style="padding: 12px 14px; text-align: right;">
                                <div style="display: inline-flex; align-items: center; gap: 8px;">
                                    <?php if ($p->status === 'published'): ?>
                                        <a href="/digital-store/<?php echo htmlspecialchars($p->slug, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" style="color: #2271b1; text-decoration: none; font-weight: 500;">View</a>
                                    <?php else: ?>
                                        <span style="color: #a7aaad; font-weight: 500; cursor: not-allowed;" title="Item is unpublished (draft/archived) and cannot be viewed on public storefront">View</span>
                                    <?php endif; ?>
                                    <span style="color: #dcdcde;">|</span>
                                    <a href="/admin/page/favorite-digital?action=edit&id=<?php echo (int)$p->id; ?>" style="color: #2271b1; text-decoration: none; font-weight: 500;">Edit</a>
                                    <span style="color: #dcdcde;">|</span>
                                    <?php if ($p->status !== 'published'): ?>
                                        <form method="POST" action="/admin/page/favorite-digital" style="display: inline; margin: 0;">
                                            <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="publish">
                                            <input type="hidden" name="id" value="<?php echo (int)$p->id; ?>">
                                            <button type="submit" style="background: none; border: none; padding: 0; color: #28a745; font-size: 13px; font-weight: 500; cursor: pointer; text-decoration: none;">Publish</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" action="/admin/page/favorite-digital" style="display: inline; margin: 0;">
                                            <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="draft">
                                            <input type="hidden" name="id" value="<?php echo (int)$p->id; ?>">
                                            <button type="submit" style="background: none; border: none; padding: 0; color: #856404; font-size: 13px; font-weight: 500; cursor: pointer; text-decoration: none;">Draft</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

