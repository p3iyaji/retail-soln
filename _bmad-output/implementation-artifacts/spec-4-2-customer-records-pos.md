---
title: 'Story 4.2: Customer records for POS'
type: 'feature'
created: '2026-08-27'
status: 'done'
baseline_commit: '272353a23c8981b0ff8900bf5d09830fef3cb3c9'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiate">

## Intent

**Problem:** POS has no customer records—cashiers cannot select walk-in vs registered customers or look up by name/phone/account, so sales attribution and history are blocked (FR-22, FR-23).

**Approach:** Add a Customers nWidart module with org-scoped customer CRUD (+ addresses, groups), a seeded Walk-in customer per org, search by name/phone/account number, and a history endpoint that returns empty section stubs until Sales exist. Admin Customers page for management; POS-ready APIs for later shell stories. Bundle the already-built Admin Users page on this branch.

## Boundaries & Constraints

**Always:**
- Gate view/search/history with `customers.view`; create/update/delete with `customers.manage` (store-scoped matrix as seeded).
- Org via `OrganizationService::current()`; camelCase JSON; money/credit fields `DECIMAL(15,4)` strings—no floats.
- Exactly one Walk-in customer per org (`isWalkIn: true`); cannot delete; available via list/search default and/or `GET /customers/walk-in`.
- Search `q` matches name, phone, email, and account number (case-insensitive).
- `GET /customers/{id}/history` returns structured empty collections for `sales`, `invoices`, `payments`, `returns`, `installmentPlans` until those domains exist—authorized with `customers.view`.
- Sensitive creates/updates audited.
- Include Admin **Users** wiring already present on this branch (list/create/role/stores against existing `/users` API).

**Ask First:**
- Enforcing credit limits on sale/checkout in this story (default: store fields only; enforce in 4.9).
- Wiring customer groups into Pricing resolve (default: groups CRUD/FK only; pricing still ignores `customerId`).
- Building POS Vue customer picker UI (default: skip—API + Admin; POS shell is 4.3).

**Never:**
- Inventing sales/invoice/payment rows for fake history.
- Gift cards / loyalty engine (AD-11) beyond optional nullable loyalty fields if cheap.
- Deleting or renaming the org Walk-in customer.
- Float money; mutating inventory.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Walk-in default | Org seeded | Walk-in customer returned; `isWalkIn` true | N/A |
| Search name/phone/account | `q` matches registered | Matching customers (exclude soft-deleted) | N/A |
| Create registered | Valid payload | 201 customer + optional addresses | 422 validation |
| History authorized | `customers.view` | 200 with empty section arrays | N/A |
| History unauthorized | No view perm | 403 | Abort |
| Delete walk-in | DELETE walk-in id | Rejected | 422 |
| Manage unauthorized | No manage perm | 403 on mutate | Abort |
| Unauthenticated | No token | 401 | Framework default |

</frozen-after-approval>

## Code Map

- New `backend/Modules/Customers/` — mirror Pricing/Purchasing module scaffold (`module.json`, providers, `routes/api.php`, `modules_statuses.json`)
- `backend/Modules/Authentication/app/Support/PermissionMatrix.php` L74–75 -- `customers.view` / `customers.manage` (store-scoped L115)
- `backend/Modules/Purchasing/` suppliers + contacts/addresses -- nested address pattern
- `backend/Modules/Pricing/app/Services/PricingService.php` -- `customerId` still ignored until groups wired (deferred)
- Continuity 4.1: DECIMAL money, FormRequest + Resource camelCase, AuditableObserver
- `frontend/admin/src/views/UsersView.vue` + `router/index.ts` -- already on branch; keep in PR
- `frontend/admin/src/views/SuppliersView.vue` / `UsersView.vue` -- list/search/detail patterns for CustomersView
- `frontend/admin/src/layouts/AppShell.vue` -- add Customers nav
- API: CRUD `/customers`, `GET /customers/{id}/history`, walk-in accessor (`api-design.md`)
- ERD: `customers`, `customer_addresses`, `customer_groups` (`database-design.md`)
- `frontend/pos/` -- placeholder only; no POS UI this story

