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
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2>Automatic Payment Gateways</h2>
            <p class="text-muted">Configure automated acquiring payment gateways: Binance Pay and bKash Merchant.</p>
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

    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?php echo $activeTab === 'binance' ? 'active' : ''; ?>" href="/admin/page/favorite-pay-gateways?tab=binance">Binance Pay (Crypto)</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $activeTab === 'bkash' ? 'active' : ''; ?>" href="/admin/page/favorite-pay-gateways?tab=bkash">bKash (Merchant API)</a>
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
        <div class="card mb-4" style="border: 1px solid #c3c4c7;">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h5 class="mb-0">bKash Merchant Gateway Status</h5>
                <?php if ($bkState === 'READY'): ?>
                    <span class="badge bg-success" style="font-size: 1rem; padding: 8px 16px;">READY</span>
                <?php elseif ($bkState === 'NOT_CONFIGURED'): ?>
                    <span class="badge bg-warning text-dark" style="font-size: 1rem; padding: 8px 16px;">NOT CONFIGURED</span>
                <?php else: ?>
                    <span class="badge bg-secondary" style="font-size: 1rem; padding: 8px 16px;">DISABLED</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <strong>Charge Currency:</strong>
                        <div><span class="badge bg-primary text-white">BDT (Direct)</span></div>
                        <div class="small text-muted mt-1">No FX conversion needed for BDT orders</div>
                    </div>
                    <div class="col-md-4">
                        <strong>Environment:</strong>
                        <div><?php echo !empty($bkashStatus['sandbox']) ? '<span class="badge bg-info text-dark">Sandbox / Test</span>' : '<span class="badge bg-success">Production</span>'; ?></div>
                    </div>
                    <div class="col-md-4">
                        <strong>Credentials:</strong>
                        <ul class="list-unstyled small mt-1 mb-0">
                            <li>App Key: <?php echo !empty($bkashStatus['has_app_key']) ? '<span class="text-success">✓ Set</span>' : '<span class="text-danger">✗ Missing</span>'; ?></li>
                            <li>App Secret: <?php echo !empty($bkashStatus['has_app_secret']) ? '<span class="text-success">✓ Set (Protected)</span>' : '<span class="text-danger">✗ Missing</span>'; ?></li>
                            <li>Username: <?php echo !empty($bkashStatus['has_username']) ? '<span class="text-success">✓ Set</span>' : '<span class="text-danger">✗ Missing</span>'; ?></li>
                            <li>Password: <?php echo !empty($bkashStatus['has_password']) ? '<span class="text-success">✓ Set (Protected)</span>' : '<span class="text-danger">✗ Missing</span>'; ?></li>
                        </ul>
                    </div>
                </div>
                <div class="alert alert-info py-2 small mb-0">
                    <strong>Diagnostic:</strong> <?php echo htmlspecialchars($bkashStatus['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                </div>
            </div>
        </div>

        <div class="card" style="border: 1px solid #c3c4c7; max-width: 800px;">
            <div class="card-header bg-light">
                <h5 class="mb-0">Configure bKash Merchant API</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="/admin/page/favorite-pay-gateways">
                    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="gateway" value="bkash_direct">

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="enabled" id="bkash_enabled" value="1" <?php echo $bkEnabled ? 'checked' : ''; ?>>
                        <label class="form-check-label font-weight-bold" for="bkash_enabled">
                            <strong>Enable bKash Automatic Merchant Gateway</strong>
                        </label>
                    </div>

                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" name="sandbox" id="bkash_sandbox" value="1" <?php echo !empty($bkashConfig['sandbox']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="bkash_sandbox">
                            Enable Sandbox Mode (Developer Testing)
                        </label>
                    </div>

                    <div class="mb-3">
                        <label for="bkash_app_key" class="form-label font-weight-bold">App Key</label>
                        <input type="text" class="form-control" name="app_key" id="bkash_app_key" value="<?php echo htmlspecialchars((string)($bkashConfig['app_key'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter bKash Merchant App Key">
                    </div>

                    <div class="mb-3">
                        <label for="bkash_app_secret" class="form-label font-weight-bold">App Secret Key</label>
                        <input type="password" class="form-control" name="app_secret" id="bkash_app_secret" placeholder="<?php echo !empty($bkashStatus['has_app_secret']) ? '•••••••••••••••• (Leave blank to keep current secret)' : 'Enter bKash Merchant App Secret'; ?>">
                        <div class="form-text small text-muted">Protected. Never displayed after saving. Leave blank to preserve existing key.</div>
                    </div>

                    <div class="mb-3">
                        <label for="bkash_username" class="form-label font-weight-bold">Merchant Username</label>
                        <input type="text" class="form-control" name="username" id="bkash_username" value="<?php echo htmlspecialchars((string)($bkashConfig['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter bKash API Username">
                    </div>

                    <div class="mb-3">
                        <label for="bkash_password" class="form-label font-weight-bold">Merchant Password</label>
                        <input type="password" class="form-control" name="password" id="bkash_password" placeholder="<?php echo !empty($bkashStatus['has_password']) ? '•••••••••••••••• (Leave blank to keep current password)' : 'Enter bKash API Password'; ?>">
                        <div class="form-text small text-muted">Protected. Never displayed after saving. Leave blank to preserve existing password.</div>
                    </div>

                    <div class="mb-4">
                        <label for="bkash_base_url" class="form-label font-weight-bold">Custom Base URL (Optional)</label>
                        <input type="text" class="form-control" name="base_url" id="bkash_base_url" value="<?php echo htmlspecialchars((string)($bkashConfig['base_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Leave blank to use official bKash endpoints">
                    </div>

                    <button type="submit" class="btn btn-primary">Save bKash Settings</button>
                </form>
            </div>
        </div>

    <?php endif; ?>
</div>
