# Favorite Pay — Phase 1 Implementation Specification

**Document Version**: 1.4.0  
**Target Repository**: `Favorite-CMS-Universal`  
**Plugin Identifier**: `favorite-pay`  
**Namespace**: `FavoriteCMS\Pay`  
**Status**: Implemented (Phase 1 Foundation, Phase 2 Database & Migrations, Phase 3A Gateway Framework & Manual Payment, Phase 4A Admin Operator Verification Panel, Phase 5A Wallet Settlement, Phase 5B Global Primary Currency Foundation)  

---

## 1. Executive Architecture Overview

**Favorite Pay** is the authoritative financial, payment orchestration, and wallet management service for the Favorite CMS ecosystem.

### Ecosystem Boundary Principles
1. **Consumer Isolation**: Plugins such as **Favorite Digital** and **Favorite Shop** do not store gateway credentials, execute direct transactions, or inspect payment provider secrets. They interface exclusively through Favorite Pay's service contracts (`PaymentServiceInterface`, `WalletServiceInterface`).
2. **Cash on Delivery (COD) Isolation**: COD logistics belong entirely to **Favorite Shop**. Favorite Pay provides financial status logging and offline ledger records without routing through online payment gateway drivers.
3. **Presentation Boundary**: Themes (such as **Favorite Web**) consume payment selection interfaces and badges through public template helpers and API endpoints, remaining completely free of business logic.

---

## 2. Locked Product Decisions & Architectural Guarantees

### Decision 1: BDT-Denominated Wallet Ledger
- All customer balances are stored strictly in **BDT minor units (Poisha)** (1 BDT = 100 Poisha).
- Foreign currency deposits are dynamically converted to BDT and locked at deposit time using the authoritative conversion rate.
- Balances are maintained via immutable append-only ledger entries (`credit`, `debit`, `hold`, `release`) preventing balance drift or race conditions.

### Decision 2: Hybrid Operator + Automated Exchange Rate Engine
- Administrative operator-configured rates are **authoritative**.
- Automated rate synchronization is optional and **must never silently overwrite** an operator-locked rate.
- Exchange rates are converted using integer-scaled precision math (6-decimal factor) to eliminate floating-point rounding bugs.

### Decision 3: 100% Operator Manual Bangladesh Payment Approval
- Manual Bangladesh payment methods (bKash, Nagad, Rocket, Bank Transfer) follow a strict 2-step verification:
  1. Customer submits Transaction Reference (TrxID) &rarr; Status becomes `awaiting_verification`.
  2. Administrative operator cross-references the TrxID with actual bank/MFS statements and explicitly triggers `approveManualPayment` or `rejectManualPayment`.
  3. Digital goods or orders are unlocked **only** after the operator approves the payment.

---

## 3. Strict Financial Money Safety (Integer Minor Units)

### The Float Ban
Floating-point mathematics (e.g. `0.1 + 0.2 === 0.30000000000000004`) is strictly forbidden across Favorite Pay.
- All monetary amounts are encapsulated in the immutable `FavoriteCMS\Pay\Domain\Money` value object.
- Amounts are stored as integers representing minor units:
  - BDT 100.50 &rarr; `10050` Poisha
  - USD 10.99  &rarr; `1099` Cents
  - JPY 500    &rarr; `500` Yen
- Decimal string inputs (e.g. `"100.50"`) are parsed using string splitting and padding, never through float casting.

---

## 4. Domain Contracts & Service Interfaces

All services are registered into the Favorite CMS Dependency Injection Container (`FavoriteCMS\Core\Application`):

| Contract | Implementation | Responsibility |
|---|---|---|
| `PaymentServiceInterface` | `PaymentService` | Intent lifecycle, manual TrxID submission, operator verification |
| `CurrencyServiceInterface` | `CurrencyService` | BDT conversion, operator rate locking, auto-sync safeguards |
| `WalletServiceInterface` | `WalletService` | BDT Poisha balance, deposits, debits, holds, ledger history |
| `RefundServiceInterface` | `RefundService` | Full and partial refund management |
| `PaymentGatewayInterface` | Gateway Drivers (Phase 2) | Abstract driver contract for payment providers |

---

## 5. Event Publishing Matrix

Favorite Pay dispatches standard Favorite CMS actions via `FavoriteCMS\Core\Hook`:

