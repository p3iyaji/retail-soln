---
title: 'Story 2.7: Low-stock alerts'
type: 'feature'
created: '2026-08-27'
status: 'done'
baseline_commit: '48f206f3df5b0152b68c1cc98faec4c3ec192e37'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-2-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiate">

## Intent

**Problem:** Store managers have no visibility when on-hand stock hits reorder thresholds, so replenishment stays reactive.

**Approach:** Expose computed low-stock alerts for a store (available ≤ product reorder level), refresh them via a scheduled inventory check command, and surface the list on the Admin dashboard stub.

## Boundaries & Constraints

**Always:**
- Gate with `inventory.view`; org-scope via `OrganizationService::current()`; optional `storeId` filter like balances.
- Compare `InventoryBalance.available` to parent product `reorder_level` (via variant→product); skip null reorder levels.
- Never mutate stock quantities; alerts are read-side only (AD-2).
- Money/qty remain DECIMAL strings in API JSON (camelCase).
- Zero/negative available uses destructive red; at-or-below reorder (still positive) uses ember accent on the dashboard stub.

**Ask First:**
- Adding email/push/notification delivery (Epic 8 territory).
- Persisting a durable alert history table if product asks for acknowledge/dismiss workflows beyond a live computed list.

**Never:**
- Mutating balances outside `InventoryLedgerService`.
- Full KPI dashboard (sales, approvals) — low-stock panel stub only.
- Warehouse-only alerts unless `warehouseId` is requested as a mirror of balances filters; default UI is store-scoped.
- Gift cards, POS UI, or Epic 3 purchasing automation.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Happy path | Balance `available` ≤ product `reorder_level` for store | Alert listed with variant/product identity, available, reorderLevel, severity | N/A |
| Above threshold | `available` > `reorder_level` | Not listed | N/A |
| No reorder | `reorder_level` null | Excluded from alerts | N/A |
| Zero/neg stock | `available` ≤ 0 and reorder set | Listed with severity `critical` | N/A |
| Unauthorized | User lacks `inventory.view` | 403 | Abort unauthorized |
| Unauthenticated | No Sanctum token | 401 | Framework default |
| Scheduled check | `inventory:check-low-stock` runs | Scans org balances; reports/counts low-stock rows (same rules as GET) | Non-zero exit only on hard failure |

</frozen-after-approval>

## Code Map

- `backend/Modules/Inventory/routes/api.php` -- add `GET /inventory/alerts/low-stock` beside balances/movements
- `backend/Modules/Inventory/app/Http/Controllers/Api/InventoryController.php` -- `balances`/`movements`/`assertView` reuse pattern; add `lowStockAlerts`
- `backend/Modules/Inventory/app/Models/InventoryBalance.php` -- query source (`available`, `location_*`, `product_variant_id`)
- `backend/Modules/Products/app/Models/Product.php` -- `reorder_level` / `min_stock` / `max_stock` on product (not variant)
- `backend/Modules/Products/app/Models/ProductVariant.php` -- join path to product thresholds
- `backend/Modules/Inventory/app/Services/InventoryLedgerService.php` -- `post()` mutates stock; do **not** change mutation path; alerts stay derived
- `backend/Modules/Inventory/app/Http/Resources/InventoryBalanceResource.php` -- camelCase resource pattern to mirror
- `backend/routes/console.php` -- register `inventory:check-low-stock` + `Schedule::` (currently only `inspire`)
- `backend/bootstrap/app.php` -- confirm schedule wiring if needed for Laravel 13
- `frontend/admin/src/views/DashboardView.vue` -- stub; add low-stock panel
- `frontend/admin/src/style.css` -- add `--ember` / `--danger` tokens if missing
- `frontend/admin/src/api/client.ts` -- `api.get` for authenticated calls
- `frontend/admin/src/views/InventoryView.vue` -- `storeId` query pattern to copy
- `backend/tests/Feature/StockAdjustmentsCountsApiTest.php` -- fixture pattern (PermissionMatrixSeeder, inventoryManager, Sanctum)
- `backend/Modules/Authentication/app/Support/PermissionMatrix.php` -- `inventory.view` already defined

## Tasks & Acceptance

