<?php
/**
 * Favorite Pay Exchange Rates Management Admin View
 *
 * Variables provided by PaymentRateController:
 * - $rates               : array
 * - $supportedCurrencies : array
 * - $baseCurrency        : string
 * - $csrfToken           : string
 * - $flashSuccess        : ?string
 * - $flashError          : ?string
 */
?>

<div class="wrap favorite-pay-rates">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2>Exchange Rate Management</h2>
            <p class="text-muted">Configure and audit authoritative exchange rates for multi-currency payment conversions (e.g. Binance Pay acquiring in USDT/USDC).</p>
        </div>
        <div>
            <a href="/admin/page/favorite-pay-gateways" class="btn btn-outline-secondary">← Gateway Settings</a>
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

    <!-- New Rate Configuration Card -->
    <div class="card mb-4" style="border: 1px solid #c3c4c7;">
        <div class="card-header bg-light">
            <h5 class="mb-0">Configure Authoritative Exchange Rate</h5>
        </div>
        <div class="card-body">
            <div class="alert alert-info py-2 mb-3">
                <small><strong>Deterministic Accounting Rule:</strong> All conversions use exact scaled integer arithmetic with zero floating-point rounding. Setting a new active rate safely retires prior active rates for the pair while preserving complete audit history.</small>
            </div>

            <form method="POST" action="/admin/page/favorite-pay-rates">
                <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="save">

                <div class="row g-3">
                    <div class="col-md-3">
                        <label for="base_currency" class="form-label font-weight-bold"><strong>Base Currency</strong></label>
                        <select name="base_currency" id="base_currency" class="form-select form-control" required onchange="updateRateDisplay()">
                            <?php foreach ($supportedCurrencies as $code): ?>
                                <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $code === 'USDT' ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text small text-muted">Asset being quoted (e.g. USDT)</div>
                    </div>

                    <div class="col-md-3">
                        <label for="quote_currency" class="form-label font-weight-bold"><strong>Quote Currency</strong></label>
                        <select name="quote_currency" id="quote_currency" class="form-select form-control" required onchange="updateRateDisplay()">
                            <?php foreach ($supportedCurrencies as $code): ?>
                                <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $code === ($baseCurrency ?? 'BDT') ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text small text-muted">Price currency (e.g. BDT)</div>
                    </div>

                    <div class="col-md-3">
                        <label for="rate" class="form-label font-weight-bold"><strong>Exchange Rate</strong></label>
                        <input type="text" name="rate" id="rate" class="form-control" placeholder="122.50" required pattern="^\d+(\.\d{1,6})?$" oninput="updateRateDisplay()">
                        <div class="form-text small text-muted" id="rate-display-helper">1 USDT = [?] BDT</div>
                    </div>

                    <div class="col-md-3">
                        <label for="effective_at" class="form-label font-weight-bold"><strong>Effective From</strong></label>
                        <input type="datetime-local" name="effective_at" id="effective_at" class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>">
                        <div class="form-text small text-muted">When rate becomes valid</div>
                    </div>
                </div>

                <div class="row g-3 mt-2">
                    <div class="col-md-4">
                        <label for="expires_at" class="form-label font-weight-bold"><strong>Expires At (Optional)</strong></label>
                        <input type="datetime-local" name="expires_at" id="expires_at" class="form-control">
                        <div class="form-text small text-muted">Leave blank for indefinite until retired</div>
                    </div>

                    <div class="col-md-5">
                        <label for="notes" class="form-label font-weight-bold"><strong>Audit Notes / Reason</strong></label>
                        <input type="text" name="notes" id="notes" class="form-control" placeholder="e.g. Daily treasury rate update for Binance Pay">
                        <div class="form-text small text-muted">Recorded in immutable rate log</div>
                    </div>

                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100 py-2">
                            <strong>Lock Authoritative Rate</strong>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Rates History & Audit Table -->
    <div class="card" style="border: 1px solid #c3c4c7;">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Authoritative Rates Audit Log</h5>
            <span class="badge bg-secondary"><?php echo count($rates); ?> records</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 60px;">ID</th>
                            <th>Currency Pair</th>
                            <th>Exchange Rate Direction</th>
                            <th>Scaled Factor</th>
                            <th>Status</th>
                            <th>Effective Period</th>
                            <th>Source / Operator</th>
                            <th>Notes</th>
                            <th style="width: 120px;" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rates)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-4 text-muted">
                                    <em>No exchange rates configured yet. Please lock an authoritative rate above to enable conversions.</em>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php 
                            $now = date('Y-m-d H:i:s');
                            foreach ($rates as $row): 
                                $isExpired = !empty($row['expires_at']) && $row['expires_at'] < $now;
                                $status = (string)($row['status'] ?? 'active');
                                $rateVal = number_format((float)$row['rate'], 6, '.', '');
                                $rateValTrimmed = rtrim(rtrim($rateVal, '0'), '.');
                            ?>
                                <tr>
                                    <td><code>#<?php echo (int)$row['id']; ?></code></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($row['base_currency'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <span class="text-muted">/</span>
                                        <strong><?php echo htmlspecialchars($row['quote_currency'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border" style="font-size: 0.95rem;">
                                            1 <?php echo htmlspecialchars($row['base_currency'], ENT_QUOTES, 'UTF-8'); ?> = 
                                            <strong><?php echo htmlspecialchars($rateValTrimmed, ENT_QUOTES, 'UTF-8'); ?></strong> 
                                            <?php echo htmlspecialchars($row['quote_currency'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small class="text-monospace">
                                            <?php echo htmlspecialchars((string)$row['rate_factor'], ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars((string)($row['rate_scale'] ?? '1000000'), ENT_QUOTES, 'UTF-8'); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php if ($status === 'inactive'): ?>
                                            <span class="badge bg-dark">INACTIVE</span>
                                        <?php elseif ($status === 'retired'): ?>
                                            <span class="badge bg-secondary">RETIRED</span>
                                        <?php elseif ($isExpired): ?>
                                            <span class="badge bg-danger">EXPIRED</span>
                                        <?php elseif ($status === 'active'): ?>
                                            <span class="badge bg-success">ACTIVE</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark"><?php echo htmlspecialchars(strtoupper($status), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small>
                                            <strong>From:</strong> <?php echo htmlspecialchars($row['effective_at'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?><br>
                                            <strong>Until:</strong> <?php echo !empty($row['expires_at']) ? htmlspecialchars($row['expires_at'], ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Indefinite</em>'; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <small>
                                            <span class="badge bg-info text-dark"><?php echo htmlspecialchars($row['source'] ?? 'operator', ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php if (!empty($row['operator_id'])): ?>
                                                <span class="text-muted">Op #<?php echo (int)$row['operator_id']; ?></span>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?php echo htmlspecialchars($row['notes'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></small>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($status === 'active' && !$isExpired): ?>
                                            <form method="POST" action="/admin/page/favorite-pay-rates" onsubmit="return confirm('Are you sure you want to deactivate rate #<?php echo (int)$row['id']; ?>? New payments requiring this rate will fail closed until a replacement is configured.');" style="display:inline;">
                                                <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="deactivate">
                                                <input type="hidden" name="rate_id" value="<?php echo (int)$row['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-muted small">Archived</span>
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
</div>

<script>
function updateRateDisplay() {
    var baseEl = document.getElementById('base_currency');
    var quoteEl = document.getElementById('quote_currency');
    var rateEl = document.getElementById('rate');
    var helperEl = document.getElementById('rate-display-helper');

    if (!baseEl || !quoteEl || !rateEl || !helperEl) return;

    var base = baseEl.value || 'USDT';
    var quote = quoteEl.value || 'BDT';
    var rate = rateEl.value.trim() || '?';

    helperEl.innerText = '1 ' + base + ' = ' + rate + ' ' + quote;
}
document.addEventListener('DOMContentLoaded', updateRateDisplay);
</script>
