---
title: 'Story 8.1: Sales and inventory dashboards'
type: 'feature'
created: '2026-08-29'
status: 'done'
baseline_commit: '4e494b88ee151e66ff917eceb5faeb1e6e69b56a'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-8-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Admin Dashboard only shows welcome + low-stock; store managers lack a store-scoped ops snapshot of today’s sales, pending fulfilment, open registers, and attention items — the Phase 9 dashboard promised by FR-26 / UX mockup.

**Approach:** Add a read-only store-scoped dashboard summary API (`reports.view`) and upgrade Admin Dashboard to the mockup’s KPI strip + low-stock list + thin needs-attention panel. Read existing sales/inventory/fulfilment/session facts; no report exports (8.2) and no notification delivery (8.3).

## Boundaries & Constraints

**Always:**
- Sanctum staff; store scope via `X-Store-Id` + assigned stores; permission `reports.view` (already in PermissionMatrix for SA/OA/SM/IM/PO/AC).
- Versioned `GET /api/v1/…` JSON; money as DECIMAL strings; no inventory mutations; no books-of-record claims.
- KPI day = store/org calendar “today” in app timezone (org timezone if available, else app timezone) for completed POS `Sale` totals.
- Reuse low-stock alerts (`inventory.view` or include count in dashboard payload gated by `reports.view` + existing alert service when caller also has inventory.view — prefer: dashboard summary includes lowStockCount when user has `inventory.view`, else null/omit list).
- Pending orders = paid portal fulfilment queue count for store (same statuses as fulfilment list: confirmed|processing|ready_for_pickup|out_for_delivery).
- Open registers = count of open cashier sessions for store.
- Admin: store switcher; KPI strip (Today’s sales, Orders pending, Low stock, Open registers); low-stock panel (ember/critical styling already present); needs-attention: compact links/counts for items already available (e.g. oversell exceptions and/or unpaid holds if cheap — otherwise fulfilment pending only).
- Hide dashboard KPIs / deep-link 403 when lacking `reports.view`; keep nav Dashboard visible but show permission message if denied (or gate content only).
- Feature tests for summary auth, store isolation, and KPI math with seeded fixtures.

**Ask First:**
- Gross profit, sales trends, AOV, top products/stores (default: **defer** to 8.2 / later).
- New Reporting nWidart module vs Sales/Inventory controllers (default: **thin Reporting module** with dashboard read service).
- Needs-attention richness (default: **thin** — reuse existing oversell list endpoint if present + fulfilment pending count; no new domain workflows).

**Never:**
- CSV/PDF report generation or filterable domain reports (Story 8.2).
- Email/SMS/in-app notification sending (Story 8.3).
- Mutating sales, stock, sessions, or portal orders.
- WebSocket live dashboards.
- Treating dashboard numbers as accounting ledgers.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Happy path | Staff + reports.view + X-Store-Id + completed sales today | DECIMAL todaySalesTotal, pendingOrdersCount, lowStockCount (if inventory.view), openRegistersCount | N/A |
| No sales today | Store with zero completed sales | todaySalesTotal `0.0000` (or org decimal scale) | N/A |
| Missing store header / not assigned | — | 422/403 | Existing store middleware pattern |
| No reports.view | Authenticated without permission | 403 | UI shows permission copy |
| Portal token | Customer Sanctum | 401/403 | Rejected |
| Wrong store | X-Store-Id outside access | 403 | Isolation |

</frozen-after-approval>

## Code Map

- Upgrade: `frontend/admin/src/views/DashboardView.vue` — KPI strip + panels; gate on `reports.view`
- Nav/router already: `frontend/admin/src/layouts/AppShell.vue`, `frontend/admin/src/router/index.ts`
- New: Reporting module (or `Modules/Reporting`) — `DashboardController` + `DashboardSummaryService`; route e.g. `GET /api/v1/reports/dashboard`
- Reuse: `LowStockAlertService` / `GET /inventory/alerts/low-stock`; `PortalFulfilmentService` queue query; `CashierSession` open status; `Sale` + `SaleStatus::Completed` for today’s sum
- RBAC: `PermissionMatrix.php` — `reports.view` already seeded; assert in controller; RbacApiTest smoke if needed
- UX ref: `_bmad-output/planning-artifacts/ux-designs/ux-retail-soln-2026-08-24/mockups/admin-dashboard.html`
- Continuity: Epic 8 context; deferred 8.2/8.3 in `deferred-work.md`

## Tasks & Acceptance

**Execution:**
- [x] Scaffold thin Reporting module + `GET /api/v1/reports/dashboard` with `reports.view` + store scope — single summary payload for KPIs
- [x] Aggregate today completed sales, open sessions, fulfilment pending count; wire low-stock count via existing service when permitted
- [x] Feature tests: auth, store isolation, KPI fixtures, portal token rejected
- [x] Upgrade Admin `DashboardView` to mockup KPIs + low-stock + thin needs-attention; permission-aware empty state
- [x] README / ops note if needed; sprint `8-1-sales-inventory-dashboards` → review on ship

