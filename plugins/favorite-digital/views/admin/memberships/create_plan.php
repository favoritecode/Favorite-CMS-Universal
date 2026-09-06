<?php
/**
 * Create Membership Plan View
 *
 * Variables:
 * - $old        : array
 * - $csrfToken  : string
 * - $flashError : ?string
 */
?>
<div class="fd-admin-wrap" style="max-width: 860px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
    <div style="margin-bottom: 20px;">
        <a href="/admin/page/favorite-digital-memberships" style="color: #2271b1; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 4px;">
            &larr; Back to Memberships
        </a>
        <h2 style="font-size: 22px; font-weight: 600; color: #1e1e1e; margin: 8px 0 4px 0;">Create Membership Plan</h2>
        <p style="color: #646970; font-size: 13px; margin: 0;">Define a new recurring or one-time subscription tier (Weekly or Monthly).</p>
    </div>

    <?php if (!empty($flashError)): ?>
        <div style="background: #fdf2f2; border-left: 4px solid #dc3545; padding: 12px 16px; margin-bottom: 20px; border-radius: 4px; color: #721c24;">
            <strong>Error:</strong> <?php echo htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="/admin/page/favorite-digital-memberships">
        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="store_plan">

        <div style="display: flex; flex-direction: column; gap: 20px;">
            <!-- Plan Details Card -->
            <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                <h3 style="font-size: 15px; font-weight: 600; margin: 0 0 16px 0; color: #1e1e1e; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px;">Tier Information</h3>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                        Plan Title <span style="color: #dc3545;">*</span>
                    </label>
                    <input type="text" name="title" required value="<?php echo htmlspecialchars((string)($old['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g., VIP Monthly Pass" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 14px;">
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                        URL Slug
                        <span style="font-weight: 400; color: #646970; font-size: 12px;">(optional, auto-generated from title)</span>
                    </label>
                    <input type="text" name="slug" value="<?php echo htmlspecialchars((string)($old['slug'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g., vip-monthly-pass" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px; font-family: monospace;">
                </div>

                <div>
                    <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                        Description
                    </label>
                    <textarea name="description" rows="3" placeholder="Benefits and content unlocked by this membership..." style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px; font-family: inherit;"><?php echo htmlspecialchars((string)($old['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
            </div>

            <!-- Duration & Billing Policy Card -->
            <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                <h3 style="font-size: 15px; font-weight: 600; margin: 0 0 16px 0; color: #1e1e1e; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px;">Duration &amp; Renewal Policy</h3>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            Plan Type <span style="color: #dc3545;">*</span>
                        </label>
                        <select name="plan_type" id="fd-plan-type" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px;">
                            <option value="monthly" <?php echo (($old['plan_type'] ?? 'monthly') === 'monthly') ? 'selected' : ''; ?>>Monthly (1 Calendar Month)</option>
                            <option value="weekly" <?php echo (($old['plan_type'] ?? '') === 'weekly') ? 'selected' : ''; ?>>Weekly (7 Days)</option>
                        </select>
                        <p style="color: #646970; font-size: 11px; margin: 4px 0 0 0;">Calendar month calculation strictly clamps end-of-month dates (e.g. Jan 31 &rarr; Feb 28).</p>
                    </div>

                    <div>
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            Grace Period (Days)
                        </label>
                        <input type="number" name="grace_period_days" min="0" value="<?php echo htmlspecialchars((string)($old['grace_period_days'] ?? '3'), ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px;">
                        <p style="color: #646970; font-size: 11px; margin: 4px 0 0 0;">Standard default: 1 day for Weekly, 3 days for Monthly.</p>
                    </div>
                </div>

                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; padding: 12px 14px;">
                    <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; font-size: 13px; color: #1e1e1e;">
                        <input type="checkbox" name="allows_auto_renewal" value="1" <?php echo !empty($old['allows_auto_renewal']) ? 'checked' : ''; ?> style="margin-top: 2px;">
                        <div>
                            <strong>Allow Automatic Renewal</strong>
                            <div style="color: #646970; font-size: 12px; margin-top: 2px;">
                                Default behavior is OFF (One-time purchase). Checking this permits customers to opt in to recurring auto-renewal.
                            </div>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Pricing Card -->
            <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                <h3 style="font-size: 15px; font-weight: 600; margin: 0 0 16px 0; color: #1e1e1e; border-bottom: 1px solid #f0f0f1; padding-bottom: 10px;">Pricing</h3>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            Price ($)
                        </label>
                        <input type="number" step="0.01" min="0" name="original_price" value="<?php echo htmlspecialchars((string)($old['original_price'] ?? '9.99'), ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px;">
                    </div>
                    <div>
                        <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #1e1e1e;">
                            Discount (%)
                        </label>
                        <input type="number" step="0.01" min="0" max="100" name="discount_percent" value="<?php echo htmlspecialchars((string)($old['discount_percent'] ?? '0.00'), ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px;">
                    </div>
                </div>

                <div>
                    <label style="display: inline-flex; align-items: center; gap: 8px; font-size: 13px; color: #1e1e1e; cursor: pointer;">
                        <input type="checkbox" name="is_free" value="1" <?php echo !empty($old['is_free']) ? 'checked' : ''; ?>>
                        Free Tier (0.00 Price)
                    </label>
                </div>
            </div>

            <!-- Submit Buttons -->
            <div style="display: flex; justify-content: flex-end; gap: 12px;">
                <a href="/admin/page/favorite-digital-memberships" style="padding: 9px 18px; border: 1px solid #c3c4c7; background: #f6f7f7; color: #50575e; text-decoration: none; border-radius: 4px; font-size: 13px;">Cancel</a>
                <button type="submit" style="padding: 9px 24px; background: #2271b1; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 13px;">Create Membership Tier</button>
            </div>
        </div>
    </form>
</div>
