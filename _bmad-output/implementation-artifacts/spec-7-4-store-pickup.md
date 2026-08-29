---
title: 'Story 7.4: Store pickup workflow'
type: 'feature'
created: '2026-08-29'
status: 'done'
baseline_commit: 'c7aeee7cec8e86b530a06c176f0450f7ee9bf256'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-7-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Packed pickup / click & collect portal orders stay at `processing` with no staff path to mark Ready for pickup or Collect at the counter — so the portal timeline never reaches Ready for Pickup / Collected for those modes.

**Approach:** Extend staff fulfilment for **store_pickup** and **click_collect**: mark ready for pickup (`processing` → `ready_for_pickup`) and mark collected (`ready_for_pickup` → `delivered`). Admin UI surfaces order ID for counter handoff. No inventory ledger posts; no shipping. Completes Epic 7 ops path for pickup.

## Boundaries & Constraints

**Always:**
- Staff APIs under `/api/v1/fulfilment/…`, Sanctum `staff`, `X-Store-Id` + assigned store; permission e.g. `fulfilment.pickup` (seed matrix).
- Eligible modes: `store_pickup` | `click_collect` (same handoff MVP); paid; packed (`processing` for ready; `ready_for_pickup` for collect); not cancelled/returned/refunded.
- Transitions: `processing` → `ready_for_pickup`; `ready_for_pickup` → `delivered` (collected). Idempotency-Key on both mutators (store keys on order like pack/ship).
- Widen list/show for `ready_for_pickup` (exclude delivered from default list, same as 7.3).
- Admin: “Ready for pickup” / “Mark collected”; prominently show **order number**; keep ship/deliver home_delivery-only.
- Reject ready/collect on `home_delivery`; reject ship/deliver on pickup modes (already true).
- No InventoryLedgerService calls; stock already consumed at pack.

**Ask First:**
- Differentiate click_collect vs store_pickup UX (default: **same handoff**).
- Require scanning / customer ID verify beyond order number (default: **no** — staff confirms order number present).

**Never:**
- Creating `portal_shipments` or OFD for pickup modes.
- Auto-ready on pack (default: explicit staff ready).
- Portal customer self-collect APIs.
- Releasing/consuming reserved stock again.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Ready for pickup | Packed pickup/C&C + Idempotency-Key | Status `ready_for_pickup` | HD mode → 422 |
| Collect | RFP + Idempotency-Key | Status `delivered` | Not RFP → 422 |
| Idempotent replay | Same key | Same result | Different key after done → 422 |
| Home delivery ready/collect | HD order | Rejected | 422 |
| Wrong store / no perm / portal token | — | 403/404/401 | N/A |

</frozen-after-approval>

## Code Map

- Extend: `PortalFulfilmentService`, `StaffFulfilmentController`, fulfilment routes, `FulfilmentView.vue`
- Timeline already pickup-aware: `PortalOrderTimelineService` + `PortalOrderStatuses::READY_FOR_PICKUP`
- Pattern: mirror ship/deliver idempotency from 7.3 (order columns `ready_idempotency_key` / `collect_idempotency_key` or equivalent)
- RBAC: `PermissionMatrix` add `fulfilment.pickup`; list/show auth pick|ship|**pickup**
- Continuity: pack → processing (7.2); ship HD only (7.3)

## Tasks & Acceptance

**Execution:**
- [x] Staff ready-for-pickup + collect APIs + RBAC `fulfilment.pickup` + matrix tests
- [x] Status transitions + idempotency; list includes RFP; exclude delivered by default
- [x] Admin Fulfilment UI for pickup/C&C; order number prominent
- [x] README; sprint `7-4-store-pickup` → review; mark **epic-7 done** on ship
- [x] Isolation: HD rejected; portal token rejected; wrong store rejected

**Acceptance Criteria:**
- [x] Given a packed store_pickup or click_collect order, when staff mark ready for pickup, then status becomes `ready_for_pickup` and the portal timeline updates.
- [x] Given a ready_for_pickup order, when staff mark collected, then status becomes `delivered`.
- [x] Given a home_delivery order, when staff attempt ready/collect, then the API rejects.
- [x] Given Idempotency-Key replay, when retried, then no duplicate side effects.

## Spec Change Log

- Implemented staff `POST …/ready` and `…/collect` with `fulfilment.pickup` + Idempotency-Key on order columns; Admin Ready/Collect UI with prominent order number; no ledger/shipment; HD ready/collect rejected; list includes `ready_for_pickup`, excludes delivered.
- Review patches: ready replay only for `ready_for_pickup` (not delivered); Admin mode-gated actions + Collected label; `collected_at`; unpaid/cancelled/ready-after-collect tests; ship rejected at RFP.
## Design Notes

**Counter UX:** Detail panel shows order number + customer name/phone if already on payload — staff matches customer to order ID.

**Nav auth:** Fulfilment visible if pick | ship | pickup.

## Verification

**Commands:**
- `cd backend && php artisan test --filter='Fulfilment|Portal|Permission'` -- expected: pickup matrix pass
- `cd frontend/admin && npm run build` -- expected: pass

**Manual checks:**
- Pack pickup order → Ready for pickup → portal RFP → Collect → Delivered/Collected
- HD order has no Ready/Collect actions

## Suggested Review Order

**Pickup handoff**

- Ready → ready_for_pickup; collect → delivered
  [`PortalFulfilmentService.php:1`](../../backend/Modules/CustomerPortal/app/Services/PortalFulfilmentService.php#L1)

- Staff ready/collect routes + fulfilment.pickup
  [`StaffFulfilmentController.php:1`](../../backend/Modules/CustomerPortal/app/Http/Controllers/Api/StaffFulfilmentController.php#L1)

**Admin SPA**

- Ready for pickup / Mark collected + order number
  [`FulfilmentView.vue:1`](../../frontend/admin/src/views/FulfilmentView.vue#L1)

**Tests**

- Pickup matrix + HD rejection + isolation
  [`FulfilmentPickupApiTest.php:124`](../../backend/tests/Feature/FulfilmentPickupApiTest.php#L124)
