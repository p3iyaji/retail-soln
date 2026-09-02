---
title: 'Inventory stock period reports'
type: 'feature'
created: '2026-09-02'
status: 'done'
baseline_commit: '45d3032bf29c77a6bafbca9855704a024e4cc0fa'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-8-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-8-2-store-performance-reports.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Operators can see live balances and low-stock alerts, but cannot run a store-scoped inventory report for end-of-day / week / month / year that shows both period-end stock position and in-period movements — the inventory domain report deferred from Story 8.2.

**Approach:** Extend the Reporting module with a one-store inventory stock period report (JSON summary + paginated position rows + CSV export) and add an Inventory tab on the existing Admin Reports screen, gated by `reports.view`.

## Boundaries & Constraints

**Always:**
- Sanctum staff; `reports.view`; required `X-Store-Id` + assigned store via `ResolvesReportScope` (same gate as sales reports).
- Versioned `/api/v1/reports/inventory` (+ `/export`); read-only; DECIMAL qty strings with `bc*`; never call `InventoryLedgerService::post`.
- Scope: `location_type=store` + selected store only (no warehouse rollup, no multi-store).
- Period presets resolve to inclusive org-timezone calendar bounds, then app-TZ `[start, endExclusive)` like sales reports: `day` (EOD for a date), `week` (Mon–Sun week containing anchor date), `month`, `year`. Anchor date required (default today).
- **Position (as of period end):** reconstruct per variant × bucket — `current[bucket] − Σ(movements.quantity where created_at ≥ periodEndExclusive AND same bucket)`. Report `available`, `reserved`, `damaged`, `in_transit`, and derived `onHand = available + reserved`. Include variants with a balance row for the store (not only those that moved in-period).
- **Movement summary (within period):** aggregate signed qty by `type` (+ `bucket` when the same type posts to more than one bucket, e.g. `sale`/`reserve`/`release`). Do not treat reserve/release as physical inbound/outbound.
- JSON: summary (period bounds, movement aggregates, position SKU count, optional onHand total) + paginated position rows (variant id/sku/name + bucket qtys). CSV: same filtered position population via sync `streamDownload`; guard ~10k rows with 422 if exceeded.
- Admin Reports: new Inventory tab beside Store performance; store + period preset + anchor date; Run; summary; position table; Export CSV. Deep-link without `reports.view` → empty/403 like today.
- Feature tests: auth, store isolation, period day-bound in org TZ, as-of math vs seeded ledger, movement aggregation, CSV content-type/headers, portal token rejected.

**Ask First:**
- Stock valuation / unit_cost rollup (default: **defer** — qty only; AD-12).
- Fast/slow/dead-stock ranking and reorder suggestions (default: **defer**).
- Async/queued export (default: **sync CSV** with row guard).
- Optional category / search filter on position rows (default: **omit** for MVP).

**Never:**
- Snapshot tables or mutating inventory.
- Org-wide multi-store rollup without store header.
- Treating export as books of record / fiscal PDF.
- Using a single movement `balance_after` as full SKU position (it is per-bucket only).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Happy path | reports.view + store + day/week/month/year + balances & movements | Position as of period end + in-period movement aggregates match ledger | N/A |
| Empty store | Valid period, no balance rows | Zeros / empty rows; CSV headers only | N/A |
| As-of reconstruction | Movements after period end | Position excludes post-cutoff qty (current − post-end Σ) | N/A |
| Reserve/release | Dual available↔reserved posts | Movement summary shows typed/bucketed lines; onHand unchanged net | N/A |
| Bad period / missing store | Invalid preset, missing anchor, from logic broken | 422 | Validation |
| No reports.view / wrong store / portal | — | 403/401 | Isolation |
| CSV oversize | >10k position rows | 422 narrow-range message | Guard |

</frozen-after-approval>

## Code Map

- Extend: `backend/Modules/Reporting/` — new `InventoryStockReportService` + `InventoryStockReportController` + filters DTO; routes `GET /reports/inventory`, `GET /reports/inventory/export`
- Reuse: `ResolvesReportScope` (`.../Http/Concerns/ResolvesReportScope.php`); org-TZ day bounds + CSV stream pattern from `SalesPerformanceReportService` / `SalesPerformanceReportController`; `InventoryBalance` + `InventoryMovement` + `BalanceBucket` + `MovementType` (read-only)
- As-of: start from store balance rows; subtract post-`periodEndExclusive` movement qty per bucket with `bcsub`/`bcadd` (never float SUM)
- Mirror tests: `backend/tests/Feature/ReportingSalesPerformanceApiTest.php` → new `ReportingInventoryStockApiTest.php`
- Admin: `frontend/admin/src/views/ReportsView.vue` — add Inventory tab/section; keep sales tab; `api.download` already supports `X-Store-Id`
- Continuity: closes deferred inventory domain report from `spec-8-2-store-performance-reports.md` / `deferred-work.md` (append close note on ship only if process expects it — do not rewrite old deferred entries)