- `favorite.pay.intent.created`: Fired when a checkout intent is initialized.
- `favorite.pay.manual.submitted`: Fired when a customer submits a manual TrxID.
- `favorite.pay.manual.approved`: Fired when an operator approves a manual payment.
- `favorite.pay.payment.succeeded`: Fired when a payment intent is fully settled. Consumer plugins (`Favorite Digital`) listen here to activate downloads/memberships.
- `favorite.pay.wallet.credited`: Fired when wallet balance increases.
- `favorite.pay.wallet.debited`: Fired when wallet balance decreases.
- `favorite.pay.rate.operator_locked`: Fired when an admin manually updates an exchange rate.

---

## 6. Phase 2 Database Schema & Migrations

Favorite Pay owns 7 dedicated, isolated relational tables created via Core `Migrator`:
Migration file: `plugins/favorite-pay/database/migrations/001_create_favorite_pay_tables.php`

### Relational Schema Overview

1. **`favorite_pay_gateways`**:
   - Stores configured gateway providers (`id`, `title`, `type`, `is_enabled`, `supported_currencies`, `config`, `sort_order`).
   - Sensitive credentials policy: No raw PAN, CVV, or PINs stored.

2. **`favorite_pay_rates`**:
   - Currency rate tables and historical operator locks (`id`, `base_currency`, `quote_currency`, `rate`, `rate_factor`, `rate_scale`, `is_authoritative`, `source`, `operator_id`, `effective_at`).
   - Precision: `DECIMAL(18, 6)` and scaled integer `rate_factor` with scale `1000000` (zero float storage).

3. **`favorite_pay_transactions`**:
   - Authoritative intent and settled transactions (`id`, `transaction_id`, `source_plugin`, `source_reference`, `user_id`, `base_amount`, `base_currency`, `charge_amount`, `charge_currency`, `exchange_rate`, `status`, `idempotency_key`, `external_reference`).
   - Monetary precision: `base_amount` (BDT Poisha) and `charge_amount` (payment currency subunits) stored as `BIGINT`.

4. **`favorite_pay_attempts`**:
   - Individual gateway execution attempts (`id`, `attempt_id`, `transaction_id`, `gateway_id`, `amount`, `currency`, `status`, `provider_reference`, `verified_by`, `verified_at`).
   - Duplicate prevention: Unique index `(gateway_id, provider_reference)` prevents reuse of submitted customer TrxIDs.

5. **`favorite_pay_refunds`**:
   - Full and partial refunds (`id`, `refund_id`, `transaction_id`, `amount`, `currency`, `status`, `provider_refund_reference`, `reason`, `operator_id`).
   - Monetary precision: `amount` stored as `BIGINT` minor units.

6. **`favorite_pay_wallets`**:
   - BDT customer balance accounts (`id`, `user_id` [UNIQUE], `balance` [BIGINT Poisha], `currency` ['BDT'], `status`).

7. **`favorite_pay_wallet_entries`**:
   - Append-only single-user financial ledger entries (`id`, `entry_id`, `wallet_id`, `user_id`, `type`, `amount`, `balance_after`, `reference_type`, `reference_id`, `idempotency_key` [UNIQUE], `description`).

---

## 7. Phase 3A: Gateway Driver Framework & Manual Bangladesh Payment
 
### 7.1 Runtime Gateway Framework
- **`PaymentGatewayInterface`**: Enforces unified driver contracts across all payment providers:
  - `getId(): string`
  - `getTitle(): string`
  - `getType(): PaymentMethodType`
  - `isEnabled(): bool`
  - `getSupportedCurrencies(): array`
  - `processPayment(PaymentAttempt $attempt, array $payload = []): array`
  - `verifyPayment(PaymentAttempt $attempt, array $payload = []): array`
  - `refundPayment(PaymentAttempt $attempt, Money $amount, string $reason = ''): array`
  - `getInstructions(array $context = []): array` (Standard instruction payload for client UI)
- **`GatewayRegistry`**: Centralized gateway driver registration and discovery supporting primary gateway IDs (`manual_bd`, `manual_bkash`, `manual_nagad`, `manual_bank`) as well as backward-compatible aliases (`bkash_manual`, `nagad_manual`, `bank_manual`).

