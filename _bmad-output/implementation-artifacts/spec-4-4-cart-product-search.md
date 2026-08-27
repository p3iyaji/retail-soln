---
title: 'Story 4.4: Cart and product search in POS'
type: 'feature'
created: '2026-08-27'
status: 'done'
baseline_commit: 'e8b04a53f68b4b2c4e99331c35d62074b5a18c47'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiate">

## Intent

**Problem:** The POS floor has empty search/cart stubs, so cashiers cannot find variants or build a priced cart before payment (FR-12).

**Approach:** Wire POS search (barcode/name via `GET /products/search`), category chips, and a client-side cart that adds variants with qty, price, and tax from `POST /pricing/resolve`; support qty adjust and permission-gated price override. No sale completion or inventory post (4.5).

## Boundaries & Constraints

**Always:**
- Cart is **client-side** in Pinia until hold/sale stories; line prices come from `POST /pricing/resolve` for the selected store (never hard-code from catalog `variant.price` alone).
- Search uses `GET /products/search?q=&categoryId=&limit=` with `X-Store-Id`; show results grid; Enter adds first active variant hit; exact single barcode/SKU hit may auto-add then clear search.
- Each cart line stores: `variantId`, label/sku/barcode, `qty`, resolved `unitPrice` / `listPrice` / `discountAmount` / `tax` / `lineTotal` (DECIMAL strings), optional `priceOverride`.
- Qty stepper: increment/decrement; cannot go below 1 without remove; re-resolve on qty change (and after override clear).
- Price override UI only if user has `sales.price-override`; overridden lines skip promo re-apply for that unit (store override as unit price; tax via resolve with qty or TaxService-equivalent path—prefer resolve then replace unit if override set).
- Debounce typing search (~150–200ms); target online p95 &lt;200ms for search API (surface `meta.elapsedMs` in UI optionally).
- Keep F2 focus search; Pay remains stub/open panel only (no sale POST). Totals in cart rail = Σ line totals (DECIMAL/`bc` or scaled bigint on client).

**Ask First:**
- Server-side cart / hold API in this story (default: skip—client cart only).
- Blocking add when stock unavailable (default: skip inventory check—4.5).
- Full line-discount engine on F8 beyond stub (default: keep F8 stub; price override is the authorized price path for 4.4).

**Never:**
- `POST /pos/sales` or inventory ledger posts.
- Hard-coding money with JS float arithmetic for totals.
- Removing shell chrome/shortcuts from 4.3.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Search hits | Typed `q` | Results grid of products/variants | Empty state |
| Enter | Results non-empty | First variant added via resolve | Toast on resolve fail |
| Exact barcode | Single exact match | Auto-add + clear input | N/A |
| Qty +/− | Cart line | Qty updates; line re-resolved | N/A |
| Remove | Qty would be &lt;1 | Line removed | N/A |
| Price override | Has `sales.price-override` | Override applied; line totals update | 403 UI hide if no perm |
| No override perm | No permission | Override control hidden | N/A |
| Resolve fail | API error | Line not added / stale kept; message | Show error |
| Duplicate variant | Add same variant again | Increment qty (re-resolve) | N/A |

</frozen-after-approval>

## Code Map

- `frontend/pos/src/views/PosFloorView.vue` -- replace search/cart stubs with results + cart UI
- `frontend/pos/src/stores/pos.ts` -- extend with cart lines, search state, totals
- `frontend/pos/src/api/client.ts` -- already has Bearer + `X-Store-Id`; add typed helpers or call paths for search/resolve
- `backend/Modules/Products/app/Services/ProductSearchService.php` + `ProductCatalogToolsController.php` -- `GET /products/search` + `elapsedMs`
- `backend/Modules/Products/routes/api.php` -- search + `/categories`
- `backend/Modules/Pricing/app/Services/PricingService.php` -- `POST /pricing/resolve` response shape
- Continuity 4.3: shortcuts, StubPanel, store selection; 4.1 resolve; 4.2 customers (F4 still stub OK)
- UX: `EXPERIENCE.md` search autofocus, Enter first hit, qty stepper, tax lines

## Tasks & Acceptance

**Execution:**
- [x] POS API helpers + cart Pinia state (lines, add/update/remove, totals via decimal-safe math)
- [x] Search UI: debounce, category chips from `/categories`, results grid, Enter/auto-add barcode path
- [x] Add-to-cart via `pricing/resolve`; qty stepper; remove; cart rail totals + tax display
- [x] Price override modal/inline gated by `sales.price-override`
- [x] Smoke: POS `npm run build`; manual search/add/qty/override; optional note if backend test already covers search/resolve

**Acceptance Criteria:**
- Given the POS floor, when searching or scanning, then matching variants appear and can be added with qty, price, and tax from resolve.
- Given a cart line, when qty is adjusted, then the line re-prices and rail totals update (DECIMAL-safe).
- Given `sales.price-override`, when overriding a unit price, then the line reflects the override; without permission, override is unavailable.
- Given typed search online, when results return, then the search API path supports &lt;200ms target (elapsedMs available).

## Spec Change Log

## Design Notes

**Auto-add:** If search returns exactly one product with exactly one active variant **or** a clear exact barcode/SKU rank hit, add that variant and clear `q`. Otherwise show grid for tap-to-add.

**Override + resolve:** Call resolve for tax/list context; if override set, set `unitPrice` to override string and recompute line tax using resolve’s tax rate/inclusive flags (or second resolve then overwrite unit)—document chosen approach in code comment. Prefer: resolve at qty → if override, `unitPrice=override`, scale tax amount proportionally with `bc`-like string math.

**Client decimal math:** Reuse scaled-bigint helpers (as Admin PurchaseOrders) or a tiny `decimal.ts` util—no `Number` money sums.

**Pay button:** Remains stub opener; enable visual state when `lines.length > 0` optional but do not POST sale.

## Verification

**Commands:**
- `cd frontend/pos && npm run build` -- expected: success
- `cd backend && php artisan test --filter=ProductSearch` -- expected: pass if filter exists; else skip
- `cd backend && php artisan test --filter=Pricing` -- expected: pass

**Manual checks (if no CLI):**
- POS login → store → search SKU/barcode → add → change qty → totals move; override only as admin with permission.

## Suggested Review Order

**Cart pricing**

- Resolve → line fields + override tax scaling (DECIMAL-safe)
  [`cartPricing.ts:88`](../../frontend/pos/src/lib/cartPricing.ts#L88)

- Pinia cart: add/merge, qty, override, store-switch clear
  [`pos.ts:251`](../../frontend/pos/src/stores/pos.ts#L251)

**Search**

- Debounced search + exact barcode/SKU auto-add
  [`pos.ts:175`](../../frontend/pos/src/stores/pos.ts#L175)

- Catalog API helpers
  [`posCatalog.ts:56`](../../frontend/pos/src/api/posCatalog.ts#L56)

**Floor UI**

- Results grid, cart rail, override modal
  [`PosFloorView.vue:289`](../../frontend/pos/src/views/PosFloorView.vue#L289)

**Tests**

- Vitest cart/search matrix
  [`pos.cart.test.ts:1`](../../frontend/pos/src/stores/pos.cart.test.ts#L1)
