<?php
/**
 * Admin Withdrawal Detail & Audit Trail View
 *
 * @var \FavoriteCMS\Pay\Domain\Withdrawal $withdrawal
 * @var array $auditTrail
 * @var array $settings
 * @var string $primaryCurrency
 * @var string $csrfToken
 * @var bool $canManage
 * @var \FavoriteCMS\Models\User|null $customerUser
 * @var int $monthlyPosition
 * @var int $maxMonthlyCount
 */

$st = $withdrawal->getStatus();
$amtDec = \FavoriteCMS\Pay\Support\DecimalFormatter::minorUnitToDecimal($withdrawal->getAmount()->getAmount(), 2);
$feeDec = \FavoriteCMS\Pay\Support\DecimalFormatter::minorUnitToDecimal($withdrawal->getFee()->getAmount(), 2);
$netDec = \FavoriteCMS\Pay\Support\DecimalFormatter::minorUnitToDecimal($withdrawal->getNetAmount()->getAmount(), 2);
$destData = $withdrawal->getDestinationData();
$currentMonth = substr($withdrawal->getCreatedAt(), 0, 7);
?>

<style>
.fpay-admin-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
}
.fpay-admin-badge-pending { background: #fef3c7; color: #b45309; }
.fpay-admin-badge-approved { background: #e0e7ff; color: #4338ca; }
.fpay-admin-badge-processing { background: #e0f2fe; color: #0369a1; }
.fpay-admin-badge-paid { background: #dcfce7; color: #15803d; }
.fpay-admin-badge-rejected, .fpay-admin-badge-failed { background: #fee2e2; color: #b91c1c; }
.fpay-admin-badge-cancelled { background: #f1f5f9; color: #475569; }

.fpay-detail-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}
.fpay-card-box {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.fpay-field-label {
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    margin-bottom: 4px;
}
.fpay-field-value {
    font-size: 15px;
    font-weight: 600;
    color: #0f172a;
    line-height: 1.5;
}
.fpay-action-btn-group {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}
</style>

<div style="margin-bottom: 20px;">
    <a href="/admin/page/favorite-pay-withdrawals" class="button">&larr; Back to Withdrawal Queue</a>
</div>

<!-- Header Card -->
<div class="fpay-card-box" style="margin-bottom: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
        <div>
            <div style="font-size: 13px; color: #64748b; margin-bottom: 4px;">WITHDRAWAL REQUEST</div>
            <h2 style="margin: 0 0 6px; font-family: monospace; font-size: 24px; color: #0f172a;"><?php echo htmlspecialchars($withdrawal->getId(), ENT_QUOTES, 'UTF-8'); ?></h2>
            <div style="font-size: 13px; color: #64748b;">
                Requested on <strong><?php echo htmlspecialchars($withdrawal->getCreatedAt(), ENT_QUOTES, 'UTF-8'); ?></strong>
                <?php if ($withdrawal->getUpdatedAt()): ?>
                    &bull; Updated: <?php echo htmlspecialchars($withdrawal->getUpdatedAt(), ENT_QUOTES, 'UTF-8'); ?>
                <?php endif; ?>
                <?php if ($withdrawal->getProcessedAt()): ?>
                    &bull; Finalized: <strong style="color: #15803d;"><?php echo htmlspecialchars($withdrawal->getProcessedAt(), ENT_QUOTES, 'UTF-8'); ?></strong>
                <?php endif; ?>
            </div>
        </div>
        <div style="text-align: right;">
            <div style="margin-bottom: 8px;">
                <span class="fpay-admin-badge fpay-admin-badge-<?php echo $st->value; ?>" style="font-size: 14px; padding: 6px 14px;">
                    <?php echo htmlspecialchars($st->label(), ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
            <div style="font-size: 12px; color: #64748b; background: #f8fafc; padding: 4px 10px; border-radius: 4px; border: 1px solid #e2e8f0;">
                Monthly Slot: <strong>#<?php echo $monthlyPosition; ?></strong> / <?php echo $maxMonthlyCount; ?> (<?php echo $currentMonth; ?>)
            </div>
        </div>
    </div>
</div>

<!-- Details Grid -->
<div class="fpay-detail-grid">
    <!-- Customer & Destination -->
    <div class="fpay-card-box">
        <h3 style="font-size: 15px; font-weight: 700; color: #1e293b; margin: 0 0 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px;">
            Customer &amp; Payout Destination
        </h3>
        <div style="margin-bottom: 14px;">
            <div class="fpay-field-label">Customer</div>
            <div class="fpay-field-value">
                User #<?php echo $withdrawal->getUserId(); ?>
                <?php if ($customerUser): ?>
                    &mdash; <?php echo htmlspecialchars((string)($customerUser->name ?? $customerUser->username ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                    <div style="font-size: 12px; color: #64748b; font-weight: normal;"><?php echo htmlspecialchars((string)($customerUser->email ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div style="margin-bottom: 14px;">
            <div class="fpay-field-label">Payout Method</div>
            <div class="fpay-field-value" style="text-transform: capitalize;">
                <?php echo htmlspecialchars(str_replace('_', ' ', $withdrawal->getMethod()), ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>
        <div style="margin-bottom: 14px;">
            <div class="fpay-field-label">Masked Destination</div>
            <div class="fpay-field-value" style="font-family: monospace;">
                <?php echo htmlspecialchars($withdrawal->getDestinationMasked(), ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>
        <div>
            <div class="fpay-field-label">Full Account Details (Admin Safe)</div>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; font-size: 13px; font-family: monospace; color: #334155;">
                <?php if (!empty($destData) && is_array($destData)): ?>
                    <?php foreach ($destData as $k => $v): ?>
                        <div><strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$k)), ENT_QUOTES, 'UTF-8'); ?>:</strong> <?php echo htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <em><?php echo htmlspecialchars($withdrawal->getDestinationMasked(), ENT_QUOTES, 'UTF-8'); ?></em>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Financial Breakdown -->
    <div class="fpay-card-box">
        <h3 style="font-size: 15px; font-weight: 700; color: #1e293b; margin: 0 0 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px;">
            Accounting &amp; Amounts
        </h3>
        <div style="margin-bottom: 14px;">
            <div class="fpay-field-label">Requested Gross Amount (Hold)</div>
            <div class="fpay-field-value" style="font-size: 18px; color: #0f172a;">
                ৳<?php echo $amtDec; ?> <?php echo htmlspecialchars($withdrawal->getCurrency(), ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>
        <div style="margin-bottom: 14px;">
            <div class="fpay-field-label">Processing Fee</div>
            <div class="fpay-field-value" style="color: #64748b;">
                ৳<?php echo $feeDec; ?> <?php echo htmlspecialchars($withdrawal->getCurrency(), ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>
        <div style="margin-bottom: 14px;">
            <div class="fpay-field-label">Net Payable Amount to Customer</div>
            <div class="fpay-field-value" style="font-size: 20px; font-weight: 800; color: #15803d;">
                ৳<?php echo $netDec; ?> <?php echo htmlspecialchars($withdrawal->getCurrency(), ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>
        <div>
            <div class="fpay-field-label">Hold Reference (Wallet)</div>
            <div style="font-family: monospace; font-size: 12px; color: #64748b; background: #f8fafc; padding: 6px 8px; border-radius: 4px; border: 1px solid #e2e8f0;">
                <?php echo htmlspecialchars($withdrawal->getHoldReference() ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>
    </div>
</div>

<!-- Admin Processing Notes & Payout Reference -->
<div class="fpay-card-box" style="margin-bottom: 24px;">
    <h3 style="font-size: 15px; font-weight: 700; color: #1e293b; margin: 0 0 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px;">
        Payout Reference &amp; Admin Notes
    </h3>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
        <!-- Payout Reference -->
        <div>
            <form method="POST" action="/admin/page/favorite-pay-withdrawals">
                <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="withdrawal_id" value="<?php echo htmlspecialchars($withdrawal->getId(), ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="action" value="update_reference" />
                <input type="hidden" name="redirect_to" value="/admin/page/favorite-pay-withdrawals?id=<?php echo urlencode($withdrawal->getId()); ?>" />

                <label class="fpay-field-label" style="display: block;">Payout Reference / TrxID</label>
                <div style="font-size: 12px; color: #64748b; margin-bottom: 6px;">External transaction identifier (e.g. bKash TrxID, Nagad TrxID, Bank Ref)</div>
                <div style="display: flex; gap: 8px;">
                    <input type="text" name="transaction_reference" value="<?php echo htmlspecialchars($withdrawal->getTransactionReference() ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter TrxID or Reference..." style="flex: 1; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; font-family: monospace;" <?php echo !$canManage ? 'disabled' : ''; ?> />
                    <?php if ($canManage): ?>
                        <button type="submit" class="button">Save Ref</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Internal Admin Notes -->
        <div>
            <form method="POST" action="/admin/page/favorite-pay-withdrawals">
                <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="withdrawal_id" value="<?php echo htmlspecialchars($withdrawal->getId(), ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="action" value="update_notes" />
                <input type="hidden" name="redirect_to" value="/admin/page/favorite-pay-withdrawals?id=<?php echo urlencode($withdrawal->getId()); ?>" />

                <label class="fpay-field-label" style="display: block;">Internal Admin Notes (ADMIN-ONLY)</label>
                <div style="font-size: 12px; color: #64748b; margin-bottom: 6px;">Confidential notes for administrators. Never visible to customers.</div>
                <div style="display: flex; gap: 8px;">
                    <input type="text" name="operator_notes" value="<?php echo htmlspecialchars($withdrawal->getOperatorNotes() ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Internal notes or verification check..." style="flex: 1; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px;" <?php echo !$canManage ? 'disabled' : ''; ?> />
                    <?php if ($canManage): ?>
                        <button type="submit" class="button">Save Note</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- State Actions Card -->
<div class="fpay-card-box" style="margin-bottom: 24px;">
    <h3 style="font-size: 15px; font-weight: 700; color: #1e293b; margin: 0 0 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px;">
        Workflow Actions (FSM)
    </h3>

    <?php if (!$canManage): ?>
        <p style="color: #64748b; font-size: 13px; margin: 0;">You do not have permission to execute state transitions.</p>
    <?php elseif ($st === \FavoriteCMS\Pay\Domain\WithdrawalStatus::PENDING): ?>
        <div class="fpay-action-btn-group">
            <!-- Approve -->
            <form method="POST" action="/admin/page/favorite-pay-withdrawals">
                <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="withdrawal_id" value="<?php echo htmlspecialchars($withdrawal->getId(), ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="action" value="approve" />
                <input type="hidden" name="redirect_to" value="/admin/page/favorite-pay-withdrawals?id=<?php echo urlencode($withdrawal->getId()); ?>" />
                <button type="submit" class="button button-primary" onclick="return confirm('Approve withdrawal for processing?');">Approve Request</button>
            </form>

            <!-- Reject -->
            <form method="POST" action="/admin/page/favorite-pay-withdrawals" style="display: flex; gap: 6px; align-items: center;">
                <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="withdrawal_id" value="<?php echo htmlspecialchars($withdrawal->getId(), ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="action" value="reject" />
                <input type="hidden" name="redirect_to" value="/admin/page/favorite-pay-withdrawals?id=<?php echo urlencode($withdrawal->getId()); ?>" />
                <input type="text" name="reason" placeholder="Rejection reason..." style="padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; width: 180px;" required />
                <button type="submit" class="button" style="color: #b91c1c;" onclick="return confirm('Reject withdrawal and release wallet hold?');">Reject</button>
            </form>

            <!-- Cancel -->
            <form method="POST" action="/admin/page/favorite-pay-withdrawals" style="display: flex; gap: 6px; align-items: center;">
                <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="withdrawal_id" value="<?php echo htmlspecialchars($withdrawal->getId(), ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="action" value="cancel" />
                <input type="hidden" name="redirect_to" value="/admin/page/favorite-pay-withdrawals?id=<?php echo urlencode($withdrawal->getId()); ?>" />
                <input type="text" name="reason" placeholder="Cancellation reason..." style="padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; width: 180px;" />
                <button type="submit" class="button" onclick="return confirm('Cancel withdrawal and restore wallet funds?');">Cancel</button>
            </form>
        </div>
    <?php elseif ($st === \FavoriteCMS\Pay\Domain\WithdrawalStatus::APPROVED): ?>
        <div class="fpay-action-btn-group">
            <!-- Start Processing -->
            <form method="POST" action="/admin/page/favorite-pay-withdrawals">
                <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="withdrawal_id" value="<?php echo htmlspecialchars($withdrawal->getId(), ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="action" value="start_processing" />
                <input type="hidden" name="redirect_to" value="/admin/page/favorite-pay-withdrawals?id=<?php echo urlencode($withdrawal->getId()); ?>" />
                <button type="submit" class="button button-primary">Start Processing</button>
            </form>

            <!-- Reject -->
            <form method="POST" action="/admin/page/favorite-pay-withdrawals" style="display: flex; gap: 6px; align-items: center;">
                <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="withdrawal_id" value="<?php echo htmlspecialchars($withdrawal->getId(), ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="action" value="reject" />
                <input type="hidden" name="redirect_to" value="/admin/page/favorite-pay-withdrawals?id=<?php echo urlencode($withdrawal->getId()); ?>" />
                <input type="text" name="reason" placeholder="Rejection reason..." style="padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; width: 180px;" required />
                <button type="submit" class="button" style="color: #b91c1c;" onclick="return confirm('Reject approved withdrawal and release wallet hold?');">Reject</button>
            </form>

            <!-- Cancel -->
            <form method="POST" action="/admin/page/favorite-pay-withdrawals" style="display: flex; gap: 6px; align-items: center;">
                <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="withdrawal_id" value="<?php echo htmlspecialchars($withdrawal->getId(), ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="action" value="cancel" />
                <input type="hidden" name="redirect_to" value="/admin/page/favorite-pay-withdrawals?id=<?php echo urlencode($withdrawal->getId()); ?>" />
                <input type="text" name="reason" placeholder="Cancellation reason..." style="padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; width: 180px;" />
                <button type="submit" class="button" onclick="return confirm('Cancel approved withdrawal and restore wallet funds?');">Cancel</button>
            </form>
        </div>
    <?php elseif ($st === \FavoriteCMS\Pay\Domain\WithdrawalStatus::PROCESSING): ?>
        <div class="fpay-action-btn-group">
            <!-- Mark Paid -->
            <form method="POST" action="/admin/page/favorite-pay-withdrawals" style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="withdrawal_id" value="<?php echo htmlspecialchars($withdrawal->getId(), ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="action" value="mark_paid" />
                <input type="hidden" name="redirect_to" value="/admin/page/favorite-pay-withdrawals?id=<?php echo urlencode($withdrawal->getId()); ?>" />
                <input type="text" name="transaction_reference" value="<?php echo htmlspecialchars($withdrawal->getTransactionReference() ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter TrxID / Reference (Recommended)..." style="padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; width: 220px;" />
                <button type="submit" class="button button-primary" onclick="return confirm('Finalize payout as PAID? This permanently debits customer wallet.');">Mark Paid &amp; Finalize Debit</button>
            </form>

            <!-- Mark Failed -->
            <form method="POST" action="/admin/page/favorite-pay-withdrawals" style="display: flex; gap: 6px; align-items: center;">
                <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="withdrawal_id" value="<?php echo htmlspecialchars($withdrawal->getId(), ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="action" value="mark_failed" />
                <input type="hidden" name="redirect_to" value="/admin/page/favorite-pay-withdrawals?id=<?php echo urlencode($withdrawal->getId()); ?>" />
                <input type="text" name="reason" placeholder="Failure reason..." style="padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; width: 180px;" required />
                <button type="submit" class="button" style="color: #b91c1c;" onclick="return confirm('Mark payout as FAILED and release wallet hold?');">Mark Failed</button>
            </form>
        </div>
    <?php else: ?>
        <div style="color: #64748b; font-size: 13px; background: #f8fafc; padding: 12px 16px; border-radius: 6px; border: 1px solid #e2e8f0;">
            This withdrawal is in a terminal state (<strong><?php echo htmlspecialchars($st->label(), ENT_QUOTES, 'UTF-8'); ?></strong>). No further state transitions are permitted.
        </div>
    <?php endif; ?>
</div>

<!-- Append-Only Audit Trail Card -->
<div class="fpay-card-box">
    <h3 style="font-size: 15px; font-weight: 700; color: #1e293b; margin: 0 0 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px; display: flex; align-items: center; gap: 8px;">
        <span>🛡️</span> Append-Only Audit Trail
    </h3>

    <?php if (empty($auditTrail)): ?>
        <div style="color: #64748b; font-size: 13px; padding: 16px 0;">No audit records available for this withdrawal.</div>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped" style="font-size: 13px;">
            <thead>
                <tr>
                    <th style="width: 160px;">Timestamp</th>
                    <th style="width: 140px;">Action</th>
                    <th style="width: 100px;">Actor</th>
                    <th style="width: 160px;">State Transition</th>
                    <th>Details &amp; Metadata</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_reverse($auditTrail) as $entry): ?>
                    <?php
                    $act = (string)($entry['action'] ?? 'unknown');
                    $actor = $entry['actor_id'] ? 'User #' . $entry['actor_id'] : 'System';
                    $prev = $entry['prev_status'] ?? '—';
                    $new = $entry['new_status'] ?? '—';
                    $meta = $entry['metadata'] ?? [];
                    ?>
                    <tr>
                        <td style="color: #64748b; font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars((string)($entry['timestamp'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <strong style="text-transform: capitalize;"><?php echo htmlspecialchars(str_replace('_', ' ', $act), ENT_QUOTES, 'UTF-8'); ?></strong>
                        </td>
                        <td><?php echo htmlspecialchars($actor, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php if ($prev !== '—' || $new !== '—'): ?>
                                <span style="font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars($prev, ENT_QUOTES, 'UTF-8'); ?> &rarr; <strong><?php echo htmlspecialchars($new, ENT_QUOTES, 'UTF-8'); ?></strong></span>
                            <?php else: ?>
                                <span style="color: #94a3b8;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($meta) && is_array($meta)): ?>
                                <?php
                                $pairs = [];
                                foreach ($meta as $mk => $mv) {
                                    if (is_scalar($mv) && $mv !== '') {
                                        $pairs[] = htmlspecialchars($mk . ': ' . $mv, ENT_QUOTES, 'UTF-8');
                                    }
                                }
                                echo implode(' &bull; ', $pairs);
                                ?>
                            <?php else: ?>
                                <span style="color: #94a3b8;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Operational Activity Center (Complementary Traceability) -->
<?php if (!empty($operationalAuditLogs)): ?>
    <div class="fpay-card-box" style="margin-top: 24px;">
        <h3 style="font-size: 15px; font-weight: 700; color: #1e293b; margin: 0 0 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
            <span style="display: flex; align-items: center; gap: 8px;">
                <span>🕵️</span> Operational Activity Center (Who, When, From Where)
            </span>
            <a href="/admin/page/favorite-pay-audit?withdrawal_id=<?php echo urlencode($withdrawal->getId()); ?>" style="font-size: 12px; color: #2563eb; text-decoration: none; font-weight: 600;">
                Full Audit Trail &rarr;
            </a>
        </h3>

        <div style="overflow-x: auto;">
            <table class="wp-list-table widefat fixed striped" style="font-size: 13px; margin: 0;">
                <thead>
                    <tr>
                        <th style="width: 150px;">Timestamp</th>
                        <th style="width: 150px;">Action</th>
                        <th style="width: 150px;">Actor</th>
                        <th>Description</th>
                        <th style="width: 120px;">IP Address</th>
                        <th style="width: 70px; text-align: center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($operationalAuditLogs as $op): 
                        $opId = (int)$op['id'];
                        $opAct = htmlspecialchars((string)$op['action'], ENT_QUOTES, 'UTF-8');
                        $opActorName = htmlspecialchars((string)($op['actor_name'] ?? 'System'), ENT_QUOTES, 'UTF-8');
                        $opActorType = strtolower((string)($op['actor_type'] ?? 'system'));
                        $opDesc = htmlspecialchars((string)$op['description'], ENT_QUOTES, 'UTF-8');
                        $opIp = htmlspecialchars((string)($op['ip_address'] ?? '—'), ENT_QUOTES, 'UTF-8');
                        $opTime = htmlspecialchars((string)$op['created_at'], ENT_QUOTES, 'UTF-8');
                    ?>
                        <tr>
                            <td style="color: #64748b; font-size: 12px; white-space: nowrap;"><?php echo $opTime; ?></td>
                            <td>
                                <code style="font-size: 11px; background: #f8fafc; padding: 2px 6px; border-radius: 4px; border: 1px solid #e2e8f0; color: #0369a1;">
                                    <?php echo $opAct; ?>
                                </code>
                            </td>
                            <td>
                                <span style="display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; background: <?php echo $opActorType === 'admin' ? '#e0e7ff' : '#dcfce7'; ?>; color: <?php echo $opActorType === 'admin' ? '#4338ca' : '#15803d'; ?>;">
                                    <?php echo strtoupper($opActorType); ?>
                                </span>
                                <strong style="font-size: 12px; margin-left: 4px;"><?php echo $opActorName; ?></strong>
                            </td>
                            <td style="font-size: 13px; color: #334155;"><?php echo $opDesc; ?></td>
                            <td style="font-family: monospace; font-size: 11px; color: #64748b;"><?php echo $opIp; ?></td>
                            <td style="text-align: center;">
                                <a href="/admin/page/favorite-pay-audit?action=detail&id=<?php echo $opId; ?>" class="button button-small" style="font-size: 11px;">
                                    Inspect
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
