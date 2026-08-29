---
title: 'Story 9.1: Security review and penetration test fixes'
type: 'feature'
created: '2026-08-29'
status: 'done'
baseline_commit: '7f1942e88632504458bff85689d8034d30430510'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-9-context.md'
  - '{project-root}/_bmad-output/planning-artifacts/architecture/architecture-retail-soln-2026-08-24/security-design.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Several server-side security controls from the design are missing or bypassable today — inventory endpoints ignore store assignment, API/webhook rate limits are absent, transport headers are unset, Stripe webhook verification has a secret-as-signature fallback, and purchasing mutations lack mandatory idempotency keys.

**Approach:** Close exploitable, code-only gaps in one pass: enforce store scope on inventory/purchasing reads, add global API throttling plus webhook limits, add security-headers middleware and production HTTPS scheme forcing, harden Stripe webhook verification, validate CSV MIME types, require idempotency keys on money-moving purchasing endpoints, and lock the `Gate::before` invariant with regression tests.

## Boundaries & Constraints

**Always:**
- Reuse `ResolvesPosStore` / `ResolvesReportScope` pattern (`canAccessStore` + org store exists check); extract shared trait if needed (e.g. `ResolvesStoreScope`).
- Inventory: `InventoryController`, `StockMutationController`, `StockTransferController` must 403 when caller lacks access to requested `storeId` / `fromStoreId` / `toStoreId`; unfiltered list endpoints must intersect with `accessibleStoreIds()`.
- Purchasing reads: `PurchaseOrderController` and `ProformaInvoiceController` store filters must intersect with accessible stores.
- `bootstrap/app.php`: enable `throttleApi()`; add dedicated throttle for unauthenticated payment webhooks in `routes/api.php`.
- New `SecurityHeaders` middleware (HSTS when `APP_ENV=production`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`); register on API group; production `URL::forceScheme('https')` + trust proxies.
- Stripe: remove secret-as-header fallback in `validStripeSignature`; enforce timestamp tolerance (e.g. 300s); reject malformed headers; keep fail-closed in production when secret unset.
- CSV uploads: add `mimes:csv,txt` (or `mimetypes:text/csv,text/plain`) on product and supplier import endpoints.
- Purchasing mutations: `GoodsReceiptController`, `SupplierInvoiceController`, `SupplierPaymentController`, `PurchaseReturnController` return 422 when `Idempotency-Key` header missing (match `PosSaleController` pattern).
- Tests: extend `StoreScopeSecurityApiTest`; add `PaymentWebhookSecurityTest`; header/throttle/idempotency/MIME cases in existing feature tests.
- No inventory quantity changes outside `InventoryLedgerService` (AD-2).

**Ask First:**
- User lifecycle hardening (`is_active`, token revocation, role-hierarchy guards) — default **defer to 9.2**.
- Mandatory 2FA enrollment for privileged roles — default **defer to 9.2**.
- Webhook payload retention/pruning — default **defer to 9.4**.
- Remove dead nWidart Blade `web.php` scaffolding — default **defer** (low priority).
- Multi-tenant `users` table scoping — default **defer** (architectural).

**Never:**
- Schema migrations or Admin SPA changes in this story.
- Live pen-test vendor integration or Sentry (9.3).
- Weakening existing portal IDOR protections or payment redirect allowlist.
- Storing secrets in repo.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Cross-store inventory read | Staff assigned Store A, `X-Store-Id` or `storeId` = Store B | 403 | No data leak |
| Cross-store adjustment | Same | 403 | No ledger mutation |
| Unfiltered PO list | Store manager Store A only | Only Store A rows | Never all org stores |
| Forged Stripe webhook | Bad/missing signature | 4xx, no payment state change | Logged, not queued |
| Stripe replay | Valid sig, timestamp > tolerance | Rejected | No duplicate paid |
| Missing idempotency key | POST goods receipt / supplier payment | 422 | No duplicate write |
| Non-CSV import | `.exe` upload | 422 validation error | File not stored |
| API flood | Rapid `/api/v1/*` requests | 429 after limit | Health still reachable |

</frozen-after-approval>

## Code Map

- `backend/Modules/Sales/app/Http/Concerns/ResolvesPosStore.php` — canonical `X-Store-Id` + `canAccessStore` pattern to reuse
- `backend/Modules/Reporting/app/Http/Concerns/ResolvesReportScope.php` — alternate scope resolver for reports
- `backend/Modules/Inventory/app/Http/Controllers/Api/InventoryController.php` — `balances`, `movements`, `lowStockAlerts` lack store access checks
- `backend/Modules/Inventory/app/Http/Controllers/Api/StockMutationController.php` — `storeAdjustment`, `storeCount`, `indexCounts`
- `backend/Modules/Inventory/app/Http/Controllers/Api/StockTransferController.php` — `index`, `store`, `receive`
- `backend/Modules/Purchasing/app/Http/Controllers/Api/PurchaseOrderController.php` — unscoped `storeId` filter
- `backend/Modules/Sales/app/Http/Controllers/Api/ProformaInvoiceController.php` — same read scoping gap
- `backend/Modules/Sales/app/Http/Controllers/Api/PosSaleController.php` — idempotency-key 422 pattern to copy
- `backend/bootstrap/app.php` — add `throttleApi()`, trust proxies, middleware registration
- `backend/routes/api.php` — payment webhook routes (unauthenticated)
- `backend/app/Services/Payments/Adapters/StripePaymentAdapter.php` — `validStripeSignature` fallback at L113–114
- `backend/Modules/Products/app/Http/Controllers/Api/ProductCatalogToolsController.php` — CSV import validation
- `backend/Modules/Purchasing/app/Http/Controllers/Api/SupplierCsvController.php` — CSV import validation
- `backend/Modules/Authentication/app/Providers/AuthenticationServiceProvider.php` — `Gate::before` dotted-ability bypass
- `backend/tests/Feature/StoreScopeSecurityApiTest.php` — extend with inventory + purchasing cases
- New: `backend/tests/Feature/PaymentWebhookSecurityTest.php`
- New: `backend/app/Http/Middleware/SecurityHeaders.php` (or `Modules/...` under app)

## Tasks & Acceptance

**Execution:**
- [x] `backend/app/Http/Concerns/ResolvesStoreScope.php` (shared) -- extract store-scope helper -- DRY for inventory/purchasing controllers
- [x] Inventory API controllers -- enforce accessible store on all store-scoped reads/mutations -- closes HIGH-1
- [x] `PurchaseOrderController.php`, `ProformaInvoiceController.php` -- intersect store filters with `accessibleStoreIds()` -- closes LOW-11
- [x] `bootstrap/app.php`, `routes/api.php` -- `throttleApi()` + webhook throttle -- closes HIGH-2
- [x] `SecurityHeaders.php`, `bootstrap/app.php` -- headers + production HTTPS -- closes HIGH-3
- [x] `StripePaymentAdapter.php` -- remove fallback; add timestamp tolerance -- closes HIGH-4
- [x] Product/supplier CSV controllers -- MIME validation -- closes MEDIUM-7
- [x] Purchasing mutation controllers -- require `Idempotency-Key` -- closes MEDIUM-8
- [x] `StoreScopeSecurityApiTest.php`, `PaymentWebhookSecurityTest.php`, related tests -- regression coverage -- closes MEDIUM-9 + matrix
- [x] `docs/operational-manual.md` -- brief security/ops note if behaviour visible to admins -- optional thin update

**Acceptance Criteria:**
- Given a store manager assigned only to Store A, when they call any inventory balance/adjustment/transfer endpoint for Store B, then the API returns 403 and no ledger mutation occurs
- Given unauthenticated rapid webhook posts, when the throttle limit is exceeded, then the API returns 429
- Given production responses from `/api/v1/health`, when inspected, then security headers (HSTS, X-Frame-Options, nosniff, Referrer-Policy) are present
- Given a forged or replayed Stripe webhook, when processed, then verification fails and payment state is unchanged
- Given a purchasing goods-receipt POST without `Idempotency-Key`, when submitted, then the API returns 422
- Given a non-CSV file on product/supplier import, when uploaded, then validation rejects it before storage

## Verification

**Commands:**
- `cd backend && php artisan test --filter='StoreScopeSecurity|PaymentWebhook|GoodsReceipt|SupplierInvoice|ProductSearchImport|ApiV1Scaffold'` -- expected: all pass
- `cd backend && php artisan test` -- expected: full suite green

**Manual checks (if no CLI):**
- Confirm `curl -I` on health endpoint shows security headers in production config

**Result (2026-08-29):** `cd backend && php artisan test` — 503 passed / 3191 assertions (baseline before
this story was 475 passed). Pint clean on every file touched by this story.

## Design Notes

**Shared store scope.** The helper landed at `backend/app/Http/Concerns/ResolvesStoreScope.php` rather
than inside the Inventory module, because Purchasing and Sales controllers need it too. It exposes
`assertStoreAccessible()` (403 on an inaccessible `storeId`), `scopedStoreIds()` (`null` for org-wide
roles), `applyStoreColumnScope()` for `store_id` columns, and `applyLocationScope()` for the ledger's
polymorphic `location_type`/`location_id` pair. `ResolvesPosStore` and `ResolvesReportScope` were left
alone — they also resolve the `X-Store-Id` header contract, which these endpoints do not use.

**Warehouses are out of scope by design.** Warehouse locations have no `store_user` equivalent, so
`applyLocationScope()` only filters rows whose `location_type` is `store`; warehouse rows stay governed
by the `warehouses.*` permissions. Stock transfers use the stricter rule "hide any transfer that touches
a store the caller is not assigned to", which keeps warehouse↔warehouse transfers visible while blocking
cross-store leakage, and matches the create-side rule (both `fromStoreId` and `toStoreId` must be
accessible). `receive` asserts the same on both endpoints of the stored transfer.

**Throttling.** `throttleApi()` needs a named limiter, so `api` and `payment-webhooks` are registered in
`AppServiceProvider::boot()` and read their per-minute limits from the new `config/security.php`
(`API_RATE_LIMIT`, `PAYMENT_WEBHOOK_RATE_LIMIT`). `GET /api/v1/health` opts out via
`withoutMiddleware('throttle:api')` so liveness survives a flood, per the edge-case matrix. The `api`
limiter keys on user id when authenticated and client IP otherwise.

**Transport.** `SecurityHeaders` is appended to the `api` group and always sets `X-Frame-Options`,
`X-Content-Type-Options`, and `Referrer-Policy`; HSTS is production-only so local HTTP is not pinned in
browsers. `URL::forceScheme('https')` runs from `AppServiceProvider::boot()` in production, and
`trustProxies(at: '*')` is set literally in `bootstrap/app.php` — config is not yet loaded when the
middleware callback executes, and nginx is the only ingress in the v1 stack.

**Stripe.** `validStripeSignature()` no longer falls back to comparing the header against the raw
secret, rejects non-numeric/absent `t=`, and enforces a `payments.stripe.webhook_tolerance` window
(default 300s, `STRIPE_WEBHOOK_TOLERANCE`). Fail-closed behaviour in production with no configured
secret is unchanged. Paystack and manual adapters were already fail-closed and were not touched.

**Idempotency.** The four purchasing mutations now return 422 with
`Idempotency-Key header is required.`, matching `PosSaleController`. Admin SPA already sends a fresh
`crypto.randomUUID()` on goods receipts, purchase returns, supplier invoices, and supplier payments, so
no frontend change was needed (and none is permitted by this spec). Existing tests route through a new
`Tests\Concerns\PostsIdempotentPurchasing` trait that supplies a key unless the test provides one.

**Test fixtures.** Enforcing store scope invalidated fixtures that acted as an unassigned
`inventory_manager` / `procurement_officer`; those now attach the store under test, which is the correct
production shape. Roles and permission assertions are unchanged.

**Deferred / not done (unchanged from Boundaries, plus one new finding):**
- User lifecycle hardening, mandatory 2FA, webhook payload retention, dead Blade scaffolding, and
  multi-tenant `users` scoping remain deferred as the spec directs.
- **New finding:** `POST /api/v1/purchase-orders` (and proforma create) still accept any `storeId` in
  the caller's organization without a store-access check, so a procurement officer can create a PO for a
  store they cannot then list. This is the write-side twin of LOW-11 and is outside this spec's
  enumerated boundaries; recommend folding it into the next security pass.

## Suggested Review Order

**Store scope enforcement**

- Shared trait: 403 on inaccessible store, intersect unfiltered lists
  [`ResolvesStoreScope.php:20`](../../backend/app/Http/Concerns/ResolvesStoreScope.php#L20)

- Inventory reads/mutations wired to scope helpers
  [`InventoryController.php:1`](../../backend/Modules/Inventory/app/Http/Controllers/Api/InventoryController.php#L1)

- Transfer create/receive asserts both endpoints accessible
  [`StockTransferController.php:1`](../../backend/Modules/Inventory/app/Http/Controllers/Api/StockTransferController.php#L1)

**Transport and edge hardening**

- API throttle + security headers on every response
  [`bootstrap/app.php:15`](../../backend/bootstrap/app.php#L15)

- Health opts out so liveness survives floods
  [`api.php:8`](../../backend/routes/api.php#L8)

- Stripe signature: no secret fallback, replay window
  [`StripePaymentAdapter.php:100`](../../backend/app/Services/Payments/Adapters/StripePaymentAdapter.php#L100)

**Regression tests**

- Cross-store inventory + purchasing scope cases
  [`StoreScopeSecurityApiTest.php:1`](../../backend/tests/Feature/StoreScopeSecurityApiTest.php#L1)

- Webhook forgery, replay, throttle coverage
  [`PaymentWebhookSecurityTest.php:1`](../../backend/tests/Feature/PaymentWebhookSecurityTest.php#L1)

## Spec Change Log

- 2026-08-29 — Implemented as written. Only deviation: the shared trait lives at
  `app/Http/Concerns/ResolvesStoreScope.php` instead of the Inventory module path, which the task line
  allowed ("or shared"). Task list updated to match.
