<?php
/**
 * Favorite Pay Exchange Rates Management Admin View
 *
 * Variables provided by PaymentRateController:
 * - $rates               : array
 * - $supportedCurrencies : array
 * - $baseCurrency        : string
 * - $csrfToken           : string
 * - $liveFxStatus        : ?array
 * - $flashSuccess        : ?string
 * - $flashError          : ?string
 */

// Compute currently active saved rates grouped by base/quote pair
$now = date('Y-m-d H:i:s');
$activeRates = [];
$activePairsMap = [];

if (!empty($rates)) {
    foreach ($rates as $r) {
        $b = strtoupper((string)($r['base_currency'] ?? ''));
        $q = strtoupper((string)($r['quote_currency'] ?? ''));
        $pair = "{$b}/{$q}";
        $isExpired = !empty($r['expires_at']) && $r['expires_at'] < $now;
        $isEffective = empty($r['effective_at']) || $r['effective_at'] <= $now;
        if (($r['status'] ?? '') === 'active' && !$isExpired && $isEffective) {
            if (!isset($activePairsMap[$pair])) {
                $rawRate = (float)($r['rate'] ?? 0);
                $rateVal = number_format($rawRate, 6, '.', '');
                $rateValTrimmed = rtrim(rtrim($rateVal, '0'), '.');
                $r['formatted_rate'] = $rateValTrimmed;
                $activePairsMap[$pair] = [
                    'id'             => (int)($r['id'] ?? 0),
                    'base_currency'  => $b,
                    'quote_currency' => $q,
                    'pair'           => $pair,
                    'rate'           => $rateValTrimmed,
                    'effective_at'   => (string)($r['effective_at'] ?? 'Immediately'),
                    'expires_at'     => (string)($r['expires_at'] ?? 'Indefinite'),
                    'source'         => (string)($r['source'] ?? 'operator'),
                ];
                $activeRates[] = $r;
            }
        }
    }
}

// Find default active rate for current default selection (USDT / BaseCurrency)
$defaultBase = 'USDT';
$defaultQuote = strtoupper((string)($baseCurrency ?? 'BDT'));
$defaultPair = "{$defaultBase}/{$defaultQuote}";
$currentSavedRate = $activePairsMap[$defaultPair] ?? ($activeRates[0] ?? null);
$currentSavedRateValue = $currentSavedRate ? ($currentSavedRate['rate'] ?? $currentSavedRate['formatted_rate'] ?? '') : '';
?>