**Acceptance Criteria:**
- Given a store with completed sales today, when staff with `reports.view` load the dashboard summary, then today’s sales total matches the sum of completed sale totals for that store/day.
- Given open cashier sessions and pending fulfilment orders, when the dashboard loads, then open-register and pending-order counts are correct for the selected store.
- Given a user without `reports.view`, when they call the dashboard API, then they receive 403.
- Given low-stock alerts for the store, when an inventory-capable user views Admin Dashboard, then the low-stock panel still lists them with existing severity styling.

## Spec Change Log

- 2026-08-29 — Implemented as specified; no intent renegotiated. Resolutions taken on the "Ask First" items: gross profit / trends / AOV / top products **deferred** to 8.2; **thin `Modules/Reporting`** module created rather than adding controllers to Sales/Inventory; needs-attention kept **thin** (counts only, sourced from existing queues).
- 2026-08-29 — Payload decision: `lowStockCount` is `null` (not `0`) when the caller lacks `inventory.view`, so the client can hide the tile instead of showing a misleading zero. Same convention applied to `needsAttention.pendingOversellApprovals` (`inventory.approve-oversell`) and `needsAttention.heldSalesCount` (`sales.view`).
- Review patches: hide null low-stock KPI; attention = oversells + ready-for-pickup only; DECIMAL-safe money format; isolate summary vs alerts errors + store-switch race; safe bcadd; IM heldSalesCount null test; processing/OFD pending counts; clean Reporting module metadata.

## Design Notes

Mockup KPI strip is the MVP contract — not full executive analytics. Prefer one `reports/dashboard` response to avoid N+1 client fan-out; Admin may still call low-stock list separately for the table rows (already implemented).

**Response shape** (`GET /api/v1/reports/dashboard`, header `X-Store-Id`):

```json
{
  "data": {
    "store": { "id": 1, "name": "Ikeja Store A" },
    "date": "2026-08-29",
    "timezone": "Africa/Lagos",
    "currency": "NGN",
    "todaySalesTotal": "428450.0000",
    "todaySalesCount": 12,
    "pendingOrdersCount": 12,
    "lowStockCount": 7,
    "openRegistersCount": 2,
    "needsAttention": {
      "pendingOversellApprovals": 1,
      "readyForPickupOrders": 3,
      "heldSalesCount": 2
    }
  }
}
```

**Calendar day.** `DashboardSummaryService` resolves the organization's `timezone` (falling back to `config('app.timezone')` if unset or not a valid identifier), takes that timezone's start of day, and converts the boundaries back to app time for the `created_at` range. A sale at 20:00 UTC therefore counts as "today" for a UTC+14 org and "yesterday" for a UTC org — covered by `test_sales_are_bucketed_by_organization_calendar_day`.

**Money.** Totals are summed in PHP with `bcadd` over a `lazyById` cursor rather than SQL `SUM()`, because sqlite returns floats for `SUM()` over DECIMAL columns. Cost is one bounded scan of a single store's day; if that ever becomes hot, move to a `SUM()` cast at the driver level rather than reintroducing float math.

**Reuse over reimplementation.** Pending-order counts go through a new `PortalFulfilmentService::queueCountForStore()` that shares a private `queueQuery()` with the existing staff list, so the dashboard can never drift from the fulfilment screen's definition of "in the queue". Low stock uses `LowStockAlertService::count()`; oversells use a new `OversellExceptionService::countPending()`.

**Not covered here.** Returns/refunds are not netted out of `todaySalesTotal` — the acceptance criterion is the sum of completed sale totals for the store/day. Netted revenue belongs to 8.2's reporting work.

## Verification

**Commands:**
- `cd backend && php artisan test --filter='Dashboard|Report|Permission'` -- expected: pass
- `cd frontend/admin && npm run build` -- expected: success

**Manual checks (if no CLI):**
- Login as org admin → Dashboard store select → KPIs populate; cashier without `reports.view` sees denial / empty KPIs

## Suggested Review Order

**Dashboard API**

- Store-scoped KPI aggregation (org calendar day, bcmath totals)
  [`DashboardSummaryService.php:40`](../../backend/Modules/Reporting/app/Services/DashboardSummaryService.php#L40)

- Route + reports.view gate
  [`api.php:1`](../../backend/Modules/Reporting/routes/api.php#L1)

**Admin UI**

- KPI strip, permission empty state, attention links
  [`DashboardView.vue:1`](../../frontend/admin/src/views/DashboardView.vue#L1)

**Tests**

- Auth, isolation, KPI math, null permission counts
  [`ReportingDashboardApiTest.php:194`](../../backend/tests/Feature/ReportingDashboardApiTest.php#L194)
