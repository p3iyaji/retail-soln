---
title: 'Story 7.2: Picking and packing workflow'
type: 'feature'
created: '2026-08-29'
status: 'done'
baseline_commit: '7b227579115842920b94258cf9caa625a18f9e6c'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-7-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Portal orders hold stock in `reserved` after place/pay, but staff have no pick/pack workflow to progress fulfilment or convert reserved inventory. Customers stay on Confirmed; reserved never becomes a fulfilled sale movement.

**Approach:** Add staff store-scoped pick/pack APIs and a thin Admin SPA: list eligible portal orders, pick lines, complete pack — consuming reserved qty via InventoryLedgerService (not available) and advancing order status to `processing`. Ship and store-pickup handoffs stay 7.3 / 7.4.

## Boundaries & Constraints

**Always:**
- Staff APIs under `/api/v1` with Sanctum `staff`, store scope (`X-Store-Id` + assigned store), and new permission(s) e.g. `fulfilment.pick` (seed RBAC matrix + roles).
- Only paid / non-cancelled portal orders for the store; typical start: `confirmed` (reserved held). Idempotency-Key on pack-complete / stock-mutating POSTs.
- Pack complete: for each line, **consume** reserved (−reserved, `MovementType::Sale` or dedicated fulfil type) via ledger — do **not** touch available; do **not** call POS `SaleCompletionService`. Extend `StockReservationService` (or sibling) with idempotent `consume`.
- On successful pack: set order status `processing` (timeline “Picked & packed”); do not set `ready_for_pickup` / `out_for_delivery` / `delivered` here.
- Partial pick OK if modeled (line picked qty) **or** MVP all-or-nothing pack — pick one and test it (default: **all lines required** then complete).
- Admin SPA: Fulfilment nav + store-scoped list/detail + pack action; portal customers still read-only on status.
- DECIMAL qty; AD-2 only.

**Ask First:**
- Partial line picks before pack (default: **no** — complete pack for full order lines in one action).
- Auto-advance delivery vs pickup to next status in 7.2 (default: **no** — stop at `processing`).

**Never:**
- Shipment/carrier tables or `out_for_delivery` (7.3).
- Counter `ready_for_pickup` / collect (7.4).
- Releasing reserved on pack (that is cancel/TTL); consuming twice; deducting available again.
- Portal customer pick/pack tools.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| List store orders | Staff + store + permission | Paid confirmed (and processing) orders for store | Empty → empty |
| Pack complete | Confirmed + reserved held + Idempotency-Key | Reserved consumed; status `processing` | Insufficient reserved → 422 |
| Pack replay | Same Idempotency-Key | Same result; no double consume | N/A |
| Wrong store / no perm | Other store or missing fulfilment.pick | 403/404 | N/A |
| Unpaid / cancelled | Not eligible | Rejected | 422 |
| Portal token | Portal Sanctum | 401/403 | N/A |

</frozen-after-approval>

## Code Map

- Reserve/release/consume: `StockReservationService` — `consume` posts −reserved `MovementType::Sale` (UUID action `consume`); `PortalCheckoutService` leave reserve/release alone
- Statuses: `PortalOrderStatuses`, `PortalOrderTimelineService` — `processing` step already labeled picked & packed
- Staff patterns: `StaffFulfilmentController` + `PortalFulfilmentService`; `X-Store-Id`; `PermissionMatrix` `fulfilment.pick`
- Pack columns: `portal_orders.packed_at`, `pack_idempotency_key`
- Admin: `frontend/admin` router + AppShell + `FulfilmentView.vue`
- Continuity 7.1: reserved held through pay; pack consume here; ship/pickup 7.3/7.4

## Tasks & Acceptance

**Execution:**
- [x] RBAC `fulfilment.pick` (+ view if needed) seeded; staff fulfilment list/show/pack APIs + tests for matrix
- [x] StockReservationService.consume (idempotent) wired on pack-complete → status `processing`
- [x] Admin SPA fulfilment list/detail + pack action (store-scoped)
- [x] README; sprint `7-2-picking-packing` → review
- [x] Isolation: portal token rejected; wrong store rejected; no double consume

**Acceptance Criteria:**
- Given a paid confirmed portal order with reserved stock, when staff complete pack for that store, then reserved qty is consumed and status becomes `processing`.
- Given a repeated Idempotency-Key pack, when retried, then stock is not double-consumed.
- Given insufficient permission or wrong store, when pack is attempted, then the request is denied.
- Given this story ships, when viewing the order in portal, then timeline shows Processing; Ready for pickup / Out for delivery remain for later stories unless already current.

## Spec Change Log

- Implemented staff `/api/v1/fulfilment/orders` list/show/pack; `StockReservationService::consume` (−reserved Sale); Admin Fulfilment view; RBAC `fulfilment.pick`.
- Review patches: pack idempotency conflict on differing key; consume guards + UUID race; eligible findForStore; Admin storeAccess/nav/confirm/sessionStorage key; deferred Policy/view/audit/checklist/warehouse/e2e.

## Design Notes

**Consume movement:** Prefer `−reserved` with `MovementType::Sale` and reference portal order (UUID action `consume`) so available stays correct. Document that POS sale path is separate.

**Events:** Optionally dispatch a domain event after pack for Epic 8 notifications — fire-and-forget if cheap; not required for AC.

## Verification

**Commands:**
- `cd backend && php artisan test --filter='Portal|Fulfilment|StockReservation|Permission'` -- expected: relevant suites pass
- `cd frontend/admin && npm run build` -- expected: pass

**Manual checks:**
- Place+pay portal order → Admin Fulfilment → Pack → reserved↓; portal shows Processing

## Suggested Review Order

**Pack / consume**

- Consume reserved (−reserved Sale) on pack-complete
  [`StockReservationService.php:1`](../../backend/Modules/Inventory/app/Services/StockReservationService.php#L1)
  [`PortalFulfilmentService.php:1`](../../backend/Modules/CustomerPortal/app/Services/PortalFulfilmentService.php#L1)

- Staff fulfilment routes (list/show/pack)
  [`StaffFulfilmentController.php:1`](../../backend/Modules/CustomerPortal/app/Http/Controllers/Api/StaffFulfilmentController.php#L1)

**Admin SPA**

- Fulfilment list/detail + pack
  [`FulfilmentView.vue:1`](../../frontend/admin/src/views/FulfilmentView.vue#L1)

**Tests**

- Pack consume, idempotency, authz
  [`FulfilmentPackApiTest.php:114`](../../backend/tests/Feature/FulfilmentPackApiTest.php#L114)
