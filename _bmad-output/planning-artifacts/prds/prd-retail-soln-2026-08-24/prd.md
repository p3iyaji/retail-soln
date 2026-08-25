---
title: Multi-Store Offline-First POS & Retail Management Platform
status: draft
created: 2026-08-24
updated: 2026-08-24
oq_resolved: 2026-08-24
source: docs/proposal.md
---

# PRD: Multi-Store Offline-First POS & Retail Management Platform

## 0. Document Purpose

This PRD distills `docs/proposal.md` into capability-focused requirements for PM, stakeholders, and downstream BMAD workflows (architecture, epics, sprint planning, build). Technical stack and implementation depth live in `addendum.md`. Architecture invariants live in `ARCHITECTURE-SPINE.md`.

**Structure:** Glossary-anchored vocabulary · features grouped with nested FRs · assumptions tagged inline · MVP aligned to proposal Phases 2–5.

---

## 1. Vision

Build a **production-grade multi-store retail platform** — not a simple CRUD app. The POS is one client of a shared platform serving admin, store managers, cashiers, procurement staff, and (post-MVP) customers via web portal.

The platform must support multiple stores and warehouses, full inventory lifecycle, purchasing, in-store sales, returns, pricing/promotions, tax, cash management, and audit trails. It must be architected for **offline-first POS** (Phase 6), **online synchronization**, and scale to hundreds of stores and millions of transactions.

Core quality bar: **financial accuracy, inventory accuracy, multi-store isolation, offline operation, synchronization, security, auditability, scalability, and maintainability** — never sacrificed for initial delivery speed.

---

## 2. Target User

### 2.1 Jobs To Be Done

- **Cashier:** Complete sales fast, handle interruptions, work through connectivity loss without losing transactions.
- **Store Manager:** Monitor store performance, reconcile cash, manage stock and staff for assigned stores only.
- **Procurement Officer:** Order from suppliers, track approvals, receive goods, manage supplier payments.
- **Inventory Manager:** Maintain accurate stock via ledger, transfers, counts, and alerts.
- **Organization Admin:** Configure org, stores, users, roles, and platform settings.
- **Customer (post-MVP):** Browse, order, pay, track delivery, manage returns online.

### 2.2 Non-Users (v1 MVP)

- End customers (no customer portal in MVP — Phase 7)
- External accounting/ERP systems (integration hooks only)
- SaaS multi-tenant operators hosting unrelated organizations on one instance `[ASSUMPTION: single org per deployment in v1]`

### 2.3 Key User Journeys

- **UJ-1. Amara completes a fast in-store sale.** Cashier opens session, scans items, applies discount, split-pays, receives receipt. Inventory and payment recorded atomically. Realizes FR-39–FR-52.
- **UJ-2. Amara sells offline and syncs on reconnect.** POS operates on local cache; sale stored with UUID; server validates idempotently on push. Realizes FR-82–FR-86 (Phase 6).
- **UJ-3. David reconciles end-of-day cash.** Store manager reviews sessions, variances, low stock for Store A only. Realizes FR-53–FR-55.
- **UJ-4. Fatima runs purchase-to-pay.** PO → approval → partial GRN → inventory update → supplier payment. Realizes FR-31–FR-38.
- **UJ-5. Sarah orders online for pickup (post-MVP).** Browse → cart → reserve stock → pay → track → collect. Realizes FR-67–FR-74.
- **UJ-6. James transfers stock and runs count.** Transfer warehouse→store, confirm receipt, stock count with variance adjustment. Realizes FR-24–FR-30.
- **UJ-7. Amara sells on installments.** Registered customer buys with deposit + 3-month plan; later pays installment #2 at POS; plan and AR update. Realizes FR-27, FR-28.
- **UJ-8. David issues a proforma then converts.** Store manager builds a proforma for a corporate customer, emails PDF, then converts to sale when they confirm. Realizes FR-29, FR-30.

---

## 3. Glossary

