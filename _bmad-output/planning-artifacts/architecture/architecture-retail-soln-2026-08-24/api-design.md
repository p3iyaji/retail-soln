# API Design — `/api/v1`

**Status:** Epic 0.2 complete  
**Date:** 2026-08-24  
**Clients:** Admin SPA, Store Manager SPA, POS PWA (later Customer Portal)

## Conventions

| Topic | Rule |
| --- | --- |
| Base | `https://{host}/api/v1` |
| Auth | `Authorization: Bearer {sanctum_token}` |
| Store context | Header `X-Store-Id: {id}` required for store-scoped ops (or query where listed) |
| Idempotency | Header `Idempotency-Key: {uuid}` on POSTs that create money/stock mutations |
| Pagination | `?page=&per_page=` → `{ data, meta: { current_page, last_page, total } }` |
| Errors | `{ message, errors?: { field: [string] } }` |
| JSON | camelCase attributes in responses; accept camelCase or snake_case on input (normalize in FormRequest) |

## Auth

| Method | Path | Notes |
| --- | --- | --- |
| POST | `/auth/login` | email/password → token + user |
| POST | `/auth/logout` | revoke current token |
| GET | `/auth/me` | user + roles + storeAccess |
| POST | `/auth/two-factor/*` | Fortify-backed |

## Organization & Stores

| Method | Path | Permission |
| --- | --- | --- |
| GET/POST | `/organizations/current` | org.view / org.update |
| GET/POST | `/stores` | stores.view / stores.create |
| GET/PATCH/DELETE | `/stores/{id}` | stores.* |
| GET/POST | `/warehouses` | warehouses.* |

## Users & RBAC

| Method | Path | Permission |
| --- | --- | --- |
| GET/POST | `/users` | users.* |
| PATCH | `/users/{id}/roles` | users.assign-roles |
| PATCH | `/users/{id}/stores` | users.assign-stores |
| GET | `/roles`, `/permissions` | roles.view |

## Catalog

| Method | Path | Permission |
| --- | --- | --- |
| CRUD | `/products`, `/products/{id}` | products.* |
| CRUD | `/products/{id}/variants` | products.* |
| GET | `/products/search?q=` | products.view (POS uses this; p95 &lt;200ms) |
| POST | `/products/import` | products.import (queued job) |
| GET | `/categories`, `/brands`, `/units` | catalog.view |

## Inventory

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/inventory/balances?storeId=` | inventory.view |
| POST | `/inventory/adjustments` | inventory.adjust |
| POST | `/inventory/transfers` | inventory.transfer |
| POST | `/inventory/transfers/{id}/receive` | inventory.transfer |
| POST | `/inventory/counts` | inventory.count |
| GET | `/inventory/movements` | inventory.view |
| GET | `/inventory/alerts/low-stock` | inventory.view |

All mutations go through InventoryLedgerService (AD-2).

## Purchasing

| Method | Path | Permission |
| --- | --- | --- |
| CRUD | `/suppliers` | suppliers.* |
| CRUD | `/purchase-orders` | purchase.* |
| POST | `/purchase-orders/{id}/submit` | purchase.create |
| POST | `/purchase-orders/{id}/approve` | purchase.approve |
| POST | `/goods-receipts` | purchase.receive |
| CRUD | `/supplier-invoices`, `/supplier-payments` | purchase.pay |

## Pricing / Tax / Loyalty

| Method | Path | Permission |
| --- | --- | --- |
| CRUD | `/price-lists`, `/prices` | pricing.* |
| CRUD | `/promotions` | promotions.* |
| CRUD | `/tax-rates` | tax.* |
| CRUD | `/loyalty/programs`, `/loyalty/rules` | loyalty.* |
| POST | `/pricing/resolve` | body: variantId, storeId, customerId → price + promos + tax |

## Customers

| Method | Path | Permission |
| --- | --- | --- |
| CRUD | `/customers` | customers.* |
| GET | `/customers/{id}/history` | customers.view |

## POS / Sales

| Method | Path | Permission |
| --- | --- | --- |
| POST | `/pos/sessions/open` | pos.session |
| POST | `/pos/sessions/{id}/close` | pos.session |
| POST | `/pos/sales` | sales.create + Idempotency-Key |
| POST | `/pos/sales/{id}/void` | sales.void |
| POST | `/pos/sales/hold` | sales.create |
| GET | `/pos/sales/held` | sales.view |
| POST | `/pos/returns` | sales.refund |
| GET | `/pos/receipts/{saleId}` | sales.view |
| POST | `/pos/receipts/{saleId}/print` | sales.view (returns ESC/POS payload or job) |
| POST | `/pos/sales/{id}/installment-plans` | sales.installments.create + registered customer |
| GET | `/installment-plans` | sales.installments.view |
| GET | `/installment-plans/{id}` | sales.installments.view |
| POST | `/installment-plans/{id}/payments` | sales.installments.collect + Idempotency-Key |
| POST | `/installment-plans/{id}/cancel` | sales.installments.cancel |

Sale POST may include optional `installmentPlan` when financing remaining balance after down payment:

```json
{
  "uuid": "client-generated-uuid",
  "terminalId": 1,
  "sessionId": 10,
  "customerId": 42,
  "items": [{ "variantId": 5, "qty": 1, "unitPrice": "300000.0000", "discount": "0" }],
  "payments": [{ "method": "cash", "amount": "100000.0000", "provider": "manual" }],
  "installmentPlan": {
    "mode": "pay_over_time",
    "installments": [
      { "sequence": 1, "amount": "100000.0000", "dueAt": "2026-09-24" },
      { "sequence": 2, "amount": "100000.0000", "dueAt": "2026-10-24" }
    ]
  },
  "loyaltyRedemption": null
}
```

## Proforma Invoices (AD-15)

| Method | Path | Permission |
| --- | --- | --- |
| GET/POST | `/proforma-invoices` | invoices.proforma.view / create |
| GET/PATCH | `/proforma-invoices/{id}` | invoices.proforma.view / update |
| POST | `/proforma-invoices/{id}/issue` | invoices.proforma.issue |
| POST | `/proforma-invoices/{id}/cancel` | invoices.proforma.cancel |
| POST | `/proforma-invoices/{id}/convert` | invoices.proforma.convert |
| GET | `/proforma-invoices/{id}/pdf` | invoices.proforma.view |

Convert body sketch: `{ "target": "sale"|"invoice"|"both", "honorPrices": false, "installmentPlan": null }`. Convert must not post inventory until the resulting sale commits.

## Payments (AD-10)

| Method | Path | Notes |
| --- | --- | --- |
| POST | `/payments/intents` | provider: manual\|stripe\|paystack |
| POST | `/webhooks/stripe` | signed, idempotent |
| POST | `/webhooks/paystack` | signed, idempotent |

## Sync (Phase 6)

| Method | Path | Notes |
| --- | --- | --- |
| POST | `/sync/push` | batch of transactions; idempotent per uuid |
| GET | `/sync/pull?cursor=` | incremental catalog/inventory/config |
| GET | `/sync/status` | device last sync, pending exceptions |
| POST | `/sync/oversell-exceptions/{id}/approve` | manager permission |

## Admin / Audit

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/audit-logs` | audit.view |
| GET/PATCH | `/settings` | settings.* |

## Versioning & breaking changes

- Additive fields preferred
- Breaking changes → `/api/v2`; keep v1 until clients migrate
