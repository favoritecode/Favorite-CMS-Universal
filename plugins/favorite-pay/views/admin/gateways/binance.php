<?php
/**
 * Binance Pay Gateway Configuration Admin View
 *
 * Variables provided by PaymentGatewaySettingsController:
 * - $gateway      : ?BinancePayGateway
 * - $status       : ?array
 * - $config       : array
 * - $csrfToken    : string
 * - $flashSuccess : ?string
 * - $flashError   : ?string
 */

$statusState = $status['state'] ?? 'DISABLED';
$isReady = !empty($status['is_ready']);
$isEnabled = !empty($status['enabled']);
$currencyCompatible = !empty($status['currency_compatible']);
$primaryCurrency = htmlspecialchars($status['primary_currency'] ?? 'BDT', ENT_QUOTES, 'UTF-8');
$paymentCurrency = htmlspecialchars($status['payment_currency'] ?? 'USDT', ENT_QUOTES, 'UTF-8');
$webhookUrl = htmlspecialchars($status['webhook_url'] ?? '', ENT_QUOTES, 'UTF-8');
?>

<div class="wrap favorite-pay-settings">
    <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 24px;">
        <div>
            <h1 class="page-title" style="margin: 0 0 4px 0;">Binance Pay Merchant Settings</h1>
            <p style="margin: 0; font-size: 13px; color: var(--wp-text-muted);">
                Configure automated cryptocurrency acquiring via the official Binance Pay Merchant OpenAPI.
            </p>
        </div>
        <div>
            <?php if ($statusState === 'READY'): ?>
                <span class="badge badge-success" style="font-size: 12px; padding: 6px 14px;">● READY</span>
            <?php elseif ($statusState === 'NOT_READY'): ?>
                <span class="badge badge-warning" style="font-size: 12px; padding: 6px 14px;">● NOT READY</span>
            <?php else: ?>
                <span class="badge badge-secondary" style="font-size: 12px; padding: 6px 14px;">● DISABLED</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($flashSuccess)): ?>
        <div class="notice notice-success" style="margin-bottom: 20px;">
            <?php echo htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($flashError)): ?>
        <div class="notice notice-error" style="margin-bottom: 20px;">
            <?php echo htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <!-- Diagnostics and Environment Card -->
    <div class="card mb-4" style="padding: 20px 24px;">
        <div style="margin-bottom: 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 12px;">
            <h3 style="font-size: 15px; font-weight: 700; margin: 0; color: var(--wp-text-main); display: flex; align-items: center; gap: 8px;">
                <span>📊</span> Gateway Status & Currency Compatibility
            </h3>
        </div>
        <div>
            <div class="row" style="margin-bottom: 14px;">
                <div class="col-md-3 mb-2">
                    <small style="display: block; font-size: 11px; color: var(--wp-text-muted); text-transform: uppercase; font-weight: 600;">Primary Currency</small>
                    <div style="margin-top: 4px;"><code style="font-size: 13px;"><?php echo $primaryCurrency; ?></code></div>
                    <div style="font-size: 11px; color: var(--wp-text-muted); margin-top: 2px;">Store accounting base</div>
                </div>
                <div class="col-md-3 mb-2">
                    <small style="display: block; font-size: 11px; color: var(--wp-text-muted); text-transform: uppercase; font-weight: 600;">Acquiring Currency</small>
                    <div style="margin-top: 4px;"><span class="badge badge-primary"><?php echo $paymentCurrency; ?></span></div>
                    <div style="font-size: 11px; color: var(--wp-text-muted); margin-top: 2px;">Customer Binance charges</div>
                </div>
                <div class="col-md-3 mb-2">
                    <small style="display: block; font-size: 11px; color: var(--wp-text-muted); text-transform: uppercase; font-weight: 600;">Rate Source</small>
                    <div style="margin-top: 4px;"><span class="badge badge-secondary"><?php echo htmlspecialchars($status['rate_source'] ?? 'None', ENT_QUOTES, 'UTF-8'); ?></span></div>
                    <div style="font-size: 11px; color: var(--wp-text-muted); margin-top: 2px;">Provider: <?php echo htmlspecialchars($status['rate_source'] ?? 'None', ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="col-md-3 mb-2">
                    <small style="display: block; font-size: 11px; color: var(--wp-text-muted); text-transform: uppercase; font-weight: 600;">Conversion Status</small>
                    <?php if ($currencyCompatible): ?>
                        <div style="margin-top: 4px;"><span class="badge badge-success">READY</span></div>
                        <div style="font-size: 11px; color: #16a34a; margin-top: 2px;">✓ <?php echo htmlspecialchars($status['rate_status'] ?? 'Valid', ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php else: ?>
                        <div style="margin-top: 4px;"><span class="badge badge-danger">NOT READY</span></div>
                        <div style="font-size: 11px; color: #dc2626; margin-top: 2px;">⚠ <?php echo htmlspecialchars($status['rate_status'] ?? 'No valid rate', ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-2">
                    <small style="display: block; font-size: 11px; color: var(--wp-text-muted); text-transform: uppercase; font-weight: 600;">Credentials Status</small>
                    <ul style="list-style: none; padding: 0; margin: 4px 0 0 0; font-size: 12px; line-height: 1.6;">
                        <li>Certificate-SN: <?php echo !empty($status['has_certificate_sn']) ? '<span style="color: #16a34a; font-weight: 600;">✓ Configured</span>' : '<span style="color: #dc2626; font-weight: 600;">✗ Not Configured</span>'; ?></li>
                        <li>API Secret Key: <?php echo !empty($status['has_api_secret']) ? '<span style="color: #16a34a; font-weight: 600;">✓ Configured (Protected)</span>' : '<span style="color: #dc2626; font-weight: 600;">✗ Not Configured</span>'; ?></li>
                    </ul>
                </div>
                <div class="col-md-6 mb-2">
                    <small style="display: block; font-size: 11px; color: var(--wp-text-muted); text-transform: uppercase; font-weight: 600;">Diagnostic Summary</small>
                    <div style="font-size: 12px; color: var(--wp-text-muted); margin-top: 4px;"><?php echo htmlspecialchars($status['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Webhook Guidance Card -->
    <div class="card mb-4" style="padding: 20px 24px;">
        <div style="margin-bottom: 14px; border-bottom: 1px solid #f1f5f9; padding-bottom: 12px;">
            <h3 style="font-size: 15px; font-weight: 700; margin: 0; color: var(--wp-text-main); display: flex; align-items: center; gap: 8px;">
                <span>🔗</span> Binance Pay Webhook Configuration
            </h3>
        </div>
        <div>
            <p style="font-size: 13px; color: #475569; margin: 0 0 10px 0;">
                Configure this exact webhook callback URL in your <strong>Binance Merchant Portal</strong> under <em>Developers &rarr; Webhook Configuration</em>:
            </p>
            <div style="display: flex; gap: 10px; margin-bottom: 14px; flex-wrap: wrap;">
                <input type="text" class="form-control" readonly value="<?php echo $webhookUrl; ?>" id="webhookUrlInput" style="font-family: monospace; background: #f8fafc; flex: 1; min-width: 280px; font-size: 13px;">
                <button class="btn btn-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('webhookUrlInput').value); this.innerText='Copied!';">Copy URL</button>
            </div>
            <div class="alert alert-info py-2" style="font-size: 12px; line-height: 1.5; margin: 0;">
                <strong>Webhook Security:</strong> Incoming notifications are authenticated using Binance Pay Merchant <strong>HMAC-SHA512</strong> signatures. The CMS never receives credentials through the webhook, and live payments are never credited without valid cryptographic verification.
            </div>
        </div>
    </div>

    <!-- Merchant Settings Form -->
    <div class="card" style="padding: 22px 24px; max-width: 820px;">
        <div style="margin-bottom: 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 12px;">
            <h3 style="font-size: 15px; font-weight: 700; margin: 0; color: var(--wp-text-main); display: flex; align-items: center; gap: 8px;">
                <span>🔑</span> Merchant API Credentials
            </h3>
        </div>
        <div>
            <form method="POST" action="/admin/page/favorite-pay-gateways">
                <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="enabled" name="enabled" value="1" <?php echo $isEnabled ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="enabled" style="font-weight: 600; font-size: 13px; color: var(--wp-text-main);">
                        Enable Binance Pay Gateway
                    </label>
                    <div class="form-text" style="font-size: 11px; color: var(--wp-text-muted); margin-top: 2px;">When disabled, customers cannot select Binance Pay at checkout.</div>
                </div>

                <div class="mb-3">
                    <label for="certificate_sn" class="form-label" style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">
                        Certificate Serial Number (Certificate-SN) <span style="color: var(--wp-danger);">*</span>
                    </label>
                    <input type="text" class="form-control" id="certificate_sn" name="certificate_sn" value="<?php echo htmlspecialchars($config['certificate_sn'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. cert_sn_1234567890">
                    <div class="form-text" style="font-size: 11px; color: var(--wp-text-muted); margin-top: 4px;">Found in your Binance Merchant Portal under <em>Developers &rarr; API Credentials</em>.</div>
                </div>

                <div class="mb-3">
                    <label for="api_secret" class="form-label" style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">
                        Binance API Secret Key <span style="color: var(--wp-danger);">*</span>
                    </label>
                    <input type="password" class="form-control" id="api_secret" name="api_secret" autocomplete="new-password" placeholder="<?php echo !empty($config['has_api_secret']) ? '•••••••••••••••• (Leave blank to keep existing secret)' : 'Enter 64-character API secret'; ?>">
                    <div class="form-text" style="font-size: 11px; margin-top: 4px;">
                        <?php if (!empty($config['has_api_secret'])): ?>
                            <span style="color: #16a34a; font-weight: 600;">✓ Secret is saved securely.</span> Leave this field blank to preserve the existing secret.
                        <?php else: ?>
                            <span style="color: var(--wp-danger);">Required for live order signing and webhook verification.</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="preferred_currency" class="form-label" style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">
                        Binance Acquiring / Payment Currency
                    </label>
                    <select class="form-control" id="preferred_currency" name="preferred_currency">
                        <option value="USDT" <?php echo ($config['preferred_currency'] ?? 'USDT') === 'USDT' ? 'selected' : ''; ?>>USDT (Tether USD) - Recommended</option>
                        <option value="USDC" <?php echo ($config['preferred_currency'] ?? 'USDT') === 'USDC' ? 'selected' : ''; ?>>USDC (USD Coin)</option>
                    </select>
                    <div class="form-text" style="font-size: 11px; color: var(--wp-text-muted); margin-top: 4px;">Cryptocurrency asset charged to customer on Binance Pay. Site orders in fiat (BDT, EUR, etc.) are converted at locked checkout rates.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label" style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">API Endpoint Environment</label>
                    <input type="text" class="form-control" readonly value="https://bpay.binanceapi.com" style="font-family: monospace; background: #f8fafc; font-size: 13px;">
                    <div class="form-text" style="font-size: 11px; color: var(--wp-text-muted); margin-top: 4px;">Official Binance Pay Merchant OpenAPI host (fixed for SSRF protection).</div>
                </div>

                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" id="sandbox" name="sandbox" value="1" <?php echo !empty($config['sandbox']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="sandbox" style="font-size: 13px; color: var(--wp-text-main);">Sandbox / Test Mode</label>
                    <div class="form-text" style="font-size: 11px; color: var(--wp-text-muted); margin-top: 2px;">Enable if testing inside the Binance Pay Merchant Sandbox environment.</div>
                </div>

                <div class="alert alert-secondary py-2 small mb-4" style="font-size: 12px; line-height: 1.5;">
                    <strong>Connectivity Note:</strong> Live connectivity testing is performed separately during controlled merchant verification to avoid creating artificial financial records or orders.
                </div>

                <button type="submit" class="btn btn-primary" style="font-weight: 600; padding: 8px 22px;">
                    Save Changes
                </button>
            </form>
        </div>
    </div>
</div>
