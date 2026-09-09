<?php
/**
 * Admin Favorite Pay Audit Entry Detail View
 *
 * @var array $log
 * @var array|null $actorUser
 * @var array|null $targetUser
 * @var \FavoriteCMS\Models\User|null $currentUser
 */

$id = (int)$log['id'];
$action = htmlspecialchars((string)$log['action'], ENT_QUOTES, 'UTF-8');
$subjectType = htmlspecialchars((string)$log['subject_type'], ENT_QUOTES, 'UTF-8');
$subjectId = htmlspecialchars((string)($log['subject_id'] ?? '—'), ENT_QUOTES, 'UTF-8');
$desc = htmlspecialchars((string)$log['description'], ENT_QUOTES, 'UTF-8');
$actorType = strtolower((string)($log['actor_type'] ?? 'system'));
$actorName = htmlspecialchars((string)($log['actor_name'] ?? 'System'), ENT_QUOTES, 'UTF-8');
$actorUid = (int)($log['actor_user_id'] ?? 0);
$targetUid = (int)($log['target_user_id'] ?? 0);
$withdrawalId = htmlspecialchars((string)($log['withdrawal_id'] ?? ''), ENT_QUOTES, 'UTF-8');
$paymentId = htmlspecialchars((string)($log['payment_id'] ?? ''), ENT_QUOTES, 'UTF-8');
$ip = htmlspecialchars((string)($log['ip_address'] ?? '—'), ENT_QUOTES, 'UTF-8');
$userAgent = htmlspecialchars((string)($log['user_agent'] ?? '—'), ENT_QUOTES, 'UTF-8');
$createdAt = htmlspecialchars((string)$log['created_at'], ENT_QUOTES, 'UTF-8');
$meta = $log['metadata_parsed'] ?? [];

$isSettings = ($subjectType === 'settings' || $action === 'settings.updated');
$settingsChanges = $meta['changes'] ?? null;
?>