<div class="wrap favorite-pay-rates">
    <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
        <div>
            <h1 class="page-title" style="margin: 0 0 4px 0;">Exchange Rate Management</h1>
            <p style="margin: 0; font-size: 13px; color: var(--wp-text-muted);">
                Configure and audit authoritative exchange rates for multi-currency payment conversions (e.g. Binance Pay acquiring in USDT/USDC).
            </p>
        </div>
        <div>
            <a href="/admin/page/favorite-pay-gateways" class="btn btn-secondary" style="font-size: 13px;">
                &larr; Gateway Settings
            </a>
        </div>
    </div>

    <?php if (!empty($flashSuccess)): ?>
        <div class="notice notice-success" style="margin-bottom: 20px; display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: #f0fdf4; border-left: 4px solid #16a34a; border-radius: 4px;">
            <span style="font-size: 18px; color: #16a34a;">✓</span>
            <div style="font-size: 13.5px; font-weight: 600; color: #15803d;">
                <?php echo htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($flashError)): ?>
        <div class="notice notice-error" style="margin-bottom: 20px; display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: #fef2f2; border-left: 4px solid #dc2626; border-radius: 4px;">
            <span style="font-size: 18px; color: #dc2626;">⚠️</span>
            <div style="font-size: 13.5px; font-weight: 600; color: #991b1b;">
                <?php echo htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Prominent "Current Saved Rate in Production" Showcase Banner -->
    <div class="card mb-4" style="padding: 22px 24px; border-left: 4px solid #16a34a; background: #f8fafc;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 14px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 20px;">💎</span>
                <h3 style="font-size: 15px; font-weight: 700; margin: 0; color: var(--wp-text-main);">
                    Current Saved Authoritative Rates
                </h3>
            </div>
            <span class="badge badge-success" style="font-size: 12px; padding: 4px 10px; display: inline-flex; align-items: center; gap: 5px;">
                <span style="width: 6px; height: 6px; border-radius: 50%; background: #16a34a;"></span>
                ACTIVE IN PRODUCTION
            </span>
        </div>

        <?php if (!empty($activePairsMap)): ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; margin-bottom: 12px;">
                <?php foreach ($activePairsMap as $pairKey => $rateInfo): ?>
                    <div style="background: #fff; border: 1px solid #bbf7d0; border-radius: 8px; padding: 16px 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <span style="font-size: 12px; font-weight: 700; color: #166534; text-transform: uppercase; letter-spacing: 0.5px;">
                                Current Saved Rate
                            </span>
                            <span class="badge badge-success" style="font-size: 10px; padding: 2px 6px;">#<?php echo (int)$rateInfo['id']; ?> Active</span>
                        </div>
                        <div style="font-size: 24px; font-weight: 800; color: #0f172a; line-height: 1.2; margin-bottom: 6px;">
                            1 <?php echo htmlspecialchars($rateInfo['base_currency'], ENT_QUOTES, 'UTF-8'); ?> = 
                            <span style="color: #15803d;"><?php echo htmlspecialchars($rateInfo['rate'], ENT_QUOTES, 'UTF-8'); ?></span> 
                            <?php echo htmlspecialchars($rateInfo['quote_currency'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <div style="font-size: 11px; color: var(--wp-text-muted); display: flex; gap: 12px; flex-wrap: wrap;">
                            <span><strong>Effective:</strong> <?php echo htmlspecialchars($rateInfo['effective_at'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <span><strong>Expires:</strong> <?php echo htmlspecialchars($rateInfo['expires_at'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="font-size: 12px; color: #475569; display: flex; align-items: center; gap: 6px;">
                <span>ℹ️</span> The rate value displayed above is currently stored in the database and applied to all incoming conversions and checkout calculations.
            </div>
        <?php else: ?>
            <div style="background: #fff; border: 1px solid #fed7aa; border-radius: 8px; padding: 16px 18px; color: #9a3412; font-size: 13px; display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 18px;">⚠️</span>
                <div>
                    <strong>No authoritative saved rate configured yet.</strong>
                    <div>Conversions currently use default fallback live market rates. Use the form below to lock a fixed authoritative rate.</div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Live FX Provider Status Card -->
    <?php if (!empty($liveFxStatus)): ?>
        <div class="card mb-4" style="padding: 20px 24px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 12px;">
                <h3 style="font-size: 15px; font-weight: 700; margin: 0; color: var(--wp-text-main); display: flex; align-items: center; gap: 8px;">
                    <span>📡</span> Automated Live FX Market Rate Provider
                </h3>
                <form method="POST" action="/admin/page/favorite-pay-rates" style="margin: 0;">
                    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="refresh_live">
                    <button type="submit" class="btn btn-sm btn-primary" style="display: inline-flex; align-items: center; gap: 6px;">
                        <span>🔄</span> Sync Live Rates Now
                    </button>
                </form>
            </div>
            <div>
                <div class="row" style="margin-bottom: 12px;">
                    <div class="col-md-3 mb-2">
                        <small style="display: block; font-size: 11px; color: var(--wp-text-muted); text-transform: uppercase; font-weight: 600;">Status</small>
                        <?php if ($liveFxStatus['state'] === 'READY'): ?>
                            <span class="badge badge-success" style="font-size: 12px; padding: 4px 10px;">● READY (Live Market Feed)</span>
                        <?php else: ?>
                            <span class="badge badge-warning" style="font-size: 12px; padding: 4px 10px;">● <?php echo htmlspecialchars($liveFxStatus['state'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3 mb-2">
                        <small style="display: block; font-size: 11px; color: var(--wp-text-muted); text-transform: uppercase; font-weight: 600;">Source Provider</small>
                        <strong style="color: var(--wp-text-main); font-size: 13px;">Open Market Rates (ExchangeRate-API)</strong>
                    </div>
                    <div class="col-md-3 mb-2">
                        <small style="display: block; font-size: 11px; color: var(--wp-text-muted); text-transform: uppercase; font-weight: 600;">Last Sync</small>
                        <span style="font-size: 13px; color: var(--wp-text-main);"><?php echo htmlspecialchars($liveFxStatus['last_refresh_time'] ?? 'Cached / Standby', ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="col-md-3 mb-2">
                        <small style="display: block; font-size: 11px; color: var(--wp-text-muted); text-transform: uppercase; font-weight: 600;">Emergency Manual Fallback</small>
                        <span class="badge <?php echo ($liveFxStatus['emergency_fallback'] ?? '') === 'ENABLED' ? 'badge-warning' : 'badge-secondary'; ?>" style="font-size: 12px;">
                            <?php echo htmlspecialchars($liveFxStatus['emergency_fallback'] ?? 'DISABLED (Fail Closed)', ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                </div>
                <div style="font-size: 12px; color: var(--wp-text-muted); line-height: 1.5; background: #f8fafc; padding: 10px 14px; border-radius: 6px;">
                    Normal automatic conversion runs on live market rates (ExchangeRate-API / Binance Ticker). If live rates are unavailable or stale, the engine safely fails closed. The form below manages explicit operator rates for manual payments or emergency override mode.
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Rate Configuration & Update Card -->
    <div class="card mb-4" style="padding: 22px 24px;">
        <div style="margin-bottom: 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 12px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <h3 style="font-size: 15px; font-weight: 700; margin: 0; color: var(--wp-text-main); display: flex; align-items: center; gap: 8px;">
                <span>⚙️</span> Configure Authoritative Exchange Rate
            </h3>
            <span style="font-size: 12px; color: var(--wp-text-muted);">
                Changes take effect upon saving and automatically archive prior rates
            </span>
        </div>

        <form method="POST" action="/admin/page/favorite-pay-rates" id="rate-lock-form">
            <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="save">

            <div class="row">
                <div class="col-md-3 mb-3">
                    <label for="base_currency" class="form-label" style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">
                        Base Currency
                    </label>
                    <select name="base_currency" id="base_currency" class="form-control" required onchange="handleCurrencyChange()">
                        <?php foreach ($supportedCurrencies as $code): ?>
                            <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $code === 'USDT' ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text" style="font-size: 11px; color: var(--wp-text-muted); margin-top: 4px;">Asset being quoted (e.g. USDT)</div>
                </div>

                <div class="col-md-3 mb-3">
                    <label for="quote_currency" class="form-label" style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">
                        Quote Currency
                    </label>
                    <select name="quote_currency" id="quote_currency" class="form-control" required onchange="handleCurrencyChange()">
                        <?php foreach ($supportedCurrencies as $code): ?>
                            <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $code === ($baseCurrency ?? 'BDT') ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text" style="font-size: 11px; color: var(--wp-text-muted); margin-top: 4px;">Price currency (e.g. BDT)</div>
                </div>

                <div class="col-md-3 mb-3">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                        <label for="rate" class="form-label" style="font-size: 12px; font-weight: 600; margin: 0;">
                            Exchange Rate
                        </label>
                        <span id="current-saved-rate-badge" class="badge badge-secondary" style="font-size: 10px;">
                            Saved: <?php echo $currentSavedRateValue !== '' ? htmlspecialchars($currentSavedRateValue, ENT_QUOTES, 'UTF-8') : 'None'; ?>
                        </span>
                    </div>
                    <input type="text" 
                           name="rate" 
                           id="rate" 
                           class="form-control" 
                           placeholder="e.g. 124.50" 
                           value="<?php echo htmlspecialchars($currentSavedRateValue, ENT_QUOTES, 'UTF-8'); ?>"
                           required 
                           pattern="^\d+(\.\d{1,6})?$" 
                           oninput="updateRateDisplay()">
                    <div class="form-text" style="font-size: 11px; margin-top: 4px;" id="rate-display-helper">
                        1 USDT = <?php echo $currentSavedRateValue !== '' ? htmlspecialchars($currentSavedRateValue, ENT_QUOTES, 'UTF-8') : '[?]'; ?> BDT
                    </div>
                </div>

                <div class="col-md-3 mb-3">
                    <label for="effective_at" class="form-label" style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">
                        Effective From
                    </label>
                    <input type="datetime-local" name="effective_at" id="effective_at" class="form-control">
                    <div class="form-text" style="font-size: 11px; color: var(--wp-text-muted); margin-top: 4px;">Blank = take effect immediately.</div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="expires_at" class="form-label" style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">
                        Expires At (Optional)
                    </label>
                    <input type="datetime-local" name="expires_at" id="expires_at" class="form-control">
                    <div class="form-text" style="font-size: 11px; color: var(--wp-text-muted); margin-top: 4px;">Blank = indefinite until retired.</div>
                </div>

                <div class="col-md-5 mb-3">
                    <label for="notes" class="form-label" style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">
                        Audit Notes / Reason
                    </label>
                    <input type="text" name="notes" id="notes" class="form-control" placeholder="e.g. Updated treasury conversion rate">
                    <div class="form-text" style="font-size: 11px; color: var(--wp-text-muted); margin-top: 4px;">Recorded in immutable rate log.</div>
                </div>

                <div class="col-md-3 mb-3" style="display: flex; align-items: flex-end;">
                    <button type="submit" class="btn btn-primary w-100" id="lock-rate-submit-btn" style="padding-top: 9px; padding-bottom: 9px; font-weight: 600;">
                        Lock Authoritative Rate
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Rates History & Audit Table -->
    <div class="card" style="padding: 0; overflow: hidden;">
        <div style="padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
            <h3 style="font-size: 15px; font-weight: 700; margin: 0; color: var(--wp-text-main);">
                Authoritative Rates Audit Log
            </h3>
            <span class="badge badge-secondary"><?php echo count($rates); ?> records</span>
        </div>
        <div class="wp-table-wrap" style="border: none; border-radius: 0; box-shadow: none;">
            <table class="wp-table">
                <thead>
                    <tr>
                        <th style="width: 60px;">ID</th>
                        <th>Currency Pair</th>
                        <th>Exchange Rate Direction</th>
                        <th>Scaled Factor</th>
                        <th>Status</th>
                        <th>Effective Period</th>
                        <th>Source / Operator</th>
                        <th>Notes</th>
                        <th style="width: 120px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rates)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; color: var(--wp-text-muted); padding: 36px 16px;">
                                <em>No exchange rates configured yet. Please lock an authoritative rate above to enable conversions.</em>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        foreach ($rates as $row): 
                            $isExpired = !empty($row['expires_at']) && $row['expires_at'] < $now;
                            $status = (string)($row['status'] ?? 'active');
                            $b = strtoupper((string)($row['base_currency'] ?? ''));
                            $q = strtoupper((string)($row['quote_currency'] ?? ''));
                            $pair = "{$b}/{$q}";
                            $rateVal = number_format((float)$row['rate'], 6, '.', '');
                            $rateValTrimmed = rtrim(rtrim($rateVal, '0'), '.');
                            $isActivePairCurrent = isset($activePairsMap[$pair]) && ($activePairsMap[$pair]['id'] === (int)$row['id']);
                        ?>
                            <tr style="<?php echo $isActivePairCurrent ? 'background: #f0fdf4; font-weight: 500;' : ''; ?>">
                                <td><code style="font-weight: 600;">#<?php echo (int)$row['id']; ?></code></td>
                                <td>
                                    <strong style="color: var(--wp-text-main);"><?php echo htmlspecialchars($row['base_currency'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <span style="color: var(--wp-text-muted);">/</span>
                                    <strong style="color: var(--wp-text-main);"><?php echo htmlspecialchars($row['quote_currency'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <?php if ($isActivePairCurrent): ?>
                                        <span class="badge badge-success" style="font-size: 10px; margin-left: 6px; padding: 2px 6px;">CURRENT</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-size: 12px; background: <?php echo $isActivePairCurrent ? '#dcfce7' : '#f8fafc'; ?>; border: 1px solid <?php echo $isActivePairCurrent ? '#86efac' : '#e2e8f0'; ?>; padding: 3px 8px; border-radius: 4px;">
                                        1 <?php echo htmlspecialchars($row['base_currency'], ENT_QUOTES, 'UTF-8'); ?> = 
                                        <strong><?php echo htmlspecialchars($rateValTrimmed, ENT_QUOTES, 'UTF-8'); ?></strong> 
                                        <?php echo htmlspecialchars($row['quote_currency'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td>
                                    <small style="font-family: monospace; color: #475569;">
                                        <?php echo htmlspecialchars((string)$row['rate_factor'], ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars((string)($row['rate_scale'] ?? '1000000'), ENT_QUOTES, 'UTF-8'); ?>
                                    </small>
                                </td>
                                <td>
                                    <?php if ($isActivePairCurrent): ?>
                                        <span class="badge badge-success" style="font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                                            <span style="width: 6px; height: 6px; border-radius: 50%; background: #16a34a;"></span>
                                            CURRENT ACTIVE
                                        </span>
                                    <?php elseif ($status === 'inactive'): ?>
                                        <span class="badge badge-secondary">INACTIVE</span>
                                    <?php elseif ($status === 'retired'): ?>
                                        <span class="badge badge-secondary">RETIRED</span>
                                    <?php elseif ($isExpired): ?>
                                        <span class="badge badge-danger">EXPIRED</span>
                                    <?php elseif ($status === 'active' && !empty($row['effective_at']) && $row['effective_at'] > $now): ?>
                                        <span class="badge badge-warning">SCHEDULED</span>
                                    <?php elseif ($status === 'active'): ?>
                                        <span class="badge badge-success">ACTIVE</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary"><?php echo htmlspecialchars(strtoupper($status), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-size: 11px; line-height: 1.5;">
                                        <div><strong>From:</strong> <?php echo htmlspecialchars($row['effective_at'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div><strong>Until:</strong> <?php echo !empty($row['expires_at']) ? htmlspecialchars($row['expires_at'], ENT_QUOTES, 'UTF-8') : '<span style="color: var(--wp-text-muted);">Indefinite</span>'; ?></div>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-size: 11px;">
                                        <span class="badge badge-info" style="font-size: 11px;"><?php echo htmlspecialchars($row['source'] ?? 'operator', ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php if (!empty($row['operator_id'])): ?>
                                            <div style="color: var(--wp-text-muted); margin-top: 2px;">Op #<?php echo (int)$row['operator_id']; ?></div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <small style="color: var(--wp-text-muted); font-size: 12px;"><?php echo htmlspecialchars($row['notes'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></small>
                                </td>
                                <td style="text-align: right;">
                                    <?php if ($status === 'active' && !$isExpired): ?>
                                        <form method="POST" action="/admin/page/favorite-pay-rates" onsubmit="return confirm('Are you sure you want to deactivate rate #<?php echo (int)$row['id']; ?>? New payments requiring this rate will fail closed until a replacement is configured.');" style="display:inline; margin: 0;">
                                            <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="deactivate">
                                            <input type="hidden" name="rate_id" value="<?php echo (int)$row['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" style="font-size: 11px; padding: 2px 8px;">Deactivate</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color: var(--wp-text-muted); font-size: 11px;">Archived</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
var ACTIVE_SAVED_RATES = <?php echo json_encode($activePairsMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

function getSelectedPairKey() {
    var baseEl = document.getElementById('base_currency');
    var quoteEl = document.getElementById('quote_currency');
    var base = (baseEl ? baseEl.value : 'USDT').toUpperCase();
    var quote = (quoteEl ? quoteEl.value : 'BDT').toUpperCase();
    return base + '/' + quote;
}

function handleCurrencyChange() {
    var pair = getSelectedPairKey();
    var rateEl = document.getElementById('rate');
    var savedInfo = ACTIVE_SAVED_RATES[pair];

    if (savedInfo && rateEl) {
        rateEl.value = savedInfo.rate;
    }
    updateRateDisplay();
}

function updateRateDisplay() {
    var baseEl = document.getElementById('base_currency');
    var quoteEl = document.getElementById('quote_currency');
    var rateEl = document.getElementById('rate');
    var helperEl = document.getElementById('rate-display-helper');
    var badgeEl = document.getElementById('current-saved-rate-badge');

    if (!baseEl || !quoteEl || !rateEl || !helperEl) return;

    var base = baseEl.value || 'USDT';
    var quote = quoteEl.value || 'BDT';
    var val = rateEl.value.trim();
    var pair = (base + '/' + quote).toUpperCase();
    var savedInfo = ACTIVE_SAVED_RATES[pair];
    var savedRate = savedInfo ? savedInfo.rate : null;

    if (badgeEl) {
        if (savedRate !== null) {
            badgeEl.className = 'badge badge-success';
            badgeEl.innerText = 'Current Saved: ' + savedRate + ' ' + quote;
        } else {
            badgeEl.className = 'badge badge-secondary';
            badgeEl.innerText = 'Saved: None';
        }
    }

    if (val === '') {
        helperEl.style.color = 'var(--wp-text-muted)';
        helperEl.innerText = 'Enter rate for 1 ' + base + ' in ' + quote;
    } else if (savedRate !== null && val === savedRate) {
        helperEl.style.color = '#15803d';
        helperEl.style.fontWeight = '600';
        helperEl.innerText = '✓ Current Saved Rate: 1 ' + base + ' = ' + val + ' ' + quote + ' (Active in Production)';
    } else if (savedRate !== null) {
        helperEl.style.color = '#b45309';
        helperEl.style.fontWeight = '600';
        helperEl.innerText = '⚠️ New Proposed Rate: 1 ' + base + ' = ' + val + ' ' + quote + ' (Unsaved — click Lock to save)';
    } else {
        helperEl.style.color = 'var(--wp-blue)';
        helperEl.style.fontWeight = '600';
        helperEl.innerText = 'New Rate: 1 ' + base + ' = ' + val + ' ' + quote;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    updateRateDisplay();
});
</script>