| Term | Definition |
| --- | --- |
| **Organization** | Top-level entity owning stores, warehouses, catalog, and configuration |
| **Store** | Retail outlet with inventory, terminals, personnel, tax/currency settings |
| **Warehouse** | Non-retail stock location supplying stores via transfer |
| **Terminal / Device** | Registered POS endpoint bound to store with sync metadata |
| **Product Variant** | Sellable SKU with own barcode, price, and inventory |
| **Inventory Ledger** | Append-only stock movement record; authoritative over mutable qty |
| **Stock Movement** | Typed ledger entry (sale, purchase, transfer, adjustment, reservation, etc.) |
| **Stock Reservation** | Temporary hold preventing oversell on online orders |
| **Purchase Order** | Supplier order with approval and receiving workflow |
| **Goods Receipt** | Record of received goods against PO; supports partial qty |
| **Sale** | POS or online transaction with UUID, items, tax, payments |
| **Cashier Session** | Open/close register period with cash variance tracking |
| **Idempotency Key** | Transaction UUID preventing duplicate processing on retry |
| **Sync Batch** | Incremental push/pull unit with cursor checkpoint |
| **Price List** | Named pricing tier resolved by pricing service |
| **Promotion** | Rule-based discount scoped by product, store, date, quantity |
| **RBAC** | Role-based access with granular permissions |
| **Audit Log** | Append-only record of sensitive entity changes |
| **Walk-in Customer** | Default anonymous customer for POS without lookup |
| **Split Payment** | Settling one sale at checkout with multiple payment methods and/or amounts in a single completion |
| **Installment Plan** | Scheduled repayment of a sale/invoice balance over time (due dates, installment amounts, outstanding AR); distinct from split payment |
| **Installment** | One scheduled payment line on an Installment Plan (amount, due date, status: pending/paid/overdue/waived) |
| **Tax Invoice** | Formal customer invoice that affects AR/payment status once Issued (statuses include Draft → Issued → Paid, etc.) |
| **Proforma Invoice** | Non-binding preliminary invoice/quote issued before a sale or tax invoice; marked as proforma; does **not** post inventory or AR until converted to a Sale and/or Issued Tax Invoice |

---

## 4. Features

### 4.1 Organization & Multi-Store

**Description:** Single organization owns multiple stores and warehouses. Users access all or specific stores. All queries enforce store boundaries. Realizes UJ-3, UJ-6.

**Functional Requirements:**

#### FR-1: Multi-store organization

Organization Admin can create and manage stores and warehouses with independent inventory, terminals, and settings.

**Consequences:**
- Each store has address, contacts, hours, currency, tax config
- Users assignable to all stores, specific stores, or warehouses
- Cross-store data access denied without authorization

#### FR-2: Store-scoped authorization

System enforces organization and store boundaries on every store-scoped query and mutation.

**Consequences:**
- Store Manager for Store A cannot read Store B sales or inventory
- Policy layer enforced server-side on all API endpoints

---

### 4.2 Authentication, Users & RBAC

**Description:** Fortify + Sanctum auth with granular RBAC. Personnel linked to stores and roles. Realizes all UJs.

#### FR-3: Role-based access control

System supports roles (Super Admin, Org Admin, Store Manager, Cashier, Inventory Manager, Procurement Officer, Accountant, Delivery Staff, Customer) with granular permissions.

**Consequences:**
- Permissions like `sales.refund`, `inventory.transfer` enforced via Policies
- 2FA available for privileged accounts
- Customer role isolated from admin API surface

#### FR-4: Personnel management

Org Admin can manage employee records linked to stores, roles, and active status.

---

### 4.3 Products & Catalog

**Description:** Full product catalog with variants, barcodes, categories, brands, import/export. Realizes UJ-1, UJ-6.

#### FR-5: Product catalog

System manages products with SKU, barcode, category, brand, supplier, unit, images, variants, and price tiers.

**Consequences:**
- Variants have independent SKU, barcode, pricing
- Reorder/min/max thresholds configurable
- Optional serial, batch, expiry tracking

#### FR-6: Product search

Users can search products by SKU, barcode, name, and category with sub-200ms response online.

#### FR-7: Product import/export

Admin can import/export products via CSV/Excel with validation, preview, and error reporting.

---

### 4.4 Inventory & Ledger

**Description:** Store-specific inventory via immutable ledger. No sole quantity mutation. Realizes UJ-1, UJ-2, UJ-6.

#### FR-8: Inventory ledger

All stock changes recorded as immutable ledger movements with type, qty, cost, reference, user, store, timestamp.

