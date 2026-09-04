# Binance Pay Merchant Gateway Integration Guide

## Overview
Favorite Pay provides native support for automated cryptocurrency acquiring via the official **Binance Pay Merchant OpenAPI** (OpenAPI v3).

- **Gateway ID**: `binance_pay`
- **Official API Host**: `https://bpay.binanceapi.com`
- **Security Standard**: HTTPS, HMAC-SHA512 request signing, uppercase hexadecimal signature, 32-character cryptographically secure nonces, and millisecond timestamps.
- **Capabilities**:
  - `PaymentGatewayInterface`: Standard payment attempt initiation.
  - `RedirectPaymentGatewayInterface`: Hosted customer checkout redirection.
  - `WebhookGatewayInterface`: Cryptographic webhook verification.
  - `StatusQueryableGatewayInterface`: Order status querying (`POST /binancepay/openapi/order/query`).
  - `RefundableGatewayInterface`: Automated merchant refunds (`POST /binancepay/openapi/order/refund`).
  - `ConfigurableGatewayInterface`: Schema-based admin settings with secret masking.

---

## 1. Enabling Binance Pay

1. Navigate to **Admin Dashboard -> Settings -> Payments -> Binance Pay**.
2. Set **Enable Binance Pay** to `Yes`.
3. Provide your **Binance Certificate Serial Number (Certificate-SN)** and **API Secret Key**.
4. Configure optional Sandbox / Test mode if operating in a staging environment.
5. Save settings.

---

## 2. Required Merchant Credentials

To obtain merchant credentials:
1. Log in to your approved **Binance Merchant Account** at [https://merchant.binance.com](https://merchant.binance.com).
2. Go to **Developers -> API Credentials**.
3. Generate or view:
   - **Certificate-SN**: The Certificate Serial Number identifying your public certificate.
   - **API Secret Key**: 64-character secret key used for HMAC-SHA512 request signing.
   - *(Optional)* **Binance Public Key**: PEM-formatted public certificate used for RSA callback verification.

> [!WARNING]
> Never commit real merchant credentials into source code, logs, exception messages, or repository commits.

---

## 3. Webhook Endpoint Configuration

Configure your webhook URL in the Binance Merchant Portal under **Developers -> Webhook Configuration**:

```
https://your-domain.com/api/favorite-pay/webhook/binance_pay
```

### Signature Verification:
- When a notification arrives, Binance sends headers:
  - `BinancePay-Timestamp`: Unix timestamp in milliseconds.
  - `BinancePay-Nonce`: 32-character random string.
  - `BinancePay-Certificate-SN`: Public key certificate serial number.
  - `BinancePay-Signature`: Digital signature.
- Favorite Pay reconstructs the signature payload:
  ```text
  timestamp + "\n" + nonce + "\n" + raw_body + "\n"
  ```
- Verifies using constant-time comparison (`hash_equals`) with your configured `API Secret Key` (HMAC-SHA512) or the Binance public certificate (RSA-SHA256).
- If verification fails or amount/currency does not match the attempt, the webhook is rejected and payment is **never** settled.

---

## 4. Supported Currency Limitations

Binance Pay Merchant API supports major cryptocurrency assets and select fiat quotes:
- **Supported Payment Currencies**: `USDT`, `USDC`, `BTC`, `ETH`, `BNB`, `USD`, `EUR`.
- **Primary Currency Note**: In accordance with the Favorite CMS financial safety architecture, Binance Pay strictly rejects orders initiated in unsupported currencies (e.g. `BDT`).
- To accept Binance Pay, the store's transaction or Primary Currency must be one of the supported currencies. No automated FX conversion is performed without an active exchange rate engine.

---

## 5. Payment Lifecycle

```text
Customer Checkout
       ↓
Favorite Pay PaymentService::createIntent()
       ↓
BinancePayGateway::createAttempt()
       ↓
POST /binancepay/openapi/v3/order
       ↓
Returns checkoutUrl / qrCodeLink
       ↓
Customer completes payment on Binance
       ↓
Binance sends Webhook Callback
       ↓
POST /api/favorite-pay/webhook/binance_pay
       ↓
WebhookService verifies signature & exact amount
       ↓
PaymentService marks attempt SUCCEEDED
       ↓
Fires 'favorite.pay.payment.succeeded'
       ↓
WalletService settles into customer ledger
```

---

## 6. Order Query & Status Reconciliation

If an ambiguous network failure occurs during checkout:
- System queries Binance Pay status using `merchantTradeNo` via `POST /binancepay/openapi/order/query`.
- Amount and currency are verified strictly against the attempt.
- `PAID` status transitions payment to `SUCCEEDED` through the standard lifecycle.
- Idempotency guarantees that multiple queries or webhook deliveries never create duplicate wallet credits.

---

## 7. Automated Refunds

- If a customer requires a refund, operators can trigger a refund via `RefundService::createGatewayRefund()`.
- Binance Pay gateway calls `POST /binancepay/openapi/order/refund` with a unique alphanumeric `refundRequestId`.
- If the provider approves, the refund is recorded in `favorite_pay_refunds` and the intent status is set to `REFUNDED` or `PARTIALLY_REFUNDED`.

---

## 8. Troubleshooting Common Errors

| Error Code | Meaning | Resolution |
| :--- | :--- | :--- |
| `400001` | Invalid parameter | Verify amount format and currency code (use 2-decimal strings). |
| `400002` | Invalid merchant account | Check Merchant Portal account status and KYC activation. |
| `400100` | Invalid signature / unauthorized | Verify `Certificate-SN` and `API Secret Key` match exactly. |
| `400102` | Timestamp expired | Ensure server clock is synchronized using NTP. |
| `400010` | Insufficient balance | Merchant balance insufficient for refund. Top up Binance merchant balance. |