## Tasks & Acceptance

**Execution:**
- [x] `backend/Modules/Reporting/app/Services/InventoryStockReportService.php` (+ filters DTO) -- period resolve, as-of position, movement aggregates, pagination, CSV -- single filtered population for JSON+CSV
- [x] `backend/Modules/Reporting/app/Http/Controllers/Api/InventoryStockReportController.php` + `routes/api.php` -- show + export using `ResolvesReportScope`
- [x] `backend/tests/Feature/ReportingInventoryStockApiTest.php` -- cover I/O matrix
- [x] `frontend/admin/src/views/ReportsView.vue` -- Inventory tab: period presets, summary, table, CSV export
- [x] Ops note in `docs/operational-manual.md` -- inventory report period semantics + qty-only scope

**Acceptance Criteria:**
- [x] Given ledger activity before and after a period end for a store, when staff with `reports.view` request the inventory report for that period, then position buckets equal current minus post-end movements (DECIMAL).
- [x] Given movements in-period including reserve/release, when the summary is returned, then aggregates are by type (and bucket when needed) and do not invent physical stock from reserve pairs.
- [x] Given Export CSV with the same store/period, when downloaded, then the file is `text/csv` and position rows align with the JSON page population.
- [x] Given a user without `reports.view` or a store they cannot access, when they call report or export APIs, then they receive 403.

## Spec Change Log

- 2026-09-02 — Implemented inventory stock period report (Ask First defaults: qty-only, sync CSV, no ranking/filters).
- 2026-09-02 — Review patches: CSV oversize message (balance-row count, not period); real oversize guard via `csvRowLimit()`; multi-bucket as-of + pagination tests; unknown-bucket skip; `date` validation; timezone + on-hand copy in Admin/ops.

## Design Notes

Prefer one service owning period → bounds, as-of reconstruction, and movement aggregates so JSON and CSV cannot drift. Cap CSV at 10k position rows. Week = Monday–Sunday containing the anchor date in org timezone (document in ops note). “EOD” for today means as-of end of that calendar day (exclusive next midnight), not “last closed business day.”

**Golden as-of example:** After period end a sale posts available qty `-2.0000`, leaving current available `8.0000`. Period-end as-of available = `bcsub('8.0000', '-2.0000')` → `10.0000`.

**As built:**

- `InventoryStockReportService` owns period → `[start, endExclusive)`, as-of position (`current − Σ post-end per bucket`), in-period movement aggregates, pagination, and CSV from one position population.
- Movement summary groups by type; when a type posts to more than one bucket in-period (e.g. reserve/release), each line includes `bucket`.
- Auth/store gate reuses `ResolvesReportScope`; routes at `GET /api/v1/reports/inventory` and `/export`.
- Admin Reports adds an Inventory tab beside Store performance (period preset + anchor date).

## Verification

**Commands:**
- `cd backend && php artisan test --filter=ReportingInventoryStock` -- expected: pass
- `cd frontend/admin && npm run build` -- expected: success

**Manual checks (if no CLI):**
- Admin → Reports → Inventory → pick store + Day/Week/Month/Year → Run → position + movement summary + CSV download; cashier without `reports.view` cannot see Reports nav

## Suggested Review Order

**As-of reconstruction**

- Single service owns period bounds, position math, movements, and CSV.
  [`InventoryStockReportService.php:36`](../../backend/Modules/Reporting/app/Services/InventoryStockReportService.php#L36)

- Period-end position = current − Σ post-end qty per bucket (`bcsub`).
  [`InventoryStockReportService.php:187`](../../backend/Modules/Reporting/app/Services/InventoryStockReportService.php#L187)

- In-period aggregates by type (+ bucket when multi-bucket).
  [`InventoryStockReportService.php:267`](../../backend/Modules/Reporting/app/Services/InventoryStockReportService.php#L267)

**API surface**

- Show + export reuse `ResolvesReportScope`; anchor defaults to org-TZ today.
  [`InventoryStockReportController.php:20`](../../backend/Modules/Reporting/app/Http/Controllers/Api/InventoryStockReportController.php#L20)

- Versioned inventory report routes beside sales.
  [`api.php:13`](../../backend/Modules/Reporting/routes/api.php#L13)

**Admin UI**

- Inventory tab: period presets, summary, position table, CSV export.
  [`ReportsView.vue:750`](../../frontend/admin/src/views/ReportsView.vue#L750)

**Tests**

- I/O matrix: as-of, reserve/release, TZ day, pagination, CSV, auth.
  [`ReportingInventoryStockApiTest.php:162`](../../backend/tests/Feature/ReportingInventoryStockApiTest.php#L162)
