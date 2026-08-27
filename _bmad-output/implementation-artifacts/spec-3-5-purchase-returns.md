---
title: 'Story 3.5: Purchase returns'
type: 'feature'
created: '2026-08-27'
status: 'done'
baseline_commit: '7bf43312b9359188f7a376b4d5f35e04345a20d8'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-3-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiate">

## Intent

**Problem:** Received goods cannot be returned to suppliers, so stock stays inflated and supplier AP is not corrected when goods go back.

**Approach:** Add purchase-return create (against a goods receipt) that posts negative `purchase_return` ledger movements, records a DECIMAL credit that reduces computed supplier outstanding, and expose a Returns flow in Admin—gated by `purchase.receive`.

## Boundaries & Constraints

**Always:**
- Stock only via `InventoryLedgerService::post` with `MovementType::PurchaseReturn` (`purchase_return`) and **negative** qty (mirror of GRN positive purchase). Never write balances directly (AD-2).
- Return against an existing goods receipt in the same org; line qty ≤ that GRN line’s remaining returnable (received − already returned); DECIMAL qty/cost only.
- Gate create/list/show with `purchase.receive` (same roles as GRN). Idempotency-Key on POST; org via `OrganizationService::current()`; camelCase JSON.
- Supplier outstanding becomes Σ invoices − Σ payments − Σ return credits (bc*); credit amount = Σ (return qty × unit_cost) from return lines. Persist credit on the return header.
- Reject return that would leave available stock below returned qty (422)—do not silently oversell on purchase return.
- Sensitive creates audited.

**Ask First:**
- Adding a distinct `purchase.return` permission instead of reusing `purchase.receive`.
- Posting AD-12 journal entries from returns (default: skip).
- Allowing returns that drive available stock negative.

**Never:**
- Using existing `MovementType::Return` (`return`) for this story—must be `purchase_return`.
- Mutating invoice/payment rows to fake a credit; inventing a mutable `supplier_balances` table.
- POS sale returns, full credit-note lifecycle (void/edit), or GRN redesign.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Happy path | GRN with returnable qty; sufficient stock | 201 return; negative `purchase_return` movements; credit stored; outstanding drops | N/A |
| Cap | qty &gt; GRN line remaining returnable | Rejected | 422 |
| Insufficient stock | available &lt; return qty | Rejected; no rows | 422 |
| Idempotent replay | Same Idempotency-Key + body | 200 existing return | N/A |
| Body conflict | Same key, different body | Conflict | 409 |
| Unauthorized | No `purchase.receive` | 403 | Abort |
| Unauthenticated | No token | 401 | Framework default |
| Uninvoiced GRN | Return before invoice | Stock reverse + credit still applied to outstanding | N/A |

</frozen-after-approval>

## Code Map

- `backend/Modules/Inventory/app/Enums/MovementType.php` -- add `PurchaseReturn = 'purchase_return'` (do not reuse `Return`)
- `backend/Modules/Inventory/app/Services/InventoryLedgerService.php` + `Data/PostMovementData.php` -- sole stock path; uuid idempotency
- `backend/Modules/Purchasing/app/Services/GoodsReceiptService.php` (~166–199) -- pattern: txn, uuid5 per line, negative qty via `bcmul(..., '-1')` like `StockTransferService` transfer-out
- `backend/Modules/Purchasing/app/Models/GoodsReceipt.php` + `GoodsReceiptItem.php` -- link target; track returnable via return items or `qty_returned` on GRN/PO lines
- `backend/Modules/Purchasing/app/Services/SupplierOutstandingService.php` -- extend formula to subtract return credits (bc*)
- `backend/Modules/Purchasing/routes/api.php` -- add `POST/GET /purchase-returns` (+ show)
- `backend/Modules/Authentication/app/Support/PermissionMatrix.php` L68 -- reuse `purchase.receive` unless Ask First overrides
- `frontend/admin/src/views/PurchaseOrdersView.vue` -- receive modal/Idempotency-Key pattern (~429–476); extend or sibling Returns UI
- `frontend/admin/src/layouts/AppShell.vue` + `router/index.ts` -- optional Returns nav entry
- `frontend/admin/src/views/PaymentsView.vue` -- outstanding display must reflect credits after service change
- Continuity 3.3/3.4: GRN `unit_cost`; computed outstanding (no balance table); fixtures in `GoodsReceiptApiTest` / `SupplierInvoicePaymentApiTest`
- ERD: `purchase_returns`, `purchase_return_items` (`database-design.md`)

