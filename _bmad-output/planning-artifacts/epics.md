---
stepsCompleted: [step-01, step-02, step-03]
inputDocuments:
  - docs/proposal.md
  - _bmad-output/planning-artifacts/prds/prd-retail-soln-2026-08-24/prd.md
  - _bmad-output/planning-artifacts/prds/prd-retail-soln-2026-08-24/addendum.md
  - _bmad-output/planning-artifacts/architecture/architecture-retail-soln-2026-08-24/ARCHITECTURE-SPINE.md
---

# retail-soln - Epic Breakdown

## Overview

Epics decompose the PRD and architecture into implementable stories aligned with proposal implementation Phases 1–10. **MVP = Epics 1–4** (Phases 2–5). Epic 0 is architecture-only (no app code). Epics 5–9 are post-MVP.

---

## Requirements Inventory

### Functional Requirements

FR-1 through FR-26 (see PRD). Full extraction: 74 core FRs in proposal analysis.

### NonFunctional Requirements

NFR-1 through NFR-28: performance, security, reliability, offline/sync, scalability, observability (see PRD addendum and analysis doc).

### Additional Requirements (Architecture)

- Vue 3 SPA clients, no Inertia (AD-1)
- Inventory ledger sole mutation path (AD-2)
- Atomic financial transactions (AD-3)
- Store-scoped authorization (AD-4)
- Laravel nWidart modular monolith (AD-8)
- Docker deployment scaffold
- `/api/v1` versioned REST API
- DECIMAL money types, UUID for offline entities

### UX Design Requirements

None yet — recommend `bmad-ux` before Epic 4 POS UI stories.

### FR Coverage Map

| Epic | FRs covered |
| --- | --- |
| Epic 0 | Architecture deliverables (proposal §58) |
| Epic 1 | FR-1, FR-2, FR-3, FR-4, FR-24, FR-25 |
| Epic 2 | FR-5, FR-6, FR-7, FR-8, FR-9 |
| Epic 3 | FR-10, FR-11 |
| Epic 4 | FR-12–FR-23, FR-27, FR-28, FR-29, FR-30 |
| Epic 5 | FR-82–FR-86 (offline) |
| Epic 6 | FR-67–FR-74 (customer portal) |
| Epic 7 | FR-75–FR-76 (fulfilment) |
| Epic 8 | FR-87–FR-91 (reporting/notifications) |
| Epic 9 | NFR hardening |

---

## Epic List

1. **Epic 0:** Architecture Foundation (Phase 1 — docs only)
2. **Epic 1:** Platform Foundation (Phase 2)
3. **Epic 2:** Products & Inventory (Phase 3)
4. **Epic 3:** Purchasing & Suppliers (Phase 4)
5. **Epic 4:** POS — Online (Phase 5) `[MVP boundary]`
6. **Epic 5:** Offline POS & Sync (Phase 6)
7. **Epic 6:** Customer Portal (Phase 7)
8. **Epic 7:** Order Fulfilment (Phase 8)
9. **Epic 8:** Reporting & Notifications (Phase 9)
10. **Epic 9:** Production Hardening (Phase 10)

---

## Epic 0: Architecture Foundation

Produce architecture deliverables per proposal §58 before any application code. No implementation stories — documentation and design approval gate.

### Story 0.1: Domain model and ERD proposal

As a **System Architect**,
I want a complete domain model and ERD proposal,
So that database design is reviewed before migrations.

**Acceptance Criteria:**

**Given** the PRD and architecture spine exist
**When** the domain model document is produced
**Then** it covers all MVP entities with relationships
**And** inventory ledger pattern is explicit
**And** multi-store isolation strategy is documented

### Story 0.2: API design specification

As a **System Architect**,
I want an API design document for `/api/v1`,
So that all Vue clients have a stable contract.

**Acceptance Criteria:**

**Given** domain model is approved
**When** API spec is written
**Then** it covers auth, org, stores, products, inventory, purchasing, sales endpoints
**And** error shapes and pagination conventions are defined
**And** idempotency key pattern documented for future sync

