# Epic 8 Context: Reporting & Notifications

<!-- Compiled from planning artifacts. Edit freely. Regenerate with compile-epic-context if planning docs change. -->

## Goal

Deliver Phase 9 (post-MVP) analytics and outbound messaging: role-scoped sales/inventory dashboards, filterable store and domain performance reports, and a multi-channel notification service (email, SMS abstraction, push, in-app) so operators and customers get timely operational signals without blocking POS or other critical paths. Full executive analytics were explicitly deferred from MVP into this epic. Epic stories list titles only — distill requirements from PRD/addendum Phase 9, proposal notification/reporting capability lists, architecture (AD-7), permission matrix, and Admin/SM dashboard UX.

## Stories

- Story 8.1: Sales and inventory dashboards
- Story 8.2: Store performance reports
- Story 8.3: Notification service (email/SMS/in-app)

## Requirements & Constraints

- **Dashboards (executive / SM / inventory-oriented):** surface KPIs such as total sales, revenue, gross profit, order count, average order value, top products/stores, sales trends; store-manager views for daily sales, cashier performance, inventory/low stock, returns, discounts, register reconciliation; inventory views for stock value, movements, fast/slow/dead stock, reorder needs.
- **Store performance & domain reports:** sales, inventory, purchase, customer, store performance, cashier performance, and profitability reports — filterable by date, store, product, category, customer, supplier, personnel, and payment method.
- **Store-manager scoped ops view:** today’s sales, stock alerts, pending orders, register status (and related attention items) for assigned stores only — not org-wide unless the user’s roles/stores allow it.
- **Low-stock signals:** alerts driven by reorder thresholds; appear on dashboard and as notification events.
- **Notifications:** support email, SMS provider abstraction, push, and in-app channels. Trigger examples include order placed/confirmed, payment received, order shipped/delivered, invoice generated, low stock, PO approved, stock received, refund processed. Org settings include notification configuration.
- **Receipt / document messaging:** sale completion and similar flows may already queue email/SMS/PDF; this epic owns the shared notification productization — critical path must not wait on delivery.
- **Authorization:** `reports.view` is store-scoped; allowed for SA, OA, SM, IM, PO, AC — denied for CA and DS. Hide unauthorized nav; deep links that fail auth show 403 with back.
- **Money & inventory invariants unchanged:** DECIMAL only; never mutate inventory quantities outside the ledger service. Reporting reads facts; it is not a substitute for the accounting ledger.
- **Out of scope here:** production hardening / load testing / DR (Epic 9); treating report exports as books of record; SaaS multi-tenancy.

## Technical Decisions

- **Modules:** Notifications and Reporting are first-class domain modules (alongside Audit/Settings); communicate via application services and domain events, not fat controllers (AD-6, AD-8).
- **Side effects (AD-7):** email, SMS, PDF generation, and report generation dispatch via queued events **after** transaction commit. Sale/order/payment consistency never depends on async completion. Use Redis/queue workers already in the stack.
- **Data:** conceptual `notifications` table exists in the planning schema list; report/dashboard APIs are not yet in the API design doc — add versioned `/api/v1` resources with pagination/error conventions, Sanctum auth, and store scope (`X-Store-Id` + Policies).
- **Clients:** Admin/Store Manager Vue SPA (shared app, role-based nav); API-first — no Inertia (AD-1). Customer portal may consume in-app/order notifications but must not reach admin report APIs.
- **Providers:** abstract SMS (and email) behind adapters so providers are swappable; do not hard-code a single vendor in domain code.
- **Deferred tech:** WebSocket real-time live dashboards not required for MVP POS — revisit for live dashboard/portal status if needed; dedicated search engines remain optional unless report query performance demands them.
- **Accounting boundary (AD-12):** full double-entry accounting is separate; reporting must not be mistaken for the books of record.

## UX & Interaction Patterns

- Admin/SM **Dashboard** nav: role-scoped KPIs (today’s sales, low stock, pending approvals/orders, open registers). Composition reference: admin-dashboard mockup (KPI strip + low-stock list + needs-attention panel); DESIGN spine wins on conflict.
- Store switcher in top bar when the user has multiple stores; all list/report queries respect selected store scope.
- Low stock on dashboard: ember accent list; use stronger/red treatment only for zero or negative available qty.
- Avoid pill-cluster / generic multi-card dashboard chrome; keep one clear purpose per surface; follow existing Admin DESIGN tokens and WCAG 2.2 AA.
- Permission denied: hide nav items; unauthorized deep links → 403 with back.

## Cross-Story Dependencies

- **Requires Epics 1–7 data & events:** org/stores/RBAC, catalog, inventory ledger & low-stock thresholds, purchasing, sales/POS/registers, portal orders, and fulfilment status changes — dashboards and notifications consume these facts/events rather than inventing parallel stores of truth.
- **Within epic:** 8.1 dashboards and 8.2 store/domain reports share reporting aggregation and `reports.view` gating; 8.3 notification service is the channel layer that domain events (including fulfilment/order/payment/low-stock) subscribe to.
- **From Epic 7:** fulfilment status changes should already emit events so this epic can deliver email/SMS/in-app without reworking fulfilment core.
- **Hands off to Epic 9:** performance/observability hardening for heavy report jobs and queue monitoring — not a substitute for correct async design here.
