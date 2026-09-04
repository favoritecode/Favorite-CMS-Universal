# Binance Pay Merchant Gateway Integration Guide

## 1. Prerequisites
Before configuring Binance Pay in Favorite CMS:
- An active, approved **Binance Merchant Account** (registered and verified at [https://merchant.binance.com](https://merchant.binance.com)).
- Access to the **Binance Merchant Portal** with permissions to generate API credentials and configure Webhooks.
- Favorite CMS running with HTTPS enabled on your production domain.
- The Favorite Pay plugin installed and activated.

---

## 2. Required Merchant Credentials
In your Binance Merchant Portal (**Developers -> API Credentials**), generate and obtain:
1. **Certificate Serial Number (Certificate-SN)**:
   - A public string identifier representing your merchant certificate serial number.
   - Example: `cert_sn_9876543210abcdef`
2. **API Secret Key**:
   - A 64-character secret key used exclusively for request signing (OpenAPI calls) and incoming webhook signature verification.
   - Used in the symmetric HMAC-SHA512 algorithm.

> [!WARNING]
> **CRITICAL SECURITY RULE**: Never commit real merchant credentials (`API Secret Key` or `Certificate-SN`) into Git repositories, test files, pull requests, issue trackers, or documentation.

---

## 3. How to Configure the Gateway
1. Log in to the Favorite CMS Admin Panel as an authorized administrator with `manage_settings` permission.
2. In the sidebar, navigate to **Favorite Pay -> Gateways & Settings** (`/admin/page/favorite-pay-gateways`).
3. Under **Merchant API Credentials**:
   - Paste your **Certificate Serial Number (Certificate-SN)**.
   - Enter your **Binance API Secret Key** into the password field.
   - Review the API Endpoint Environment (fixed to official `https://bpay.binanceapi.com` for SSRF protection).
   - Check **Sandbox / Test Mode** only if operating against a registered Binance test environment.
4. Click **Save Changes**.

---

## 4. Enabling / Disabling & Operational Readiness
Favorite Pay enforces a strict 3-state operational model:
- **DISABLED**:
  - The gateway is switched off (`enabled = false`).
  - Binance Pay is hidden from customers at checkout and cannot initiate payments.
- **NOT READY (Incomplete)**:
  - The gateway is enabled, but either `Certificate-SN` or `API Secret Key` is missing, or the CMS Primary Currency is incompatible.
  - The gateway fails safely before executing any network calls. No orders or attempts are created.
- **READY**:
  - The gateway is enabled, both credentials are validly configured, and the CMS Primary Currency matches one of the supported currencies.
  - The gateway is fully operational.

> [!NOTE]
> Saving credentials does not automatically enable the gateway. An administrator must explicitly toggle **Enable Binance Pay Gateway**.

---

## 5. Webhook URL & Merchant Portal Setup
The CMS exposes a dedicated webhook endpoint for Binance Pay callbacks:
```text
https://YOUR-DOMAIN/api/favorite-pay/webhook/binance_pay
```
The exact absolute URL is dynamically displayed on your **Favorite Pay -> Gateways & Settings** admin screen.

### Merchant Portal Configuration:
1. Go to **Binance Merchant Portal -> Developers -> Webhook Configuration**.
2. Set the Webhook URL to your CMS endpoint: `https://YOUR-DOMAIN/api/favorite-pay/webhook/binance_pay`.
3. Select notification events: Payment (`PAY`), Refund (`REFUND`).
4. Save the webhook configuration.

---

## 6. Webhook Security Standard
- Webhook notifications are cryptographically verified using **HMAC-SHA512**.
- Binance sends:
  - `BinancePay-Timestamp`: Epoch millisecond timestamp.
  - `BinancePay-Nonce`: 32-character random string.
  - `BinancePay-Certificate-SN`: Certificate identifier.
  - `BinancePay-Signature`: Uppercase hexadecimal signature.
- Favorite Pay verifies:
  $$\text{Signature} = \text{strtoupper}(\text{hash\_hmac}('sha512', \text{timestamp} + \text{"\n"} + \text{nonce} + \text{"\n"} + \text{rawBody} + \text{"\n"}, \text{apiSecret}))$$
- Constant-time verification (`hash_equals`) ensures timing-attack resistance.
- The CMS does **not** receive Binance credentials in webhook requests.
- Never disable signature verification. RSA callbacks are not used by the Binance Pay Merchant OpenAPI and RSA signatures are rejected.

---

## 7. Supported Payment Currencies
Binance Pay Merchant OpenAPI supports major cryptocurrency assets and fiat quotes:
- **Supported Assets**: `USDT`, `USDC`, `BTC`, `ETH`, `BNB`, `USD`, `EUR`.

---

## 8. Multi-Currency Order Conversion & Primary Currency Compatibility
Favorite Pay features a complete three-tier financial separation:
1. **Original Order Currency & Amount**: Commercial customer-facing amount (e.g. `120.00 BDT`, `60.00 EUR`, `100.00 USD`, `50.00 GBP`).
2. **Accounting / Primary Currency & Amount**: Site-level accounting and wallet denomination (e.g. `BDT`).
3. **Binance Acquiring / Payment Currency & Amount**: The cryptocurrency token requested via Binance Pay OpenAPI (e.g. `1.25 USDT`, `70.26 USDT`).

### Dynamic Locked Conversion at Checkout:
- When a customer selects Binance Pay at checkout, Favorite Pay locks an immutable `ConversionSnapshot` converting the order amount into the configured Binance payment token (default: `USDT`).
- **Examples**:
  - `120 BDT` $\rightarrow$ Locked rate `0.010417` $\rightarrow$ `1.25 USDT` charged on Binance $\rightarrow$ Upon confirmation, recorded as **120 BDT PAID**.
  - `60 EUR` $\rightarrow$ Locked rate `1.171` $\rightarrow$ `70.26 USDT` charged on Binance $\rightarrow$ Upon confirmation, recorded as **60 EUR PAID**.
- **Financial Safety Guarantee**:
  - Strict minor-unit integer arithmetic and integer-scaled rate factors (`rateFactor` / `rateScale`).
  - Zero floating-point money math.
  - No arbitrary or 1:1 conversions. If an exchange rate is not configured, the transaction fails safely.
- **Wallet Settlement**:
  - Successfully verified payments are settled into the customer's wallet in the site's primary accounting currency (`120 BDT`), maintaining accounting consistency.

---

## 9. Operator Exchange Rate Management

To ensure financial safety and regulatory compliance, Favorite Pay allows authorized operators to configure and audit exchange rates directly in the admin panel:

### 1. Permission Requirements
- Only administrators with `super-admin`, `admin`, or the specific `favorite_pay.rates.manage` permission can access exchange rate management.
- Users with view-only or payment-verification permissions are denied access with HTTP 403.

### 2. Admin Navigation
- Navigate to **Favorite Pay -> Exchange Rates** (`/admin/page/favorite-pay-rates`).

### 3. Clear Rate Direction Convention
- Rate definition follows standard financial quotation:
  $$\text{Base Currency} = \text{USDT}, \quad \text{Quote Currency} = \text{BDT}, \quad \text{Rate} = 122.500000$$
  Meaning: **1 USDT = 122.50 BDT**.
- When a customer checks out an order priced in BDT, the system converts BDT to USDT using exact integer-scaled factor arithmetic (e.g., $120.00\text{ BDT} \rightarrow 1.00\text{ USDT}$ at $120.00\text{ BDT/USDT}$).

### 4. Overlap Prevention & Immutability
- **Historical Records Preserved**: Historical exchange rates are NEVER deleted (`DELETE` queries are forbidden).
- **Safe Replacement**: Setting a new active rate automatically retires prior active rates for that currency pair by setting their expiration date to the new rate's effective date, ensuring zero overlapping windows.
- **Deactivation**: Operators can deactivate an active rate at any time, immediately marking it as inactive and retiring future use.

### 5. Fail-Closed Protection
- If no active, authoritative, non-expired rate is configured for a currency pair, checkout fails closed immediately with `UnauthoritativeRateException`. No `PaymentIntent`, `PaymentAttempt`, or Binance API call is executed.

---

## 10. Secret Handling & Privacy Rules
- **Zero Display**: The `API Secret Key` is never displayed in HTML inputs, view sources, JavaScript, logs, exceptions, or debug responses.
- **Preservation on Edit**: When editing gateway settings, leaving the `API Secret Key` field blank preserves the previously saved secret. Submitting a new value replaces it.
- **Storage**: Credentials are stored in the core `settings` table under group `favorite_pay_binance`. They are never stored in transaction logs, order records, or wallet ledgers.

---

## 11. Troubleshooting Configuration Problems
| Problem | Cause | Resolution |
| :--- | :--- | :--- |
| Status shows `NOT READY` | Missing credentials or missing exchange rate | Check that Certificate-SN and Secret are configured, and exchange rates exist between Primary Currency and Binance Payment Currency. |
| Error: `Disallowed Binance Pay API base URL` | Administrator entered an unapproved host | Only official Binance endpoints (`https://bpay.binanceapi.com`) are allowed (SSRF protection). |
| Webhook returns HTTP 401 | Invalid HMAC signature | Verify that the `API Secret Key` configured in CMS matches the secret key in the Binance Merchant Portal. |
| Webhook returns HTTP 422 | Currency or amount mismatch | Attempt was tampered with or received with a mismatched currency. Payment is rejected. |
| Error: `Binance Pay gateway is disabled` | Gateway toggle is off | Enable the gateway in **Favorite Pay -> Gateways & Settings**. |

---

## 12. Summary: Binance Merchant Dashboard Setup Checklist
1. Create and verify your Binance Merchant Account at [https://merchant.binance.com](https://merchant.binance.com).
2. Generate API Credentials (`Certificate-SN` and `API Secret Key`).
3. Set Webhook URL to: `https://YOUR-DOMAIN/api/favorite-pay/webhook/binance_pay`.
4. Enter credentials in Favorite CMS Admin (**Favorite Pay -> Gateways & Settings**).
5. Verify status badge displays `READY`.