### Story 0.3: Offline sync design (Phase 6 prep)

As a **System Architect**,
I want offline sync and conflict resolution documented,
So that Phase 6 implementation has a reviewed blueprint.

**Acceptance Criteria:**

**Given** AD-5 idempotency rule in spine
**When** offline-sync-design.md is produced
**Then** push/pull protocol, local storage schema, and conflict rules are defined
**And** oversell reconciliation strategy is documented (resolves OQ-3)

### Story 0.4: Security and permission matrix

As a **Security Engineer**,
I want a permission matrix mapping roles to actions,
So that RBAC implementation is unambiguous.

**Acceptance Criteria:**

**Given** roles defined in PRD
**When** permission-matrix.md is produced
**Then** every MVP permission string is mapped to roles
**And** store-scoped vs org-scoped permissions are distinguished

### Story 0.5: Architecture review and approval gate

As a **Product Owner**,
I want formal architecture approval,
So that Phase 2 coding can begin safely.

**Acceptance Criteria:**

**Given** all Epic 0 deliverables exist
**When** review is conducted
**Then** open questions OQ-1 through OQ-6 are resolved or deferred with rationale
**And** approval is recorded in memlog

---

## Epic 1: Platform Foundation

Implement Laravel scaffold, auth, organization structure, RBAC, settings, and audit infrastructure (Phase 2).

### Story 1.1: Laravel modular monolith scaffold

As a **Developer**,
I want a Laravel project with nWidart modules, Docker, and API routing,
So that domain modules can be added incrementally.

**Acceptance Criteria:**

**Given** architecture approved
**When** scaffold is created
**Then** Docker compose runs MySQL, Redis, Nginx, app, queue worker
**And** `/api/v1` route group exists with Sanctum middleware
**And** at least Organization and Authentication module stubs exist
**And** CI runs PHPUnit on push

### Story 1.2: Authentication with Fortify and Sanctum

As a **User**,
I want to log in and receive an API token,
So that Vue clients can authenticate securely.

**Acceptance Criteria:**

**Given** registered user credentials
**When** login via API
**Then** Sanctum token is returned
**And** invalid credentials return 401
**And** 2FA can be enabled for admin roles
**And** rate limiting applies to login endpoint

### Story 1.3: Organization and store management

As an **Organization Admin**,
I want to create stores and warehouses,
So that the multi-store structure exists.

**Acceptance Criteria:**

**Given** authenticated org admin
**When** CRUD operations on stores/warehouses
**Then** each store has address, currency, tax config fields
**And** stores belong to organization
**And** unauthorized users receive 403

### Story 1.4: Users, roles, and permissions

As an **Organization Admin**,
I want to assign roles and store access to users,
So that RBAC is enforced (FR-3, FR-4).

**Acceptance Criteria:**

**Given** permission matrix from Epic 0
**When** admin assigns role and store access
**Then** Policies enforce permissions on API endpoints
**And** user limited to assigned stores sees only scoped data
**And** permission strings match matrix (e.g. `sales.refund`)

### Story 1.5: Settings and audit infrastructure

As an **Organization Admin**,
I want org settings and audit logging,
So that configuration and sensitive changes are tracked (FR-24).

**Acceptance Criteria:**

**Given** settings module exists
**When** sensitive entity is created/updated/deleted
**Then** append-only audit log records user, action, before/after, store
**And** audit logs cannot be updated or deleted via API

### Story 1.6: Admin portal shell (Vue SPA)

As an **Organization Admin**,
I want a Vue admin app with auth and navigation,
So that admin features have a UI foundation.

**Acceptance Criteria:**

**Given** Vue 3 + Vite + TypeScript + Tailwind scaffold
**When** admin logs in
**Then** token stored and attached to API requests
**And** navigation shows org, stores, users modules (stubs OK)
**And** no Inertia.js dependency exists

---

## Epic 2: Products & Inventory

Catalog, variants, barcodes, inventory ledger, transfers, counts (Phase 3).

### Story 2.1: Product catalog CRUD

As an **Inventory Manager**,
I want to manage products with categories, brands, and units,
So that the catalog is complete (FR-5).

