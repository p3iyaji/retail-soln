---
title: 'Story 6.3: Cart and checkout'
type: 'feature'
created: '2026-08-29'
status: 'done'
baseline_commit: '1c8276b8bdf1c4e52921489a49614c0d2c7d8009'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-6-context.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-retail-soln-2026-08-24/mockups/shop-cart.html'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-retail-soln-2026-08-24/mockups/shop-checkout.html'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Portal shoppers can browse products but cannot add lines to a cart, choose fulfilment, or place a paid online order — UJ-5 stops before purchase.

**Approach:** Server-side portal cart + checkout that creates a first-class online **order** (not a POS `Sale`), recomputes totals via PricingService, checks available stock, initiates payment through a new shared PaymentService (manual/test + Stripe/Paystack adapters), and returns a confirmation handoff for 6.4 tracking. No order-list/invoice/returns UI.

## Boundaries & Constraints

**Always:**
- Cart/checkout APIs under `/api/v1/portal/*`, `auth:sanctum` + `portal`, verified email required; rate-limit; **Idempotency-Key** on place-order / payment-intent POSTs.
- Server recomputes line prices, tax, delivery fee, and grand total — never trust client money fields (DECIMAL only).
- New portal order aggregates (`portal_orders` / `portal_order_items` or equivalent) owned by CustomerPortal; do **not** complete via POS `SaleCompletionService` / cashier session.
- Fulfilment modes: `home_delivery` | `click_collect` | `store_pickup`; delivery uses own `customer_addresses`; pickup/C&C require org store selection via `PortalStoreResolver` rules.
- At place-order: reject if insufficient **available** qty at fulfilling store (no uncontrolled oversell); do **not** post ledger reservation yet (Epic 7.1) — order starts `pending` / `awaiting_payment`.
- PaymentService abstraction (AD-10): adapters `manual` (dev/test confirm), `stripe`, `paystack`; webhook endpoints verify signatures and are idempotent (stubs OK if live keys absent).
- SPA: wire Add to cart; `/shop/cart`, `/shop/checkout`; Cart chrome + badge; minimal confirmation (order id / status) — not full tracking timeline.
- Staff isolation unchanged; portal cannot call POS sales or staff payment admin routes.

**Ask First:**
- Guest cart without login (default: **no** — login+verify required before cart mutations).
- Ledger `available→reserved` on place-order (default: **defer to Epic 7**; stock **check** only in 6.3).

**Never:**
- Order list, status timeline, invoice PDF, returns (6.4–6.5).
- Full pick/pack/ship staff UI (Epic 7).
- Completing portal checkout as a POS sale with cashier session.
- Gift cards; trusting client totals; mutating inventory outside InventoryLedgerService (and no ledger writes required for this story’s default).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Add to cart | Auth + variantId + qty | Cart line upserted; totals refreshed | Inactive/foreign variant → 404/422 |
| Update/remove line | Auth + line id / qty | Cart updated | Qty &lt; 1 → remove or 422 |
| Checkout preview | Cart + fulfilment + store/address | Server totals (items, tax, delivery fee, pay) | Empty cart → 422 |
| Place order OK | Valid cart + Idempotency-Key + provider | Order pending/awaiting_payment + payment intent/redirect or manual confirm path | Duplicate key → same order |
| Insufficient stock | Qty &gt; available at store | No order created | 422 stock |
| Unverified / guest | No portal token or unverified | Cart/checkout rejected | 401/403 |
| Payment webhook replay | Same provider event twice | Idempotent; order payment status stable | N/A |
| Staff token on portal cart | Staff Sanctum | 401/403 | N/A |

</frozen-after-approval>

## Code Map

