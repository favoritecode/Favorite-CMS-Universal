<?php
/**
 * Manual Bangladesh Payment Methods Admin View
 *
 * Variables provided by PaymentGatewaySettingsController:
 * - $methods      : array<string, ?ManualBangladeshGateway> (bkash, nagad, rocket, bank)
 * - $activeTab    : string ('mobile' | 'bank')
 * - $csrfToken    : string
 * - $flashSuccess : ?string
 * - $flashError   : ?string
 */

$activeTab = $activeTab ?? 'mobile';
?>

<div class="wrap favorite-pay-settings">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2>Manual / Offline Payment Settings</h2>
            <p class="text-muted">Configure receiving numbers, bank accounts, and checkout instructions for manual regional payment methods.</p>
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
            <a class="nav-link <?php echo $activeTab === 'mobile' ? 'active' : ''; ?>" href="/admin/page/favorite-pay-manual?tab=mobile">Mobile Banking (bKash, Nagad, Rocket)</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $activeTab === 'bank' ? 'active' : ''; ?>" href="/admin/page/favorite-pay-manual?tab=bank">Bank Wire / Transfer</a>
        </li>
    </ul>

    <?php if ($activeTab === 'mobile'): ?>
        <!-- Mobile Banking Methods -->
        <div class="row">
            <?php 
            $mfsList = [
                'manual_bkash'  => ['title' => 'bKash Manual Payment', 'color' => '#e2136e', 'gw' => $methods['bkash'] ?? null],
                'manual_nagad'  => ['title' => 'Nagad Manual Payment', 'color' => '#f7941d', 'gw' => $methods['nagad'] ?? null],
                'manual_rocket' => ['title' => 'Rocket Manual Payment', 'color' => '#8c3494', 'gw' => $methods['rocket'] ?? null],
            ];
            foreach ($mfsList as $methodKey => $info): 
                $gw = $info['gw'];
                $cfg = $gw ? $gw->getConfig() : [];
                $isEnabled = $gw ? $gw->isEnabled() : false;
                $isConfigured = $gw ? $gw->isConfigured() : false;
            ?>
            <div class="col-lg-4 mb-4">
                <div class="card h-100" style="border: 1px solid #c3c4c7; border-top: 4px solid <?php echo $info['color']; ?>;">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h5 class="mb-0" style="font-size: 1.1rem;"><?php echo htmlspecialchars($info['title'], ENT_QUOTES, 'UTF-8'); ?></h5>
                        <?php if ($isEnabled && $isConfigured): ?>
                            <span class="badge bg-success">ACTIVE</span>
                        <?php elseif ($isEnabled && !$isConfigured): ?>
                            <span class="badge bg-warning text-dark">NO NUMBER</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">DISABLED</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="/admin/page/favorite-pay-manual">
                            <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="method" value="<?php echo htmlspecialchars($methodKey, ENT_QUOTES, 'UTF-8'); ?>">

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="enabled" id="<?php echo $methodKey; ?>_enabled" value="1" <?php echo $isEnabled ? 'checked' : ''; ?>>
                                <label class="form-check-label font-weight-bold" for="<?php echo $methodKey; ?>_enabled">
                                    <strong>Enable <?php echo htmlspecialchars($info['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                </label>
                            </div>

                            <div class="mb-3">
                                <label for="<?php echo $methodKey; ?>_num" class="form-label font-weight-bold">Payment / Receiving Number</label>
                                <input type="text" class="form-control" name="account_number" id="<?php echo $methodKey; ?>_num" value="<?php echo htmlspecialchars((string)($cfg['account_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. 017XXXXXXXX">
                                <div class="form-text small text-muted">Customer sends payment to this receiving number.</div>
                            </div>

                            <div class="mb-3">
                                <label for="<?php echo $methodKey; ?>_name" class="form-label font-weight-bold">Account Name (Optional)</label>
                                <input type="text" class="form-control" name="account_name" id="<?php echo $methodKey; ?>_name" value="<?php echo htmlspecialchars((string)($cfg['account_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. Favorite CMS Merchant">
                            </div>

                            <div class="mb-3">
                                <label for="<?php echo $methodKey; ?>_type" class="form-label font-weight-bold">Account Type</label>
                                <select class="form-select" name="account_type" id="<?php echo $methodKey; ?>_type">
                                    <option value="Merchant" <?php echo (($cfg['account_type'] ?? '') === 'Merchant') ? 'selected' : ''; ?>>Merchant Payment</option>
                                    <option value="Personal" <?php echo (($cfg['account_type'] ?? '') === 'Personal') ? 'selected' : ''; ?>>Personal (Send Money)</option>
                                    <option value="Agent" <?php echo (($cfg['account_type'] ?? '') === 'Agent') ? 'selected' : ''; ?>>Agent (Cash In)</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="<?php echo $methodKey; ?>_inst" class="form-label font-weight-bold">Public Payment Instructions</label>
                                <textarea class="form-control" name="instructions" id="<?php echo $methodKey; ?>_inst" rows="3" placeholder="Step-by-step instructions shown to customer on checkout..."><?php echo htmlspecialchars((string)($cfg['instructions'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label for="<?php echo $methodKey; ?>_ref" class="form-label font-weight-bold">Reference Instructions</label>
                                <input type="text" class="form-control" name="reference_instructions" id="<?php echo $methodKey; ?>_ref" value="<?php echo htmlspecialchars((string)($cfg['reference_instructions'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. Use your Order ID as reference">
                            </div>

                            <button type="submit" class="btn btn-primary btn-sm w-100">Save <?php echo htmlspecialchars($info['title'], ENT_QUOTES, 'UTF-8'); ?></button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

    <?php else: ?>
        <!-- Bank Transfer Method -->
        <?php 
        $bankGw = $methods['bank'] ?? null;
        $bankCfg = $bankGw ? $bankGw->getConfig() : [];
        $bankEnabled = $bankGw ? $bankGw->isEnabled() : false;
        $bankConfigured = $bankGw ? $bankGw->isConfigured() : false;
        ?>
        <div class="card" style="border: 1px solid #c3c4c7; max-width: 800px;">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Manual Bank Wire / Transfer Configuration</h5>
                <?php if ($bankEnabled && $bankConfigured): ?>
                    <span class="badge bg-success">ACTIVE</span>
                <?php elseif ($bankEnabled && !$bankConfigured): ?>
                    <span class="badge bg-warning text-dark">NO BANK ACCOUNT</span>
                <?php else: ?>
                    <span class="badge bg-secondary">DISABLED</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <form method="POST" action="/admin/page/favorite-pay-manual">
                    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="method" value="manual_bank">

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" name="enabled" id="bank_enabled" value="1" <?php echo $bankEnabled ? 'checked' : ''; ?>>
                        <label class="form-check-label font-weight-bold" for="bank_enabled">
                            <strong>Enable Bank Transfer Payment</strong>
                        </label>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="bank_name" class="form-label font-weight-bold">Bank Name</label>
                            <input type="text" class="form-control" name="bank_name" id="bank_name" value="<?php echo htmlspecialchars((string)($bankCfg['bank_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. Standard Chartered / City Bank">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="branch_name" class="form-label font-weight-bold">Branch Name</label>
                            <input type="text" class="form-control" name="branch_name" id="branch_name" value="<?php echo htmlspecialchars((string)($bankCfg['branch_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. Principal Branch, Dhaka">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="account_name" class="form-label font-weight-bold">Account Holder Name</label>
                            <input type="text" class="form-control" name="account_name" id="account_name" value="<?php echo htmlspecialchars((string)($bankCfg['account_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. Favorite CMS Ltd.">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="account_number" class="form-label font-weight-bold">Account Number</label>
                            <input type="text" class="form-control" name="account_number" id="account_number" value="<?php echo htmlspecialchars((string)($bankCfg['account_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. 1101234567890">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="routing_no" class="form-label font-weight-bold">Routing Number (EFT/NPSB)</label>
                            <input type="text" class="form-control" name="routing_no" id="routing_no" value="<?php echo htmlspecialchars((string)($bankCfg['routing_no'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. 225260000">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="swift_code" class="form-label font-weight-bold">SWIFT / BIC (Optional)</label>
                            <input type="text" class="form-control" name="swift_code" id="swift_code" value="<?php echo htmlspecialchars((string)($bankCfg['swift_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. CIBLBDDH">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="instructions" class="form-label font-weight-bold">Customer Payment Instructions</label>
                        <textarea class="form-control" name="instructions" id="instructions" rows="3" placeholder="Transfer exact amount to our bank account and submit deposit slip / reference..."><?php echo htmlspecialchars((string)($bankCfg['instructions'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label for="reference_instructions" class="form-label font-weight-bold">Transaction Reference Guidance</label>
                        <input type="text" class="form-control" name="reference_instructions" id="reference_instructions" value="<?php echo htmlspecialchars((string)($bankCfg['reference_instructions'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. Enter your bank deposit transaction number or EFT reference">
                    </div>

                    <button type="submit" class="btn btn-primary">Save Bank Transfer Settings</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>
