---
title: 'Story 3.2: Purchase order creation and approval'
type: 'feature'
created: '2026-08-27'
status: 'done'
baseline_commit: '78a0fdaf16826ceb9e842eb564049a04d80f54f1'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-3-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiate">

## Intent

**Problem:** Procurement can manage suppliers but cannot create purchase orders with a controlled submit → approve/reject workflow, so spending is not gated before receiving.

**Approach:** Extend the Purchasing module with store-scoped PO CRUD (header + line items), submit/approve/reject transitions that are audited, dual-control when the org setting is on, and an Admin Purchase Orders page. No GRN or stock posts in this story.

## Boundaries & Constraints

**Always:**
- Permissions: create/update/submit/delete-draft with `purchase.create`; approve/reject with `purchase.approve`; list/show for users with either permission.
- Status machine for this story: `draft → pending_approval → approved | rejected`. Receiving remains blocked until `approved` (GRN is Story 3.3).
- Money and qty as DECIMAL strings in API; camelCase JSON; org via `OrganizationService::current()`; PO tied to a store (`storeId`).
- Status transitions audited (AuditableObserver and/or explicit audit on approve/reject).
- Dual-control: when setting `purchase.dual_control` is truthy, the PO creator cannot approve/reject their own PO.
- Never mutate inventory balances (AD-2).

**Ask First:**
- Changing the dual-control setting key name away from `purchase.dual_control`.
- Allowing edit of line items after submit (default: only `draft` and `rejected` are editable; `rejected` may be re-submitted after edits).

**Never:**
- Goods receipts, ledger posts, invoices, payments, or `partial`/`received`/`closed` transitions (3.3–3.5).
- Supplier CRUD changes beyond using existing suppliers.
- POS UI or Inertia.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Create PO | Valid supplier, store, ≥1 line (productVariantId, qty, unitCost) | 201 draft PO with items + totals | 422 validation; 403 without create |
| Update draft | PATCH while draft/rejected | Items/header updated | 422 if pending/approved; 404 wrong org |
| Submit | POST …/submit on draft (or rejected after edit) | Status `pending_approval` | 422 empty lines / wrong status |
| Approve | POST …/approve by purchase.approve | Status `approved`; approvedBy set | 403; 422 wrong status; dual-control 403 |
| Reject | POST …/reject with optional reason | Status `rejected` | Same gates as approve |
| Dual-control | Setting on; creator tries approve | Forbidden | 403 message |
| Unauthorized | Missing purchase.create / approve | 403 | Abort |
| Unauthenticated | No token | 401 | Framework default |
| Audit | Submit/approve/reject | audit_logs reflect status change | N/A |

</frozen-after-approval>

## Code Map

- `backend/Modules/Purchasing/` -- extend; suppliers already live here (`SupplierService`, routes, AuditableObserver in `PurchasingServiceProvider`)
- `backend/Modules/Purchasing/routes/api.php` -- add `/purchase-orders` CRUD + submit/approve/reject beside suppliers
- `backend/Modules/Inventory/app/Services/StockTransferService.php` -- status-gate pattern analog (initiate/receive)
- `backend/Modules/Audit/Observers/AuditableObserver.php` + `AuditLogger` -- register PO models; optional explicit approve actions
- `backend/Modules/Settings/` -- `Setting` key/value; read `purchase.dual_control` (default off if missing)
- `backend/Modules/Authentication/app/Support/PermissionMatrix.php` -- `purchase.create` / `purchase.approve` already seeded
- `backend/Modules/Organization/Models/Store.php` -- PO `store_id` FK
- `backend/Modules/Products/app/Models/ProductVariant.php` -- line item sellable SKU
- `frontend/admin/src/views/SuppliersView.vue` + `InventoryView.vue` -- UI patterns for list/form/actions
- `frontend/admin/src/layouts/AppShell.vue` + `router/index.ts` -- add Purchase orders nav
- `backend/tests/Feature/SupplierApiTest.php` -- fixture/Sanctum/procurementOfficer pattern; add store_manager for approve tests
- Continuity from Story 3.1: keep Purchasing module ownership; do not move suppliers back to Products

## Tasks & Acceptance