**Consequences:**
- Movement types: purchase, sale, sale_return, purchase_return, transfer_out/in, adjustment, damage, expiry, opening_balance, stock_count, reservation, reservation_release
- Balances derived/materialized from ledger
- Concurrent terminal sales reconcile without silent overwrite

#### FR-9: Stock operations

Inventory Manager can receive, adjust, transfer, count stock with reason codes and audit trail.

**Consequences:**
- Transfers track in-transit quantity
- Stock counts generate variance adjustments
- Low-stock alerts based on reorder thresholds

---

### 4.5 Purchasing & Suppliers

**Description:** Full procure-to-pay workflow. Realizes UJ-4.

#### FR-10: Supplier management

Procurement Officer manages suppliers with contacts, addresses, and product mappings.

#### FR-11: Purchase order workflow

System supports PO creation → approval → goods receipt (partial) → supplier invoice → payment.

**Consequences:**
- Approval required before receiving (permission-gated)
- GRN creates inventory ledger entries automatically
- Purchase returns create reverse movements

---

### 4.6 POS — In-Store Sales (MVP: online-only)

**Description:** Fast POS optimized for speed. MVP delivers online POS; offline in Phase 6. Realizes UJ-1.

#### FR-12: POS sale processing

Cashier can search/scan products, adjust qty, apply authorized discounts/promotions, select customer, and complete payment.

**Consequences:**
- Payment methods: cash, card, bank transfer, mobile, store credit
- Split payments supported (multiple methods at one checkout)
- Deposit-style partial pay at checkout may leave a balance only when an Installment Plan is attached (FR-27) — not an informal untracked balance
- Hold/suspend and resume sale
- Void/cancel with audit trail

#### FR-13: POS receipts

System generates print, digital, email, and SMS receipts.

#### FR-14: Atomic sale transaction

Sale atomically creates sale items, validates inventory, records movements, payment, invoice, and audit event in one DB transaction.

**Consequences:**
- Failure rolls back entire sale
- Background jobs (email, PDF) queued, not blocking sale completion

#### FR-15: POS UX speed

POS supports keyboard shortcuts (F2 search, F4 customer, F8 discount, F9 payment, ESC cancel), touchscreen, and barcode scanner input.

---

### 4.7 Cash Register Management

**Description:** Register sessions with cash variance. Realizes UJ-3.

#### FR-16: Cashier sessions

Cashier opens session with opening float; system tracks cash in/out, sales, refunds.

#### FR-17: Session reconciliation

Session close computes expected vs actual cash and records variance; auditable and reportable.

---

### 4.8 Returns & Refunds

#### FR-18: Returns processing

Authorized user can process full/partial returns and exchanges with reason codes.

**Consequences:**
- Returns create sale_return inventory movements
- Refunds tracked independently; support original method, store credit, partial refund

---

### 4.9 Pricing, Promotions & Tax

#### FR-19: Price lists

System supports multiple price lists (retail, wholesale, VIP, store-specific, online) resolved by pricing service.

#### FR-20: Promotions

Promotions support % discount, fixed discount, BOGO with product/category/store/date/quantity rules.

#### FR-21: Tax configuration

Tax configurable by rate, category, inclusive/exclusive, store, and product — not hard-coded to one country.

---

### 4.10 Customers

#### FR-22: Customer records

System manages walk-in, registered, business, and corporate customers with profile, addresses, group, credit limit, balance.

#### FR-23: Customer history

Staff can view customer order history, invoices, payments, returns, and installment plans.

---

### 4.10a Installment Payments (MVP)

**Description:** Formal scheduled repayment of sale/invoice balances for registered customers. Distinct from split payment at checkout. Realizes UJ-7.

#### FR-27: Installment plan on sale or invoice

Authorized staff can attach an **Installment Plan** to a sale (POS) or issued invoice when the customer is a registered customer (not walk-in).

**Consequences:**
- Plan defines: total financed amount, number of installments, schedule (equal or custom amounts), due dates, optional fees/interest per org policy
- Down payment / deposit recorded at plan creation via normal payment providers (manual, Stripe, Paystack)
- Remaining balance tracked as customer AR linked to the sale/invoice
- Inventory for the sale posts when the sale is completed (goods sold), not when each installment is paid — unless store policy uses layaway mode (see Notes)
- Each installment payment records against the plan via PaymentService (AD-10); statuses: pending → paid; overdue when past due and unpaid
- Staff can view plan status, outstanding balance, payment history; customer credit limit checked at plan creation
- Plan void/cancel requires permission; already-paid installments handled via refund rules (FR-18)
- Offline POS (Phase 6): creating new installment plans **online-only** unless sync design explicitly extends (default: block offline installment checkout)

