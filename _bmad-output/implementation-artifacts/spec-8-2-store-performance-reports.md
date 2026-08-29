---
title: 'Story 8.2: Store performance reports'
type: 'feature'
created: '2026-08-29'
status: 'done'
baseline_commit: 'c7ab3a7e3fc40939898e23159356cab1a384a15a'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-8-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Store managers can see today’s KPIs on the Dashboard but cannot run a filterable sales/store-performance report or export CSV for a date range — the Phase 9 reporting slice deferred from Story 8.1.

**Approach:** Extend the Reporting module with a store-scoped sales performance report (JSON summary + line rows) and CSV export, plus an Admin Reports screen gated by `reports.view`. Read completed POS sales only; no inventory/purchase/customer report suite and no notifications.

## Boundaries & Constraints

**Always:**
- Sanctum staff; `reports.view`; `X-Store-Id` + assigned store (same gate as dashboard).
- Versioned `/api/v1/reports/…`; money DECIMAL strings; `bcadd`-safe aggregation (no float SUM); read-only; not books of record.
- Date range required (`from`/`to` inclusive calendar days in org timezone, same boundary conversion pattern as `DashboardSummaryService`).
- Scope: **completed** `Sale` rows for the selected store. Filters (all optional): productVariantId, categoryId, paymentMethod, cashierId.
- Summary: salesTotal, salesCount, averageOrderValue (DECIMAL; AOV = total/count or `0.0000` if count=0). Optional thin cashier breakdown: cashierId, name, salesCount, salesTotal.
- Line/page: paginated sale rows (id, order/sale number or uuid, createdAt, cashier, total, payment methods) and/or top products by line total — keep payload lean; at least one tabular result set suitable for the Admin UI.
- CSV export of the same filtered set (sync `streamDownload` like products/suppliers); include `X-Store-Id` on download.
- Admin: nav **Reports** (`reports.view`); store + date range + filters; summary tiles; table; Export CSV. Deep-link without permission → 403/empty like dashboard.
- Feature tests: auth, store isolation, date bucket, filter math, CSV content-type, portal token rejected.

**Ask First:**
- Gross profit / COGS / trends charts (default: **defer** — AD-12; no COGS report here).
- Inventory, purchase, customer, profitability domain reports (default: **defer**).
- Cashier breakdown (default: **include thin** table in summary).
- Top products in MVP (default: **include top N by line total**, N≤10).
- Async/queued export (default: **sync CSV** for MVP ranges).

**Never:**
- PDF or fiscal report engines.
- Notification delivery (Story 8.3).
- Mutating sales/inventory/purchasing.
- Org-wide multi-store rollup without store header (MVP = selected store only).
- Treating exports as accounting ledgers.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Happy path | reports.view + store + from/to + completed sales | Summary totals + rows match filtered completed sales | N/A |
| Empty range | Valid range, no sales | Zeros / empty rows; CSV headers only | N/A |
| Payment filter | paymentMethod=cash | Only sales with a cash payment | Invalid method → 422 |
| Missing dates / bad range | from>to or missing | 422 | Validation |
| No reports.view / wrong store / portal | — | 403/401 | Isolation |
| CSV export | Same filters | text/csv download | Same auth as JSON |

</frozen-after-approval>

## Code Map

- Extend: `backend/Modules/Reporting/` — new `SalesPerformanceReportService` + controller/routes under `/api/v1/reports/sales` (summary/rows) and `/api/v1/reports/sales/export` (CSV)
- Reuse: `DashboardController` auth/store helpers; org timezone day bounds from `DashboardSummaryService`; `Sale`/`SaleItem`/`SalePayment`/`SaleStatus::Completed`; product→category join
- CSV pattern: `ProductCatalogToolsController` / `SupplierCsvController` streamDownload; extend Admin `api.download` to send `X-Store-Id`
- Admin: `AppShell.vue` Reports nav; new `ReportsView.vue` + router; gate `reports.view`
- Continuity: Story 8.1 dashboard; deferred 8.2 entry already in `deferred-work.md` (close on ship)

## Tasks & Acceptance