**Acceptance Criteria:**

**Given** authenticated inventory manager
**When** product CRUD via API and admin UI
**Then** product has SKU, barcode, category, brand, supplier, unit, images
**And** reorder/min/max thresholds configurable

### Story 2.2: Product variants and barcodes

As an **Inventory Manager**,
I want product variants with independent SKU/barcode/price,
So that sellable SKUs are tracked (FR-5).

**Acceptance Criteria:**

**Given** base product exists
**When** variants are added
**Then** each variant has own SKU, barcode, pricing
**And** barcode uniqueness enforced per organization

### Story 2.3: Inventory ledger service

As a **Developer**,
I want an InventoryLedgerService as sole stock mutation path,
So that AD-2 is enforced (FR-8).

**Acceptance Criteria:**

**Given** ledger service exists
**When** any stock change occurs
**Then** inventory_movement record created with type, qty, cost, reference
**And** balance materialized/derived from movements
**And** direct quantity mutation outside service is prohibited by convention/tests

### Story 2.4: Stock adjustments and counts

As an **Inventory Manager**,
I want to adjust stock and run stock counts,
So that inventory stays accurate (FR-9).

**Acceptance Criteria:**

**Given** store inventory exists
**When** adjustment or count with variance
**Then** ledger movement created with reason and user
**And** count variance generates adjustment movement

### Story 2.5: Stock transfers between locations

As an **Inventory Manager**,
I want to transfer stock between stores/warehouses,
So that in-transit inventory is tracked (FR-9, UJ-6).

**Acceptance Criteria:**

**Given** source location has available stock
**When** transfer initiated and confirmed at destination
**Then** transfer_out and transfer_in movements created
**And** in-transit quantity tracked between initiation and receipt

### Story 2.6: Product search and import/export

As an **Inventory Manager**,
I want fast search and CSV import/export,
So that catalog management scales (FR-6, FR-7).

**Acceptance Criteria:**

**Given** product catalog populated
**When** search by SKU/barcode/name/category
**Then** results return in <200ms p95 online
**And** CSV import validates, previews, reports errors
**And** large imports queued asynchronously

### Story 2.7: Low-stock alerts

As a **Store Manager**,
I want alerts when stock falls below reorder level,
So that replenishment happens proactively (FR-9).

**Acceptance Criteria:**

**Given** product at or below reorder level
**When** inventory changes or scheduled check runs
**Then** alert generated visible in store manager dashboard stub

---

## Epic 3: Purchasing & Suppliers

Suppliers, POs, approvals, GRN, invoices, payments (Phase 4).

### Story 3.1: Supplier management

As a **Procurement Officer**,
I want to manage suppliers and contacts,
So that purchasing has supplier records (FR-10).

**Acceptance Criteria:**

**Given** authenticated procurement officer
**When** supplier CRUD
**Then** supplier has contacts, addresses, product mappings
**And** CSV import/export supported

### Story 3.2: Purchase order creation and approval

As a **Procurement Officer**,
I want to create POs with approval workflow,
So that spending is controlled (FR-11, UJ-4).

**Acceptance Criteria:**

**Given** supplier and products exist
**When** PO created and submitted
**Then** PO requires approval before receiving
**And** approver with `purchase.approve` permission can approve/reject
**And** status transitions audited

### Story 3.3: Goods receipt (partial)

As a **Procurement Officer**,
I want to receive partial deliveries against POs,
So that inventory reflects actual receipts (FR-11).

**Acceptance Criteria:**

**Given** approved PO
**When** GRN recorded for partial qty
**Then** inventory ledger entries created for received qty only
**And** PO status reflects partial fulfilment
**And** remaining qty can be received on subsequent GRN

### Story 3.4: Supplier invoices and payments

As a **Procurement Officer**,
I want to record supplier invoices and payments,
So that accounts payable is tracked (FR-11).

**Acceptance Criteria:**

**Given** goods received
**When** invoice matched and payment recorded
**Then** supplier balance updated
**And** partial payments supported
**And** all amounts use DECIMAL types

