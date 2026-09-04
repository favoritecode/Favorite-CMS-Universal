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
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2>Binance Pay Merchant Settings</h2>
            <p class="text-muted">Configure automated cryptocurrency acquiring via the official Binance Pay Merchant OpenAPI.</p>
        </div>
        <div>
            <?php if ($statusState === 'READY'): ?>
                <span class="badge bg-success" style="font-size: 1rem; padding: 8px 16px;">READY</span>
            <?php elseif ($statusState === 'NOT_READY'): ?>
                <span class="badge bg-warning text-dark" style="font-size: 1rem; padding: 8px 16px;">NOT READY</span>
            <?php else: ?>
                <span class="badge bg-secondary" style="font-size: 1rem; padding: 8px 16px;">DISABLED</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($flashSuccess)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($flashError)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <!-- Diagnostics and Environment Card -->
    <div class="card mb-4" style="border: 1px solid #c3c4c7;">
        <div class="card-header bg-light">
            <h5 class="mb-0">Gateway Status & Currency Compatibility</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <strong>Primary Currency:</strong>
                    <div><code><?php echo $primaryCurrency; ?></code></div>
                    <div class="text-muted small mt-1">Store accounting base</div>
                </div>
                <div class="col-md-3 mb-3">
                    <strong>Acquiring Currency:</strong>
                    <div><span class="badge bg-primary text-white" style="font-size: 0.95rem;"><?php echo $paymentCurrency; ?></span></div>
                    <div class="text-muted small mt-1">Customer Binance charges</div>
                </div>
                <div class="col-md-3 mb-3">
                    <strong>Rate Source:</strong>
                    <div><span class="badge bg-secondary"><?php echo htmlspecialchars($status['rate_source'] ?? 'None', ENT_QUOTES, 'UTF-8'); ?></span></div>
                    <div class="small mt-1 text-muted">Provider: <?php echo htmlspecialchars($status['rate_source'] ?? 'None', ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="col-md-3 mb-3">
                    <strong>Conversion Status:</strong>
                    <?php if ($currencyCompatible): ?>
                        <div><span class="badge bg-success">READY</span></div>
                        <div class="text-success small mt-1">✓ <?php echo htmlspecialchars($status['rate_status'] ?? 'Valid', ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php else: ?>
                        <div><span class="badge bg-danger">NOT READY</span></div>
                        <div class="text-danger small mt-1">⚠ <?php echo htmlspecialchars($status['rate_status'] ?? 'No valid rate', ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-2">
                    <strong>Credentials Status:</strong>
                    <ul class="list-unstyled small mt-1 mb-0">
                        <li>Certificate-SN: <?php echo !empty($status['has_certificate_sn']) ? '<span class="text-success">✓ Configured</span>' : '<span class="text-danger">✗ Not Configured</span>'; ?></li>
                        <li>API Secret Key: <?php echo !empty($status['has_api_secret']) ? '<span class="text-success">✓ Configured (Protected)</span>' : '<span class="text-danger">✗ Not Configured</span>'; ?></li>
                    </ul>
                </div>
                <div class="col-md-6 mb-2">
                    <strong>Diagnostic Summary:</strong>
                    <div class="small mt-1 text-muted"><?php echo htmlspecialchars($status['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Webhook Guidance Card -->
    <div class="card mb-4" style="border: 1px solid #c3c4c7;">
        <div class="card-header bg-light">
            <h5 class="mb-0">Binance Pay Webhook Configuration</h5>
        </div>
        <div class="card-body">
            <p class="small mb-2">Configure this exact webhook callback URL in your <strong>Binance Merchant Portal</strong> under <em>Developers -> Webhook Configuration</em>:</p>
            <div class="input-group mb-2">
                <input type="text" class="form-control font-monospace" readonly value="<?php echo $webhookUrl; ?>" id="webhookUrlInput" style="background-color: #f8f9fa;">
                <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('webhookUrlInput').value); this.innerText='Copied!';">Copy URL</button>
            </div>
            <div class="alert alert-info small mb-0">
                <strong>Webhook Security:</strong> Incoming notifications are authenticated using Binance Pay Merchant <strong>HMAC-SHA512</strong> signatures. The CMS never receives credentials through the webhook, and live payments are never credited without valid cryptographic verification.
            </div>
        </div>
    </div>

    <!-- Merchant Settings Form -->
    <div class="card" style="border: 1px solid #c3c4c7;">
        <div class="card-header bg-light">
            <h5 class="mb-0">Merchant API Credentials</h5>
        </div>
        <div class="card-body">
            <form method="POST" action="/admin/page/favorite-pay-gateways">
                <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="mb-3 form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="enabled" name="enabled" value="1" <?php echo $isEnabled ? 'checked' : ''; ?>>
                    <label class="form-check-label fw-bold" for="enabled">Enable Binance Pay Gateway</label>
                    <div class="form-text">When disabled, customers cannot select Binance Pay at checkout.</div>
                </div>

                <div class="mb-3">
                    <label for="certificate_sn" class="form-label fw-bold">Certificate Serial Number (Certificate-SN) <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="certificate_sn" name="certificate_sn" value="<?php echo htmlspecialchars($config['certificate_sn'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. cert_sn_1234567890">
                    <div class="form-text">Found in your Binance Merchant Portal under <em>Developers -> API Credentials</em>.</div>
                </div>

                <div class="mb-3">
                    <label for="api_secret" class="form-label fw-bold">Binance API Secret Key <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" id="api_secret" name="api_secret" autocomplete="new-password" placeholder="<?php echo !empty($config['has_api_secret']) ? '•••••••••••••••• (Leave blank to keep existing secret)' : 'Enter 64-character API secret'; ?>">
                    <div class="form-text">
                        <?php if (!empty($config['has_api_secret'])): ?>
                            <span class="text-success fw-bold">✓ Secret is saved securely.</span> Leave this field blank to preserve the existing secret.
                        <?php else: ?>
                            <span class="text-danger">Required for live order signing and webhook verification.</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="preferred_currency" class="form-label fw-bold">Binance Acquiring / Payment Currency</label>
                    <select class="form-select" id="preferred_currency" name="preferred_currency">
                        <option value="USDT" <?php echo ($config['preferred_currency'] ?? 'USDT') === 'USDT' ? 'selected' : ''; ?>>USDT (Tether USD) - Recommended</option>
                        <option value="USDC" <?php echo ($config['preferred_currency'] ?? 'USDT') === 'USDC' ? 'selected' : ''; ?>>USDC (USD Coin)</option>
                    </select>
                    <div class="form-text">Cryptocurrency asset charged to customer on Binance Pay. Site orders in fiat (BDT, EUR, etc.) are converted at locked checkout rates.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">API Endpoint Environment</label>
                    <input type="text" class="form-control font-monospace" readonly value="https://bpay.binanceapi.com" style="background-color: #f8f9fa;">
                    <div class="form-text">Official Binance Pay Merchant OpenAPI host (fixed for SSRF protection).</div>
                </div>

                <div class="mb-4 form-check">
                    <input class="form-check-input" type="checkbox" id="sandbox" name="sandbox" value="1" <?php echo !empty($config['sandbox']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="sandbox">Sandbox / Test Mode</label>
                    <div class="form-text">Enable if testing inside the Binance Pay Merchant Sandbox environment.</div>
                </div>

                <div class="alert alert-secondary small mb-4">
                    <strong>Connectivity Note:</strong> Live connectivity testing is performed separately during controlled merchant verification to avoid creating artificial financial records or orders.
                </div>

                <button type="submit" class="btn btn-primary px-4">Save Changes</button>
            </form>
        </div>
    </div>
</div>
