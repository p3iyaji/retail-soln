---
title: 'Story 9.2: Performance and database optimization'
type: 'feature'
created: '2026-08-29'
status: 'done'
baseline_commit: '82e8780d82505b5d20c3ff890219988737d9252a'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-9-context.md'
  - '{project-root}/_bmad-output/planning-artifacts/architecture/architecture-retail-soln-2026-08-24/database-design.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Hot paths (POS product search, dashboard/reporting sales aggregates, sync pull watermarks, fulfilment queue counts) lack supporting composite indexes and use expensive `LIKE`/`whereHas` patterns — risking SM-4 (search ≤200ms p95) and SM-1 (sale ≤3s) under realistic catalog volume.

**Approach:** Add targeted composite indexes on sales, products, portal orders, customers, and prices; refactor product search to an indexed exact-match fast path before fuzzy fallback; tighten sync price pull to a join; reduce dashboard/reporting aggregation round-trips while preserving DECIMAL/bcmath correctness on sqlite tests.

## Boundaries & Constraints

**Always:**
- New migration only (never edit committed migrations); one concern: `add_hot_path_performance_indexes`.
- Indexes (minimum):
  - `sales`: `(organization_id, store_id, status, created_at)` — dashboard “today”, reporting date ranges
  - `products`: `(organization_id, is_active, updated_at, id)` — sync pull watermark
  - `customers`: `(organization_id, updated_at, id)` — sync pull watermark
  - `prices`: `(organization_id, updated_at, id)` — sync pull watermark
  - `portal_orders`: `(organization_id, store_id, payment_status, status)` — fulfilment queue counts
  - `sale_payments`: `(organization_id, method)` — report payment-method filter
- `ProductSearchService`: phase 1 exact match on product SKU/barcode + variant SKU + barcode `code` (indexed lookups, joins not nested `whereHas`); phase 2 fuzzy `LIKE` only when phase 1 empty and term length ≥ 2; preserve relevance ordering and 50-row cap.
- `SyncPullService::pullPrices`: replace `whereHas('priceList')` with `join` on `price_lists` filtered by org/store/active.
- `DashboardSummaryService`: combine today completed-sales `count` + money total in ≤2 queries (driver-aware: MySQL/MariaDB may use `SUM(total)` cast-safe; sqlite tests keep bcmath-safe path — no float money).
- `SalesPerformanceReportService::summary`: same aggregation improvement where safe; do not change report outputs.
- `config/performance.php` + `.env.example` keys documenting targets (`SEARCH_P95_MS`, `SALE_COMPLETION_MS`) for ops — informational, not enforced in CI.
- Tests: migration/index smoke; search exact-path query-count or structural test; sync price pull still correct; reporting/dashboard totals unchanged.
- DECIMAL money rules unchanged (AD-2 ledger untouched).

**Ask First:**
- Dedicated search engine (Meilisearch/Elasticsearch) — default **defer**.
- Redis response caching for dashboard/search — default **defer** (indexes + query shape first).
- Load-test harness / k6 scripts — default **defer to 9.4**.
- Slow-query logging / APM — default **defer to 9.3**.

**Never:**
- Schema changes beyond additive indexes.
- Weakening store-scope or auth from 9.1.
- Caching stale inventory quantities for POS.
- Float arithmetic for money totals.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Barcode scan exact | Known barcode/SKU | Phase-1 hit; no fuzzy `LIKE` sweep | Empty if not found |
| Fuzzy name search | `q=chip` partial | Phase-2 results when no exact hit | Empty list OK |
| Dashboard today | Many completed sales today | Correct count + total; fewer queries than before | Permissions unchanged |
| Sales report summary | Date range with 100+ sales | Same totals as pre-change | Filters unchanged |
| Sync pull prices | Store with price list | Same rows; join-based query | Device disabled → 403 |
| Migration rollback | `migrate:rollback` | Indexes dropped cleanly | N/A |

</frozen-after-approval>

## Code Map