### Story 3.5: Purchase returns

As a **Procurement Officer**,
I want to return goods to suppliers,
So that inventory and supplier balance are corrected (FR-11).

**Acceptance Criteria:**

**Given** received goods
**When** purchase return processed
**Then** purchase_return ledger movement created
**And** supplier balance adjusted

---

## Epic 4: POS — Online

Online POS UI, cart, payments, receipts, cash sessions (Phase 5). **MVP release boundary.**

### Story 4.1: Pricing and promotion services

As a **Developer**,
I want backend pricing and promotion resolution services,
So that POS never hard-codes prices (FR-19, FR-20, AD-6).

**Acceptance Criteria:**

**Given** price lists and promotion rules configured
**When** POS requests price for product/variant/customer/store
**Then** correct price and applicable promotions returned
**And** tax calculated per store config (FR-21)

### Story 4.2: Customer records for POS

As a **Cashier**,
I want to select walk-in or registered customers,
So that sales are attributed correctly (FR-22, FR-23).

**Acceptance Criteria:**

**Given** POS session active
**When** cashier searches/selects customer
**Then** walk-in default available
**And** registered customer lookup by name/phone/account
**And** customer history viewable by authorized staff

### Story 4.3: POS Vue application shell

As a **Cashier**,
I want a fast POS UI with keyboard shortcuts,
So that sales are efficient (FR-15, UJ-1).

**Acceptance Criteria:**

**Given** Vue POS app with Sanctum auth
**When** cashier opens POS for assigned store/terminal
**Then** keyboard shortcuts work (F2 search, F4 customer, F8 discount, F9 payment, ESC cancel)
**And** layout optimized for speed (large tap targets, minimal clicks)
**And** PWA manifest present (offline shell deferred to Epic 5)

### Story 4.4: Cart and product search in POS

As a **Cashier**,
I want to search and scan products into cart,
So that items are added quickly (FR-12, FR-39).

**Acceptance Criteria:**

**Given** active cart
**When** barcode scan or search
**Then** variant added with qty, price, tax line
**And** qty adjustable, authorized price override gated by permission
**And** search returns in <200ms online

### Story 4.5: Sale completion with atomic transaction

As a **Cashier**,
I want to complete payment and receive receipt,
So that sale is recorded correctly (FR-12, FR-14, UJ-1).

**Acceptance Criteria:**

**Given** cart with items and customer
**When** payment confirmed (cash, card, split, and/or installment plan with deposit)
**Then** single DB transaction creates sale, items, movements, payment, invoice stub, audit
**And** if installmentPlan present, plan + schedule lines are created in the same transaction (FR-27)
**And** failure rolls back entirely
**And** receipt printable/emailable (queued)
**And** completion ≤3s excluding queued jobs

### Story 4.6: Hold, suspend, and void sales

As a **Cashier**,
I want to hold, resume, and void sales,
So that interruptions are handled (FR-12).

**Acceptance Criteria:**

**Given** active or suspended sale
**When** hold/resume/void actions
**Then** suspended sales retrievable
**And** void creates audit trail without inventory impact (or reverses if completed)

### Story 4.7: Cashier sessions and cash management

As a **Cashier**,
I want to open/close register sessions with cash tracking,
So that cash variance is reconcilable (FR-16, FR-17, UJ-3).

**Acceptance Criteria:**

**Given** terminal assigned
**When** session opened with opening float
**Then** cash in/out, sales, refunds tracked
**And** close computes expected vs actual variance
**And** Store Manager can review sessions for assigned stores only

### Story 4.8: Returns and refunds (POS)

As a **Cashier**,
I want to process returns and refunds,
So that customers are credited correctly (FR-18).

**Acceptance Criteria:**

**Given** completed sale
**When** return/refund with reason
**Then** sale_return inventory movement created
**And** refund recorded (original method, store credit, partial)
**And** authorized by permission
**And** open installment plans on the sale are blocked or recalculated per cancel/refund rules before refund completes

### Story 4.9: Create installment plan at POS