**Execution:**
- [x] Migrations `purchase_orders` + `purchase_order_items` (org, store, supplier, status, number, notes, created_by, submitted_at, approved_by/at, rejection_reason; items: product_variant_id, qty, unit_cost DECIMAL, line totals)
- [x] Models/enums/resources/requests + `PurchaseOrderService` (create/update draft, submit, approve, reject, dual-control check) + `PurchaseOrderController`
- [x] Routes under Purchasing `/api/v1/purchase-orders` (+ `/{id}/submit`, `/approve`, `/reject`); register AuditableObserver
- [x] Admin `PurchaseOrdersView.vue` + nav/router — list/filter by status, create/edit draft, submit, approve/reject actions by permission
- [x] `backend/tests/Feature/PurchaseOrderApiTest.php` — matrix rows (happy path, wrong status, 403, dual-control, audit)

**Acceptance Criteria:**
- Given supplier + products and a user with `purchase.create`, when they create and submit a PO, then status is `pending_approval` and receiving remains impossible until approved (no GRN endpoints yet; status gate enforced).
- Given a user with `purchase.approve`, when they approve a pending PO, then status becomes `approved` and the transition is audited.
- Given `purchase.approve`, when they reject a pending PO, then status becomes `rejected` (optional reason stored).
- Given `purchase.dual_control` enabled, when the creator tries to approve their own PO, then 403.
- Given Admin SPA, when opening Purchase orders, then permitted users can create/submit and approvers can approve/reject.

## Spec Change Log

## Design Notes

**Reject API:** Story AC requires reject; api-design only lists `/approve`. Add `POST /purchase-orders/{id}/reject` (optional `reason`) and status `rejected` — additive, does not replace `/approve`.

**Permissions without `purchase.view`:** List/show allowed if user has `purchase.create` OR `purchase.approve`. Mutations: create/update/submit/delete-draft → create; approve/reject → approve.

**Numbering:** Server-generated `PO-{yyyy}-{seq}` (or similar) unique per org.

**Line items:** Nested sync on create/update like suppliers (replace-all while draft/rejected). Qty and `unitCost` DECIMAL(15,4); line total = qty × unitCost; header total = sum of lines.

**Dual-control default:** Missing setting ⇒ off. When on, compare `created_by` to acting user id on approve/reject.

**Out of scope statuses:** Do not implement `partial` / `received` / `closed` transitions yet; columns may reserve status enum values for later stories.

## Verification

**Commands:**
- `cd backend && php artisan test --filter=PurchaseOrder` -- expected: all pass
- `cd backend && php artisan test` -- expected: full suite green
- `cd frontend/admin && npm run build` -- expected: success

**Manual checks (if no CLI):**
- As `procurement@retail.local`, create PO → submit; as org admin/SM, approve; enable dual-control and confirm creator cannot self-approve.

## Suggested Review Order

**Routes & workflow**

- PO CRUD plus submit/approve/reject beside suppliers
  [`api.php:22`](../../backend/Modules/Purchasing/routes/api.php#L22)

**Domain service**

- Create draft with nested lines and unique PO number
  [`PurchaseOrderService.php:27`](../../backend/Modules/Purchasing/app/Services/PurchaseOrderService.php#L27)

- Submit → pending_approval; approve/reject with dual-control gate
  [`PurchaseOrderService.php:132`](../../backend/Modules/Purchasing/app/Services/PurchaseOrderService.php#L132)

- Reject uses rejected_by/at (not approved_*); dual-control check
  [`PurchaseOrderService.php:191`](../../backend/Modules/Purchasing/app/Services/PurchaseOrderService.php#L191)

**HTTP**

- Controller permission gates for create vs approve actions
  [`PurchaseOrderController.php:134`](../../backend/Modules/Purchasing/app/Http/Controllers/Api/PurchaseOrderController.php#L134)

**Admin UI**

- List/filter, create/edit draft, submit/approve/reject
  [`PurchaseOrdersView.vue:1`](../../frontend/admin/src/views/PurchaseOrdersView.vue#L1)

**Tests**

- Matrix: dual-control, audit, cross-org, permission 403s
  [`PurchaseOrderApiTest.php:84`](../../backend/tests/Feature/PurchaseOrderApiTest.php#L84)
