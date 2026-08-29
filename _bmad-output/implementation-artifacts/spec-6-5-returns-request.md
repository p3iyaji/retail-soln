---
title: 'Story 6.5: Returns request flow'
type: 'feature'
created: '2026-08-29'
status: 'done'
baseline_commit: '1528184fa13b115a6d95968bef348376faee93e2'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-6-context.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-retail-soln-2026-08-24/mockups/shop-order.html'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Customers can track portal orders but cannot request a return. The Order detail “Request return” control is a stub, and POS `SaleReturn` posting is staff-only and sale-bound — unsuitable for portal orders.

**Approach:** Add portal-owned **return request** create/list/show (lines + reason + notes) for eligible own orders, wire the SPA from Order detail, and surface request status on the order. Do **not** post inventory or payment refunds via `SaleReturnService`; staff fulfilment of approved requests is later. Minimal “refunds” visibility = request status (and placeholder if no completed refund yet).

## Boundaries & Constraints

**Always:**
- APIs under `/api/v1/portal/return-requests*` (and/or nested under orders), `auth:sanctum` + `portal` + verified; own-account only; rate-limit; **Idempotency-Key** on create.
- New tables e.g. `portal_return_requests` + items linked to `portal_orders` / `portal_order_items` — not `sale_returns`.
- Reason codes align with POS vocabulary where practical (`defective|wrong_item|changed_mind|other`).
- Eligibility: own order; `payment_status=paid`; status `delivered` (not `cancelled|returned|refunded`); no open request in `requested|under_review`; line qtys ≤ remaining returnable on that order.
- Create → status `requested` (or `under_review`); list/show own requests; order detail exposes return eligibility + linked request summary.
- SPA: enable Request return on eligible orders; form (lines/qty/reason/notes); list/detail of requests; show status on order detail.
- DECIMAL only if money shown; **no** InventoryLedgerService / PaymentService refund posts in this story.

**Ask First:**
- Allow return request from `confirmed`/`processing`/… before delivered (default: **delivered only**).
- Staff Admin approve/reject API in this story (default: **no** — requests stay `requested`/`under_review` until a later staff tool).

**Never:**
- Calling `SaleReturnService` / POS return endpoints from portal.
- Auto-mutating order to `returned`/`refunded` or posting stock/money on request create.
- Letting customers see or mutate other accounts’ requests.
- Full staff pick/pack or gateway refund capture (Epic 7 / payments hardening).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Create eligible | Paid delivered order + lines + reason + Idempotency-Key | Request `requested`; link on order | Duplicate key → same request |
| Create ineligible | Unpaid / not delivered / terminal | Rejected | 422 |
| Duplicate open request | Existing requested/under_review | Rejected | 422 |
| Over-qty line | Qty &gt; ordered (or already requested) | Rejected | 422 |
| List/show own | Verified account | Own requests only | Other id → 404 |
| Guest / staff token | No portal / staff Sanctum | 401/403 | N/A |

</frozen-after-approval>

## Code Map

- Portal orders: `PortalOrder`, `PortalOrderItem`, `PortalOrderController`, `OrderDetailView.vue` return stub, `PortalOrderStatuses`
- Do **not** call: `SaleReturnService`, `PosReturnController`, `sale_returns` — reason enum reference only (`SaleReturnReason`)
- New: CustomerPortal models/migrations/services/controllers for return requests; routes beside portal orders
- SPA: return request form/modal or `/shop/orders/:id/return`; optional `/shop/returns` list; enable OrderDetail CTA
- Continuity 6.4: invoice/timeline unchanged; epic-6-context “request returns / view refunds” → status visibility this story
- Mockup: `shop-order.html` Request return action

## Tasks & Acceptance

**Execution:**
- [x] Portal return-request API (create/list/show) + eligibility rules + idempotency + feature tests for matrix
- [x] Migrations/models for requests + items; order detail payload flags (eligible / active request)
- [x] SPA: wire Request return, submit form, show request status; thin returns list if useful
- [x] README; sprint `6-5-returns-request` → review; mark epic-6 done when story ships
- [x] Isolation tests (staff/guest/other account)

**Acceptance Criteria:**
- [x] Given a paid delivered order, when the customer submits a valid return request, then a portal return request is stored and visible on list/detail.
- [x] Given an ineligible order (unpaid, not delivered, or open request), when they submit, then the API rejects without creating a request.
- [x] Given Idempotency-Key reuse with the same body, when retried, then the same request is returned.
- [x] Given this story ships, when a request is created, then inventory and payment balances are unchanged (no SaleReturn post).

## Spec Change Log

## Design Notes

**Refunds view:** Order/request UI shows request status (`requested`, …). Do not invent refund money rows until a later staff completion path exists — empty/“No refunds yet” is fine.

**Qty remaining:** Sum prior request item qtys in non-rejected requests against ordered qty.

## Verification

**Commands:**
- `cd backend && php artisan test --filter=Portal` -- expected: prior + return-request matrix pass
- `cd frontend/customer-portal && npm run test && npm run build` -- expected: pass

**Manual checks:**
- Delivered paid order → Request return → see status on order
- Undelivered / duplicate open request rejected; POS stock unchanged

## Suggested Review Order

**Return requests**

- Create/list/show + eligibility + Idempotency-Key
  [`PortalReturnRequestService.php:1`](../../backend/Modules/CustomerPortal/app/Services/PortalReturnRequestService.php#L1)

- Portal return-request routes (throttled)
  [`api.php:1`](../../backend/Modules/CustomerPortal/routes/api.php#L1)

- Order payload eligibility / active request flags
  [`PortalOrderPayloadBuilder.php:1`](../../backend/Modules/CustomerPortal/app/Services/PortalOrderPayloadBuilder.php#L1)

**SPA**

- Request form + returns list/detail + order CTA
  [`ReturnRequestView.vue:1`](../../frontend/customer-portal/src/views/ReturnRequestView.vue#L1)
  [`OrderDetailView.vue:1`](../../frontend/customer-portal/src/views/OrderDetailView.vue#L1)

**Tests**

- Eligibility matrix, idempotency, no inventory side effects
  [`PortalReturnRequestApiTest.php:98`](../../backend/tests/Feature/PortalReturnRequestApiTest.php#L98)
