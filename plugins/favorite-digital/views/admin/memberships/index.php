<?php
/**
 * Membership Management Dashboard View
 *
 * Variables:
 * - $plans        : array of objects (membership tiers)
 * - $memberships  : array of objects (customer subscriptions)
 * - $total        : int
 * - $page         : int
 * - $totalPages   : int
 * - $statusFilter : string
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

    <!-- Header & Action Bar -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
        <div>
            <h2 style="font-size: 22px; font-weight: 600; color: #1e1e1e; margin: 0 0 4px 0;">Membership Management</h2>
            <p style="color: #646970; font-size: 13px; margin: 0;">Configure subscription tiers (Weekly &amp; Monthly) and manage customer memberships.</p>
        </div>
        <div>
            <a href="/admin/page/favorite-digital-memberships?action=create_plan" style="display: inline-flex; align-items: center; gap: 6px; background: #2271b1; color: #fff; text-decoration: none; padding: 8px 16px; border-radius: 4px; font-weight: 500; font-size: 14px;">
                <span>+</span> Add Membership Tier
            </a>
        </div>
    </div>

    <!-- Section 1: Membership Plans / Tiers -->
    <div style="margin-bottom: 36px;">
        <h3 style="font-size: 16px; font-weight: 600; margin: 0 0 12px 0; color: #1e1e1e; display: flex; align-items: center; gap: 8px;">
            <span>Membership Plans &amp; Tiers</span>
            <span style="background: #f0f0f1; color: #50575e; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: normal;">
                <?php echo count($plans); ?> tiers
            </span>
        </h3>

        <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; overflow-x: auto; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13px;">
                <thead>
                    <tr style="background: #f8f9fa; border-bottom: 1px solid #c3c4c7; color: #50575e; font-weight: 600;">
                        <th style="padding: 12px 14px; width: 60px;">ID</th>
                        <th style="padding: 12px 14px;">Plan Name</th>
                        <th style="padding: 12px 14px; width: 140px;">Type &amp; Period</th>
                        <th style="padding: 12px 14px; width: 120px;">Price</th>
                        <th style="padding: 12px 14px; width: 110px;">Grace Window</th>
                        <th style="padding: 12px 14px; width: 120px;">Auto-Renewal</th>
                        <th style="padding: 12px 14px; width: 100px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($plans)): ?>
                        <tr>
                            <td colspan="7" style="padding: 24px 14px; text-align: center; color: #646970;">
                                No membership plans configured.
                                <a href="/admin/page/favorite-digital-memberships?action=create_plan" style="color: #2271b1; text-decoration: underline; margin-left: 6px;">Create your first plan</a>.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($plans as $p): ?>
                            <tr style="border-bottom: 1px solid #f0f0f1;">
                                <td style="padding: 12px 14px; color: #646970; font-family: monospace;">
                                    #<?php echo (int)$p->id; ?>
                                </td>
                                <td style="padding: 12px 14px;">
                                    <strong style="color: #1e1e1e;"><?php echo htmlspecialchars($p->title, ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <div style="color: #646970; font-size: 12px; margin-top: 2px;">
                                        Slug: <code><?php echo htmlspecialchars($p->slug, ENT_QUOTES, 'UTF-8'); ?></code>
                                    </div>
                                </td>
                                <td style="padding: 12px 14px;">
                                    <?php if ($p->plan_type === 'weekly'): ?>
                                        <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; background: #e0f2fe; color: #0369a1;">Weekly (7 Days)</span>
                                    <?php else: ?>
                                        <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; background: #fdf4ff; color: #9333ea;">Monthly (1 Cal. Month)</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 14px; font-weight: 600; color: #1e1e1e;">
                                    <?php if (!empty($p->is_free)): ?>
                                        <span style="color: #16a34a;">Free</span>
                                    <?php else: ?>
                                        $<?php echo number_format((float)$p->final_price, 2); ?>
                                        <?php if ((float)$p->discount_percent > 0): ?>
                                            <div style="font-size: 11px; color: #646970; font-weight: normal; text-decoration: line-through;">
                                                $<?php echo number_format((float)$p->original_price, 2); ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 14px; color: #50575e;">
                                    <?php echo (int)$p->grace_period_days; ?> day<?php echo (int)$p->grace_period_days === 1 ? '' : 's'; ?>
                                </td>
                                <td style="padding: 12px 14px;">
                                    <?php if (!empty($p->allows_auto_renewal)): ?>
                                        <span style="color: #15803d; font-size: 12px; font-weight: 500;">&#10003; Supported</span>
                                    <?php else: ?>
                                        <span style="color: #646970; font-size: 12px;">Manual Only</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 14px; text-align: right;">
                                    <div style="display: inline-flex; align-items: center; gap: 8px;">
                                        <?php if ($p->status === 'published'): ?>
                                            <a href="/digital-store/<?php echo htmlspecialchars($p->slug, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" style="color: #2271b1; text-decoration: none; font-weight: 500;">View</a>
                                        <?php else: ?>
                                            <span style="color: #a7aaad; font-weight: 500; cursor: not-allowed;" title="Item is unpublished (draft/archived) and cannot be viewed on public storefront">View</span>
                                        <?php endif; ?>
                                        <span style="color: #dcdcde;">|</span>
                                        <a href="/admin/page/favorite-digital-memberships?action=edit_plan&amp;id=<?php echo (int)$p->product_id; ?>" style="color: #2271b1; text-decoration: none; font-weight: 500;">Edit</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Section 2: Customer Memberships / Subscriptions -->
    <div>
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 12px;">
            <h3 style="font-size: 16px; font-weight: 600; margin: 0; color: #1e1e1e;">
                Customer Subscriptions
            </h3>
            <!-- Status Tabs -->
            <div style="display: flex; gap: 6px; font-size: 12px;">
                <?php
                $tabs = ['all' => 'All', 'active' => 'Active', 'grace' => 'Grace', 'expired' => 'Expired', 'cancelled' => 'Cancelled'];
                foreach ($tabs as $key => $label):
                    $isActive = ($statusFilter === $key);
                ?>
                    <a href="/admin/page/favorite-digital-memberships?status=<?php echo $key; ?>" style="text-decoration: none; padding: 4px 8px; border-radius: 4px; <?php echo $isActive ? 'background: #2271b1; color: #fff; font-weight: 600;' : 'color: #2271b1; background: #f6f7f7;'; ?>">
                        <?php echo $label; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; overflow-x: auto; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13px;">
                <thead>
                    <tr style="background: #f8f9fa; border-bottom: 1px solid #c3c4c7; color: #50575e; font-weight: 600;">
                        <th style="padding: 12px 14px; width: 60px;">ID</th>
                        <th style="padding: 12px 14px; width: 90px;">User ID</th>
                        <th style="padding: 12px 14px;">Plan</th>
                        <th style="padding: 12px 14px; width: 100px;">Status</th>
                        <th style="padding: 12px 14px; width: 160px;">Expires At</th>
                        <th style="padding: 12px 14px; width: 110px;">Auto-Renew</th>
                        <th style="padding: 12px 14px; width: 120px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($memberships)): ?>
                        <tr>
                            <td colspan="7" style="padding: 24px 14px; text-align: center; color: #646970;">
                                No customer subscriptions found for this view.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($memberships as $m): ?>
                            <tr style="border-bottom: 1px solid #f0f0f1;">
                                <td style="padding: 12px 14px; color: #646970; font-family: monospace;">
                                    #<?php echo (int)$m->id; ?>
                                </td>
                                <td style="padding: 12px 14px; font-weight: 500; color: #1e1e1e;">
                                    User #<?php echo (int)$m->user_id; ?>
                                </td>
                                <td style="padding: 12px 14px;">
                                    <strong><?php echo htmlspecialchars($m->plan_title ?? 'Membership', ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <span style="font-size: 11px; color: #646970; text-transform: capitalize; margin-left: 4px;">
                                        (<?php echo htmlspecialchars($m->plan_type ?? '', ENT_QUOTES, 'UTF-8'); ?>)
                                    </span>
                                </td>
                                <td style="padding: 12px 14px;">
                                    <?php
                                    $st = $m->status;
                                    if ($st === 'active'): ?>
                                        <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; background: #e7f7ed; color: #155724;">Active</span>
                                    <?php elseif ($st === 'grace'): ?>
                                        <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; background: #fef3c7; color: #92400e;">Grace Window</span>
                                    <?php elseif ($st === 'cancelled'): ?>
                                        <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; background: #f1f5f9; color: #475569;">Cancelled</span>
                                    <?php else: ?>
                                        <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; background: #fdf2f2; color: #721c24;">Expired</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 14px; font-family: monospace; font-size: 12px; color: #1e1e1e;">
                                    <?php echo htmlspecialchars($m->expires_at, ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if (!empty($m->grace_expires_at) && $m->status === 'grace'): ?>
                                        <div style="color: #b45309; font-size: 11px;">
                                            Grace: <?php echo htmlspecialchars($m->grace_expires_at, ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 14px;">
                                    <?php if (!empty($m->auto_renew)): ?>
                                        <span style="color: #15803d; font-size: 12px; font-weight: 600;">ON</span>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 12px;">OFF</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 14px; text-align: right;">
                                    <a href="/admin/page/favorite-digital-memberships?action=view_membership&amp;id=<?php echo (int)$m->id; ?>" style="color: #2271b1; text-decoration: none; font-weight: 500; font-size: 12px; padding: 4px 8px; border: 1px solid #c3c4c7; border-radius: 3px; background: #f6f7f7;">
                                        Inspect
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
