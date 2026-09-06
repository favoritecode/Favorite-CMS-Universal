<?php
/**
 * View Customer Membership Record View
 *
 * Variables:
 * - $membership     : object
 * - $availablePlans : array
 * - $csrfToken      : string
 * - $flashSuccess   : ?string
 * - $flashError     : ?string
 */
?>
<div class="fd-admin-wrap" style="max-width: 900px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
    <div style="margin-bottom: 20px;">
        <a href="/admin/page/favorite-digital-memberships" style="color: #2271b1; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 4px;">
            &larr; Back to Memberships
        </a>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 8px;">
            <div>
                <h2 style="font-size: 22px; font-weight: 600; color: #1e1e1e; margin: 0 0 4px 0;">
                    Subscription #<?php echo (int)$membership->id; ?>
                </h2>
                <p style="color: #646970; font-size: 13px; margin: 0;">User #<?php echo (int)$membership->user_id; ?> &bull; <?php echo htmlspecialchars($membership->plan_title ?? 'Membership', ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div>
                <?php
                $st = $membership->status;
                if ($st === 'active'): ?>
                    <span style="display: inline-block; padding: 4px 12px; border-radius: 4px; font-size: 13px; font-weight: 600; background: #e7f7ed; color: #155724;">Active</span>
                <?php elseif ($st === 'grace'): ?>
                    <span style="display: inline-block; padding: 4px 12px; border-radius: 4px; font-size: 13px; font-weight: 600; background: #fef3c7; color: #92400e;">In Grace Window</span>
                <?php elseif ($st === 'cancelled'): ?>
                    <span style="display: inline-block; padding: 4px 12px; border-radius: 4px; font-size: 13px; font-weight: 600; background: #f1f5f9; color: #475569;">Cancelled (Active until Expiry)</span>
                <?php else: ?>
                    <span style="display: inline-block; padding: 4px 12px; border-radius: 4px; font-size: 13px; font-weight: 600; background: #fdf2f2; color: #721c24;">Expired</span>
                <?php endif; ?>
            </div>
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

    <div style="display: grid; grid-template-columns: 3fr 2fr; gap: 24px;">
        <!-- Left Column: Details & Timeline -->
        <div style="display: flex; flex-direction: column; gap: 20px;">
            <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                <h3 style="font-size: 15px; font-weight: 600; margin: 0 0 16px 0; color: #1e1e1e; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px;">Subscription Overview</h3>

                <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                    <tr style="border-bottom: 1px solid #f0f0f1;">
                        <td style="padding: 8px 0; color: #646970; width: 140px;">Customer User ID:</td>
                        <td style="padding: 8px 0; font-weight: 600; color: #1e1e1e;">User #<?php echo (int)$membership->user_id; ?></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #f0f0f1;">
                        <td style="padding: 8px 0; color: #646970;">Current Plan:</td>
                        <td style="padding: 8px 0; font-weight: 600; color: #1e1e1e;">
                            <?php echo htmlspecialchars($membership->plan_title ?? '', ENT_QUOTES, 'UTF-8'); ?>
                            (<?php echo htmlspecialchars($membership->plan_type ?? '', ENT_QUOTES, 'UTF-8'); ?>)
                        </td>
                    </tr>
                    <tr style="border-bottom: 1px solid #f0f0f1;">
                        <td style="padding: 8px 0; color: #646970;">Started At:</td>
                        <td style="padding: 8px 0; font-family: monospace;"><?php echo htmlspecialchars($membership->started_at, ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #f0f0f1;">
                        <td style="padding: 8px 0; color: #646970;">Expires At:</td>
                        <td style="padding: 8px 0; font-family: monospace; font-weight: 600; color: #1e1e1e;">
                            <?php echo htmlspecialchars($membership->expires_at, ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                    </tr>
                    <?php if (!empty($membership->grace_expires_at)): ?>
                        <tr style="border-bottom: 1px solid #f0f0f1;">
                            <td style="padding: 8px 0; color: #b45309;">Grace Window Until:</td>
                            <td style="padding: 8px 0; font-family: monospace; color: #b45309; font-weight: 600;">
                                <?php echo htmlspecialchars($membership->grace_expires_at, ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <td style="padding: 8px 0; color: #646970;">Auto Renewal:</td>
                        <td style="padding: 8px 0;">
                            <?php if (!empty($membership->auto_renew)): ?>
                                <span style="color: #15803d; font-weight: 600;">Enabled (Active recurring opt-in)</span>
                            <?php else: ?>
                                <span style="color: #646970;">Disabled (One-time or opted out)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Right Column: Administrative Actions -->
        <div style="display: flex; flex-direction: column; gap: 20px;">
            <!-- Extend Duration Action -->
            <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 18px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                <h4 style="font-size: 14px; font-weight: 600; margin: 0 0 12px 0; color: #1e1e1e;">Extend Membership</h4>
                <p style="font-size: 12px; color: #646970; margin: 0 0 12px 0;">
                    Appends plan duration directly onto existing active time. Zero paid time is lost.
                </p>
                <form method="POST" action="/admin/page/favorite-digital-memberships">
                    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="extend">
                    <input type="hidden" name="id" value="<?php echo (int)$membership->id; ?>">

                    <div style="margin-bottom: 12px;">
                        <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Duration to add:</label>
                        <select name="new_plan_id" style="width: 100%; padding: 6px 8px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 12px;">
                            <?php foreach ($availablePlans as $ap): ?>
                                <option value="<?php echo (int)$ap->id; ?>" <?php echo ((int)$ap->id === (int)$membership->plan_id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ap->title . ' (' . $ap->plan_type . ')', ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" style="width: 100%; padding: 8px 12px; background: #2271b1; color: #fff; border: none; border-radius: 4px; font-size: 13px; font-weight: 500; cursor: pointer;">
                        Extend Membership
                    </button>
                </form>
            </div>

            <!-- Auto-Renew Toggle Action -->
            <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 18px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                <h4 style="font-size: 14px; font-weight: 600; margin: 0 0 8px 0; color: #1e1e1e;">Auto-Renewal Control</h4>
                <p style="font-size: 12px; color: #646970; margin: 0 0 12px 0;">
                    Turning auto-renewal OFF does NOT cancel or shorten current paid time.
                </p>
                <form method="POST" action="/admin/page/favorite-digital-memberships">
                    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="toggle_auto_renew">
                    <input type="hidden" name="id" value="<?php echo (int)$membership->id; ?>">

                    <?php if (!empty($membership->auto_renew)): ?>
                        <input type="hidden" name="enable" value="0">
                        <button type="submit" style="width: 100%; padding: 8px 12px; background: #f6f7f7; color: #b32d2e; border: 1px solid #b32d2e; border-radius: 4px; font-size: 13px; font-weight: 500; cursor: pointer;">
                            Turn Auto-Renewal OFF
                        </button>
                    <?php else: ?>
                        <input type="hidden" name="enable" value="1">
                        <button type="submit" style="width: 100%; padding: 8px 12px; background: #f6f7f7; color: #15803d; border: 1px solid #15803d; border-radius: 4px; font-size: 13px; font-weight: 500; cursor: pointer;">
                            Turn Auto-Renewal ON
                        </button>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Grace Recovery (if in Grace) -->
            <?php if ($membership->status === 'grace'): ?>
                <div style="background: #fefce8; border: 1px solid #fef08a; border-radius: 4px; padding: 18px;">
                    <h4 style="font-size: 14px; font-weight: 600; margin: 0 0 8px 0; color: #854d0e;">Grace Window Recovery</h4>
                    <p style="font-size: 12px; color: #a16207; margin: 0 0 12px 0;">
                        Customer is currently in grace period. Confirm payment recovery to restore active standing.
                    </p>
                    <form method="POST" action="/admin/page/favorite-digital-memberships">
                        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="recover_grace">
                        <input type="hidden" name="id" value="<?php echo (int)$membership->id; ?>">
                        <button type="submit" style="width: 100%; padding: 8px 12px; background: #ca8a04; color: #fff; border: none; border-radius: 4px; font-size: 13px; font-weight: 600; cursor: pointer;">
                            Recover to Active Status
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Expire Immediately Action -->
            <?php if ($membership->status !== 'expired'): ?>
                <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 18px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                    <h4 style="font-size: 14px; font-weight: 600; margin: 0 0 8px 0; color: #dc3545;">Manual Expiration</h4>
                    <p style="font-size: 12px; color: #646970; margin: 0 0 12px 0;">
                        Immediately revokes active membership access.
                    </p>
                    <form method="POST" action="/admin/page/favorite-digital-memberships" onsubmit="return confirm('Are you sure you want to expire this membership immediately?');">
                        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="expire">
                        <input type="hidden" name="id" value="<?php echo (int)$membership->id; ?>">
                        <button type="submit" style="width: 100%; padding: 8px 12px; background: #dc3545; color: #fff; border: none; border-radius: 4px; font-size: 13px; font-weight: 500; cursor: pointer;">
                            Expire Immediately
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