**Out of Scope:**
- Consumer BNPL third-party products (Klarna, etc.) as native providers — integration hooks only if later required
- Gift-card-funded installment schedules (gift cards deferred OQ-1)

**Notes:** `[ASSUMPTION]` Default mode is **sale-now / pay-over-time** (inventory decrements at sale). Optional **layaway** mode (stock reserved until fully paid) is configurable per org and may trail initial installment ship if not needed day-one.

#### FR-28: Collect installment payments

Cashier or authorized staff can collect a due or early installment against an open plan at POS or Admin, applying manual/Stripe/Paystack, and update plan + invoice payment status atomically.

**Consequences:**
- Overpayment beyond outstanding rejected or applied to next installment per policy (default: reject overpay beyond plan remaining)
- Partial installment payment allowed only if permission `sales.installments.partial` granted; otherwise full installment amount required
- Receipt/statement generated for installment collection
- Accounting hooks post AR reduction / cash (AD-12) when accounting module active

---

### 4.10b Proforma Invoices (MVP)

**Description:** Staff can generate a **Proforma Invoice** (preliminary / draft commercial document) for a customer before committing a sale or issuing a tax invoice. Realizes UJ-8.

#### FR-29: Create and manage proforma invoices

Authorized staff can create, edit, save, PDF-download, email, cancel, and expire **Proforma Invoices** from Admin (and optionally POS quote flow).

**Consequences:**
- Proforma includes customer, store, line items (variant/SKU, qty, unit price, discount, tax, totals), optional notes, validity/expiry date
- Document is clearly labeled **PROFORMA INVOICE** / “Not a tax invoice” on screen and PDF
- Uses a **separate number series** from tax invoices (e.g. `PF-{store}-{seq}`) — never reuse tax-invoice numbers
- Statuses: `draft` → `issued` (sent/shared) → `accepted` | `converted` | `expired` | `cancelled`
- Creating or issuing a proforma **does not** mutate inventory, create AR, or post accounting journals
- Editable while `draft` or `issued` (org policy may lock after issued); cancelled/converted are immutable
- Audit log on create, issue, convert, cancel

#### FR-30: Convert proforma to sale or tax invoice

Authorized staff can convert an accepted/issued proforma into a **POS/Admin sale** and/or an **Issued Tax Invoice**, carrying line items and prices forward (re-validated at convert time).

**Consequences:**
- Convert re-prices/taxes via PricingService unless “honor proforma prices” permission is used
- Inventory and payments follow normal sale/invoice rules after conversion
- Proforma status becomes `converted` with link to resulting sale/invoice ids
- Cannot convert cancelled or already-converted proformas
- Optional: attach installment plan at conversion (FR-27) when customer is registered

**Out of Scope:**
- Customer self-service proforma request in portal (Phase 7 may add “quote request”)
- Legal e-invoicing authority submission for proformas (proformas are non-fiscal)

---

### 4.11 Customer Portal & Online Orders `[NON-GOAL for MVP]`

Deferred to Phase 7. See addendum for full FR set (FR-67–FR-74 in extraction).

---

### 4.12 Offline Sync & Devices `[NON-GOAL for MVP]`

Deferred to Phase 6. See addendum for sync FR set (FR-82–FR-86).

---

### 4.13 Notifications, Reporting & Audit

#### FR-24: Audit logging

System records append-only audit logs for sensitive changes (user, action, entity, before/after, IP, store, timestamp).

#### FR-25: Admin portal

Admin portal provides modules for organization, stores, users, products, inventory, purchasing, sales, settings.

#### FR-26: Store manager dashboard

Store Manager sees scoped dashboard: today's sales, stock alerts, pending orders, register status for assigned stores only.

---

## 5. Non-Goals (Explicit)

- Full offline POS with IndexedDB sync (Phase 6)
- Customer web portal and online checkout (Phase 7)
- Pick/pack/ship fulfilment workflow (Phase 8)
- Full analytics/reporting dashboards (Phase 9)
- Production hardening, load testing, DR (Phase 10)
- SaaS multi-tenancy (many unrelated orgs per instance)
- Full double-entry accounting module
- Native mobile apps (API-ready for future)
- Gift card program (scope TBD — likely post-MVP)