### 7.2 Manual Bangladesh Gateway (`ManualBangladeshGateway`)
- Concrete payment driver encapsulating offline/manual Bangladesh payment channels (`manual_bkash`, `manual_nagad`, `manual_bank`).
- **Zero External Network Dependencies**: Operates strictly offline without external API or HTTP requests.
- **Account & Instruction Configuration**: Supports dynamic and configured merchant/agent account numbers, account names, account types (e.g., Merchant, Personal), and customer instructions.

### 7.3 Idempotency & Duplicate TrxID Protection
- **Idempotency Safeguard**: If a customer resubmits the same checkout request with an identical `idempotency_key`, `PaymentService` deterministically returns the existing attempt without creating duplicates.
- **Composite TrxID Uniqueness**: In-memory registry and database unique constraint `(gateway_id, provider_reference)` guarantees that a submitted Transaction Reference / TrxID cannot be reused across payment attempts for the same gateway.

### 7.4 Client Status Protection & Security Model
- **Strict Server Control**: Clients can never submit or dictate transaction statuses. Attempt creation and TrxID submission strictly sets the attempt to `awaiting_verification`.
- **Operator Authority**: Status transitions to `succeeded` or `failed` require explicit administrative action (`approveManualPayment` / `rejectManualPayment`) with operator ID and audit logging.

---

## 8. Phase 4A: Admin Operator Verification Panel
 
### 8.1 Overview & Architecture
- Integrated natively into the standard Favorite CMS admin interface using Core `AdminMenu` routing (`/admin/page/favorite-pay` and submenu `favorite-pay-payments`).
- Server-rendered PHP using the standard Favorite CMS admin layout, typography, buttons, tables, notices, and styling conventions with zero external frontend dependencies.
 
### 8.2 Permission Separation (RBAC)
- **`favorite_pay.payments.view`**: Grants capability to view the verification queue, filter attempts, and inspect customer/payment detail screens.
- **`favorite_pay.payments.verify`**: Grants capability to approve or reject manual payment attempts.
- Super-administrators (`super-admin`) inherently possess full authorization across all actions.
- Unauthorized or unauthenticated users are strictly blocked at the `Kernel` dispatch and controller layers with HTTP 302/403 responses.
 
### 8.3 Verification Queue & Server-Side Filtering
- Default view highlights `awaiting_verification` payment attempts.
- Status filter tabs (`subsubsub`): All, Awaiting Verification, Succeeded, Failed/Rejected.
- Gateway filter dropdown (`manual_bkash`, `manual_nagad`, `manual_bank`, generic).
- Search bar querying indexed Transaction IDs (`transaction_id`) and Customer TrxIDs (`provider_reference`).
- Paginated results (25 per page default) with previous/next controls.
 
### 8.4 Review & Verification Detail Screen
- Dedicated operator review screen providing four comprehensive audit cards:
  1. **Transaction Overview**: Transaction ID, attempt ID, base/charge amounts, ISO currency, source plugin/reference, and submission timestamp.
  2. **Customer Details**: Core user ID, username, and email address.
  3. **Manual Payment Proof**: Customer-submitted TrxID, sender phone/account number, and customer notes.
  4. **Verification Audit Trail**: Current status, verifier name/ID, verification timestamp, operator notes, or rejection reason.
 
### 8.5 Authoritative Approval & Rejection Handlers
- **Approve Flow**:
  - Requires POST with valid timing-safe CSRF token and `favorite_pay.payments.verify` capability.
  - Invokes `PaymentService::approveManualPayment($attemptId, $operatorId, $notes)`.
  - Transitions attempt to `SUCCEEDED` and intent to `SUCCEEDED`. Fires event `favorite.pay.manual.approved` and `favorite.pay.payment.succeeded`.
- **Reject Flow**:
  - Requires POST with valid CSRF token, `favorite_pay.payments.verify` capability, and a mandatory non-empty rejection reason.
  - Invokes `PaymentService::rejectManualPayment($attemptId, $operatorId, $reason)`.
  - Transitions attempt to `FAILED` and intent to `FAILED`. Fires event `favorite.pay.manual.rejected`.
 
### 8.6 Double-Action Protection & State Machine Integrity
- In-flight or parallel approvals/rejections (e.g. Admin B approves an attempt while Admin A has the review page open) are caught safely at the `PaymentService` state machine layer (`RuntimeException`).
- Controller catches state conflicts and informs the operator via native flash error notifications without duplicate events or corrupted balances.
 
