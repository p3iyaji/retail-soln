---
title: 'Story 7.3: Shipment and delivery tracking'
type: 'feature'
created: '2026-08-29'
status: 'done'
baseline_commit: 'a8279b7baf7011e8fa67f0ab37226ab314a841c4'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-7-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** After pack, home-delivery portal orders sit at `processing` with no staff path to create a shipment, mark out for delivery, or mark delivered — so the portal timeline never advances past Processing for delivery orders.

**Approach:** Extend staff fulfilment for **home_delivery** only: thin shipment record + APIs to ship (→ `out_for_delivery`) and deliver (→ `delivered`), plus Admin Fulfilment actions. No inventory ledger posts (stock already consumed at pack). Store pickup / click & collect remain 7.4.

## Boundaries & Constraints

**Always:**
- Staff APIs under `/api/v1/fulfilment/…`, Sanctum `staff`, `X-Store-Id` + assigned store; permission e.g. `fulfilment.ship` (seed matrix; pack stays on `fulfilment.pick`).
- Eligible: paid, packed (`processing` or later), `fulfilment_mode = home_delivery`, not cancelled/returned/refunded.
- Create/update thin shipment (carrier name, tracking number, shipped_at / delivered_at) — new `portal_shipments` table (1:1 or 1:n MVP **1:1** with order) or equivalent columns; Idempotency-Key on ship/deliver mutators.
- Status transitions: `processing` → `out_for_delivery` on ship; `out_for_delivery` → `delivered` on deliver. Portal timeline already defines these steps.
- Widen fulfilment list/show to include `out_for_delivery` (and optionally delivered for history).
- Admin SPA: ship + deliver actions for home_delivery; show address + carrier/tracking; no Ready for pickup / Collect.
- No InventoryLedgerService calls in this story’s ship/deliver path.

**Ask First:**
- Multi-parcel / multi-carrier (default: **single shipment per order**).
- Auto-ship on pack for delivery (default: **no** — explicit staff ship).

**Never:**
- `ready_for_pickup` / counter collect (7.4).
- Shipping pickup/C&C modes as home delivery.
- Re-consuming or releasing reserved stock on ship/deliver.
- Portal customer shipment-create tools; rich carrier integrations / webhooks.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Ship processing home_delivery | Staff + store + ship perm + Idempotency-Key + carrier/tracking optional | Shipment row; status `out_for_delivery` | Wrong mode → 422 |
| Deliver out_for_delivery | Staff + Idempotency-Key | Status `delivered`; delivered_at set | Not OFD → 422 |
| Idempotent ship/deliver replay | Same key | Same result; no duplicate status flip | Different key after done → 422 |
| Pickup mode ship | store_pickup / click_collect | Rejected | 422 |
| Wrong store / no perm / portal token | — | 403/404/401 | N/A |

</frozen-after-approval>

## Code Map

- Extend: `PortalFulfilmentService`, `StaffFulfilmentController`, fulfilment routes, `FulfilmentView.vue`
- Statuses: `PortalOrderStatuses::{OUT_FOR_DELIVERY, DELIVERED}`, `PortalOrderTimelineService` delivery path
- New: `portal_shipments` migration/model; RBAC `fulfilment.ship` in `PermissionMatrix`
- Continuity 7.2: pack → processing + reserved consumed; list filters need OFD
- Do not touch: `StockReservationService` consume/release for ship

## Tasks & Acceptance

**Execution:**
- [x] `portal_shipments` (+ ship/deliver fields) + staff ship/deliver APIs + RBAC `fulfilment.ship` + matrix tests
- [x] Status transitions + idempotency; widen list/show for OFD/delivered as needed
- [x] Admin Fulfilment UI: ship + deliver for home_delivery; show tracking
- [x] README; sprint `7-3-shipment-delivery` → review
- [x] Isolation: pickup rejected; portal token rejected; wrong store rejected

**Acceptance Criteria:**
- [x] Given a packed home_delivery order, when staff ship it, then status becomes `out_for_delivery` and shipment metadata is stored.
- [x] Given an out_for_delivery order, when staff mark delivered, then status becomes `delivered` and the portal timeline reflects it.
- [x] Given a pickup/C&C order, when staff attempt ship, then the API rejects.
- [x] Given Idempotency-Key replay, when ship/deliver is retried, then no duplicate side effects.

## Spec Change Log

- Implemented thin `portal_shipments` (1:1), staff `POST …/ship` and `…/deliver` with `fulfilment.ship` + Idempotency-Key; Admin Fulfilment ship/deliver UI; no ledger posts; pickup/C&C ship rejected.
- Review patches: list/show pick|ship; nav either perm; idempotent short-circuit requires paid+home_delivery and stored key; isDeliverable needs shipment; Admin 422 conflict refetch; exclude delivered from default list; expanded ship/deliver matrix tests.

## Design Notes

**Carrier fields:** Optional tracking number; carrier name free text MVP. Validate length; no external API.

**Permissions:** Users with only `fulfilment.pick` can pack but not ship unless also granted `fulfilment.ship` (SM/IM typically both).

## Verification

**Commands:**
- `cd backend && php artisan test --filter='Fulfilment|Portal|Permission'` -- expected: ship/deliver matrix pass
- `cd frontend/admin && npm run build` -- expected: pass

**Manual checks:**
- Pack home_delivery → Ship → portal OFD → Deliver → Delivered
- Pickup order has no ship action

## Suggested Review Order

**Ship / deliver**

- Ship → out_for_delivery; deliver → delivered (home_delivery only)
  [`PortalFulfilmentService.php:1`](../../backend/Modules/CustomerPortal/app/Services/PortalFulfilmentService.php#L1)

- Staff ship/deliver routes + fulfilment.ship
  [`StaffFulfilmentController.php:1`](../../backend/Modules/CustomerPortal/app/Http/Controllers/Api/StaffFulfilmentController.php#L1)

- Thin portal_shipments
  [`PortalShipment.php:1`](../../backend/Modules/CustomerPortal/app/Models/PortalShipment.php#L1)

**Admin SPA**

- Ship + Mark delivered on Fulfilment
  [`FulfilmentView.vue:1`](../../frontend/admin/src/views/FulfilmentView.vue#L1)

**Tests**

- Ship/deliver matrix + isolation
  [`FulfilmentShipApiTest.php:124`](../../backend/tests/Feature/FulfilmentShipApiTest.php#L124)