---

## 6. MVP Scope

### 6.1 In Scope (Phases 2–5)

| Phase | Deliverables |
| --- | --- |
| Foundation | Auth, org, stores, warehouses, users, roles, permissions, store access, settings, audit infra |
| Product & Inventory | Catalog, variants, barcodes, ledger, adjustments, transfers, stock counts |
| Purchasing | Suppliers, POs, approvals, GRN, supplier invoices/payments, purchase returns |
| POS (online) | POS UI, cart, search, barcode, customers, discounts, tax, payments (incl. installments), receipts, cash sessions, proforma create/convert |
| Foundation+ | Tax invoice Draft/Issued lifecycle remains supported alongside proformas (proforma ≠ tax-invoice draft) |

### 6.2 Out of Scope for MVP

| Item | Reason | Target phase |
| --- | --- | --- |
| Offline IndexedDB sync | Requires dedicated architecture | Phase 6 |
| Customer portal | Separate auth surface and checkout flows | Phase 7 |
| Order fulfilment | Depends on customer portal | Phase 8 |
| Executive dashboards | Reporting epic | Phase 9 |
| Load testing / DR | Hardening epic | Phase 10 |

---

## 7. Success Metrics

**Primary**
- **SM-1:** POS sale completion ≤3s from payment confirm (online). Validates FR-14, FR-15.
- **SM-2:** Zero inventory discrepancies from concurrent terminal sales in acceptance testing. Validates FR-8.
- **SM-3:** 100% of store-scoped authorization tests pass (no cross-store leakage). Validates FR-2.

**Secondary**
- **SM-4:** Product search ≤200ms p95 online. Validates FR-6.
- **SM-5:** Purchase-to-pay cycle completable without manual inventory adjustment. Validates FR-11.
- **SM-6:** Installment plan: after deposit + scheduled pay, outstanding AR matches schedule within 0.01 store currency. Validates FR-27, FR-28.
- **SM-7:** Proforma create → PDF shows PROFORMA watermark; convert creates sale/invoice without double inventory. Validates FR-29, FR-30.

**Counter-metrics**
- **SM-C1:** Do not optimize for feature count over ledger/financial correctness.

---

## 8. Open Questions — Resolved 2026-08-24

| ID | Decision | Impact |
| --- | --- | --- |
| **OQ-1** | Gift cards **deferred** (post-MVP) | Remains Non-Goal for MVP |
| **OQ-2** | MVP payment providers: **manual + Stripe + Paystack** | Payment abstraction must support all three in Epic 4 |
| **OQ-3** | Offline oversell: **allow negative stock with manager approval** | Sync conflict path creates approval-required exception; not hard-reject |
| **OQ-4** | Loyalty: **rules engine in v1** | Not field-only; loyalty engine required (scope into Epic 4 / dedicated stories) |
| **OQ-5** | Accounting: **full ledger** | Double-entry accounting module required (not export-only); post-MVP phase or parallel epic after foundation |
| **OQ-6** | Receipt printers: **ESC/POS** for POS | POS hardware abstraction targets ESC/POS in Epic 4 |

---

## 9. Assumptions Index

- `[ASSUMPTION]` Single organization per deployment in v1; SaaS multi-tenancy deferred
- `[ASSUMPTION]` Vue 3 SPA without Inertia.js (proposal line 77 overrides Inertia listing)
- `[ASSUMPTION]` Laravel version pinned at implementation start (proposal says Laravel 13)
- `[ASSUMPTION]` MVP POS is online-only; offline architecture designed but not implemented until Phase 6
- `[ADOPTED]` Payment providers for MVP: manual recording, Stripe, and Paystack (OQ-2)
- `[ADOPTED]` Offline oversell allows negative inventory with manager approval workflow (OQ-3)
- `[ADOPTED]` Loyalty rules engine in v1 (OQ-4)
- `[ADOPTED]` Full accounting ledger (OQ-5) — timing relative to MVP POS TBD in Epic 0 gate
- `[ADOPTED]` ESC/POS receipt printing for POS (OQ-6)
- `[ADOPTED]` Gift cards deferred (OQ-1)