## Tasks & Acceptance

**Execution:**
- [x] Scaffold Customers module + migrations (`customer_groups`, `customers`, `customer_addresses`) with Walk-in seed/ensure-per-org; DECIMAL credit fields; account number
- [x] Models/resources/requests + services + controllers/routes: CRUD, search `q`, walk-in endpoint, history stub
- [x] Admin `CustomersView.vue` + nav/router: list/search/create/edit, addresses, groups; show Walk-in; history panel (empty OK)
- [x] Keep Admin `UsersView.vue` router wiring on this branch (create/list/role/stores)
- [x] `backend/tests/Feature/CustomerApiTest.php` — matrix (walk-in, search, CRUD, history, 403, delete walk-in)

**Acceptance Criteria:**
- Given an organization, when customers are listed or walk-in is requested, then a Walk-in customer is available and marked as such.
- Given registered customers, when searching by name/phone/account, then matching customers are returned.
- Given `customers.view`, when fetching history, then structured empty history sections are returned (until sales exist).
- Given a user without `customers.manage`, when creating/updating/deleting, then 403; Walk-in cannot be deleted.
- Given Admin SPA, when opening Customers, then permitted users can manage customers; Users page remains functional.

## Spec Change Log

## Design Notes

**Walk-in:** One row per org: `is_walk_in=true`, name “Walk-in”, no phone required; created in seeder and/or lazily on first org access. Exclude from “new customer” forms; PATCH limited (no flip `is_walk_in`).

**Account number:** Org-unique string (`account_number` / API `accountNumber`); auto-generate if omitted (e.g. `C-{id}` or sequential).

**Types (MVP):** `walk_in` | `registered` | `business` | `corporate` (enum/string). Groups: simple `customer_groups` (org, name, code); optional `customer_group_id` on customer.

**History stub shape:** `{ sales: [], invoices: [], payments: [], returns: [], installmentPlans: [] }` — extend later without breaking clients.

**Credit:** Persist `creditLimit` / `balance` DECIMAL; do not enforce here.

**Users bundle:** Non-goal of FR-22 but required on this PR per human (option 3); no backend Users API changes.

## Verification

**Commands:**
- `cd backend && php artisan test --filter=Customer` -- expected: all pass
- `cd backend && php artisan test` -- expected: full suite green
- `cd frontend/admin && npm run build` -- expected: success

**Manual checks (if no CLI):**
- Open Customers: see Walk-in; create registered; search by phone; open history (empty). Confirm Users page still works.

## Suggested Review Order

**Walk-in & domain**

- Ensure one Walk-in per org (txn + lock)
  [`CustomerService.php:14`](../../backend/Modules/Customers/app/Services/CustomerService.php#L14)

- Walk-in update guards (rename/type/deactivate/account)
  [`CustomerService.php:128`](../../backend/Modules/Customers/app/Services/CustomerService.php#L128)

**API**

- Search by name/phone/email/account + CRUD/history/walk-in routes
  [`api.php:15`](../../backend/Modules/Customers/routes/api.php#L15)

- Escaped LIKE search
  [`CustomerController.php:17`](../../backend/Modules/Customers/app/Http/Controllers/Api/CustomerController.php#L17)

**Admin**

- Customers list/search/edit + empty history
  [`CustomersView.vue:362`](../../frontend/admin/src/views/CustomersView.vue#L362)

- Bundled Users create/role/stores page
  [`UsersView.vue:1`](../../frontend/admin/src/views/UsersView.vue#L1)

**Tests**

- Walk-in, search, CRUD, history, guards, audit
  [`CustomerApiTest.php:25`](../../backend/tests/Feature/CustomerApiTest.php#L25)
