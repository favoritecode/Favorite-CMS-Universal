# Favorite Shop — Favorite Pay integration (COD-safe contract)

Favorite Shop is a separate plugin for physical products. Its Cash on Delivery (COD) workflow belongs to Favorite Shop; it is not an online gateway and must not be treated as a successful Favorite Pay transaction.

## Bridge API

```php
use FavoriteCMS\Pay\Commerce\FavoriteShopPaymentBridge;

$methods = FavoriteShopPaymentBridge::methods();
$cod = FavoriteShopPaymentBridge::isCashOnDelivery($selectedMethod);
$initialState = FavoriteShopPaymentBridge::initialOrderPaymentState($selectedMethod);
```

When Favorite Pay is installed, Favorite Shop can read this descriptor and show COD only for physical-product checkout. If Favorite Pay is not installed, Favorite Shop should retain its own COD implementation rather than making the plugin a hard dependency.

## Required invariants

- COD requires a delivery address and is only appropriate for physical orders.
- A COD order starts `pending` / `unpaid`; selecting COD must not mark it paid.
- Do not create a successful Favorite Pay transaction, debit/credit a wallet, or trigger digital-product delivery for COD selection.
- Favorite Shop records collection after the courier/customer payment is actually received, then updates its own order/payment state.
- Keep Favorite Digital checkout, payment verification, wallet, refund, and digital delivery logic unchanged.
- This bridge intentionally does not register COD as a `PaymentGatewayInterface` or add it to Favorite Digital checkout.

## Theme mode

Favorite Pay admin fragments and customer account pages follow the active theme's `data-theme="dark"` / `data-theme="light"` state, and compatibility selectors for `.dark` and `data-bs-theme`. Favorite Pay does not save a second theme preference; the theme remains the source of truth.