**Execution:**
- [x] `backend/Modules/Inventory/app/Services/LowStockAlertService.php` -- query balances joined to variants/products where reorder_level is set and available ≤ reorder_level; support org + optional store/warehouse filters; classify severity (`warning` vs `critical`)
- [x] `backend/Modules/Inventory/app/Http/Controllers/Api/InventoryController.php` + `.../Http/Resources/LowStockAlertResource.php` + `routes/api.php` -- `GET /api/v1/inventory/alerts/low-stock` gated by `inventory.view`, paginated meta like balances
- [x] `backend/Modules/Inventory/app/Console/CheckLowStockCommand.php` + `backend/routes/console.php` -- artisan `inventory:check-low-stock` scheduled periodically; uses the same service (print counts; no stock writes)
- [x] `frontend/admin/src/style.css` + `DashboardView.vue` -- ember/danger tokens; fetch alerts for a selected/default store; dense list with mono SKU and severity styling
- [x] `backend/tests/Feature/LowStockAlertsApiTest.php` -- cover matrix rows (listed/excluded/critical/403) + command smoke

**Acceptance Criteria:**
- Given a store balance at or below the product reorder level, when `GET /inventory/alerts/low-stock?storeId=` is called by a permitted user, then the SKU appears with available and reorderLevel.
- Given stock rises above reorder level, when the same endpoint is called, then that SKU is absent.
- Given the scheduled check runs, when low-stock rows exist, then the command completes successfully using the same detection rules (visible via exit status / output).
- Given an authenticated store manager on the Admin dashboard stub, when alerts exist for the scoped store, then they are visible with ember (low) or red (zero/negative) styling.
- Given a user without `inventory.view`, when calling the endpoint, then 403.

## Spec Change Log

## Design Notes

Compute-on-read (no alerts table): dashboard and GET always reflect live ledger balances. The scheduled command reuses `LowStockAlertService` so “inventory change or scheduled check” both surface the same truth without duplicating mutation hooks in `InventoryLedgerService::post`. Severity: `critical` when `available <= 0`, else `warning` when `available <= reorder_level`.

Example response item shape:
```json
{
  "productVariantId": 12,
  "sku": "TEE-RED-M",
  "productName": "Tee",
  "locationType": "store",
  "locationId": 3,
  "available": "2.0000",
  "reorderLevel": "5.0000",
  "severity": "warning"
}
```

## Verification

**Commands:**
- `cd backend && php artisan test --filter=LowStockAlerts` -- expected: all pass
- `cd backend && php artisan test` -- expected: full suite green
- `cd frontend/admin && npm run build` -- expected: success

**Manual checks (if no CLI):**
- Seed/login as inventory or org admin; set reorderLevel; adjust stock at/below; open Dashboard and confirm alert row; raise stock above reorder and confirm it disappears.

## Suggested Review Order

**Detection**

- Live compare of available ≤ product reorder_level; active SKUs only; critical first
  [`LowStockAlertService.php:22`](../../backend/Modules/Inventory/app/Services/LowStockAlertService.php#L22)

- Severity critical when available ≤ 0
  [`LowStockAlertService.php:67`](../../backend/Modules/Inventory/app/Services/LowStockAlertService.php#L67)

**API**

- Route beside balances; inventory.view gated in controller
  [`api.php:12`](../../backend/Modules/Inventory/routes/api.php#L12)

- Validated filters, clamped page size, camelCase resource payload
  [`InventoryController.php:17`](../../backend/Modules/Inventory/app/Http/Controllers/Api/InventoryController.php#L17)

**Scheduled check**

- Same service; sample output; hourly Schedule registration
  [`CheckLowStockCommand.php:19`](../../backend/Modules/Inventory/app/Console/CheckLowStockCommand.php#L19)

- Hourly cron entry
  [`console.php:11`](../../backend/routes/console.php#L11)

**Dashboard stub**

- Store-scoped fetch; ember vs danger severity styling
  [`DashboardView.vue:123`](../../frontend/admin/src/views/DashboardView.vue#L123)

**Tests**

- Matrix edges: equal reorder, negative critical, 422, schedule list
  [`LowStockAlertsApiTest.php:58`](../../backend/tests/Feature/LowStockAlertsApiTest.php#L58)
