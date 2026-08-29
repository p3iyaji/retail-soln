---
title: 'Story 6.4: Order tracking and invoices'
type: 'feature'
created: '2026-08-29'
status: 'done'
baseline_commit: '3887bbb2b08ab51aedf0235d6e35397b0dc7ca49'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-6-context.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-retail-soln-2026-08-24/mockups/shop-order.html'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** After checkout, customers only see a thin confirmation. They cannot list orders, see a fulfilment status timeline, or download an invoice — blocking UJ-5 tracking and the Orders chrome.

**Approach:** Add portal order list + enriched detail (status timeline, payment, fulfilment, lines) for the owning verified account, generate a portal invoice download from `portal_orders` (not POS `Sale`/`Invoice`), and enable Orders nav in the customer-portal SPA. Returns remain stubbed for 6.5; staff fulfilment transitions stay Epic 7.

## Boundaries & Constraints

**Always:**
- APIs under `/api/v1/portal/orders*` with `auth:sanctum` + `portal` + verified email; own-account scope only; rate-limit; pagination on list.
- Expand/display order lifecycle toward: Pending, Confirmed, Processing, Ready for Pickup, Out for Delivery, Delivered, Cancelled, Returned, Refunded — map fulfilment-mode-aware timeline steps in the API payload.
- Detail includes items, totals (DECIMAL strings), payment status/provider ref, fulfilment mode, store/address summary, and timeline steps (completed / current / upcoming).
- Invoice: portal-owned document generated from the order (HTML download acceptable, proforma-style); available when payment is `paid` (or order past confirmed) — not a Sales `invoices.sale_id` row.
- SPA: enable Orders nav; `/shop/orders` list; `/shop/orders/:id` detail with timeline + Download invoice; link confirmation → order detail.
- Staff isolation; no admin/POS order mutation APIs for customers.

**Ask First:**
- Persist status transition history table (default: **no** — derive timeline from current `status` + timestamps; optional `paid_at` if missing).
- Staff-facing portal-order status updater in Admin (default: **no** in 6.4 — Epic 7; seed/demo may set advanced statuses in tests only).

**Never:**
- Request-return / refunds UI or APIs (6.5) — button stub/disabled OK.
- Pick/pack/ship staff workflows or live WebSocket status (Epic 7 / deferred).
- Reusing POS `Invoice`/`Sale` as the portal fiscal document without a clear portal link.
- Listing other customers’ orders.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| List own orders | Verified portal account | Paginated orders (newest first) with status/total/number | Empty → empty page |
| Show own order | Own order id | Detail + timeline + lines + payment | Other account → 404 |
| Invoice when paid | Own paid/confirmed order | Downloadable invoice document | Unpaid → 422/404 |
| Invoice unpaid | awaiting_payment | No invoice | 422 |
| Guest / unverified | No token / unverified | Rejected | 401/403 |
| Staff token | Staff Sanctum on portal orders | 401/403 | N/A |
| Unknown order | Random id | 404 | N/A |

</frozen-after-approval>

## Code Map

- Extend: `PortalOrder` / `PortalOrderItem`, `PortalCheckoutController::showOrder` + `orderPayload`, routes in `Modules/CustomerPortal/routes/api.php`
- New: `PortalOrderController` or expand checkout controller — `index`, enriched `show`, `invoice` (Blade view under CustomerPortal like `sales::proforma-document`)
- Status constants / timeline builder service; migration only if adding `paid_at` or enum checks
- Do not use: Sales `Invoice` model / sale_id PDF as primary; staff Proforma PDF is pattern-only
- SPA: `ShopLayout` Orders stub → link; views OrdersList + OrderDetail; `checkoutApi`/`ordersApi` list + invoice blob download; ConfirmationView deep-link to detail
- Mockup: `shop-order.html` (timeline + Download invoice + Request return stub)
- Continuity 6.3: confirmation `orderId`, payment `paid` → `confirmed`

## Tasks & Acceptance

**Execution:**
- [x] Portal orders list + enriched show (timeline) + invoice download API + ownership tests for matrix
- [x] Status/timeline mapping for fulfilment modes; `paid_at` if needed for display
- [x] SPA: Orders nav, list, detail timeline, invoice download, confirmation → detail; return button stub
- [x] README note; sprint `6-4-order-tracking-invoices` → review
- [x] Feature + SPA smoke tests as warranted

**Acceptance Criteria:**
- [x] Given a verified customer with orders, when they open Orders, then they see their orders only and can open detail with a status timeline.
- [x] Given a paid order, when they download invoice, then a portal invoice document is returned for that order.
- [x] Given an unpaid order, when they request invoice, then the API rejects.
- [x] Given another customer’s order id, when requested, then 404.
- [x] Given this story ships, when they click Request return, then it remains stubbed (6.5).

## Spec Change Log

## Design Notes

**Timeline:** Ordered steps vary slightly by fulfilment (pickup/C&C emphasize Ready for Pickup; delivery emphasizes Out for Delivery / Delivered). Mark steps ≤ current status complete. Without Epic 7 mutators, most live orders stay Pending→Confirmed unless tests set further statuses.

**Invoice content:** Org name, order number, customer, lines, tax, delivery fee, total, payment ref — DECIMAL formatted; filename `invoice-{order_number}.html` (or PDF if library already present — prefer HTML parity with proforma).

## Verification

**Commands:**
- `cd backend && php artisan test --filter=Portal` -- expected: prior + order list/invoice matrix pass
- `cd frontend/customer-portal && npm run test && npm run build` -- expected: pass

**Manual checks:**
- Place/pay order → Orders list → detail timeline → download invoice
- Unpaid order has no invoice; other account 404

## Suggested Review Order

**Order tracking API**

- List / show / invoice routes (own-account scoped)
  [`api.php:42`](../../backend/Modules/CustomerPortal/routes/api.php#L42)

- Enriched payload + timeline builder
  [`PortalOrderPayloadBuilder.php:1`](../../backend/Modules/CustomerPortal/app/Services/PortalOrderPayloadBuilder.php#L1)
  [`PortalOrderTimelineService.php:1`](../../backend/Modules/CustomerPortal/app/Services/PortalOrderTimelineService.php#L1)

- HTML invoice from portal order
  [`PortalOrderController.php:1`](../../backend/Modules/CustomerPortal/app/Http/Controllers/Api/PortalOrderController.php#L1)

**SPA**

- Orders list + detail timeline + invoice download
  [`OrdersListView.vue:1`](../../frontend/customer-portal/src/views/OrdersListView.vue#L1)
  [`OrderDetailView.vue:1`](../../frontend/customer-portal/src/views/OrderDetailView.vue#L1)

**Tests**

- Ownership, invoice gating, terminal timeline
  [`PortalOrderTrackingApiTest.php:95`](../../backend/tests/Feature/PortalOrderTrackingApiTest.php#L95)
