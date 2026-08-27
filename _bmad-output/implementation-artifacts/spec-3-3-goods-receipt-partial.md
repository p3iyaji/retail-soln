---
title: 'Story 3.3: Goods receipt (partial)'
type: 'feature'
created: '2026-08-27'
status: 'done'
baseline_commit: '714b37a44464c29c44e9a1c82bd001c9b134a83f'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-3-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiate">

## Intent

**Problem:** Approved purchase orders cannot be received into stock, so inventory does not reflect deliveries and partial shipments cannot be tracked.

**Approach:** Add goods receipts (`POST /goods-receipts`) that post `purchase` ledger movements for received qty only, update PO line received progress, and move PO status `approved → partial → received`, with an Admin receive UI. No invoices/payments/returns.

## Boundaries & Constraints

**Always:**
- Gate receive with `purchase.receive`; list/show of related PO context may use existing purchase create/approve view rules where needed.
- Only `approved` or `partial` POs can receive; never draft/pending/rejected/received/closed.
- Stock mutations only via `InventoryLedgerService::post` with `MovementType::Purchase` (AD-2). DECIMAL qty/cost; camelCase JSON.
- Atomic: GRN create + ledger posts + PO status/qty updates in one transaction; support Idempotency-Key on POST.
- Remaining receivable qty = ordered − previously received; over-receive and zero qty → 422.
- Org-scoped; receive into the PO’s store location (`LocationType::Store`).

**Ask First:**
- Receiving into a warehouse instead of the PO store.
- Closing POs short (mark `closed` without full receipt) — default: out of scope this story.

**Never:**
- Supplier invoices, payments, or purchase returns (3.4–3.5).
- Direct balance writes or inventing GRN list APIs beyond what Admin needs (minimal GET for verification OK if required by UI; prefer receive from PO).
- Changing approval/dual-control rules from 3.2.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Partial receive | Approved PO; receive qty &lt; ordered on a line | GRN created; purchase movements for received qty; PO → `partial`; remaining open | N/A |
| Complete receive | Receive remaining on all lines | Movements posted; PO → `received` | N/A |
| Subsequent GRN | Partial PO; another partial | Additional movements; still `partial` until complete | N/A |
| Over-receive | qty &gt; remaining | Rejected | 422 |
| Wrong status | Draft/pending/rejected PO | Rejected | 422 |
| Unauthorized | No `purchase.receive` | 403 | Abort |
| Unauthenticated | No token | 401 | Framework default |
| Idempotent replay | Same Idempotency-Key | Same GRN / no double stock | 200/201 stable |

</frozen-after-approval>

## Code Map

- `backend/Modules/Purchasing/app/Services/PurchaseOrderService.php` -- approve/reject only today; extend status to `partial`/`received` via GRN service (do not bypass ledger)
- `backend/Modules/Purchasing/app/Enums/PurchaseOrderStatus.php` -- `Partial`, `Received`, `Closed` already reserved
- `backend/Modules/Purchasing/app/Models/PurchaseOrder.php` + `PurchaseOrderItem.php` -- add `qty_received` on items (or derive from GRN sums); expose remaining in resources
- `backend/Modules/Inventory/app/Services/InventoryLedgerService.php` -- `post(PostMovementData)` sole mutator
- `backend/Modules/Inventory/app/Data/PostMovementData.php` + `Enums/MovementType.php` -- use `Purchase`
- `backend/Modules/Inventory/app/Services/StockTransferService.php` -- `receive()` transactional analog
- `backend/Modules/Purchasing/routes/api.php` -- add `POST /goods-receipts` (+ optional `GET` for list by PO if UI needs)
- `backend/Modules/Authentication/app/Support/PermissionMatrix.php` -- `purchase.receive` already defined
- `frontend/admin/src/views/PurchaseOrdersView.vue` -- add Receive action for approved/partial; or new GRN view + AppShell nav
- `frontend/admin/src/views/InventoryView.vue` -- receive UX pattern (`onReceive`)
- Continuity Story 3.2: PO numbering, store_id, unit_cost on lines, AuditableObserver on Purchasing models
- `backend/tests/Feature/PurchaseOrderApiTest.php` -- fixture pattern; new `GoodsReceiptApiTest.php`