### 8.7 Wallet Safety & Zero Leakage
- `PaymentAdminController` contains zero wallet ledger manipulation, zero balance calculations, and zero direct SQL mutations.
- Digital wallet balances remain completely isolated and protected.
 
### 8.8 Intentionally NOT Implemented
- No external automated payment APIs (bKash API, Nagad API, Stripe Card, Binance/USDT).
- No webhooks or public callback endpoints.
- No customer checkout frontend UI or theme template tags.
 
---
 
## 9. Phase 5A: Backend Wallet Settlement Architecture

### 9.1 Core Settlement Flow & Guarantees
- Implemented via `WalletServiceInterface::settleSuccessfulPayment(string $transactionId): WalletLedgerEntry`.
- Guarantees **exact one-to-one credit**: A valid successful payment transaction credits the customer's BDT wallet exactly once.
- Event-driven reactive settlement: `FavoritePayPlugin::boot()` listens to `favorite.pay.payment.succeeded` and triggers `settleSuccessfulPayment($transactionId)` automatically upon transaction completion or admin manual approval.

### 9.2 Strict BDT Denomination & Minor Unit Integrity
- All wallet accounts and balances are strictly denominated in **BDT** (currency = `'BDT'`).
- Amounts are stored in integer minor units (**Poisha**, 1 BDT = 100 Poisha) preventing any floating-point truncation or accumulation error.
- Foreign currency transactions credit the customer's wallet with the **authoritative BDT base accounting amount** (`$intent->getBaseAmount()`), completely ignoring foreign charge amounts or rates at settlement time.

### 9.3 Transaction Eligibility & Validation Rules
Before any ledger entry is created or balance modified, the following strict checks are enforced:
1. **Transaction Existence**: The transaction must exist in the database or service runtime.
2. **Registered Customer**: Must be associated with a valid registered customer (`user_id > 0`). Anonymous / guest transactions are rejected with an exception.
3. **Transaction Status**: Must be in `PaymentStatus::SUCCEEDED`. Pending, awaiting verification, or failed/rejected transactions are strictly rejected.
4. **Positive Base Amount**: The BDT base amount must be strictly greater than zero (`> 0`).

### 9.4 Multi-Layer Idempotency & Concurrency Protection
To prevent double crediting under duplicate webhooks, concurrent requests, or network retries:
1. **In-Memory Cache**: Fast in-process registry (`$settledTransactions[$transactionId]`) returns the settled `WalletLedgerEntry` immediately on redundant calls.
2. **Database Unique Constraint**: Table `favorite_pay_wallet_entries` enforces a `UNIQUE` constraint on `idempotency_key`. The settlement engine generates a deterministic key: `'settle:payment:' . $transactionId`.
3. **Reference Pair Check**: Pre-insert query checks `WHERE (reference_type = 'payment' AND reference_id = ?) OR idempotency_key = ?`.
4. **Atomic Transactional Block**: All database reads, wallet creation/locking, ledger insertion, and balance updates execute inside an atomic `Database::transaction(\Closure)` wrapper.

### 9.5 Automatic Customer Wallet Provisioning
- When a customer without a pre-existing wallet has a payment settled, a new active BDT wallet (`balance = 0`, `currency = 'BDT'`, `status = 'active'`) is provisioned automatically inside the atomic transaction before crediting the payment amount.

### 9.6 Append-Only Ledger & Balance Synchronization
- Balance mutations are recorded exclusively via immutable append-only entries in `favorite_pay_wallet_entries`:
  - `entry_id`: Unique prefixed ID (`led_...`).
  - `wallet_id`: Foreign key reference to `favorite_pay_wallets`.
  - `user_id`: Target customer ID.
  - `type`: `'credit'`.
  - `amount`: Exact transaction BDT amount in Poisha.
  - `balance_after`: Precise balance in Poisha following the credit.
  - `reference_type`: `'payment'`.
  - `reference_id`: Transaction ID.
  - `idempotency_key`: `'settle:payment:' . $transactionId`.
  - `metadata`: JSON payload recording source plugin, source reference, charge currency, and charge amount.
