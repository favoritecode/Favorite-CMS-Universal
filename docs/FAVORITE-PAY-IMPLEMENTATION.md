# Favorite Pay — Phase 1 Implementation Specification

**Document Version**: 1.0.0  
**Target Repository**: `Favorite-CMS-Universal`  
**Plugin Identifier**: `favorite-pay`  
**Namespace**: `FavoriteCMS\Pay`  
**Status**: Implemented (Phase 1 Foundation)  

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

## 6. Phase 2 & Future Roadmap

- **Phase 2**: Production database schema migrations (`favorite_pay_*` tables) using Core `Migrator`.
- **Phase 3**: Gateway driver implementations:
  - Manual BD Drivers: `BkashManualGateway`, `NagadManualGateway`, `BankTransferGateway`.
  - International Card Driver: `StripeCardGateway`.
  - Crypto Driver: `BinancePayGateway`.
- **Phase 4**: Operator Admin Console UI module for pending manual transaction verification.
- **Phase 5**: Theme checkout template tags and API endpoints for Favorite Web Theme.