- Portal: `Modules/CustomerPortal/routes/api.php`, auth middleware, `PortalStoreResolver`, `PortalCatalogueService` / `PricingService::resolve`, addresses via `PortalAccountService`
- Do **not** use: `SaleCompletionService`, `PosSaleController`, cashier sessions — POS `Sale` hold has no inventory
- Inventory: read `InventoryBalance` available for stock check; `InventoryLedgerService` reserved path = Epic 7 (out of default scope)
- New: Payments module or `app/Services/Payments/PaymentService` + Stripe/Paystack/Manual adapters; webhook controllers; portal cart + order models/migrations/services
- Continuity 6.2: `frontend/customer-portal` ShopLayout Cart stub, ProductDetailView Add to cart stub, catalogue `variants[].id` / `primaryVariantId`
- Mockups: `shop-cart.html`, `shop-checkout.html`
- Spine AD-2 / AD-10; epic-6-context order statuses + Idempotency-Key

## Tasks & Acceptance

**Execution:**
- [x] Portal cart API (add/update/remove/get) + checkout preview/place-order services + order tables + feature tests for matrix
- [x] PaymentService + manual/stripe/paystack adapters + webhook stubs (signature + idempotency) + config/`.env.example`
- [x] SPA: cart store/pages, checkout fulfilment+payment, enable Cart chrome + PDP add-to-cart, confirmation handoff
- [x] README: cart/checkout + payment test/manual provider; sprint `6-3-cart-checkout` → review
- [x] Isolation + stock + idempotency tests; SPA smoke as warranted

**Acceptance Criteria:**
- [x] Given a verified portal customer, when they add catalogue variants to the cart and checkout with a fulfilment mode, then an online order is created with server-computed totals.
- [x] Given insufficient available stock at the fulfilling store, when they place the order, then the API rejects without creating a paid/confirmed order.
- [x] Given a place-order with Idempotency-Key, when retried, then the same order is returned (no duplicate).
- [x] Given payment provider webhook/manual confirm, when payment succeeds, then order payment status updates idempotently.
- [x] Given this story ships, when browsing Orders nav / invoice / returns, then those remain absent or stubs (6.4+).

## Spec Change Log

## Design Notes

**Cart persistence:** Server rows keyed by `customer_account_id` (or customer_id); lines store `product_variant_id` + qty; reprice on read/checkout.

**Delivery fee:** Configurable flat fee or zero MVP (`customer_portal.delivery_fee`); document; refine later.

**Providers:** Without live keys, `manual` confirms in local/testing; Stripe/Paystack return placeholder redirect URLs or fail closed with clear 503 if misconfigured in production.

**Confirmation:** SPA page or route query with `orderId` / number — detail timeline is 6.4.

## Verification

**Commands:**
- `cd backend && php artisan test --filter=Portal` -- expected: auth + catalogue + cart/checkout/payment matrix pass
- `cd frontend/customer-portal && npm run test && npm run build` -- expected: pass

**Manual checks:**
- Login → add to cart → checkout delivery/pickup → place order (manual) → see confirmation id
- Oversell qty rejected; duplicate Idempotency-Key safe
- Portal token still blocked from POS `/pos/sales`

## Suggested Review Order

**Checkout & payments**

- Place-order with stock check, fingerprint, payment intent
  [`PortalCheckoutService.php:94`](../../backend/Modules/CustomerPortal/app/Services/PortalCheckoutService.php#L94)

- PaymentService adapters + webhook apply
  [`PaymentService.php:16`](../../backend/app/Services/Payments/PaymentService.php#L16)

- Verified portal cart/checkout routes
  [`api.php:42`](../../backend/Modules/CustomerPortal/routes/api.php#L42)

**Cart**

- Server cart lines + reprice / inactive cleanup
  [`PortalCartService.php:16`](../../backend/Modules/CustomerPortal/app/Services/PortalCartService.php#L16)

**SPA**

- Cart / checkout / confirmation + chrome badge
  [`cart.ts:1`](../../frontend/customer-portal/src/stores/cart.ts#L1)
  [`CheckoutView.vue:1`](../../frontend/customer-portal/src/views/CheckoutView.vue#L1)

**Tests**

- Cart, place, oversell, idempotency, webhooks, ownership
  [`PortalCartCheckoutApiTest.php:94`](../../backend/tests/Feature/PortalCartCheckoutApiTest.php#L94)
