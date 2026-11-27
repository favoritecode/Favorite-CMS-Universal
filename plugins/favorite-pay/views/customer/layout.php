<?php
/**
 * Favorite Pay — Customer Account Area Layout
 */
require_once __DIR__ . '/_helpers.php';

$activeTab = $activeTab ?? 'wallet';
$pageTitle = $pageTitle ?? 'Customer Account';
$flashError = $_SESSION['flash_error'] ?? null;
$flashSuccess = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

?>


<style>
.fpay-customer-wrapper {
    max-width: 1100px;
    margin: 32px auto 64px;
    padding: 0 20px;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    color: var(--text, #1e293b);
}
.fpay-page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 24px;
}
.fpay-page-title {
    font-size: 26px;
    font-weight: 800;
    color: var(--heading, #0f172a);
    margin: 0 0 4px;
}
.fpay-page-desc {
    font-size: 14px;
    color: var(--muted, #64748b);
    margin: 0;
}
.fpay-nav-tabs {
    display: flex;
    gap: 8px;
    border-bottom: 2px solid var(--border, #e2e8f0);
    margin-bottom: 28px;
    overflow-x: auto;
    padding-bottom: 2px;
}
.fpay-nav-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    border-radius: 8px 8px 0 0;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    color: var(--muted, #64748b);
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
    transition: all 0.15s ease;
    white-space: nowrap;
}
.fpay-nav-link:hover {
    color: var(--heading, #0f172a);
    background: var(--surface-muted, #f1f5f9);
}
.fpay-nav-link.active {
    color: var(--accent, #2563eb);
    border-bottom-color: var(--accent, #2563eb);
    background: var(--accent-soft, #eff6ff);
}
.fpay-alert {
    padding: 14px 18px;
    border-radius: 8px;
    font-size: 14px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.fpay-alert-success {
    background: var(--success-soft, #ecfdf5);
    color: var(--success, #065f46);
    border: 1px solid var(--success-border, #a7f3d0);
}
.fpay-alert-error {
    background: var(--danger-soft, #fef2f2);
    color: var(--danger, #991b1b);
    border: 1px solid var(--danger-border, #fecaca);
}
.fpay-alert-warning {
    background: rgba(245, 158, 11, 0.12);
    color: #d97706;
    border: 1px solid rgba(245, 158, 11, 0.3);
}
.fpay-alert-info {
    background: var(--accent-soft, #eff6ff);
    color: var(--accent, #1e40af);
    border: 1px solid var(--border, #bfdbfe);
}
.fpay-card {
    background: var(--surface, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    margin-bottom: 28px;
}
.fpay-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border, #f1f5f9);
}
.fpay-card-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--heading, #0f172a);
    margin: 0;
}
.fpay-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}
.fpay-table th {
    background: var(--surface-muted, #f8fafc);
    color: var(--muted, #475569);
    font-weight: 600;
    text-align: left;
    padding: 12px 14px;
    border-bottom: 1px solid var(--border, #e2e8f0);
}
.fpay-table td {
    padding: 14px;
    border-bottom: 1px solid var(--border, #f1f5f9);
    color: var(--text, #334155);
}
.fpay-table tr:hover td {
    background: var(--surface-muted, #f8fafc);
}
.fpay-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 9999px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.fpay-badge-success, .fpay-badge-succeeded, .fpay-badge-paid {
    background: var(--success-soft, #dcfce7);
    color: var(--success, #15803d);
}
.fpay-badge-pending, .fpay-badge-awaiting_verification, .fpay-badge-warning {
    background: rgba(245, 158, 11, 0.15);
    color: #d97706;
}
.fpay-badge-failed, .fpay-badge-cancelled {
    background: var(--danger-soft, #fee2e2);
    color: var(--danger, #b91c1c);
}
.fpay-badge-active {
    background: var(--accent-soft, #e0e7ff);
    color: var(--accent, #4338ca);
}
.fpay-badge-secondary {
    background: var(--surface-muted, #f1f5f9);
    color: var(--muted, #64748b);
}
.fpay-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    border: none;
    transition: background 0.15s ease;
}
.fpay-btn-primary {
    background: var(--accent, #2563eb);
    color: #ffffff;
}
.fpay-btn-primary:hover {
    opacity: 0.9;
}
.fpay-btn-secondary {
    background: var(--surface-muted, #f1f5f9);
    color: var(--text, #334155);
    border: 1px solid var(--border-strong, #cbd5e1);
}
.fpay-btn-secondary:hover {
    background: var(--border, #e2e8f0);
}
.fpay-btn-sm {
    padding: 6px 12px;
    font-size: 12px;
    border-radius: 6px;
}
.fpay-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 20px;
    font-size: 13px;
    color: var(--muted, #64748b);
}
.fpay-pagination-links {
    display: flex;
    gap: 6px;
}
</style>

<div class="fpay-customer-wrapper">
    <div class="fpay-page-header">
        <div>
            <h1 class="fpay-page-title"><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p class="fpay-page-desc">Customer account &amp; wallet management</p>
        </div>
        <div>
            <a href="/account/wallet#recharge-wallet" class="fpay-btn fpay-btn-primary">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg>
                Recharge Balance
            </a>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <nav class="fpay-nav-tabs" aria-label="Customer Account Navigation">
        <a href="/account/wallet" class="fpay-nav-link <?php echo $activeTab === 'wallet' ? 'active' : ''; ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
            Wallet &amp; Balance
        </a>
        <?php if (!empty($withdrawEnabled)): ?>
        <a href="/account/withdraw" class="fpay-nav-link <?php echo $activeTab === 'withdraw' ? 'active' : ''; ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
            Withdraw
        </a>
        <?php endif; ?>
        <a href="/account/payments" class="fpay-nav-link <?php echo $activeTab === 'payments' ? 'active' : ''; ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
            Payment History
        </a>
        <a href="/account/transactions" class="fpay-nav-link <?php echo $activeTab === 'transactions' ? 'active' : ''; ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
            Transactions
        </a>
        <a href="/account/notifications" class="fpay-nav-link <?php echo $activeTab === 'notifications' ? 'active' : ''; ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
            Notifications<?php if (!empty($unreadNotificationsCount)): ?> <span style="background:#ef4444; color:#fff; font-size:11px; font-weight:700; padding:1px 6px; border-radius:10px; margin-left:4px;"><?php echo (int)$unreadNotificationsCount; ?></span><?php endif; ?>
        </a>
    </nav>

    <!-- Flash Messages -->
    <?php if ($flashSuccess): ?>
        <div class="fpay-alert fpay-alert-success">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            <span><?php echo htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($flashError): ?>
        <div class="fpay-alert fpay-alert-error">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
            <span><?php echo htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    <?php endif; ?>

    <?php if (!empty($isSuspended)): ?>
        <div class="fpay-alert fpay-alert-warning">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
            <div>
                <strong>Account Suspended:</strong> Your account is currently suspended. You can view your balance and payment records, but balance recharge and payment submissions are restricted.
            </div>
        </div>
    <?php endif; ?>

    <!-- View Content -->
    <?php if (!empty($contentView) && file_exists($contentView)): ?>
        <?php include $contentView; ?>
    <?php endif; ?>
</div>