- `backend/Modules/Products/app/Services/ProductSearchService.php` — nested `whereHas` + `%LIKE%` on every search
- `backend/Modules/Sales/database/migrations/2026_08_28_000000_create_sales_tables.php` — missing `created_at` in store/status composite
- `backend/Modules/Sales/database/migrations/2026_08_28_010000_add_hold_void_columns_to_sales_table.php` — has `(org, store, status)` only
- `backend/Modules/Reporting/app/Services/DashboardSummaryService.php` — separate count + `lazyById` sum
- `backend/Modules/Reporting/app/Services/SalesPerformanceReportService.php` — `lazyById` over full sale set
- `backend/Modules/Sync/app/Services/SyncPullService.php` — `pullPrices` uses `whereHas`
- `backend/Modules/CustomerPortal/database/migrations/2026_08_29_140100_create_portal_orders_tables.php` — no fulfilment-queue composite
- `backend/tests/Feature/ProductSearchImportApiTest.php` — extend for exact-path behavior
- `backend/tests/Feature/ReportingDashboardApiTest.php`, `ReportingSalesPerformanceApiTest.php` — regression totals
- `backend/tests/Feature/SyncPullApiTest.php` (or equivalent) — price pull after join refactor
- New: `backend/database/migrations/*_add_hot_path_performance_indexes.php`
- New: `backend/config/performance.php`

## Tasks & Acceptance

**Execution:**
- [x] Migration `add_hot_path_performance_indexes` -- composite indexes listed in Always -- supports dashboard/report/sync/fulfilment
- [x] `ProductSearchService.php` -- exact-then-fuzzy two-phase search -- SM-4 fast path
- [x] `SyncPullService.php` -- join-based `pullPrices` -- removes N+1 subquery pattern
- [x] `DashboardSummaryService.php` -- bounded aggregation queries -- fewer round-trips
- [x] `SalesPerformanceReportService.php` -- same aggregation pattern for `summary()` -- consistent totals
- [x] `config/performance.php`, `backend/.env.example` -- document targets -- ops visibility
- [x] Feature/unit tests for search path, indexes, dashboard/report/sync regressions
- [x] `docs/operational-manual.md` -- thin “Performance” note (indexes, search behaviour) -- optional

**Acceptance Criteria:**
- Given an exact product or variant barcode/SKU query, when search runs, then results match the pre-change API shape and phase-1 uses indexed lookups (verified by test asserting no fuzzy-only path on exact hit)
- Given a store with completed sales in the dashboard date window, when dashboard loads, then `todaySalesCount` and `todaySalesTotal` match pre-optimization fixtures
- Given the same sales report filters as before, when summary is requested, then `salesTotal` and `salesCount` are unchanged
- Given sync pull for prices, when cursor advances, then row payload matches prior behaviour
- Given `php artisan migrate`, when indexes migration runs, then all listed composites exist and full test suite passes

## Verification

**Commands:**
- `cd backend && php artisan test --filter='ProductSearch|ReportingDashboard|ReportingSales|SyncPull|Performance'` -- expected: all pass
- `cd backend && php artisan test` -- expected: full suite green

**Manual checks (if no CLI):**
- `EXPLAIN` on product exact search and sales date-range query shows new index usage on MySQL

**Result (2026-08-29):** `php artisan test` — 509 passed / 3213 assertions (6 new tests).

## Design Notes

- `DecimalAggregator` centralizes count/sum: MySQL uses SQL `SUM`; sqlite uses bcmath `lazyById` so tests stay DECIMAL-safe.
- Product exact search uses `leftJoin` on variants/barcodes; fuzzy path unchanged for partial names.
- Reporting `summary()` uses grouped SQL on MySQL and single-pass lazy grouping on sqlite.

## Suggested Review Order

**Indexes**

- Hot-path composite indexes migration
  [`2026_08_29_210000_add_hot_path_performance_indexes.php:1`](../../backend/database/migrations/2026_08_29_210000_add_hot_path_performance_indexes.php#L1)

**Query shape**

- Exact-then-fuzzy product search
  [`ProductSearchService.php:16`](../../backend/Modules/Products/app/Services/ProductSearchService.php#L16)

- DECIMAL-safe aggregations
  [`DecimalAggregator.php:1`](../../backend/app/Support/DecimalAggregator.php#L1)

- Sync price pull join
  [`SyncPullService.php:197`](../../backend/Modules/Sync/app/Services/SyncPullService.php#L197)

**Tests**

- Search fast path + index smoke
  [`ProductSearchPerformanceTest.php:1`](../../backend/tests/Feature/ProductSearchPerformanceTest.php#L1)

## Spec Change Log

- 2026-08-29 — Implemented as specified; 509 tests green.
