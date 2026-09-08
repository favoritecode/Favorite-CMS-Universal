<?php
/**
 * Customer Binance Pay QR Checkout View
 */
$paymentId = $intent->getId();
$statusVal = strtolower($intent->getStatus()->value);
$isPending = ($statusVal === 'pending' || $statusVal === 'processing');
$isSucceeded = ($statusVal === 'succeeded');
$isTerminalFailed = ($statusVal === 'failed' || $statusVal === 'cancelled' || $statusVal === 'expired');

$baseMajor = \FavoriteCMS\Pay\Support\DecimalFormatter::minorUnitToDecimal($intent->getBaseAmount()->getAmount(), 2);
$baseCurr = $intent->getBaseAmount()->getCurrency();

$chargeMajor = \FavoriteCMS\Pay\Support\DecimalFormatter::minorUnitToDecimal($intent->getChargeAmount()->getAmount(), 2);
$chargeCurr = $intent->getChargeAmount()->getCurrency();
?>

<div style="max-width: 580px; margin: 0 auto;">
    <!-- Breadcrumb -->
    <div style="margin-bottom: 18px;">
        <a href="/account/recharge" style="color: #2563eb; text-decoration: none; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
            &larr; Back to Recharge Options
        </a>
    </div>

    <div class="fpay-card" id="binance-checkout-card" style="text-align: center; padding: 32px 28px;">
        <!-- Header / Logo -->
        <div style="margin-bottom: 20px;">
            <div style="display: inline-flex; align-items: center; justify-content: center; width: 56px; height: 56px; border-radius: 14px; background: #fef08a; color: #854d0e; margin-bottom: 12px; box-shadow: 0 4px 12px rgba(234, 179, 8, 0.2);">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2L6.5 7.5L8.6 9.6L12 6.2L15.4 9.6L17.5 7.5L12 2ZM2 12L7.5 6.5L9.6 8.6L6.2 12L9.6 15.4L7.5 17.5L2 12ZM12 22L17.5 16.5L15.4 14.4L12 17.8L8.6 14.4L6.5 16.5L12 22ZM22 12L16.5 17.5L14.4 15.4L17.8 12L14.4 8.6L16.5 6.5L22 12ZM12 9.2L14.8 12L12 14.8L9.2 12L12 9.2Z"/>
                </svg>
            </div>
            <h1 style="font-size: 24px; font-weight: 800; color: #0f172a; margin: 0 0 6px;">
                Binance Pay
            </h1>
            <p style="font-size: 14px; color: #64748b; margin: 0;">
                Complete your payment using the Binance App or Web Checkout
            </p>
        </div>

        <!-- Payment ID & Amount Block -->
        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px 20px; margin-bottom: 24px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; font-size: 13px; color: #64748b;">
                <span>Payment ID</span>
                <strong style="font-family: monospace; font-size: 14px; color: #0f172a;"><?php echo htmlspecialchars($paymentId, ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; font-size: 14px; color: #475569;">
                <span>Amount (Accounting)</span>
                <strong style="font-size: 16px; color: #0f172a;"><?php echo htmlspecialchars($baseCurr . ' ' . $baseMajor, ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 10px; border-top: 1px dashed #cbd5e1; font-size: 15px;">
                <span style="font-weight: 600; color: #1e293b;">Binance Payable Amount</span>
                <strong style="font-size: 20px; font-weight: 800; color: #d97706;"><?php echo htmlspecialchars($chargeMajor . ' ' . $chargeCurr, ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
        </div>

        <!-- Succeeded State Container -->
        <div id="status-succeeded-view" style="display: <?php echo $isSucceeded ? 'block' : 'none'; ?>; padding: 20px 0;">
            <div style="width: 64px; height: 64px; background: #dcfce7; color: #16a34a; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 16px;">
                <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
            </div>
            <h2 style="font-size: 22px; font-weight: 800; color: #15803d; margin: 0 0 8px;">
                Payment Successful
            </h2>
            <p style="font-size: 14px; color: #475569; margin: 0 0 20px;">
                Paid: <strong><?php echo htmlspecialchars($chargeMajor . ' ' . $chargeCurr, ENT_QUOTES, 'UTF-8'); ?></strong><br>
                Wallet credited: <strong style="color: #15803d;"><?php echo htmlspecialchars($baseCurr . ' ' . $baseMajor, ENT_QUOTES, 'UTF-8'); ?></strong>
            </p>
            <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
                <a href="/account/wallet" class="fpay-btn fpay-btn-primary">
                    View Wallet &rarr;
                </a>
                <a href="/account/payments/<?php echo urlencode($paymentId); ?>" class="fpay-btn fpay-btn-secondary">
                    View Payment Details
                </a>
            </div>
        </div>

        <!-- Terminal Failed / Expired State Container -->
        <div id="status-failed-view" style="display: <?php echo $isTerminalFailed ? 'block' : 'none'; ?>; padding: 20px 0;">
            <div style="width: 64px; height: 64px; background: #fee2e2; color: #dc2626; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 16px;">
                <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </div>
            <h2 id="failed-title" style="font-size: 22px; font-weight: 800; color: #b91c1c; margin: 0 0 8px;">
                <?php echo ($statusVal === 'expired') ? 'Payment Expired' : 'Payment Failed'; ?>
            </h2>
            <p style="font-size: 14px; color: #64748b; margin: 0 0 20px;">
                This payment was cancelled, failed, or expired. No funds were credited to your wallet.
            </p>
            <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
                <a href="/account/recharge" class="fpay-btn fpay-btn-primary">
                    Back to Recharge
                </a>
                <a href="/account/payments/<?php echo urlencode($paymentId); ?>" class="fpay-btn fpay-btn-secondary">
                    View Details
                </a>
            </div>
        </div>

        <!-- Pending QR & Interactive Checkout Container -->
        <div id="status-pending-view" style="display: <?php echo $isPending ? 'block' : 'none'; ?>;">
            <!-- QR Code Section -->
            <div style="margin: 0 auto 20px; display: inline-block; padding: 14px; background: #ffffff; border: 2px solid #e2e8f0; border-radius: 16px; box-shadow: 0 4px 16px rgba(0,0,0,0.06);">
                <?php if (!empty($qrcodeLink)): ?>
                    <img src="<?php echo htmlspecialchars($qrcodeLink, ENT_QUOTES, 'UTF-8'); ?>" alt="Binance Pay QR Code" style="display: block; width: 220px; height: 220px; object-fit: contain;" />
                <?php else: ?>
                    <div style="width: 220px; height: 220px; display: flex; flex-direction: column; align-items: center; justify-content: center; background: #f8fafc; border-radius: 12px; color: #64748b; padding: 16px; text-align: center; box-sizing: border-box;">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom: 8px;">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                            <circle cx="8.5" cy="8.5" r="1.5"></circle>
                            <polyline points="21 15 16 10 5 21"></polyline>
                        </svg>
                        <span style="font-size: 12px;">QR Code image unavailable. Please use the button below to pay via Binance.</span>
                    </div>
                <?php endif; ?>
            </div>

            <p style="font-size: 14px; font-weight: 600; color: #334155; margin: 0 0 16px;">
                Scan this QR code using the Binance App (Pay)
            </p>

            <!-- Open Binance CTA Button -->
            <?php if (!empty($checkoutUrl)): ?>
                <div style="margin-bottom: 24px;">
                    <a href="<?php echo htmlspecialchars($checkoutUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="fpay-btn fpay-btn-primary" style="padding: 12px 28px; font-size: 15px; font-weight: 700; width: 100%; max-width: 320px; box-sizing: border-box; justify-content: center; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                            <polyline points="15 3 21 3 21 9"></polyline>
                            <line x1="10" y1="14" x2="21" y2="3"></line>
                        </svg>
                        Open Binance
                    </a>
                </div>
            <?php endif; ?>

            <!-- Status Indicator -->
            <div style="background: #f1f5f9; border-radius: 10px; padding: 14px 18px; display: inline-flex; align-items: center; gap: 10px; color: #475569; font-size: 14px; margin-bottom: 8px;">
                <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #eab308; animation: fpay-pulse 1.8s infinite;"></span>
                <span id="payment-status-label" style="font-weight: 600;">Waiting for payment...</span>
            </div>
            <div style="font-size: 12px; color: #94a3b8;">
                Do not close this page while payment is pending. It will automatically update once confirmed.
            </div>
        </div>
    </div>
</div>

<style>
@keyframes fpay-pulse {
    0% { transform: scale(0.95); opacity: 0.8; }
    50% { transform: scale(1.3); opacity: 1; }
    100% { transform: scale(0.95); opacity: 0.8; }
}
</style>

<!-- Polling Script -->
<?php if ($isPending): ?>
<script>
(function() {
    var paymentId = <?php echo json_encode($paymentId); ?>;
    var statusUrl = '/account/payments/' + encodeURIComponent(paymentId) + '/status';
    var pendingView = document.getElementById('status-pending-view');
    var succeededView = document.getElementById('status-succeeded-view');
    var failedView = document.getElementById('status-failed-view');
    var statusLabel = document.getElementById('payment-status-label');
    var failedTitle = document.getElementById('failed-title');

    var pollInterval = 3000;
    var pollTimer = null;
    var maxPolls = 200; // ~10 minutes
    var pollCount = 0;

    function checkStatus() {
        pollCount++;
        if (pollCount > maxPolls) {
            if (pollTimer) clearInterval(pollTimer);
            if (statusLabel) statusLabel.textContent = 'Session timed out. Please check your payment history.';
            return;
        }

        fetch(statusUrl, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        })
        .then(function(data) {
            if (!data || !data.status) return;

            var status = String(data.status).toLowerCase();

            if (status === 'succeeded' || data.is_success) {
                if (pollTimer) clearInterval(pollTimer);
                if (pendingView) pendingView.style.display = 'none';
                if (failedView) failedView.style.display = 'none';
                if (succeededView) succeededView.style.display = 'block';
            } else if (status === 'failed' || status === 'cancelled' || status === 'expired') {
                if (pollTimer) clearInterval(pollTimer);
                if (pendingView) pendingView.style.display = 'none';
                if (succeededView) succeededView.style.display = 'none';
                if (failedView) failedView.style.display = 'block';
                if (failedTitle) failedTitle.textContent = (status === 'expired') ? 'Payment Expired' : 'Payment Failed';
            } else {
                if (statusLabel && data.status_label) {
                    statusLabel.textContent = data.status_label;
                }
            }
        })
        .catch(function(err) {
            // Silently retry on transient network errors
        });
    }

    // Start polling after 2 seconds
    setTimeout(function() {
        checkStatus();
        pollTimer = setInterval(checkStatus, pollInterval);
    }, 2000);
})();
</script>
<?php endif; ?>
