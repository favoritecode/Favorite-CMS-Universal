<?php
/**
 * Favorite Pay — Customer In-App Notifications View
 *
 * Variables:
 * - $notifications: array
 * - $totalNotifications: int
 * - $unreadCount: int
 * - $unreadOnly: bool
 * - $currentPage: int
 * - $totalPages: int
 * - $csrfToken: string
 */
?>

<div class="fpay-card">
    <div class="fpay-card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <h2 class="fpay-card-title" style="margin:0; font-size:20px; font-weight:700; color:#0f172a;">
                Activity &amp; Notifications
                <?php if ($unreadCount > 0): ?>
                    <span style="background:#ef4444; color:#fff; font-size:12px; font-weight:700; padding:2px 8px; border-radius:12px; vertical-align:middle; margin-left:6px;">
                        <?php echo (int)$unreadCount; ?> unread
                    </span>
                <?php endif; ?>
            </h2>
            <p style="margin:4px 0 0; font-size:13px; color:#64748b;">Important updates regarding your wallet, payments, and withdrawal requests.</p>
        </div>
        <div style="display:flex; gap:10px; align-items:center;">
            <!-- Filter Tabs -->
            <a href="/account/notifications" style="padding:6px 14px; font-size:13px; font-weight:600; border-radius:6px; text-decoration:none; <?php echo !$unreadOnly ? 'background:#2563eb; color:#fff;' : 'background:#f1f5f9; color:#475569;'; ?>">
                All (<?php echo (int)$totalNotifications; ?>)
            </a>
            <a href="/account/notifications?filter=unread" style="padding:6px 14px; font-size:13px; font-weight:600; border-radius:6px; text-decoration:none; <?php echo $unreadOnly ? 'background:#2563eb; color:#fff;' : 'background:#f1f5f9; color:#475569;'; ?>">
                Unread (<?php echo (int)$unreadCount; ?>)
            </a>
            <?php if ($unreadCount > 0): ?>
                <form method="POST" action="/account/notifications" style="margin:0;">
                    <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="mark_all_read">
                    <button type="submit" style="padding:6px 14px; font-size:13px; font-weight:600; border-radius:6px; border:1px solid #cbd5e1; background:#fff; color:#475569; cursor:pointer; transition:all 0.15s ease;">
                        Mark All Read
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($notifications)): ?>
        <div style="text-align:center; padding:48px 20px; color:#94a3b8;">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:12px; color:#cbd5e1;"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
            <p style="margin:0; font-size:15px; font-weight:600; color:#64748b;">No notifications to display</p>
            <p style="margin:4px 0 0; font-size:13px; color:#94a3b8;"><?php echo $unreadOnly ? 'You have caught up with all your unread messages.' : 'When withdrawals or account actions take place, updates will appear here.'; ?></p>
        </div>
    <?php else: ?>
        <div style="display:flex; flex-direction:column; gap:12px;">
            <?php foreach ($notifications as $n): ?>
                <?php
                $isUnread = empty($n['is_read']);
                $type = (string)($n['type'] ?? '');
                $borderLeft = '#94a3b8';
                $iconBg = '#f1f5f9';
                $iconColor = '#64748b';

                if (str_contains($type, 'paid') || str_contains($type, 'approved')) {
                    $borderLeft = '#10b981';
                    $iconBg = '#ecfdf5';
                    $iconColor = '#059669';
                } elseif (str_contains($type, 'processing')) {
                    $borderLeft = '#3b82f6';
                    $iconBg = '#eff6ff';
                    $iconColor = '#2563eb';
                } elseif (str_contains($type, 'rejected') || str_contains($type, 'failed') || str_contains($type, 'cancelled')) {
                    $borderLeft = '#ef4444';
                    $iconBg = '#fef2f2';
                    $iconColor = '#dc2626';
                } elseif (str_contains($type, 'created')) {
                    $borderLeft = '#8b5cf6';
                    $iconBg = '#f5f3ff';
                    $iconColor = '#7c3aed';
                }
                ?>
                <div style="border: 1px solid <?php echo $isUnread ? '#bfdbfe' : '#e2e8f0'; ?>; border-left: 4px solid <?php echo $borderLeft; ?>; background: <?php echo $isUnread ? '#f8faff' : '#ffffff'; ?>; border-radius: 8px; padding: 16px; display: flex; justify-content: space-between; align-items: flex-start; gap: 14px;">
                    <div style="display:flex; gap:14px; align-items:flex-start;">
                        <div style="width:36px; height:36px; border-radius:8px; background:<?php echo $iconBg; ?>; color:<?php echo $iconColor; ?>; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <?php if (str_contains($type, 'paid') || str_contains($type, 'approved')): ?>
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            <?php elseif (str_contains($type, 'rejected') || str_contains($type, 'failed') || str_contains($type, 'cancelled')): ?>
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                            <?php elseif (str_contains($type, 'processing')): ?>
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                            <?php else: ?>
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                <h4 style="margin:0; font-size:15px; font-weight:700; color:#0f172a;">
                                    <?php echo htmlspecialchars((string)($n['title'] ?? 'Notification'), ENT_QUOTES, 'UTF-8'); ?>
                                </h4>
                                <?php if ($isUnread): ?>
                                    <span style="background:#dbeafe; color:#1e40af; font-size:11px; font-weight:700; padding:1px 6px; border-radius:4px;">NEW</span>
                                <?php endif; ?>
                                <span style="font-size:12px; color:#94a3b8;">
                                    <?php echo htmlspecialchars((string)($n['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </div>
                            <p style="margin:6px 0 0; font-size:14px; color:#334155; line-height:1.5;">
                                <?php echo htmlspecialchars((string)($n['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <?php if (!empty($n['withdrawal_id'])): ?>
                                <div style="margin-top:8px;">
                                    <a href="/account/withdrawals/<?php echo urlencode((string)$n['withdrawal_id']); ?>" style="display:inline-flex; align-items:center; gap:4px; font-size:13px; font-weight:600; color:#2563eb; text-decoration:none;">
                                        <span>View Withdrawal #<?php echo htmlspecialchars(substr((string)$n['withdrawal_id'], 0, 8), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($isUnread): ?>
                        <form method="POST" action="/account/notifications" style="margin:0; flex-shrink:0;">
                            <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="mark_read">
                            <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)$n['id'], ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" title="Mark as Read" style="background:none; border:1px solid #cbd5e1; border-radius:6px; padding:4px 8px; font-size:12px; font-weight:600; color:#64748b; cursor:pointer;">
                                Mark read
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div style="display:flex; justify-content:center; gap:6px; margin-top:24px;">
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <a href="/account/notifications?page=<?php echo $p; ?><?php echo $unreadOnly ? '&filter=unread' : ''; ?>"
                       style="padding:6px 12px; border-radius:6px; font-size:13px; font-weight:600; text-decoration:none; <?php echo $p === $currentPage ? 'background:#2563eb; color:#fff;' : 'background:#f1f5f9; color:#475569;'; ?>">
                        <?php echo $p; ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
