---
title: 'Story 3.1: Supplier management'
type: 'feature'
created: '2026-08-27'
status: 'done'
baseline_commit: 'e4f2cfa187bff6e650d6a863fa6f39c651f34dbd'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-3-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiate">

## Intent

**Problem:** Procurement only has a thin name/code supplier stub, so contacts, addresses, product mappings, and CSV bulk ops needed for purchasing are missing.

**Approach:** Add a Purchasing module that owns full supplier CRUD (contacts, addresses, product mappings) with CSV import/export and an Admin Suppliers page, while keeping catalog `products.supplier_id` working against the same `suppliers` table.

## Boundaries & Constraints

**Always:**
- Gate reads with `suppliers.view`, writes/import/export with `suppliers.manage` (org-scoped via `OrganizationService::current()`).
- New nWidart **Purchasing** module owns Supplier domain; API under `/api/v1/suppliers` (and nested resources).
- DECIMAL for any cost on mappings; camelCase JSON; paginated list meta like other modules.
- Preserve existing `suppliers` rows and Products `supplier_id` / CSV supplier resolve — no data loss.
- Sensitive mutations audited (AuditableObserver pattern used elsewhere).
- Seed/factory a `procurement_officer` user for verification.

**Ask First:**
- Changing Product default-supplier semantics beyond linking via `supplier_products` / existing `supplier_id`.
- Adding `suppliers.import` as a separate permission (default: import/export use `suppliers.manage`).

**Never:**
- Purchase orders, GRN, invoices, payments (Stories 3.2–3.5).
- Inventory ledger mutations from this story.
- POS UI or Inertia.
- Dropping the `suppliers` table or breaking Products catalog create-supplier shortcut without a replacement path.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Create supplier | Valid name (+ optional code, contacts, addresses, mappings) | 201 with nested data | 422 on validation; unique code per org |
| Update / soft fields | PATCH/PUT with contacts/addresses/mappings | Persisted; response reflects state | 404 wrong org/id |
| Delete | DELETE supplier | Removed or deactivated per chosen policy; blocked if unsafe | 409 if mapped products block hard delete — prefer `isActive=false` soft deactivate |
| Product mapping | Map supplier ↔ existing org product (by id or SKU) | Mapping stored; optional supplierSku/cost | 422 unknown product |
| CSV preview/import | Valid CSV rows | Preview errors; import creates/updates suppliers | Row errors reported; large files may async like products |
| CSV export | Authenticated manage/view | Download CSV of suppliers (+ key contact/address fields) | 403 without permission |
| Unauthorized | No `suppliers.view` / `.manage` | 403 | Abort |
| Unauthenticated | No token | 401 | Framework default |

</frozen-after-approval>

## Code Map

- `backend/Modules/Products/app/Models/Supplier.php` -- thin stub today; migrate ownership to Purchasing or re-point Products to Purchasing model on same table
- `backend/Modules/Products/database/migrations/2026_08_26_200000_create_product_catalog_tables.php` -- existing `suppliers` columns (`name`, `code`, `is_active`)
- `backend/Modules/Products/app/Http/Controllers/Api/CatalogController.php` -- `suppliers` / `storeSupplier` (lines ~155–200); keep catalog list/create compatible or delegate
- `backend/Modules/Products/routes/api.php` -- `GET/POST /suppliers`; Purchasing must not double-register conflicting routes
- `backend/Modules/Products/app/Services/ProductCsvService.php` -- `resolveSupplierId` / supplier firstOrCreate — must keep working
- `backend/Modules/Inventory/` -- module scaffold template (`module.json`, `*ServiceProvider`, `RouteServiceProvider`, `modules_statuses.json`)
- `backend/modules_statuses.json` -- enable Purchasing
- `backend/Modules/Authentication/app/Support/PermissionMatrix.php` -- `suppliers.view` / `suppliers.manage` already defined
- `backend/database/factories/UserFactory.php` -- add `procurementOfficer()`; `DevelopmentUsersSeeder` add PO login
- `backend/Modules/Products/app/Services/ProductCsvService.php` + `ProductCatalogToolsController` + `ProcessProductImportJob` -- CSV preview/import/export pattern to mirror for suppliers
- `frontend/admin/src/views/ProductsView.vue` -- list/form/CSV UI pattern
- `frontend/admin/src/layouts/AppShell.vue` + `router/index.ts` -- add Suppliers nav/route
- `frontend/admin/src/api/client.ts` -- `api.upload` / `api.download` already present
- `backend/tests/Feature/ProductSearchImportApiTest.php` / `StockAdjustmentsCountsApiTest.php` -- fixture + Sanctum patterns

## Tasks & Acceptance

