# Epic 4 Context: POS — Online

<!-- Compiled from planning artifacts. Edit freely. Regenerate with compile-epic-context if planning docs change. -->

## Goal

Deliver the online POS MVP: pricing/promotion/tax resolution, customer selection, Vue POS shell with cart and payments, atomic sale completion with receipts, cash sessions, returns/refunds, installment plans + collection, and proforma create/convert. This epic is the **MVP release boundary** — cashiers must complete fast in-store sales online; offline sync is Epic 5.

## Stories

- Story 4.1: Pricing and promotion services
- Story 4.2: Customer records for POS
- Story 4.3: POS Vue application shell
- Story 4.4: Cart and product search in POS
- Story 4.5: Sale completion with atomic transaction
- Story 4.6: Hold, suspend, and void sales
- Story 4.7: Cashier sessions and cash management
- Story 4.8: Returns and refunds (POS)
- Story 4.9: Create installment plan at POS
- Story 4.10: Collect installment payments
- Story 4.11: Create and issue proforma invoices
- Story 4.12: Convert proforma to sale or tax invoice

## Requirements & Constraints

- Cashiers search/scan variants into a cart, adjust qty, apply authorized discounts/promotions, select walk-in or registered customer, and settle with cash/card/bank/mobile/store credit — including split tender.
- Informal leftover balances are forbidden; a remaining balance at checkout requires an Installment Plan on a registered customer (not walk-in).
- Sale completion is atomic: items → inventory validation/movements → payments → invoice stub → audit in one DB transaction; failure rolls back entirely. Email/PDF/SMS receipts queue after commit.
- Success targets: product search ≤200ms p95 online; sale completion ≤3s from payment confirm (excluding queued jobs).
- Hold/resume suspended sales; void with audit (no inventory impact if never completed; reverse if completed).
- Cashier sessions: open with float, track cash in/out/sales/refunds, close with expected vs actual variance; Store Managers review sessions for assigned stores only.
- Returns/refunds create `sale_return` movements; refunds support original method, store credit, partial; open installment plans blocked or recalculated before refund completes.
- Pricing: resolve via price lists + promotion rules (% / fixed / BOGO) + tax (inclusive/exclusive by store/product/category) — never hard-code prices in POS.
- Customers: walk-in default; lookup by name/phone/account; authorized staff see history (sales, invoices, payments, returns, plans).
- Installments: deposit + schedule at sale; inventory posts at sale (`pay_over_time` default); credit limit enforced; plan creation online-only; collect with Idempotency-Key; partial installment needs `sales.installments.partial`; reject overpay beyond plan remaining by default.
- Proformas: separate number series, labeled “PROFORMA / Not a tax invoice”; no inventory/AR/journals until convert; statuses draft → issued → converted/cancelled/expired; convert re-prices unless honor-prices permission; optional installment at convert.
- Gift cards deferred; loyalty is a rules engine (not a dead field) — wire when needed without blocking core sale path.

## Technical Decisions

- **Clients:** separate Vue 3 POS app under `frontend/pos/` (Sanctum Bearer, PWA manifest; offline shell deferred). Admin SPA for proformas/cash review. No Inertia. Store scope via `X-Store-Id` + Policies.
- **Modules / services:** Pricing, Sales/POS, Customers, Payments, Invoices. Domain logic in named services (pricing, promotions, tax, inventory) — not fat controllers. Inventory quantity changes **only** through InventoryLedgerService (`sale` / `sale_return`).
- **Money:** `DECIMAL` only. Client UUIDs + `Idempotency-Key` on sale create, installment collect, and other money/stock POSTs.
- **Payments:** PaymentService adapters for `manual` | `stripe` | `paystack`; signed, idempotent webhooks. Split payment = same-checkout multi-tender only; InstallmentPlan is the time-separated AR aggregate.
- **Receipts:** ESC/POS via a printer service port (not raw device APIs); print/email/SMS after commit.
- **Key REST:** `POST /pricing/resolve`; `/customers` + history; `/pos/sessions/open|close`; `POST /pos/sales` (± optional `installmentPlan`); hold/void/returns/receipts; `/installment-plans` + payments/cancel; `/proforma-invoices` issue/cancel/convert/pdf; `/payments/intents` + provider webhooks.
- **Permissions (store-scoped where marked):** `pos.session`, `sales.create|view|void|refund|price-override|discount`, `sales.installments.*`, `invoices.proforma.*`, `pricing.*`, `customers.*`. Cashiers typically cannot void/refund/price-override or create plans; they can collect installments and view proformas.

## UX & Interaction Patterns

- Three-zone POS: chrome (store/terminal/cashier/**sync badge**/session) · search/scan grid · cart rail (customer, total, Pay/Hold/Void). Sync badge always visible (ONLINE in MVP; offline states are Epic 5).
- Shortcuts: F2 search, F4 customer, F8 discount, F9 payment, Esc cancel; Enter confirms; scanner = keyboard wedge into search. Touch targets ≥44px; modals max one deep; no infinite-scroll cart.
- Pay CTA disabled until session open + ≥1 line; payment sheet supports tenders + installment schedule editor (blocks walk-in). Session closed blocks sales.
- Visual: charcoal POS chrome, forest primary for go/pay, ember accent for attention; total is the dominant element; money/SKU in mono. WCAG 2.2 AA; sync state has text label, not color alone.
- Journeys: fast sale → receipt; EOD variance; deposit + schedule then later collect installment; proforma issue PDF → convert to sale/tax invoice.

## Cross-Story Dependencies

- **Requires Epics 1–3:** auth/RBAC/store context, product catalog + barcodes, InventoryLedgerService, and seeded permission matrix.
- Within epic: pricing (4.1) and customers (4.2) before cart/sale (4.4–4.5); shell (4.3) before cart UI; sessions (4.7) gate Pay CTA; sale completion (4.5) before void/returns/installments/proforma convert (4.6, 4.8–4.12); plan create (4.9) before collect (4.10); proforma create/issue (4.11) before convert (4.12).
- **Defers to Epic 5:** IndexedDB offline shell, offline sale queue, sync push/pull, offline oversell exceptions. Do not create installment plans offline.
