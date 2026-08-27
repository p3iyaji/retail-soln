# Epic 3 Context: Purchasing & Suppliers

<!-- Compiled from planning artifacts. Edit freely. Regenerate with compile-epic-context if planning docs change. -->

## Goal

Deliver the procure-to-pay path for multi-store retail: supplier records, purchase orders with gated approval, partial goods receipt into the inventory ledger, supplier invoices/payments (AP), and purchase returns. Success means the full cycle runs without anyone manually adjusting stock outside the ledger.

## Stories

- Story 3.1: Supplier management
- Story 3.2: Purchase order creation and approval
- Story 3.3: Goods receipt (partial)
- Story 3.4: Supplier invoices and payments
- Story 3.5: Purchase returns

## Requirements & Constraints

- Procurement staff manage suppliers (contacts, addresses, product mappings) with CSV import/export.
- PO lifecycle: create → submit → approve/reject → receive (partial OK) → supplier invoice → payment; receiving is blocked until approved.
- Approval is permission-gated (`purchase.approve`); status changes are audited. Dual-control may forbid a user approving their own PO when that org setting is on.
- GRN posts inventory only for received qty; PO status reflects partial fulfilment; remaining qty stays open for later GRNs.
- Supplier invoices match receipts; payments (including partial) update supplier balance; all money amounts use DECIMAL (never float).
- Purchase returns reverse inventory via ledger movements and adjust supplier balance.
- Admin SPA exposes Purchasing (suppliers, POs, GRN, payments); APIs under `/api/v1` with store-scoped authorization where applicable.
- Role permissions: `suppliers.view` / `suppliers.manage`; `purchase.create`, `purchase.approve`, `purchase.receive`, `purchase.pay` (org vs store scope per matrix).

## Technical Decisions

- Implement in a **Purchasing** nWidart module; Vue Admin SPA consumes REST — no Inertia.
- **Inventory ledger is the sole stock mutation path.** GRN posts `purchase` movements; returns post `purchase_return`. Never write balances directly.
- Money and qty as `DECIMAL(15,4)`. Prefer UUID where client/idempotent entities apply; API kebab-case URLs, camelCase JSON responses.
- Domain shape: Supplier (+ contacts, product mappings); PurchaseOrder (+ items) with status machine `draft → pending_approval → approved → partial/received → closed`; GoodsReceipt (+ items, partial OK); SupplierInvoice / SupplierPayment; purchase return header/items.
- REST surface: CRUD `/suppliers`; CRUD `/purchase-orders` plus `submit` / `approve`; POST `/goods-receipts`; CRUD `/supplier-invoices`, `/supplier-payments`. Use Idempotency-Key on POSTs that create money/stock mutations.
- Financial/stock posting paths should be atomic (rollback on failure); queue non-critical side effects after commit.
- Store-scoped queries and Policies enforce org/store isolation server-side.

## UX & Interaction Patterns

- Primary journey: create PO → submit → Store Manager / Org Admin approve → receive partial qty → balances update → PO shows partial with remaining open.
- Admin Purchasing nav: Suppliers, POs, GRN, payments; dense data tables with sticky headers; calm light workspace chrome.

## Cross-Story Dependencies

- **Requires Epic 2:** product catalog (incl. supplier linkage) and InventoryLedgerService before GRN/returns can post stock.
- Within epic: 3.1 before 3.2; approved PO (3.2) before GRN (3.3); receipts before invoices/payments (3.4) and returns (3.5).
- Feeds Epic 4+ only indirectly (accurate stock from purchasing); no POS UI work in this epic.