**Execution:**
- [x] Scaffold `backend/Modules/Purchasing/` (module.json, providers, routes, `modules_statuses.json`) — Purchasing owns supplier domain
- [x] Migrations: expand `suppliers` if needed; add `supplier_contacts`, `supplier_addresses`, `supplier_products` (org-scoped FKs; mapping cost DECIMAL)
- [x] Models/resources/requests + `SupplierController` (and nested contact/address/mapping endpoints or embed in show/update) — full CRUD gated by `suppliers.*`
- [x] Reconcile Products `CatalogController` supplier list/create with Purchasing (no duplicate routes; ProductCsvService still resolves suppliers)
- [x] `SupplierCsvService` + import preview/import/export endpoints (manage-gated; async OK for large files mirroring products)
- [x] Admin `SuppliersView.vue` + nav/router — CRUD + CSV; procurement officer factory/seed
- [x] `backend/tests/Feature/SupplierApiTest.php` (+ CSV coverage) — matrix rows including 403/422/unique code

**Acceptance Criteria:**
- Given a procurement officer, when they create/update a supplier with contacts, addresses, and product mappings, then data persists and returns under `/api/v1/suppliers`.
- Given valid CSV, when preview/import runs, then suppliers are validated/created/updated with error reporting; export downloads CSV.
- Given a user without `suppliers.manage`, when they POST/import, then 403.
- Given existing catalog products referencing suppliers, when Purchasing ships, then those FKs and product CSV supplier resolve still work.
- Given Admin SPA, when opening Suppliers, then list/create/edit and CSV actions are available to permitted users.

## Spec Change Log

## Design Notes

**Addresses:** DB design lists only contacts + products; Story/FR-10 also require addresses. Model as `supplier_addresses` (`type`: billing|shipping|other, line1/line2, city, region, postalCode, country, isDefault) — separate from contacts.

**Product mappings:** `supplier_products` links `supplier_id` ↔ `product_id` (org product), optional `supplierSku` + `cost` DECIMAL. Does not replace `products.supplier_id` default; both can coexist (default supplier on product vs multi-supplier mapping).

**Delete policy:** Prefer deactivate (`isActive=false`) over hard delete when `products.supplier_id` or mappings exist.

**CSV headers (minimum):** `name`, `code`, `isActive`, contact fields (`contactName`, `contactEmail`, `contactPhone`), address fields (`addressType`, `line1`, `city`, `country`), optional `productSku` / `supplierSku` / `cost` for mapping rows.

**Route ownership:** Move authoritative supplier CRUD (+ CSV) to Purchasing; Products may keep a thin create used by catalog UI **or** call the same Purchasing service — avoid two competing POST handlers.

## Verification

**Commands:**
- `cd backend && php artisan test --filter=Supplier` -- expected: all pass
- `cd backend && php artisan test` -- expected: full suite green
- `cd frontend/admin && npm run build` -- expected: success

**Manual checks (if no CLI):**
- Login as procurement officer; create supplier with contact/address/mapping; CSV round-trip; confirm Products still lists suppliers for catalog default.

## Suggested Review Order

**Module & routes**

- Purchasing owns `/suppliers*` including CSV before `{supplier}` wildcards
  [`api.php:9`](../../backend/Modules/Purchasing/routes/api.php#L9)

**Domain service**

- Create/update with nested contacts, addresses, mappings
  [`SupplierService.php:22`](../../backend/Modules/Purchasing/app/Services/SupplierService.php#L22)

- Soft-deactivate when products or mappings reference the supplier
  [`SupplierService.php:77`](../../backend/Modules/Purchasing/app/Services/SupplierService.php#L77)

- Nested sync enforces single primary / default and mapping product resolve
  [`SupplierService.php:100`](../../backend/Modules/Purchasing/app/Services/SupplierService.php#L100)

**HTTP API**

- Paginated index with lite/q clamps for catalog lookup
  [`SupplierController.php:17`](../../backend/Modules/Purchasing/app/Http/Controllers/Api/SupplierController.php#L17)

**CSV**

- Header contract including address extras and mapping columns
  [`SupplierCsvService.php:16`](../../backend/Modules/Purchasing/app/Services/SupplierCsvService.php#L16)

- Sync vs queued import; failures mark import failed
  [`SupplierCsvService.php:114`](../../backend/Modules/Purchasing/app/Services/SupplierCsvService.php#L114)

**Admin UI**

- Suppliers page: CRUD, pagination, CSV import poll
  [`SuppliersView.vue:92`](../../frontend/admin/src/views/SuppliersView.vue#L92)

**Tests**

- Matrix coverage including async job run, audit, cross-org
  [`SupplierApiTest.php:32`](../../backend/tests/Feature/SupplierApiTest.php#L32)
