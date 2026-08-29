---
title: 'Story 7.1: Stock reservation for online orders'
type: 'feature'
created: '2026-08-29'
status: 'done'
baseline_commit: '59b7fbfbf52b6b469149a0269290133ab8ce9661'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-7-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Portal checkout only *checks* available stock at place-order. Concurrent checkouts can oversell, and reserved inventory is never held in the ledger — Epic 6 deferred available→reserved to this story.

**Approach:** Introduce a StockReservationService that posts dual ledger movements (available↔reserved) via InventoryLedgerService, call it inside the portal place-order transaction (replace read-only check), and release reservations on cancel and unpaid expiry. Do not consume reserved stock on pay (that is pick/fulfil in 7.2).

## Boundaries & Constraints

**Always:**
- All qty changes via `InventoryLedgerService` only (AD-2); use `BalanceBucket::Reserved` and `MovementType::Reserve` / `Release` (or equivalent named types already in enums).
- Reserve at **place-order** in the same DB transaction as order create: lock balances, assert available ≥ qty, post −available / +reserved per line; reference portal order (+ item if needed); Idempotency-safe (no double-reserve on idempotent place replay).
- Hard-reject insufficient available (no AD-9 portal oversell / negative available).
- Release reserved→available when order is cancelled or unpaid reservation expires; idempotent release.
- Portal catalogue / POS available signals must not treat reserved as sellable (already available-only reads — verify).
- DECIMAL qty; store location = order `store_id` + store location type.
- No pick/pack/ship UI or reserved→sale consume in this story.

**Ask First:**
- Customer self-cancel while `awaiting_payment` (default: **yes** — thin portal cancel that releases stock).
- Unpaid TTL minutes (default: **config** e.g. 60 via `customer_portal.reservation_ttl_minutes` + scheduled command/job).

**Never:**
- Mutating balance columns outside the ledger service.
- Reserving only on payment success (pay keeps hold; does not create it).
- Consuming reserved into sale/shipment (7.2+).
- Applying POS offline oversell (AD-9) to portal orders.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Place with stock | Available ≥ lines | Order created; available↓ reserved↑ | N/A |
| Place oversell | Concurrent/insufficient available | No order; no partial reserve | 422 |
| Idempotent place replay | Same Idempotency-Key | Same order; no second reserve | N/A |
| Cancel awaiting_payment | Own unpaid order | Status cancelled; reserved released | Already cancelled → idempotent |
| Unpaid TTL expire | Past TTL, still awaiting | Cancel/expire + release | Paid orders untouched |
| Pay success | Reserved order | Status confirmed/paid; reserved **unchanged** | N/A |

</frozen-after-approval>

## Code Map

- Ledger: `Modules/Inventory/.../InventoryLedgerService`, `BalanceBucket`, `MovementType`, `PostMovementData`; dual-post pattern from `StockTransferService`
- Checkout: `PortalCheckoutService::placeOrder` / `assertAvailableStock` — replace with reserve; `markPaymentStatus` must not touch stock
- Models: `PortalOrder`, `PortalOrderItem` (`store_id`, `product_variant_id`, `quantity`)
- New: `StockReservationService` (Inventory or Fulfilment module); optional `portal_order_reservations` tracking table if needed for idempotency/audit; cancel API + expire command
- Continuity: epic-6 deferred “available→reserved”; epic-7-context reservation types
- Tests: extend Portal checkout tests + ledger unit/feature for reserve/release; concurrent place race if practical

## Tasks & Acceptance

**Execution:**
- [x] StockReservationService (reserve + release) via ledger dual-posts + tests
- [x] Hook reserve into portal place-order txn; idempotent with existing place key
- [x] Cancel (awaiting_payment) + unpaid TTL job/config; release on both
- [x] Confirm pay path does not release/consume; README + sprint `7-1-stock-reservation` → review
- [x] Feature tests for matrix (oversell, idempotent place, cancel release, expire, pay keeps reserved)

**Acceptance Criteria:**
- Given sufficient stock, when a portal order is placed, then line qty moves available→reserved in the ledger and the order exists.
- Given insufficient available, when place is attempted, then no order is created and balances are unchanged.
- Given an awaiting_payment order, when cancelled or TTL expires, then reserved qty returns to available.
- Given payment success, when status becomes paid/confirmed, then reserved qty is still held (not consumed).

## Spec Change Log

## Design Notes

**Idempotency:** Prefer movement UUIDs / reservation rows keyed by `portal_order_id` (+ variant) so place replay and cancel/expire are safe.

**Consume later:** 7.2 posts −reserved (`Sale` or dedicated fulfil type) at pick/ship — document handoff in README.

**SPA:** Optional cancel button on confirmation/order detail for unpaid orders; minimal if API-only is enough for tests — prefer thin portal cancel for Ask First default.

## Verification

**Commands:**
- `cd backend && php artisan test --filter=Portal` -- expected: checkout + reservation matrix pass
- `cd backend && php artisan test --filter=Inventory` (or reservation-specific) -- expected: reserve/release pass

**Manual checks:**
- Place order → reserved↑ available↓; pay → reserved unchanged; cancel unpaid → released

## Suggested Review Order

**Ledger dual-posts**

- Entry: available↔reserved via InventoryLedgerService only, UUID-idempotent
  [`StockReservationService.php:36`](../../backend/Modules/Inventory/app/Services/StockReservationService.php#L36)

- Release path mirrors reserve; skip when release UUID already exists
  [`StockReservationService.php:124`](../../backend/Modules/Inventory/app/Services/StockReservationService.php#L124)

**Place-order hold**

- Reserve inside the same place-order transaction after line create
  [`PortalCheckoutService.php:274`](../../backend/Modules/CustomerPortal/app/Services/PortalCheckoutService.php#L274)

- Pay path still only flips payment/status — no stock posts
  [`PortalCheckoutService.php:442`](../../backend/Modules/CustomerPortal/app/Services/PortalCheckoutService.php#L442)

**Cancel + TTL release**

- Customer cancel for unpaid pending; releases reserved
  [`PortalCheckoutService.php:339`](../../backend/Modules/CustomerPortal/app/Services/PortalCheckoutService.php#L339)

- Scheduled expire for awaiting_payment/failed past TTL
  [`PortalCheckoutService.php:373`](../../backend/Modules/CustomerPortal/app/Services/PortalCheckoutService.php#L373)

- HTTP cancel + every-minute schedule + TTL config
  [`PortalOrderController.php:56`](../../backend/Modules/CustomerPortal/app/Http/Controllers/Api/PortalOrderController.php#L56)
  [`ExpireUnpaidPortalReservationsCommand.php:8`](../../backend/Modules/CustomerPortal/app/Console/ExpireUnpaidPortalReservationsCommand.php#L8)
  [`console.php:11`](../../backend/routes/console.php#L11)
  [`config.php:51`](../../backend/Modules/CustomerPortal/config/config.php#L51)

**SPA + tests**

- Thin cancel button when `cancellable`
  [`OrderDetailView.vue:69`](../../frontend/customer-portal/src/views/OrderDetailView.vue#L69)

- Matrix coverage: place/oversell/idempotent/cancel/TTL/pay-keeps
  [`PortalStockReservationApiTest.php:98`](../../backend/tests/Feature/PortalStockReservationApiTest.php#L98)
  [`StockReservationServiceTest.php:70`](../../backend/tests/Feature/StockReservationServiceTest.php#L70)