## Tasks & Acceptance

**Execution:**
- [x] `MovementType` + migrations `purchase_returns` / `purchase_return_items` (org, goods_receipt_id, supplier_id, store_id, credit_amount DECIMAL, idempotency_key; items with qty, unit_cost, inventory_movement_id, returnable cap tracking)
- [x] `PurchaseReturnService` + models/resources/requests/controllers/routes — post negative ledger lines, compute credit, Idempotency-Key replay/409
- [x] Extend `SupplierOutstandingService` — subtract Σ return credits; Admin outstanding/meta updates
- [x] Admin: return from GRN/PO context (qty caps, Idempotency-Key); show credit effect on outstanding
- [x] `backend/tests/Feature/PurchaseReturnApiTest.php` — matrix (happy, cap, stock, idempotency, 403, outstanding, ledger type)

**Acceptance Criteria:**
- Given received goods on a GRN, when a purchase return is processed, then `purchase_return` ledger movements with negative qty exist and available stock decreases by returned qty.
- Given a return with DECIMAL line costs, when posted, then supplier outstanding decreases by the credit amount (invoices − payments − credits).
- Given return qty above returnable or above available stock, when POST runs, then 422 and no partial commit.
- Given a user without `purchase.receive`, when creating a return, then 403.
- Given Admin SPA, when a permitted user returns goods from a receipt, then the return succeeds and outstanding reflects the credit.

## Spec Change Log

- 2026-08-27: Implemented Story 3.5 (backend + Admin return modal + tests green).

## Design Notes

**Link model:** Return header requires `goods_receipt_id` (same org). Items reference `goods_receipt_item_id` (or PO item + GRN item). Returnable = GRN item qty − Σ prior return qtys for that GRN item. Denormalize `supplier_id` / `store_id` / `purchase_order_id` from GRN→PO.

**Ledger:** `type: purchase_return`, `quantity: bcmul(qty, '-1', 4)`, `unitCost` from GRN line, `referenceType: purchase_return`, `referenceId: return.id`.

**AP credit:** Persist `credit_amount` on header (= Σ qty×unit_cost). Outstanding = invoices − payments − credits. Prefer allowing negative outstanding (supplier credit) over a silent floor—if existing UI assumes ≥0, expose signed outstanding and keep display clear.

**Permission:** Reuse `purchase.receive` for MVP (matrix already covers SA/OA/SM/IM/PO).

**UI:** Prefer extend Purchase Orders page (return modal next to receive) over a brand-new page; list recent returns optional.

## Verification

**Commands:**
- `cd backend && php artisan test --filter=PurchaseReturn` -- expected: all pass
- `cd backend && php artisan test` -- expected: full suite green
- `cd frontend/admin && npm run build` -- expected: success

**Manual checks (if no CLI):**
- Receive PO → return partial qty → stock down; outstanding down by credit; second return cannot exceed remaining.

## Suggested Review Order

**Ledger & return posting**

- Negative `purchase_return` movements via ledger only (AD-2)
  [`PurchaseReturnService.php:33`](../../backend/Modules/Purchasing/app/Services/PurchaseReturnService.php#L33)

- Enum case `purchase_return` (not generic `return`)
  [`MovementType.php:9`](../../backend/Modules/Inventory/app/Enums/MovementType.php#L9)

- Header/items schema with DECIMAL credit and idempotency key
  [`2026_08_27_190000_create_purchase_returns_tables.php:11`](../../backend/Modules/Purchasing/database/migrations/2026_08_27_190000_create_purchase_returns_tables.php#L11)

**AP outstanding**

- Outstanding = invoices − payments − return credits (signed)
  [`SupplierOutstandingService.php:16`](../../backend/Modules/Purchasing/app/Services/SupplierOutstandingService.php#L16)

**API**

- `GET/POST /purchase-returns` gated by `purchase.receive`
  [`api.php:38`](../../backend/Modules/Purchasing/routes/api.php#L38)

**Admin**

- Return modal on POs with Idempotency-Key and qty caps
  [`PurchaseOrdersView.vue:597`](../../frontend/admin/src/views/PurchaseOrdersView.vue#L597)

- Negative outstanding shown as supplier credit
  [`PaymentsView.vue:357`](../../frontend/admin/src/views/PaymentsView.vue#L357)

**Tests**

- Happy path, caps, stock aggregate, idempotency, filters, 403
  [`PurchaseReturnApiTest.php:127`](../../backend/tests/Feature/PurchaseReturnApiTest.php#L127)
