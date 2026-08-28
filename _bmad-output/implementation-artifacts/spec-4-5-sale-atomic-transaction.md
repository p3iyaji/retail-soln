---
title: 'Story 4.5: Sale completion with atomic transaction'
type: 'feature'
created: '2026-08-27'
status: 'in-review'
baseline_commit: 'c934a59afb12d29c8a7f2b9e6f0e4a565a32f34a'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiate">

## Intent

**Problem:** POS can build a priced cart but cannot complete payment, so no sale, inventory, or receipt is recorded (FR-12, FR-14, UJ-1).

**Approach:** Add a Sales module with `POST /pos/sales` (Idempotency-Key + client UUID) that atomically creates sale + items + sale payments + `MovementType::Sale` ledger posts + tax-invoice stub + audit; wire POS payment sheet to post and show a queued receipt. Cash/card/split manual tenders only this story.

## Boundaries & Constraints

**Always:**
- One DB transaction for sale, items, payments, inventory movements, invoice stub; any failure rolls back entirely. Receipt print/email jobs queue **after** commit.
- Stock qty changes **only** via `InventoryLedgerService` with `MovementType::Sale` and **negative** qty (AD-2). Movement UUID = uuid5 from idempotency key + line index (PurchaseReturn pattern).
- Money: `DECIMAL(15,4)` / `bc*` only. CamelCase JSON. `sales.create` + `X-Store-Id`. Client `uuid` unique; header `Idempotency-Key` (≤64) required.
- Payments array required; Σ amounts must equal sale total (DECIMAL). Methods: `cash` | `card` | `bank` | `mobile` | `store_credit`; provider `manual` only this story.
- Default customer = org walk-in (`GET /customers/walk-in` or ensureWalkIn); registered `customerId` allowed when provided and in org.
- Online stock check: reject line if store available qty &lt; requested (422); Phase 6 oversell is later.
- Register Sale (+ related) with `AuditableObserver`. Completion path target ≤3s excluding queued jobs.

**Ask First:**
- Require open cashier session / terminals (default: **skip until 4.7** — `sessionId`/`terminalId` nullable; Pay enabled when cart has ≥1 line).
- Create `installmentPlan` in the same txn (default: **defer to 4.9** — if body includes `installmentPlan`, 422 with clear message).
- Full F4 customer search UI (default: walk-in auto-selected; optional minimal pick from `/customers?q=` if cheap).
- Stripe/Paystack intents (default: skip; manual tenders only).
- Real SMTP/SMS providers (default: queue `SendSaleReceipt` job that builds receipt payload / logs; no live email required).

**Never:**
- Bypass ledger for stock; float money; hold/void APIs (4.6); session open/close (4.7); returns (4.8); installment create/collect (4.9–4.10); proforma (4.11–4.12); gift cards / loyalty redemption.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Happy cash | Cart + cash = total | 201 sale; stock ↓; invoice stub; receipt job | N/A |
| Split tender | cash+card sum = total | Both `sale_payments`; stock ↓ | N/A |
| Idempotent replay | Same key + same payload | 200 existing sale; no double stock | N/A |
| Idempotent conflict | Same key + different payload | 409 | Abort |
| Under/overpay | Σ payments ≠ total | 422 | Abort |
| Insufficient stock | available &lt; qty | 422; no sale | Abort |
| installmentPlan present | Body has plan | 422 deferred | Abort |
| Missing Idempotency-Key | No header | 422 | Abort |
| No sales.create | Cashier without perm | 403 | Abort |
| Walk-in default | No customerId | Sale uses walk-in customer | N/A |

</frozen-after-approval>

## Code Map

- New `backend/Modules/Sales/` — mirror Pricing/Purchasing (`module.json`, ServiceProvider, routes, `modules_statuses.json`)
- `PurchaseReturnService` + `PurchaseReturnController` — idempotency + negative ledger template
- `InventoryLedgerService` + `PostMovementData` + `MovementType::Sale` — stock out
- `database-design.md` — `sales`, `sale_items`, `sale_payments`, `invoices`/`invoice_items` stub columns
- `api-design.md` — `POST /pos/sales`, `GET /pos/receipts/{saleId}`, `POST .../print`
- `PermissionMatrix.php` — `sales.create` / `sales.view` already seeded
- `CustomerService::ensureWalkIn` + `GET /customers/walk-in`
- `AuditableObserver` — register Sale models
- Continuity 4.4: `frontend/pos` cart (`pos.ts`, `cartPricing.ts`), `PosFloorView` Pay/F9 stub → real sheet; `api.post(..., { 'Idempotency-Key': ... })`
- Epic context: atomic sale; sessions/installments deferred per Ask First defaults

## Tasks & Acceptance

**Execution:**
- [x] Scaffold Sales module + migrations (`sales`, `sale_items`, `sale_payments`, minimal `invoices`/`invoice_items` stub) DECIMAL + uuid + org/store FKs + idempotency unique
- [x] `SaleCompletionService` + `POST /pos/sales` controller/request/resource — atomic create, stock posts, invoice stub, idempotency 200/201/409
- [x] Receipt: `GET /pos/receipts/{saleId}` + queue job after commit; print endpoint may return queued/stub payload
- [x] Feature tests for I/O matrix (happy, split, idempotent, pay mismatch, stock, installment reject, authz)
- [x] POS: payment sheet (tenders → POST); client UUID + Idempotency-Key; walk-in customer; clear cart + show receipt summary on success
- [x] Smoke: `php artisan test --filter=Sale` + `cd frontend/pos && npm run build`

**Acceptance Criteria:**
- Given a priced cart and confirmed payment, when `POST /pos/sales` succeeds, then sale, items, payments, sale movements, invoice stub, and audit exist; stock reflects the sale.
- Given payment confirmation failure mid-flight, when any step fails, then nothing is persisted (full rollback).
- Given the same Idempotency-Key and payload, when retried, then the original sale is returned without double inventory.
- Given POS Pay/F9, when tenders cover the total, then the sale completes and a receipt summary is available (queued delivery OK).

## Spec Change Log

- 2026-08-28: Approved and implemented on `feat/4-5-sale-atomic-transaction` — Sales module, POST /pos/sales, POS payment panel.
## Design Notes

**Totals:** Server recomputes line `lineTotal`/`tax` from posted `qty`/`unitPrice`/`discount` + store `tax_config` (bcmath). Do not trust client `lineTotal` alone.

**Invoice stub:** Minimal row (`status=draft` or `issued` non-fiscal-enough for MVP) linked `sale_id`, copying totals — not full AR/journals.

**Location:** Store location (`LocationType::Store`, `store_id` from `X-Store-Id`).

**Example POST body:**
```json
{
  "uuid": "…",
  "customerId": null,
  "items": [{ "variantId": 5, "qty": "1.0000", "unitPrice": "10.0000", "discount": "0.0000" }],
  "payments": [{ "method": "cash", "amount": "10.7500", "provider": "manual" }]
}
```

## Verification

**Commands:**
- `cd backend && php artisan test --filter=Sale` -- expected: pass
- `cd frontend/pos && npm run build` -- expected: success

**Manual checks (if no CLI):**
- POS: add line → Pay → cash = total → success; inventory balance drops; replay same key does not double-post.