## Tasks & Acceptance

**Execution:**
- [x] Migrations: `goods_receipts`, `goods_receipt_items`; add `qty_received` on `purchase_order_items` (DECIMAL default 0)
- [x] Models/resources + `GoodsReceiptService` (validate PO status, remaining qty, post Purchase movements, update qty_received + PO status) + controller/routes
- [x] Idempotency-Key handling for POST; register AuditableObserver on GRN models
- [x] Admin: receive UI from Purchase orders (approved/partial) showing remaining qty per line
- [x] `backend/tests/Feature/GoodsReceiptApiTest.php` — matrix rows including ledger balance change, partial then complete, over-receive, wrong status, 403, idempotency

**Acceptance Criteria:**
- Given an approved PO, when a GRN posts partial line qty, then `InventoryLedgerService` records purchase movements for that qty only and PO status becomes `partial`.
- Given remaining qty, when a subsequent GRN completes all lines, then PO status becomes `received`.
- Given receive qty above remaining, when POST runs, then 422 and no stock change.
- Given a non-receivable PO status, when POST runs, then 422.
- Given a user without `purchase.receive`, when POST runs, then 403.
- Given Admin SPA, when a receivable PO is selected, then the user can enter received qtys and submit a GRN.

## Spec Change Log

## Design Notes

**POST body:** `{ purchaseOrderId, notes?, items: [{ purchaseOrderItemId, qty }] }` — qty is this receipt’s quantity (not cumulative). Location = PO `store_id` / `LocationType::Store`.

**Remaining qty:** Persist `purchase_order_items.qty_received`; remaining = `qty - qty_received`. Status: any successful receipt on `approved` → `partial`; when every line `qty_received >= qty` → `received`. Do not auto-`closed`.

**Ledger:** One `Purchase` movement per GRN line; `unitCost` from PO line; `referenceType` = `goods_receipt`, `referenceId` = GRN id; optional movement `uuid` from Idempotency-Key + line index.

**Idempotency:** Store key on `goods_receipts` (unique per org) or reuse movement uuid strategy; replay returns the existing GRN without double-posting.

**UI:** Prefer receive modal/panel on Purchase Orders page (no mandatory separate GRN CRUD list). Expose `qtyReceived` / `qtyRemaining` on PO item resources.

## Verification

**Commands:**
- `cd backend && php artisan test --filter=GoodsReceipt` -- expected: all pass
- `cd backend && php artisan test` -- expected: full suite green
- `cd frontend/admin && npm run build` -- expected: success

**Manual checks (if no CLI):**
- Approve a PO; receive partial; confirm inventory balance up and PO `partial`; receive remainder → `received`.

## Suggested Review Order

**Receive service (ledger)**

- Atomic GRN + Purchase movements via InventoryLedgerService only
  [`GoodsReceiptService.php:33`](../../backend/Modules/Purchasing/app/Services/GoodsReceiptService.php#L33)

- MovementType::Purchase into PO store available bucket
  [`GoodsReceiptService.php:171`](../../backend/Modules/Purchasing/app/Services/GoodsReceiptService.php#L171)

**API**

- POST /goods-receipts with Idempotency-Key handling
  [`api.php:33`](../../backend/Modules/Purchasing/routes/api.php#L33)

**Admin**

- Receive panel from Purchase Orders (remaining qty, progress)
  [`PurchaseOrdersView.vue:392`](../../frontend/admin/src/views/PurchaseOrdersView.vue#L392)

**Tests**

- Partial/complete, over-receive, idempotency, multi-line, audit
  [`GoodsReceiptApiTest.php:106`](../../backend/tests/Feature/GoodsReceiptApiTest.php#L106)