**Execution:**
- [x] Reporting APIs: sales performance summary (+ thin cashier + top products) and paginated rows; date/store filters
- [x] CSV export endpoint with store header; Admin download helper supports `X-Store-Id`
- [x] Feature tests covering I/O matrix
- [x] Admin Reports UI: filters, summary, table, export; nav gated
- [x] Ops/README note; sprint `8-2-store-performance-reports` → review on ship

**Acceptance Criteria:**
- Given completed sales in a date range for a store, when staff with `reports.view` request the sales report, then salesTotal and salesCount match those sales (DECIMAL).
- Given a paymentMethod or cashierId filter, when applied, then results include only matching sales.
- Given Export CSV with the same filters, when downloaded, then the file is `text/csv` and rows align with the JSON report.
- Given a user without `reports.view`, when they call report or export APIs, then they receive 403.

## Spec Change Log

- Review patches: export store-scope tests; exclude held/voided; CSV 10k 422 test; skip null cashier buckets; reset denial on store change; align CSV filename; ops notes for gross totals/timezone.

- 2026-08-29 — Implemented as specified; all Ask First defaults taken (thin cashier breakdown included, top 10 products included, sync CSV, no COGS/trends, no other domain reports).

## Design Notes

Prefer one filtered query builder shared by JSON summary, rows, and CSV so exports cannot drift. Cap default page size (e.g. 50) and CSV row guard (e.g. 10k) with clear 422 if exceeded — avoid unbounded sync exports.

**As built:**

- `SalesPerformanceReportService::baseQuery()` is the single filtered builder; summary, cashier breakdown, top products, paginated rows, and the CSV all derive from it, so the export cannot describe a different population than the screen.
- Money stays DECIMAL strings summed with `bcadd` over `lazyById` (SQL `SUM()` is float-typed on sqlite), matching `DashboardSummaryService`. AOV is `bcdiv(total, count, 4)`, `0.0000` at zero count.
- Day bounds reuse the dashboard pattern: inclusive `from`/`to` calendar days in the org timezone, converted to app timezone as `[start, endExclusive)`.
- Top products and the cashier table are scoped by the same filters as the sales, so a variant/category filter never lists excluded products.
- Auth/store resolution was extracted into `ResolvesReportScope` and is now shared by `DashboardController` and `SalesPerformanceReportController` (`reports.view` → 403, missing/bad `X-Store-Id` → 422, unassigned store → 403).
- CSV export is synchronous with a 10,000-sale guard (422 with a "narrow the range" message); rows are emitted in the same order as the JSON page (`lazyByIdDesc`).
- Admin `api.download` now takes optional headers so the export can send `X-Store-Id`; the Reports screen filters by store, range, payment method, cashier, category, and (by selecting a Top products row) product variant.
- The category filter is optional chrome: `/categories` needs `products.view`, so a 403 hides the select rather than failing the report for report-only roles.
- Totals are gross completed sales: `SaleReturn`/refund rows are a separate ledger and are not netted off here (spec scope is completed `Sale` rows).

## Verification

**Commands:**
- `cd backend && php artisan test --filter='Reporting|SalesPerformance|Permission'` -- expected: pass
- `cd frontend/admin && npm run build` -- expected: success

**Manual checks (if no CLI):**
- Admin → Reports → pick store/dates → summary + CSV; cashier without `reports.view` cannot see nav

## Suggested Review Order

**Report API**

- Shared filtered query for summary, rows, CSV
  [`SalesPerformanceReportService.php:33`](../../backend/Modules/Reporting/app/Services/SalesPerformanceReportService.php#L33)

- Sales + export routes
  [`api.php:1`](../../backend/Modules/Reporting/routes/api.php#L1)

**Admin UI**

- Reports filters, summary, export
  [`ReportsView.vue:1`](../../frontend/admin/src/views/ReportsView.vue#L1)

**Tests**

- Matrix + held/voided + export isolation + CSV cap
  [`ReportingSalesPerformanceApiTest.php:216`](../../backend/tests/Feature/ReportingSalesPerformanceApiTest.php#L216)
