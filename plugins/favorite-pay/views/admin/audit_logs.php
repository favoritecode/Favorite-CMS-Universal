<?php
/**
 * Admin Favorite Pay Audit Logs Listing View
 *
 * @var array $items
 * @var int $total
 * @var int $page
 * @var int $totalPages
 * @var int $limit
 * @var array $filters
 * @var array $userMap
 * @var \FavoriteCMS\Models\User|null $currentUser
 */

$currentAction = $filters['action'] ?? 'all';
$currentSubject = $filters['subject_type'] ?? 'all';
$currentActorType = $filters['actor_type'] ?? 'all';
$currentSearch = htmlspecialchars($filters['search'] ?? '', ENT_QUOTES, 'UTF-8');
$currentFrom = htmlspecialchars($filters['date_from'] ?? '', ENT_QUOTES, 'UTF-8');
$currentTo = htmlspecialchars($filters['date_to'] ?? '', ENT_QUOTES, 'UTF-8');
$currentWd = htmlspecialchars($filters['withdrawal_id'] ?? '', ENT_QUOTES, 'UTF-8');
$currentPay = htmlspecialchars($filters['payment_id'] ?? '', ENT_QUOTES, 'UTF-8');
?>

<div class="wrap" style="max-width: 1320px; margin: 20px auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <!-- Page Header -->
    <div style="margin-bottom: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
            <div>
                <h1 style="margin: 0; font-size: 24px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 10px;">
                    <span>🛡️</span> Favorite Pay &mdash; Operational Audit Log
                </h1>
                <p style="margin: 6px 0 0; color: #64748b; font-size: 13px;">
                    Comprehensive traceability of administrative actions, settings mutations, and customer operations. Read-only.
                </p>
            </div>
            <div style="display: flex; gap: 10px; align-items: center;">
                <a href="/admin/page/favorite-pay-dashboard" class="button button-secondary" style="font-size: 13px;">
                    &larr; Financial Dashboard
                </a>
            </div>
        </div>
    </div>

    <!-- Filter & Search Bar -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px 20px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <form method="GET" action="/admin/page/favorite-pay-audit" style="display: flex; flex-direction: column; gap: 14px;">
            <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center;">
                <!-- Text Search -->
                <div style="flex: 1; min-width: 240px;">
                    <input type="text" name="search" placeholder="Search action, actor, description, reference..." value="<?php echo $currentSearch; ?>" style="width: 100%; padding: 6px 12px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px;">
                </div>

                <!-- Subject Type Filter -->
                <div>
                    <select name="subject_type" style="padding: 6px 12px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px;">
                        <option value="all" <?php echo $currentSubject === 'all' ? 'selected' : ''; ?>>All Subjects</option>
                        <option value="withdrawal" <?php echo $currentSubject === 'withdrawal' ? 'selected' : ''; ?>>Withdrawals</option>
                        <option value="recharge" <?php echo $currentSubject === 'recharge' ? 'selected' : ''; ?>>Recharges</option>
                        <option value="payment" <?php echo $currentSubject === 'payment' ? 'selected' : ''; ?>>Payments</option>
                        <option value="settings" <?php echo $currentSubject === 'settings' ? 'selected' : ''; ?>>Settings</option>
                    </select>
                </div>

                <!-- Actor Type Filter -->
                <div>
                    <select name="actor_type" style="padding: 6px 12px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px;">
                        <option value="all" <?php echo $currentActorType === 'all' ? 'selected' : ''; ?>>All Actors</option>
                        <option value="admin" <?php echo $currentActorType === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="customer" <?php echo $currentActorType === 'customer' ? 'selected' : ''; ?>>Customer</option>
                        <option value="system" <?php echo $currentActorType === 'system' ? 'selected' : ''; ?>>System</option>
                    </select>
                </div>

                <!-- Date Range -->
                <div style="display: flex; align-items: center; gap: 6px;">
                    <input type="date" name="date_from" value="<?php echo $currentFrom; ?>" style="padding: 5px 8px; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 4px;" title="From date">
                    <span style="color: #94a3b8;">&ndash;</span>
                    <input type="date" name="date_to" value="<?php echo $currentTo; ?>" style="padding: 5px 8px; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 4px;" title="To date">
                </div>

                <button type="submit" class="button button-primary" style="font-size: 13px;">Filter</button>
                <a href="/admin/page/favorite-pay-audit" class="button" style="color: #64748b; font-size: 13px;">Reset</a>
            </div>

            <!-- Secondary reference filters -->
            <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center; font-size: 12px; color: #64748b;">
                <span>References:</span>
                <input type="text" name="withdrawal_id" placeholder="Withdrawal ID (wd_...)" value="<?php echo $currentWd; ?>" style="padding: 4px 8px; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 4px; width: 170px;">
                <input type="text" name="payment_id" placeholder="Payment / TrxID" value="<?php echo $currentPay; ?>" style="padding: 4px 8px; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 4px; width: 170px;">
                <span style="margin-left: auto; font-weight: 600; color: #334155;">
                    Total Records: <?php echo number_format($total); ?>
                </span>
            </div>
        </form>
    </div>

    <!-- Audit Logs Table -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 22px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <?php if (empty($items)): ?>
            <div style="text-align: center; padding: 48px 16px; color: #64748b; background: #f8fafc; border-radius: 8px; border: 1px dashed #cbd5e1;">
                <p style="margin: 0; font-size: 15px; font-weight: 600; color: #475569;">No audit records found.</p>
                <p style="margin: 6px 0 0; font-size: 13px;">Try adjusting your search criteria or resetting filters.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="wp-list-table widefat fixed striped" style="margin: 0;">
                    <thead>
                        <tr>
                            <th style="width: 140px;">Date / Time</th>
                            <th style="width: 130px;">Actor</th>
                            <th style="width: 140px;">Action</th>
                            <th style="width: 100px;">Subject</th>
                            <th>Description</th>
                            <th style="width: 130px;">Target User</th>
                            <th style="width: 110px;">IP Address</th>
                            <th style="width: 80px; text-align: center;">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $row): 
                            $id = (int)$row['id'];
                            $actorType = strtolower((string)($row['actor_type'] ?? 'system'));
                            $actorName = htmlspecialchars((string)($row['actor_name'] ?? 'System'), ENT_QUOTES, 'UTF-8');
                            $actorUid = (int)($row['actor_user_id'] ?? 0);
                            $action = htmlspecialchars((string)$row['action'], ENT_QUOTES, 'UTF-8');
                            $subjectType = htmlspecialchars((string)$row['subject_type'], ENT_QUOTES, 'UTF-8');
                            $targetUid = (int)($row['target_user_id'] ?? 0);
                            $desc = htmlspecialchars((string)$row['description'], ENT_QUOTES, 'UTF-8');
                            $ip = htmlspecialchars((string)($row['ip_address'] ?? '—'), ENT_QUOTES, 'UTF-8');
                            $created = htmlspecialchars((string)$row['created_at'], ENT_QUOTES, 'UTF-8');

                            $actorBadgeColor = match($actorType) {
                                'admin'    => ['bg' => '#e0e7ff', 'text' => '#4338ca'],
                                'customer' => ['bg' => '#dcfce7', 'text' => '#15803d'],
                                default    => ['bg' => '#f1f5f9', 'text' => '#475569'],
                            };
                        ?>
                            <tr>
                                <td style="color: #64748b; font-size: 12px; white-space: nowrap;">
                                    <?php echo $created; ?>
                                </td>
                                <td>
                                    <span style="display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; background: <?php echo $actorBadgeColor['bg']; ?>; color: <?php echo $actorBadgeColor['text']; ?>; margin-bottom: 2px;">
                                        <?php echo htmlspecialchars(strtoupper($actorType), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                    <div style="font-size: 12px; font-weight: 600; color: #0f172a;">
                                        <?php echo $actorName; ?>
                                    </div>
                                </td>
                                <td>
                                    <code style="font-size: 11px; background: #f8fafc; padding: 2px 6px; border-radius: 4px; border: 1px solid #e2e8f0; color: #0369a1; display: inline-block;">
                                        <?php echo $action; ?>
                                    </code>
                                </td>
                                <td>
                                    <span style="font-size: 12px; color: #334155; text-transform: capitalize;">
                                        <?php echo $subjectType; ?>
                                    </span>
                                    <?php if (!empty($row['subject_id'])): ?>
                                        <div style="font-size: 11px; font-family: monospace; color: #64748b;">
                                            #<?php echo htmlspecialchars((string)$row['subject_id'], ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 13px; color: #1e293b;">
                                    <?php echo $desc; ?>
                                </td>
                                <td>
                                    <?php if ($targetUid > 0): 
                                        $tUser = $userMap[$targetUid] ?? null;
                                        $tName = $tUser ? htmlspecialchars($tUser['username'], ENT_QUOTES, 'UTF-8') : "User #{$targetUid}";
                                    ?>
                                        <a href="/admin/page/favorite-pay-dashboard?action=customer&user_id=<?php echo $targetUid; ?>" style="font-size: 12px; color: #2563eb; text-decoration: none; font-weight: 600;">
                                            #<?php echo $targetUid; ?> (<?php echo $tName; ?>)
                                        </a>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 12px;">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-family: monospace; font-size: 11px; color: #64748b;">
                                    <?php echo $ip; ?>
                                </td>
                                <td style="text-align: center;">
                                    <a href="/admin/page/favorite-pay-audit?action=detail&id=<?php echo $id; ?>" class="button button-small" style="font-size: 11px;">
                                        Inspect
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Bounded Pagination -->
            <?php if ($totalPages > 1): ?>
                <div style="margin-top: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                    <div style="font-size: 12px; color: #64748b;">
                        Showing page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo number_format($total); ?> total records)
                    </div>
                    <div style="display: flex; gap: 6px;">
                        <?php
                        $queryParams = $_GET;
                        if ($page > 1):
                            $queryParams['p'] = $page - 1;
                        ?>
                            <a href="/admin/page/favorite-pay-audit?<?php echo http_build_query($queryParams); ?>" class="button button-secondary" style="font-size: 12px;">
                                &larr; Previous
                            </a>
                        <?php endif; ?>

                        <?php if ($page < $totalPages): 
                            $queryParams['p'] = $page + 1;
                        ?>
                            <a href="/admin/page/favorite-pay-audit?<?php echo http_build_query($queryParams); ?>" class="button button-secondary" style="font-size: 12px;">
                                Next &rarr;
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
