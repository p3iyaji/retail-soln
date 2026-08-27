---
title: 'Story 4.1: Pricing and promotion services'
type: 'feature'
created: '2026-08-27'
status: 'done'
baseline_commit: 'dbf502d9095a0ddf5ea10e0abadebca3e4845aa6'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiate">

## Intent

**Problem:** POS and future sales have no pricing service—only catalog `variant.price`—so promotions and store tax cannot be resolved consistently (FR-19/20/21, AD-6).

**Approach:** Add a Pricing nWidart module with price lists + prices, promotion rules (% / fixed / simple BOGO), `POST /pricing/resolve` (base → promo → tax from `stores.tax_config`), DECIMAL-only math in named services, and a thin Admin Pricing page. Catalog `variant.price` remains the fallback when no list price matches.

## Boundaries & Constraints

**Always:**
- Domain logic in `PricingService` / `PromotionService` / `TaxService` (AD-6)—controllers stay thin.
- Money and rates as `DECIMAL(15,4)` strings / `bc*`; never float. CamelCase JSON; org via `OrganizationService::current()`.
- Gate list CRUD with `pricing.manage`; promotions CRUD with `promotions.manage`; resolve readable by any authenticated user who can sell or manage pricing (`sales.create` **or** `pricing.manage` **or** `promotions.manage`).
- Resolve input: `variantId` + `storeId` (+ optional `customerId`, `qty`); output: `unitPrice`, applied promotions, `tax` (rate, inclusive, amount), `lineTotal` / tax breakdown—enough for POS later.
- Tax from existing `stores.tax_config` (`rate`, `inclusive`, `name`) only in this story.
- Price resolution order: active store-scoped list price → org default list price → `product_variants.price`.
- Sensitive creates/updates audited.

**Ask First:**
- Building full `tax_rates` / `tax_categories` / `product_taxes` tables (default: skip; use store JSON).
- Customer-group / VIP list targeting before Story 4.2 customers exist (default: ignore `customerId` for list selection; accept field for forward compat).
- Dual-writing catalog `variant.price` when list prices change (default: no dual-write; resolve fallback only).

**Never:**
- Hard-coding prices in POS or controllers; inventing sale/cart UI (4.3–4.5).
- Loyalty engine (AD-11) or gift cards (OQ-1).
- Float money; mutating inventory.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Resolve list price | Store has matching price list entry | `unitPrice` from list; tax from store config | N/A |
| Fallback | No list price for variant | `unitPrice` = variant.price | N/A |
| Percent promo | Active % promo in scope | Discount applied; DECIMAL | N/A |
| Fixed promo | Active fixed-off promo | Discount capped so unit ≥ 0 | N/A |
| BOGO | qty meets buy/get threshold | Discount per rule | N/A |
| Tax exclusive | store inclusive=false | taxAmount = net × rate | N/A |
| Tax inclusive | store inclusive=true | tax extracted from gross | N/A |
| Missing variant/store | Bad ids | 422 | Abort |
| Unauthorized CRUD | No manage perm | 403 | Abort |
| Unauthenticated | No token | 401 | Framework default |

</frozen-after-approval>

## Code Map

- New module `backend/Modules/Pricing/` — mirror Products/Purchasing (`module.json`, ServiceProvider, `routes/api.php`, `modules_statuses.json`)
- `backend/Modules/Products/app/Models/ProductVariant.php` -- fallback `price` DECIMAL; do not remove catalog fields
- `backend/Modules/Organization/app/Models/Store.php` -- `tax_config` JSON (`rate`/`inclusive`/`name`); TaxService reads this only
- `backend/Modules/Authentication/app/Support/PermissionMatrix.php` L70–72 -- `pricing.manage`, `promotions.manage`, `tax.manage` (tax CRUD out of scope)
- `backend/Modules/*/routes/api.php` + Purchasing FormRequest patterns -- Sanctum, org assert, camelCase resources
- `frontend/admin/src/layouts/AppShell.vue` + `router/index.ts` -- add Pricing nav; reuse ProductsView money-string patterns
- API design: `POST /pricing/resolve`; CRUD `/price-lists`, `/prices`, `/promotions` (`api-design.md`)
- ERD names: `price_lists`, `prices`, `promotions`, `promotion_rules` (`database-design.md`)
- AD-6: services not controllers (`ARCHITECTURE-SPINE.md`)

