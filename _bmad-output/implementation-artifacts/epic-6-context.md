# Epic 6 Context: Customer Portal

<!-- Compiled from planning artifacts. Edit freely. Regenerate with compile-epic-context if planning docs change. -->

## Goal

Ship a separate customer-facing Vue SPA so end customers can register, browse a sellable catalogue, checkout online (delivery / pickup / click & collect), pay, track orders, download invoices, and request returns — realizing UJ-5 (browse → cart → reserve → pay → track → collect). This is Phase 7 / post-MVP; it must not grant access to admin or POS APIs. **Gap:** numbered FR-67–FR-74 bodies are referenced by the PRD/epics but were never written into the PRD or addendum; requirements below are distilled from UJ-5, the customer-portal capability list in planning sources, architecture, and UX shop mockups.

## Stories

- Story 6.1: Customer registration and auth
- Story 6.2: Product catalogue and search
- Story 6.3: Cart and checkout
- Story 6.4: Order tracking and invoices
- Story 6.5: Returns request flow

## Requirements & Constraints

- Customers can register, log in, verify email, and manage profile and delivery addresses.
- Catalogue: browse, search, filter, and view product detail (price, store stock signal); add lines to a cart and checkout.
- Checkout resolves fulfilling store, available inventory, tax, discounts/promotions, delivery fee, and payment status; fulfilment modes: home delivery, store pickup, click & collect.
- Place order reserves stock appropriately and avoids uncontrolled oversell; payment uses the shared payment abstraction (Stripe / Paystack; card via provider).
- Customers can view and track their orders, see delivery/collection status, view payments, download invoices, request returns, and view refunds.
- Order lifecycle statuses include at least: Pending, Confirmed, Processing, Ready for Pickup, Out for Delivery, Delivered, Cancelled, Returned, Refunded.
- Customer role is isolated from the admin API surface; rate-limit public/portal endpoints.
- Money is DECIMAL only; inventory quantity changes only via InventoryLedgerService; sale/order money-stock side effects stay atomic where they touch sales/inventory.
- Staff-side customer CRUD and history already exist for POS; portal auth is a distinct customer identity surface, not staff login.
- Self-service proforma / quote request is optional later — not required for this epic’s core path.

## Technical Decisions

- **Client:** new Vue 3 SPA under `frontend/customer-portal/` (Sanctum Bearer, TypeScript, Tailwind, Vite) consuming `/api/v1` — API-first, no Inertia (AD-1). Same DESIGN tokens as admin/POS; refine shop visual identity before/during build.
- **Backend module:** CustomerPortal (governed by AD-1, AD-4). Domain logic stays in named services — never only in Vue.
- **Auth / security:** separate guard and token ability set for portal customers; Policies/Gates on every sensitive endpoint; **no admin route access**. Customer (CU) role exists for Phase 7; matrix currently has no CU permission column — define portal abilities when implementing (own orders, profile, returns request).
- **Store context:** store selection for fulfilment/pickup is explicit in checkout (not staff `X-Store-Id` semantics). Org/store boundaries still enforced server-side.
- **Payments:** PaymentService adapters `stripe` | `paystack` (and card via provider); webhook signature verify + idempotency (AD-10). Prefer same stack as POS rather than a parallel gateway.
- **Pricing:** resolve via existing pricing/promotion/tax services (online price list where configured); server recomputes totals — never trust client totals.
- **Data:** reuse `customers`, `customer_addresses`; introduce online order aggregates (`orders` / `order_items` and related) as needed. Stock **reservation** and pick/pack/ship workflows are Epic 7 — checkout may create the hold/order that fulfilment later processes; do not build full fulfilment UI here.
- **API:** no dedicated portal route table yet in api-design — add versioned portal-scoped resources (auth, catalogue read, cart/checkout, orders, invoices, return requests) under `/api/v1` with pagination/error conventions. Idempotency-Key on money/stock-creating POSTs.
- **Deferred:** gift cards; dedicated search engine (DB search OK); WebSockets (nice-to-have for live order status later).

## UX & Interaction Patterns

- Journey UJ-5: catalogue → product → cart → checkout (fulfilment + pay) → order tracking (status timeline through Ready for pickup / collected).
- Composition references (same DESIGN tokens; refine before build):  
  `_bmad-output/planning-artifacts/ux-designs/ux-retail-soln-2026-08-24/mockups/shop-catalogue.html`,  
  `shop-product.html`, `shop-cart.html`, `shop-checkout.html`, `shop-order.html`  
  (gallery: `mockups/index.html` · Customer online store · Phase 7).
- Chrome: brand logo, search, Account / Orders / Cart. Product detail shows price, stock store, qty stepper, add-to-cart; checkout panels for fulfilment mode, collecting store, Paystack/Stripe; order view shows status steps, download invoice, request return.
- Mobile-usable shop (unlike POS tablet-first); WCAG 2.2 AA; spine/DESIGN wins on conflict with mockups.

## Cross-Story Dependencies

- **Requires Epics 1–4:** org/stores, catalog, pricing/tax, InventoryLedgerService, PaymentService, customer records, returns/refund domain concepts. Epic 5 offline POS is not a hard prerequisite for portal.
- Within epic: registration/auth (6.1) before checkout identity; catalogue/search (6.2) before cart (6.3); checkout/order create (6.3) before tracking/invoices (6.4) and return requests (6.5).
- **Hands off to Epic 7:** stock reservation service depth, picking/packing, shipment/delivery tracking ops, store pickup staff workflow — portal shows status and collection info; fulfilment ops live in Epic 7.
- **Blocks / open:** numbered FR-67–FR-74 text missing from PRD/addendum — confirm acceptance criteria from this context + story specs; portal-specific permission strings and CU matrix column not yet defined.
