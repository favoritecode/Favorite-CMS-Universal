<?php
/**
 * Automatic Payment Gateways Admin View
 *
 * Variables provided by PaymentGatewaySettingsController:
 * - $gateway        : ?BinancePayGateway
 * - $status         : ?array
 * - $config         : array
 * - $bkash          : ?BkashMerchantGateway
 * - $bkashStatus    : ?array
 * - $bkashConfig    : array
 * - $activeTab      : string ('binance' | 'bkash')
 * - $csrfToken      : string
 * - $flashSuccess   : ?string
 * - $flashError     : ?string
 */

$activeTab = $activeTab ?? 'binance';
?>

<div class="wrap favorite-pay-settings">
    <div class="page-header" style="margin-bottom: 24px;">
        <h1 class="page-title" style="margin: 0 0 4px 0;">Automatic Payment Gateways</h1>
        <p style="margin: 0; font-size: 13px; color: var(--wp-text-muted);">
            Configure automated acquiring payment gateways: Binance Pay (Crypto OpenAPI) and bKash Merchant API.
        </p>
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

    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?php echo $activeTab === 'binance' ? 'active' : ''; ?>" href="/admin/page/favorite-pay-gateways?tab=binance">
                🟡 Binance Pay (Crypto)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $activeTab === 'bkash' ? 'active' : ''; ?>" href="/admin/page/favorite-pay-gateways?tab=bkash">
                💖 bKash (Merchant API)
            </a>
        </li>
    </ul>

    <?php if ($activeTab === 'binance'): ?>
        <!-- Binance Pay Configuration (reusing exact binance.php sub-view) -->
        <?php include __DIR__ . '/binance.php'; ?>

    <?php elseif ($activeTab === 'bkash'): ?>
        <!-- bKash Merchant Configuration -->
        <?php
        $bkState = $bkashStatus['state'] ?? 'NOT_CONFIGURED';
        $bkReady = !empty($bkashStatus['is_ready']);
        $bkEnabled = !empty($bkashStatus['enabled']);
        ?>
        <div class="card mb-4" style="padding: 20px 24px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 12px;">
                <h3 style="font-size: 15px; font-weight: 700; margin: 0; color: var(--wp-text-main); display: flex; align-items: center; gap: 8px;">
                    <span>🛡️</span> bKash Merchant Gateway Status
                </h3>
                <?php if ($bkState === 'READY'): ?>
                    <span class="badge badge-success" style="font-size: 12px; padding: 5px 12px;">● READY</span>
                <?php elseif ($bkState === 'NOT_CONFIGURED'): ?>
                    <span class="badge badge-warning" style="font-size: 12px; padding: 5px 12px;">● NOT CONFIGURED</span>
                <?php else: ?>
                    <span class="badge badge-secondary" style="font-size: 12px; padding: 5px 12px;">● DISABLED</span>
                <?php endif; ?>
            </div>
            <div>
                <div class="row" style="margin-bottom: 12px;">
                    <div class="col-md-4 mb-2">
                        <small style="display: block; font-size: 11px; color: var(--wp-text-muted); text-transform: uppercase; font-weight: 600;">Charge Currency</small>
                        <div style="margin-top: 4px;"><span class="badge badge-primary">BDT (Direct)</span></div>
                        <div style="font-size: 11px; color: var(--wp-text-muted); margin-top: 4px;">No FX conversion needed for BDT orders</div>
                    </div>
                    <div class="col-md-4 mb-2">
                        <small style="display: block; font-size: 11px; color: var(--wp-text-muted); text-transform: uppercase; font-weight: 600;">Environment</small>
                        <div style="margin-top: 4px;"><?php echo !empty($bkashStatus['sandbox']) ? '<span class="badge badge-info">Sandbox / Test</span>' : '<span class="badge badge-success">Production</span>'; ?></div>
                    </div>
                    <div class="col-md-4 mb-2">
                        <small style="display: block; font-size: 11px; color: var(--wp-text-muted); text-transform: uppercase; font-weight: 600;">Credentials</small>
                        <ul style="list-style: none; padding: 0; margin: 4px 0 0 0; font-size: 12px; line-height: 1.6;">
                            <li>App Key: <?php echo !empty($bkashStatus['has_app_key']) ? '<span style="color: #16a34a; font-weight: 600;">✓ Set</span>' : '<span style="color: #dc2626; font-weight: 600;">✗ Missing</span>'; ?></li>
                            <li>App Secret: <?php echo !empty($bkashStatus['has_app_secret']) ? '<span style="color: #16a34a; font-weight: 600;">✓ Set (Protected)</span>' : '<span style="color: #dc2626; font-weight: 600;">✗ Missing</span>'; ?></li>
                            <li>Username: <?php echo !empty($bkashStatus['has_username']) ? '<span style="color: #16a34a; font-weight: 600;">✓ Set</span>' : '<span style="color: #dc2626; font-weight: 600;">✗ Missing</span>'; ?></li>
                            <li>Password: <?php echo !empty($bkashStatus['has_password']) ? '<span style="color: #16a34a; font-weight: 600;">✓ Set (Protected)</span>' : '<span style="color: #dc2626; font-weight: 600;">✗ Missing</span>'; ?></li>
                        </ul>
                    </div>
                </div>
                <div class="alert alert-info py-2" style="font-size: 12px; line-height: 1.5; margin-bottom: 0;">
                    <strong>Diagnostic:</strong> <?php echo htmlspecialchars($bkashStatus['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                </div>
            </div>
        </div>

        <div class="card" style="padding: 22px 24px; max-width: 820px;">
            <div style="margin-bottom: 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 12px;">
                <h3 style="font-size: 15px; font-weight: 700; margin: 0; color: var(--wp-text-main);">
                    Configure bKash Merchant API
                </h3>
            </div>
            <div>
                <form method="POST" action="/admin/page/favorite-pay-gateways">
                    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="gateway" value="bkash_direct">

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="enabled" id="bkash_enabled" value="1" <?php echo $bkEnabled ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="bkash_enabled" style="font-weight: 600; font-size: 13px; color: var(--wp-text-main);">
                            Enable bKash Automatic Merchant Gateway
                        </label>
                    </div>

                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" name="sandbox" id="bkash_sandbox" value="1" <?php echo !empty($bkashConfig['sandbox']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="bkash_sandbox" style="font-size: 13px; color: var(--wp-text-main);">
                            Enable Sandbox Mode (Developer Testing)
                        </label>
                    </div>

                    <div class="mb-3">
                        <label for="bkash_app_key" class="form-label" style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">App Key</label>
                        <input type="text" class="form-control" name="app_key" id="bkash_app_key" value="<?php echo htmlspecialchars((string)($bkashConfig['app_key'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter bKash Merchant App Key">
                    </div>

                    <div class="mb-3">
                        <label for="bkash_app_secret" class="form-label" style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">App Secret Key</label>
                        <input type="password" class="form-control" name="app_secret" id="bkash_app_secret" placeholder="<?php echo !empty($bkashStatus['has_app_secret']) ? '•••••••••••••••• (Leave blank to keep current secret)' : 'Enter bKash Merchant App Secret'; ?>">
                        <div class="form-text" style="font-size: 11px; color: var(--wp-text-muted); margin-top: 4px;">Protected. Never displayed after saving. Leave blank to preserve existing key.</div>
                    </div>

                    <div class="mb-3">
                        <label for="bkash_username" class="form-label" style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Merchant Username</label>
                        <input type="text" class="form-control" name="username" id="bkash_username" value="<?php echo htmlspecialchars((string)($bkashConfig['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter bKash API Username">
                    </div>

                    <div class="mb-3">
                        <label for="bkash_password" class="form-label" style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Merchant Password</label>
                        <input type="password" class="form-control" name="password" id="bkash_password" placeholder="<?php echo !empty($bkashStatus['has_password']) ? '•••••••••••••••• (Leave blank to keep current password)' : 'Enter bKash API Password'; ?>">
                        <div class="form-text" style="font-size: 11px; color: var(--wp-text-muted); margin-top: 4px;">Protected. Never displayed after saving. Leave blank to preserve existing password.</div>
                    </div>

                    <div class="mb-4">
                        <label for="bkash_base_url" class="form-label" style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Custom Base URL (Optional)</label>
                        <input type="text" class="form-control" name="base_url" id="bkash_base_url" value="<?php echo htmlspecialchars((string)($bkashConfig['base_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Leave blank to use official bKash endpoints">
                    </div>

                    <button type="submit" class="btn btn-primary" style="font-weight: 600; padding: 8px 18px;">
                        Save bKash Settings
                    </button>
                </form>
            </div>
        </div>

    <?php endif; ?>
</div>