## Tasks & Acceptance

**Execution:**
- [x] Scaffold Pricing module + migrations (`price_lists`, `prices`, `promotions`, `promotion_rules`) with DECIMAL amounts, dates, store/org scope, active flags
- [x] Models/resources/requests + `PricingService` (resolve order) + `PromotionService` (%, fixed, simple BOGO) + `TaxService` (store `tax_config`) + controllers/routes
- [x] CRUD APIs: `/price-lists`, `/prices`, `/promotions`; `POST /pricing/resolve`
- [x] Thin Admin Pricing page: manage lists/prices/promotions; smoke resolve optional
- [x] `backend/tests/Feature/PricingResolveApiTest.php` (+ CRUD coverage) — matrix rows

**Acceptance Criteria:**
- Given configured price lists and promotions, when `POST /pricing/resolve` with variant/store/(qty), then returned unit price and applied promotions match rules and DECIMAL math.
- Given store tax_config, when resolve runs, then tax amount respects inclusive/exclusive.
- Given no list price, when resolve runs, then variant.price is used.
- Given a user without manage permission, when creating a price list/promotion, then 403.
- Given Admin SPA, when opening Pricing, then permitted users can configure lists and promotions.

## Spec Change Log

## Design Notes

**Price lists:** Org-scoped; optional `store_id` (null = org default). `prices`: `price_list_id` + `product_variant_id` + `amount` DECIMAL, unique per list+variant. Seed or document a default “Retail” list; do not wipe catalog prices.

**Promotions:** Header (name, dates, active, optional store_id) + rules (`type`: `percent` | `fixed` | `bogo`; `value`; optional `product_variant_id` / category later; BOGO: `buy_qty`/`get_qty`/`get_discount_percent`). Apply best single promo MVP (highest savings) to avoid stacking complexity—document in response `appliedPromotions[]`.

**Resolve response (illustrative):** `{ unitPrice, listPrice, discountAmount, netPrice, tax: { name, rate, inclusive, amount }, grossPrice, appliedPromotions: [...] }` — all DECIMAL strings.

**Tax:** `TaxService::forStore(Store)` reads `tax_config`; exclusive: `tax = net * rate`; inclusive: `tax = gross - gross/(1+rate)`. Missing/empty config → rate 0.

**Permissions:** Use seeded `.manage` names (not wildcards).

## Verification

**Commands:**
- `cd backend && php artisan test --filter=Pricing` -- expected: all pass
- `cd backend && php artisan test` -- expected: full suite green
- `cd frontend/admin && npm run build` -- expected: success

**Manual checks (if no CLI):**
- Create Retail list + price; add % promo; resolve for store; confirm tax from store config.

## Suggested Review Order

**Resolve pipeline**

- List → promo → tax composition (DECIMAL)
  [`PricingService.php:39`](../../backend/Modules/Pricing/app/Services/PricingService.php#L39)

- Prefer store list, then default org list (is_default wins)
  [`PricingService.php:133`](../../backend/Modules/Pricing/app/Services/PricingService.php#L133)

- Best single promo among % / fixed / BOGO
  [`PromotionService.php:21`](../../backend/Modules/Pricing/app/Services/PromotionService.php#L21)

- Tax from store `tax_config` inclusive/exclusive
  [`TaxService.php:15`](../../backend/Modules/Pricing/app/Services/TaxService.php#L15)

**API**

- `POST /pricing/resolve` + CRUD routes
  [`api.php:11`](../../backend/Modules/Pricing/routes/api.php#L11)

**Admin**

- Thin Pricing page: create lists/prices/promotions + smoke resolve
  [`PricingView.vue:230`](../../frontend/admin/src/views/PricingView.vue#L230)

**Tests**

- Matrix: list price, fallback, promos, tax, auth, edge cases
  [`PricingResolveApiTest.php:61`](../../backend/tests/Feature/PricingResolveApiTest.php#L61)
