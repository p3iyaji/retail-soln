# Epic 7 Context: Order Fulfilment

<!-- Compiled from planning artifacts. Edit freely. Regenerate with compile-epic-context if planning docs change. -->

## Goal

Deliver Phase 8 staff-side fulfilment so online orders from Epic 6 can reserve stock, be picked and packed, shipped or handed over at store pickup, and progress through delivery/collection tracking — completing the ops half of UJ-5 (reserve → pay → track → collect). This is post-MVP and depends on customer-portal orders. **Gap:** numbered FR-75–FR-76 bodies are referenced by the epics map but were never written into the PRD or addendum; requirements below are distilled from Phase 8 capability lists, glossary (stock reservation), inventory/ledger design, deferred schema notes, Epic 6 handoff, and shop status mockups.

## Stories

- Story 7.1: Stock reservation for online orders
- Story 7.2: Picking and packing workflow
- Story 7.3: Shipment and delivery tracking
- Story 7.4: Store pickup workflow

## Requirements & Constraints

- Online order placement holds stock so sellable qty cannot freely oversell; holds release or convert when cancelled, expired, or fulfilled.
- Staff can pick and pack fulfilment lines for a store/warehouse against an order.
- Home-delivery path: create shipment, track delivery status through to delivered (carrier detail tables in scope for this epic).
- Store pickup / click & collect: staff mark ready for pickup and complete collection at the counter (order ID / customer present).
- Order lifecycle statuses already expected by the portal include at least: Pending, Confirmed, Processing, Ready for Pickup, Out for Delivery, Delivered, Cancelled, Returned, Refunded — fulfilment ops must drive the ops-relevant transitions consistently with what customers see.
- Inventory quantity changes only via InventoryLedgerService; balances expose available, reserved, damaged, in_transit buckets; money remains DECIMAL.
- Reservation and release are first-class ledger movement types (`reservation`, `reservation_release`), not silent qty edits.
- Distinguish online-order reservation from optional POS layaway (reserve-until-paid org policy on installments) — different product path.
- Store-scoped authorization: fulfilment work is limited to the user’s assigned stores; Policies enforce server-side.
- Portal customers track status and request returns; they do not run pick/pack/ship tools.

## Technical Decisions

- **Domain:** treat order fulfilment as its own domain (aggregates around `orders` / `order_items`, `order_fulfilments`, `shipments`, `deliveries`) communicating with Inventory via ledger services — not logic only in Vue or controllers (AD-6, AD-8).
- **Inventory (AD-2):** every reserve, release, pick-to-ship, or sale conversion posts an `inventory_movement`; never mutate balance columns directly. Reserved bucket reduces what POS/portal treat as available to sell.
- **Schema:** add full shipment/carrier-related tables in this epic (explicitly deferred until Epic 7 in database design). Reuse portal order aggregates from Epic 6; extend with fulfilment/shipment/delivery records as needed.
- **API:** staff fulfilment endpoints under `/api/v1` (admin/store-manager SPA), versioned, paginated, store-scoped (`X-Store-Id` + Policies). Idempotency-Key on money/stock-mutating POSTs. No dedicated fulfilment route table yet — add with the stories.
- **Client:** staff workflows in admin (and/or store-manager) Vue SPA; customer status UI stays in `frontend/customer-portal/` (Epic 6). API-first, no Inertia (AD-1).
- **Side effects:** customer notifications for status changes are queued after commit (AD-7); rich notification productization is Epic 8 — wire events so Epic 8 can subscribe.
- **RBAC:** fulfilment permission strings and role maps are not yet in the matrix — define when implementing (inventory/store-manager style scopes).
- **Out of scope here:** customer auth/catalogue/checkout (Epic 6); executive reporting dashboards (Epic 8); gift cards.

## UX & Interaction Patterns

- Customer-facing timeline (portal): Confirmed (stock reserved) → Processing (picked & packed) → Ready for pickup / Out for delivery → collected or delivered — composition refs under `ux-designs/.../mockups/shop-order.html` (and checkout reserve copy on `shop-checkout.html`). Spine/DESIGN wins on conflict.
- Staff pick/pack/ship/pickup surfaces: no dedicated fulfilment mockups yet; follow admin DESIGN tokens, store-scoped lists, and clear status actions — keep the customer order ID visible for counter collection.
- Mobile-usable for counter pickup checks; WCAG 2.2 AA.

## Cross-Story Dependencies

- **Requires Epic 6:** online orders, checkout fulfilment mode (delivery / pickup / click & collect), and portal order tracking that consumes status updates from this epic. Epic 6 may create a thin hold/order; this epic owns reservation depth and ops workflows.
- **Requires Epics 1–4:** org/stores, catalog, InventoryLedgerService (reserved bucket), RBAC, DECIMAL money patterns.
- Within epic: reservation (7.1) before reliable pick/pack (7.2); pick/pack before shipment tracking (7.3) and ready-for-pickup handoff (7.4). Delivery vs pickup branch by order fulfilment mode.
- **Hands off to Epic 8:** notification channels (email/SMS/in-app) for status changes; dashboards that surface pending fulfilments.
- **Blocks / open:** numbered FR-75–FR-76 text missing from PRD/addendum — confirm acceptance criteria from this context + story specs; fulfilment permission matrix not yet defined; staff fulfilment UI mockups not authored.