As a **Store Manager**,
I want to finance a sale with a deposit and installment schedule,
So that registered customers can pay over time (FR-27, AD-14, UJ-7).

**Acceptance Criteria:**

**Given** registered customer, open session, and cart total
**When** authorized user creates sale with down payment + installment plan
**Then** sale completes atomically with inventory posted (pay_over_time mode)
**And** installment_plans + installments rows match schedule amounts/due dates
**And** customer credit limit is enforced
**And** walk-in customer is rejected for plans
**And** split tender may be used for the deposit only; remaining balance must equal sum of installments
**And** plan creation is denied when POS is offline (Phase 6)

### Story 4.10: Collect installment payments

As a **Cashier**,
I want to collect a due installment against an open plan,
So that AR is reduced correctly (FR-28, UJ-7).

**Acceptance Criteria:**

**Given** an open installment plan with a due or payable installment
**When** payment is collected via manual/Stripe/Paystack
**Then** installment status becomes paid; plan outstanding decreases
**And** Idempotency-Key prevents double collection
**And** receipt/statement is generated
**And** overdue installments are visible and collectable
**And** partial installment amount requires `sales.installments.partial`
**And** overpay beyond remaining plan balance is rejected (default policy)

### Story 4.11: Create and issue proforma invoices

As a **Store Manager**,
I want to generate a proforma invoice PDF for a customer,
So that I can quote before committing stock or AR (FR-29, AD-15, UJ-8).

**Acceptance Criteria:**

**Given** a customer and line items (variants, qty, prices, tax)
**When** proforma is created and issued
**Then** document uses proforma number series (not tax-invoice numbers)
**And** PDF/UI shows PROFORMA / “Not a tax invoice”
**And** no inventory movements, AR, or journal posts occur
**And** statuses support draft → issued → cancelled/expired
**And** email/download of PDF is available

### Story 4.12: Convert proforma to sale or tax invoice

As a **Store Manager**,
I want to convert an issued proforma into a sale and/or tax invoice,
So that confirmed quotes become real transactions (FR-30, UJ-8).

**Acceptance Criteria:**

**Given** an issued or accepted proforma
**When** convert is invoked (target sale, invoice, or both)
**Then** resulting sale/invoice lines match converted content (re-priced unless honor-prices permission)
**And** inventory posts only if a sale is created (normal sale rules)
**And** proforma status becomes `converted` with links to resulting ids
**And** already converted or cancelled proformas are rejected
**And** optional installment plan may be attached at conversion (FR-27)

---

## Epic 5: Offline POS & Sync (Phase 6 — Post-MVP)

High-level stories; detail after Epic 0.3 offline design.

### Story 5.1: PWA offline shell with IndexedDB
### Story 5.2: Local product/inventory cache sync
### Story 5.3: Offline sale queue with UUID
### Story 5.4: Push sync API with idempotency
### Story 5.5: Pull sync with cursors
### Story 5.6: Sync status UI and error recovery
### Story 5.7: Device registration and management

---

## Epic 6: Customer Portal (Phase 7 — Post-MVP)

### Story 6.1: Customer registration and auth
### Story 6.2: Product catalogue and search
### Story 6.3: Cart and checkout
### Story 6.4: Order tracking and invoices
### Story 6.5: Returns request flow

---

## Epic 7: Order Fulfilment (Phase 8 — Post-MVP)

### Story 7.1: Stock reservation for online orders
### Story 7.2: Picking and packing workflow
### Story 7.3: Shipment and delivery tracking
### Story 7.4: Store pickup workflow

---

## Epic 8: Reporting & Notifications (Phase 9 — Post-MVP)

### Story 8.1: Sales and inventory dashboards
### Story 8.2: Store performance reports
### Story 8.3: Notification service (email/SMS/in-app)

---

## Epic 9: Production Hardening (Phase 10 — Post-MVP)

### Story 9.1: Security review and penetration test fixes
### Story 9.2: Performance and database optimization
### Story 9.3: Observability (Sentry, metrics)
### Story 9.4: Backup, DR, and load testing
### Story 9.5: Full E2E and offline sync test suite
