---
title: 'Story 6.2: Product catalogue and search'
type: 'feature'
created: '2026-08-29'
status: 'done'
baseline_commit: '226177ae6eb8b8e3b6d6e786b1d08c2c66cf91a0'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-6-context.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-retail-soln-2026-08-24/mockups/shop-catalogue.html'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-retail-soln-2026-08-24/mockups/shop-product.html'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The customer portal has auth/account only. Customers cannot browse, search, filter, or open product detail with price and a store stock signal — blocking UJ-5 before cart (6.3).

**Approach:** Add portal-scoped catalogue read APIs (categories, list/search, product detail with resolved price + stock signal) that reuse Products / PricingService / inventory balances without exposing staff routes, and shop UI in `frontend/customer-portal/` (list + detail + header search). No cart, checkout, or inventory mutations.

## Boundaries & Constraints

**Always:**
- New endpoints under `/api/v1/portal/catalogue/*` (or `/portal/catalog/*`), rate-limited; never call or expose staff `/products`, `/pricing/resolve`, `/inventory/*` to portal tokens or the public.
- Active products/variants only (`is_active`); org from `PortalOrganizationResolver` (same MVP as 6.1).
- Prices via `PricingService::resolve` (DECIMAL strings in JSON); stock signal is **read-only** balance `available` (and optionally reserved) — no ledger posts.
- Optional `storeId` query for price/stock; when omitted use `config('customer_portal.default_store_id')` or first active org store.
- SPA: shop chrome (logo → shop, header search, Account); routes for catalogue list + product detail; query `?q=` / category filters. Design tokens aligned with shop mockups.
- Guest browse allowed (public catalogue); auth not required for read.

**Ask First:**
- Guest vs login-required catalogue (default: **guest OK**, throttle public reads).
- Multi-store stock matrix on list cards (default: **single store signal** from resolved store; not all stores on list).

**Never:**
- Cart, add-to-cart persistence, checkout, orders, invoices, returns (6.3–6.5).
- Mutating inventory or trusting client prices.
- Granting portal access to Admin/POS catalogue write APIs.
- Building a dedicated search engine (DB/`ProductSearchService`-style search is enough).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| List catalogue | GET categories + products, optional `q`/`categoryId`/`storeId` | Paginated active products with display price + stock signal for resolved store | Empty → empty page |
| Search | `q` with matches | Ranked/filtered active hits (reuse search service patterns) | No matches → empty |
| Product detail | Valid product id in org | Detail + variants (active), resolved price, stock line for store | Unknown/inactive → 404 |
| Wrong org / inactive | Product outside portal org or inactive | 404 | N/A |
| Staff token on portal catalogue | Staff Sanctum | 401/403 (or public ignore staff; staff must not get staff payload via portal) | N/A |
| Missing store | No `storeId`, no config, no stores | Price may fall back per PricingService; stock signal null/unavailable with clear UI | 422 only if storeId invalid for org |

</frozen-after-approval>

## Code Map

- Portal module + org: `Modules/CustomerPortal/routes/api.php`, `PortalOrganizationResolver`, `config/config.php` — add `default_store_id`; new catalogue controller/services/resources
- Staff products (do not expose): `Modules/Products/routes/api.php` — `ProductController::index/show`, `ProductCatalogToolsController::search` → `ProductSearchService`, `CatalogController::categories`; resources `ProductResource` / `ProductVariantResource`
- Pricing (reuse service only): `Modules/Pricing/.../PricingService::resolve` — wrap in portal service; **not** staff `POST /pricing/resolve`
- Stock read: `InventoryController::balances` pattern / balance model — portal query `available` for variant+store; no `InventoryLedgerService` writes
- Middleware: public throttle for catalogue GETs; keep `staff` / `portal` isolation from 6.1
- SPA: `frontend/customer-portal/src/router/index.ts`, `App.vue` chrome; views Catalogue + ProductDetail; API client for portal catalogue; mockups `shop-catalogue.html`, `shop-product.html`
- Continuity from 6.1: portal prefix `/api/v1/portal`, guest auth routes pattern, DESIGN tokens; home should prefer `/shop` over `/account`

## Tasks & Acceptance

**Execution:**
- [x] Portal catalogue API (categories, list/search, show) + price/stock composition services + rate limits + feature tests for matrix
- [x] Config `default_store_id` (+ `.env.example`); org-scoped store validation
- [x] Customer-portal SPA: shop layout chrome, `/shop` list+search+filters, `/shop/products/:id` detail (no cart wire-up)
- [x] README note for catalogue/dev store; sprint `6-2-product-catalogue` in progress → review
- [x] Tests: inactive/404, guest access, staff isolation smoke, SPA unit/smoke as warranted

**Acceptance Criteria:**
- [x] Given active catalog data, when a guest opens the shop, then they see searchable/filterable products with prices.
- [x] Given a product detail request, when the product is active in the portal org, then price and a store stock signal are shown; inactive/foreign → 404.
- [x] Given staff product/pricing/inventory HTTP APIs, when called with a portal token, then they remain unauthorized.
- [x] Given this story ships, when browsing the portal, then cart/checkout are absent or non-functional stubs (not implemented).

## Spec Change Log

## Design Notes

**Public reads:** Throttle like `portal-auth` (e.g. `portal-catalogue`). Optional auth does not change payload.

**Price on list:** Resolve per default/selected store for the default (or listed) variant — batch carefully to avoid N+1; acceptable to resolve for primary variant only on cards.

**Stock copy:** e.g. “In stock · {store name}” when `available > 0`, else “Out of stock” / “Check availability” — no warehouse internals.

**Add to cart:** Omit or disabled button; wire in 6.3.

## Verification

**Commands:**
- `cd backend && php artisan test --filter=Portal` -- expected: auth + catalogue matrix pass
- `cd frontend/customer-portal && npm run test && npm run build` -- expected: pass

**Manual checks:**
- Guest: open shop → search → open detail → see price + stock line
- Portal token still cannot hit staff `/products`
- Invalid `storeId` rejected or ignored per matrix

## Suggested Review Order

**Portal catalogue API**

- Public throttled catalogue routes (no staff product APIs)
  [`api.php:31`](../../backend/Modules/CustomerPortal/routes/api.php#L31)

- Active-only list/search + PricingService + stock labels
  [`PortalCatalogueService.php:18`](../../backend/Modules/CustomerPortal/app/Services/PortalCatalogueService.php#L18)

- Compose list/detail payloads with price and stock signal
  [`PortalCatalogueController.php:33`](../../backend/Modules/CustomerPortal/app/Http/Controllers/Api/PortalCatalogueController.php#L33)

- Optional storeId → config → first active store
  [`PortalStoreResolver.php:17`](../../backend/Modules/CustomerPortal/app/Services/PortalStoreResolver.php#L17)

**SPA shop**

- Shop chrome: logo, header search, Account
  [`ShopLayout.vue:1`](../../frontend/customer-portal/src/components/ShopLayout.vue#L1)

- Catalogue list, filters, pagination, stock on cards
  [`CatalogueView.vue:1`](../../frontend/customer-portal/src/views/CatalogueView.vue#L1)

- Product detail with variant display; cart stub only
  [`ProductDetailView.vue:1`](../../frontend/customer-portal/src/views/ProductDetailView.vue#L1)

**Tests**

- Guest catalogue matrix + staff isolation
  [`PortalCatalogueApiTest.php:89`](../../backend/tests/Feature/PortalCatalogueApiTest.php#L89)
