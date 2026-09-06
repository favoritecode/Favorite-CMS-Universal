<?php
/**
 * Customer Manual Recharge Submission View
 *
 * @var object $intent
 * @var string $intentId
 * @var string $gatewayId
 * @var string $amount
 * @var string $currency
 * @var array $instructions
 * @var string $csrfToken
 * @var string|null $flashError
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Manual Recharge Reference — Favorite CMS</title>
    <style>
        .fav-manual-container {
            max-width: 680px;
            margin: 40px auto;
            padding: 0 16px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            color: #1e293b;
        }
        .fav-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 28px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .fav-title {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            margin: 0 0 8px;
        }
        .fav-subtitle {
            font-size: 14px;
            color: #64748b;
            margin: 0 0 24px;
        }
        .fav-instructions-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-left: 4px solid #2563eb;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
            font-size: 14px;
            line-height: 1.6;
        }
        .fav-instr-item {
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
        }
        .fav-instr-label {
            font-weight: 600;
            color: #475569;
        }
        .fav-instr-val {
            font-weight: 700;
            color: #0f172a;
        }
        .fav-alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .fav-form-group {
            margin-bottom: 18px;
        }
        .fav-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 6px;
            color: #334155;
        }
        .fav-input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 15px;
            color: #0f172a;
            box-sizing: border-box;
        }
        .fav-input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }
        .fav-btn-submit {
            width: 100%;
            background: #2563eb;
            color: #ffffff;
            font-size: 15px;
            font-weight: 700;
            padding: 12px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.15s;
        }
        .fav-btn-submit:hover {
            background: #1d4ed8;
        }
        .fav-back-link {
            display: inline-block;
            margin-top: 16px;
            font-size: 14px;
            font-weight: 600;
            color: #64748b;
            text-decoration: none;
        }
        .fav-back-link:hover {
            color: #0f172a;
        }
    </style>
</head>
<body>

<?php
$activeTab = 'wallet';
include __DIR__ . '/../account/nav.php';
?>

<div class="fav-manual-container">
    <div class="fav-card">
        <h1 class="fav-title">Submit Manual Payment Details</h1>
        <p class="fav-subtitle">
            Complete your recharge by sending the exact amount to the account below, then submit your transaction reference.
        </p>

        <?php if ($flashError): ?>
            <div class="fav-alert-error" role="alert">
                <?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="fav-instructions-box">
            <div class="fav-instr-item">
                <span class="fav-instr-label">Amount Payable:</span>
                <span class="fav-instr-val" style="color: #059669; font-size: 16px;">
                    ৳<?= htmlspecialchars($amount, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
            <?php if (!empty($instructions['account_number'])): ?>
                <div class="fav-instr-item">
                    <span class="fav-instr-label">Account Number:</span>
                    <span class="fav-instr-val" style="font-family: monospace; font-size: 15px;">
                        <?= htmlspecialchars($instructions['account_number'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
            <?php endif; ?>
            <?php if (!empty($instructions['account_type'])): ?>
                <div class="fav-instr-item">
                    <span class="fav-instr-label">Account Type:</span>
                    <span class="fav-instr-val"><?= htmlspecialchars($instructions['account_type'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>
            <?php if (!empty($instructions['bank_name'])): ?>
                <div class="fav-instr-item">
                    <span class="fav-instr-label">Bank Name:</span>
                    <span class="fav-instr-val"><?= htmlspecialchars($instructions['bank_name'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>
            <?php if (!empty($instructions['instructions'])): ?>
                <div style="margin-top: 10px; padding-top: 8px; border-top: 1px solid #e2e8f0; font-size: 13px; color: #475569;">
                    <?= htmlspecialchars($instructions['instructions'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>
        </div>

        <form action="/account/wallet/recharge/manual" method="POST">
            <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="intent_id" value="<?= htmlspecialchars($intentId, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="gateway_id" value="<?= htmlspecialchars($gatewayId, ENT_QUOTES, 'UTF-8') ?>">

            <div class="fav-form-group">
                <label for="trx_id" class="fav-label">Transaction ID (TrxID) / Reference *</label>
                <input 
                    type="text" 
                    id="trx_id" 
                    name="trx_id" 
                    class="fav-input" 
                    placeholder="e.g. 9J84KL2M" 
                    required 
                    autocomplete="off"
                >
            </div>

            <div class="fav-form-group">
                <label for="sender_account" class="fav-label">Sender Account / Phone Number (Optional)</label>
                <input 
                    type="text" 
                    id="sender_account" 
                    name="sender_account" 
                    class="fav-input" 
                    placeholder="e.g. 017XXXXXXXX"
                >
            </div>

            <div class="fav-form-group">
                <label for="notes" class="fav-label">Notes / Remarks (Optional)</label>
                <input 
                    type="text" 
                    id="notes" 
                    name="notes" 
                    class="fav-input" 
                    placeholder="e.g. Sent via bKash App"
                >
            </div>

            <button type="submit" class="fav-btn-submit">
                Submit Verification Request
            </button>
        </form>

        <div style="text-align: center;">
            <a href="/account/wallet" class="fav-back-link">
                &larr; Cancel and return to Wallet
            </a>
        </div>
    </div>
</div>

</body>
</html>