<div class="wrap" style="max-width: 1080px; margin: 20px auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <!-- Navigation Back Link -->
    <div style="margin-bottom: 20px;">
        <a href="/admin/page/favorite-pay-audit" style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: #2563eb; text-decoration: none; font-weight: 600; margin-bottom: 12px;">
            &larr; Back to Audit Log
        </a>
        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
            <div>
                <h1 style="margin: 0; font-size: 24px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 10px;">
                    <span>🛡️</span> Audit Event Record #<?php echo $id; ?>
                </h1>
                <p style="margin: 6px 0 0; color: #64748b; font-size: 13px;">
                    Immutable administrative activity record logged at <?php echo $createdAt; ?>.
                </p>
            </div>
            <?php if (!empty($withdrawalId)): ?>
                <div>
                    <a href="/admin/page/favorite-pay-withdrawals?id=<?php echo urlencode($withdrawalId); ?>" class="button button-secondary" style="font-size: 13px;">
                        View Withdrawal #<?php echo $withdrawalId; ?> &rarr;
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Event Summary Card -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 22px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <h3 style="margin: 0 0 18px; font-size: 16px; font-weight: 700; color: #0f172a;">
            Operational Event Details
        </h3>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 18px; margin-bottom: 18px;">
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px;">
                <span style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #64748b; display: block;">Action</span>
                <strong style="font-size: 16px; color: #0f172a; font-family: monospace; display: block; margin-top: 4px;">
                    <?php echo $action; ?>
                </strong>
            </div>

            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px;">
                <span style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #64748b; display: block;">Subject</span>
                <div style="font-size: 15px; color: #0f172a; margin-top: 4px;">
                    <strong style="text-transform: capitalize;"><?php echo $subjectType; ?></strong>
                    <?php if ($subjectId !== '—'): ?>
                        <code style="font-size: 13px; color: #475569; margin-left: 6px;"><?php echo $subjectId; ?></code>
                    <?php endif; ?>
                </div>
            </div>

            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px;">
                <span style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #64748b; display: block;">Actor</span>
                <div style="font-size: 15px; color: #0f172a; margin-top: 4px; display: flex; align-items: center; gap: 8px;">
                    <span style="display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; background: #e0e7ff; color: #4338ca;">
                        <?php echo htmlspecialchars(strtoupper($actorType), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <strong><?php echo $actorName; ?></strong>
                    <?php if ($actorUid > 0): ?>
                        <span style="color: #64748b; font-size: 12px;">(#<?php echo $actorUid; ?>)</span>
                    <?php endif; ?>
                </div>
            </div>

            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px;">
                <span style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #64748b; display: block;">Target Customer</span>
                <div style="font-size: 15px; color: #0f172a; margin-top: 4px;">
                    <?php if ($targetUid > 0): ?>
                        <a href="/admin/page/favorite-pay-dashboard?action=customer&user_id=<?php echo $targetUid; ?>" style="color: #2563eb; font-weight: 600; text-decoration: none;">
                            User #<?php echo $targetUid; ?>
                            <?php if (!empty($targetUser['username'])): ?>
                                (<?php echo htmlspecialchars($targetUser['username'], ENT_QUOTES, 'UTF-8'); ?>)
                            <?php endif; ?>
                        </a>
                    <?php else: ?>
                        <span style="color: #94a3b8;">None</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div style="padding: 14px; background: #f1f5f9; border-radius: 8px; border-left: 4px solid #3b82f6; margin-bottom: 18px;">
            <span style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Description</span>
            <div style="font-size: 14px; font-weight: 600; color: #0f172a;">
                <?php echo $desc; ?>
            </div>
        </div>

        <!-- IP & User Agent Info -->
        <div style="display: flex; flex-wrap: wrap; gap: 24px; padding: 12px 16px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 12px; color: #64748b;">
            <div>
                <strong>Client IP:</strong> <span style="font-family: monospace; color: #0f172a;"><?php echo $ip; ?></span>
            </div>
            <div style="flex: 1; min-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                <strong>User Agent:</strong> <span style="color: #475569;" title="<?php echo $userAgent; ?>"><?php echo $userAgent; ?></span>
            </div>
        </div>
    </div>

    <!-- Before / After Settings Card (When applicable) -->
    <?php if ($isSettings && is_array($settingsChanges)): ?>
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 22px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <h3 style="margin: 0 0 16px; font-size: 16px; font-weight: 700; color: #0f172a;">
                ⚙️ Settings Mutation Comparison (Before vs After)
            </h3>

            <div style="overflow-x: auto;">
                <table class="wp-list-table widefat fixed striped" style="margin: 0;">
                    <thead>
                        <tr>
                            <th style="width: 220px;">Setting Name</th>
                            <th>Previous Value (Before)</th>
                            <th>Updated Value (After)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($settingsChanges as $key => $change): 
                            $bVal = $change['before'] ?? null;
                            $aVal = $change['after'] ?? null;

                            $formatVal = function($v) {
                                if (is_bool($v)) {
                                    return $v ? '<strong style="color: #15803d;">ENABLED (ON)</strong>' : '<strong style="color: #b91c1c;">DISABLED (OFF)</strong>';
                                }
                                if (is_array($v)) {
                                    return '<code>' . htmlspecialchars(implode(', ', $v), ENT_QUOTES, 'UTF-8') . '</code>';
                                }
                                if ($v === null) {
                                    return '<span style="color: #94a3b8;">None</span>';
                                }
                                return '<strong>' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '</strong>';
                            };

                            $label = match($key) {
                                'enabled'           => 'Withdrawals Globally Enabled',
                                'min_amount'        => 'Minimum Withdrawal Amount',
                                'max_monthly_count' => 'Monthly Withdrawal Request Limit',
                                'allowed_methods'   => 'Allowed Withdrawal Methods',
                                'fee_fixed'         => 'Fixed Fee per Withdrawal',
                                'fee_pct'           => 'Percentage Fee (%)',
                                default             => ucwords(str_replace('_', ' ', (string)$key)),
                            };
                        ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                <td style="background: #fef2f2; color: #991b1b;">
                                    <?php echo $formatVal($bVal); ?>
                                </td>
                                <td style="background: #f0fdf4; color: #166534;">
                                    <?php echo $formatVal($aVal); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Context Metadata Card -->
    <?php if (!empty($meta)): ?>
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 22px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <h3 style="margin: 0 0 14px; font-size: 16px; font-weight: 700; color: #0f172a;">
                Audit Metadata Payload
            </h3>
            <pre style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; font-size: 12px; color: #334155; overflow-x: auto; margin: 0; line-height: 1.5;"><?php echo htmlspecialchars(json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?></pre>
        </div>
    <?php endif; ?>
</div>