- Fires Core event hook `favorite.pay.wallet.credited` with `user_id`, `amount`, and `balance`.

### 9.7 Intentionally NOT Implemented
- No customer-facing wallet UI, customer portal dashboard, or recharge forms.
- No administrative wallet management, adjustment UI, or manual balance editor.
- No customer checkout frontend UI or theme template tags.
- No automatic refunds or balance reversals.
- No external automated payment APIs (bKash/Nagad/Bank/Stripe/Binance APIs).

---

---

## 10. Phase 5B: Global Primary Currency / Currency Settings Foundation

### 10.1 Architectural Purpose & Global Usability
Phase 5B eliminates hard-coded currency assumptions (`'BDT'`) across Core and Favorite Pay, replacing them with a configurable site-level `primary_currency` setting stored in the Core `settings` table (`group_name = 'general'`, `setting_key = 'primary_currency'`).
- **Default Currency**: Unconditionally defaults to `'BDT'`, ensuring existing installations continue operating without manual intervention.
- **Centralized Metadata**: All currency definitions, formatting metadata, decimal precisions, and ISO validations reside in `FavoriteCMS\Core\Currency`. Neither plugins nor themes maintain duplicate currency lists.

### 10.2 Currency Roles in the Ecosystem
Favorite CMS strictly separates three currency concepts:
1. **Primary / Base Currency (`primary_currency`)**: The site-wide accounting base currency. Defines the default currency of newly provisioned customer wallets, base accounting amounts for transactions, and internal financial ledger reporting.
2. **Display Currency**: The currency shown to visitors on catalog/product pages before checkout.
3. **Payment / Charge Currency**: The currency accepted by a specific gateway driver during checkout and charged to the customer.

### 10.3 Historical Financial Safety & Non-Mutation Rule
Changing `primary_currency` in the Admin Settings panel:
- **MUST NOT** recalculate, convert, or mutate existing customer wallet balances.
- **MUST NOT** alter historical transactions, payment attempts, exchange rates, or ledger entries.
- **MUST NOT** perform automatic, silent, or lossy balance conversions.
- Each wallet retains its original `currency` (e.g., an existing BDT wallet remains a BDT wallet with its historical minor units intact).

### 10.4 Settlement Currency Matching & Guard
When `WalletServiceInterface::settleSuccessfulPayment` executes:
- It checks the authoritative transaction currency against the customer's wallet currency.
- If the customer already owns a wallet in a currency different from the transaction base currency, settlement is rejected (`RuntimeException: Currency mismatch`) to prevent mixing un-converted minor units of different currencies into the same balance ledger.
- For new customers, their initial wallet is provisioned in the site's current `primary_currency`.

### 10.5 Core & Favorite Pay Integration Surface
- **Core Currency Service**: `FavoriteCMS\Core\Currency::getPrimaryCurrency()` reads the setting via Core `Setting` model (with in-memory caching and fallback to `'BDT'`).
- **Global Helper**: `primary_currency()` provides immediate access across Core and plugins.
- **Admin Settings UI**: General Settings includes a server-validated dropdown populated from `Currency::getSupportedCurrencies()`.
- **Domain Money Value Object**: `Money` supports 12 major ISO currencies (`BDT`, `USD`, `EUR`, `GBP`, `INR`, `PKR`, `AED`, `SAR`, `CAD`, `AUD`, `JPY`, `CNY`) with named factories (`usd()`, `eur()`, `inr()`, `gbp()`) and precise subunit mappings (0 for JPY, 2 for standard fiat currencies).
- **Wallet Ledger Entry**: Validates that entry `amount` and resulting `balance_after` share identical currency codes.

### 10.6 Intentionally NOT Implemented
- No external automated payment APIs (bKash/Nagad/Bank/Stripe/Binance APIs).
- No webhooks, customer checkout frontend UI, or theme template tags.
- No multi-currency customer wallets or automated balance conversions.
- No public real-time currency-converter APIs or external FX sync scrapers.

---

## 11. Future Roadmap

- **Phase 3B**: Automated Gateway Driver implementations:
  - International Card Driver: `StripeCardGateway`.
  - Crypto Driver: `BinancePayGateway`.
- **Phase 5C**: Wallet Customer UI & Checkout Integration (theme template tags, wallet balance display, pay-with-wallet checkout option).





