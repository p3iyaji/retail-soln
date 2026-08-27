# Epic 2 Context: Products & Inventory

<!-- Compiled from planning artifacts. Edit freely. Regenerate with compile-epic-context if planning docs change. -->

## Goal

Deliver the multi-store product catalog and inventory lifecycle that later purchasing and POS depend on: sellable variants with barcodes, an append-only ledger as the only stock mutation path, adjustments/counts/transfers (including in-transit), fast search and CSV import/export, and low-stock alerts for store managers. Inventory accuracy and multi-store isolation are non-negotiable quality bars for MVP.

## Stories

- Story 2.1: Product catalog CRUD
- Story 2.2: Product variants and barcodes
- Story 2.3: Inventory ledger service
- Story 2.4: Stock adjustments and counts
- Story 2.5: Stock transfers between locations
- Story 2.6: Product search and import/export
- Story 2.7: Low-stock alerts

## Requirements & Constraints

- Catalog holds products with SKU, barcode, category, brand, supplier, unit, images, and variants; reorder/min/max thresholds are configurable per sellable SKU.
- Each variant is an independent sellable SKU with its own barcode and pricing; barcode uniqueness is enforced per organization.
- Product search by SKU, barcode, name, and category must meet ≤200ms p95 online.
- CSV/Excel import/export supports validation, preview, and error reporting; large imports run asynchronously.
- Every stock change is an immutable ledger movement (type, qty, cost, reference, user, location, timestamp). Supported types include purchase, sale, returns, transfer_out/in, adjustment, damage, expiry, opening_balance, stock_count, reservation, and reservation_release.
- Balances are derived or materialized from movements — never edited as the sole source of truth.
- Stock operations (adjust, transfer, count) require reason/audit trail; transfers track in-transit qty until destination receipt; count variance posts adjustment movements; low-stock alerts fire when at/below reorder level.
- Store-scoped users see only assigned locations; inventory permissions use strings such as `products.view|manage|import` and `inventory.view|adjust|transfer|count` (store-scoped where marked).
- Success bar: no inventory discrepancies from concurrent mutations attributable to bypassing the ledger; catalog search stays within the p95 budget.

## Technical Decisions

- Products and Inventory are separate nWidart modules behind `/api/v1`; Admin Vue SPA is the UI surface (no Inertia).
- `InventoryLedgerService` is the sole application path that mutates balances; direct quantity writes are prohibited by convention and tests.
- Domain shape: Product → ProductVariant → Barcode; InventoryBalance per (location, variant) with available/reserved/damaged/in_transit; append-only InventoryMovement; StockTransfer (+ items) and StockCount (+ lines) that post movements.
- Prefer `location_type` + `location_id` on balances/movements over separate store/warehouse FK schemes; serial/batch/expiry columns may exist but full lot tracking is deferred.
- Money and costs use DECIMAL (never float); API JSON is camelCase; store-scoped calls send `X-Store-Id`; stock/money-creating POSTs may carry `Idempotency-Key`; list endpoints paginate with `meta` page totals; errors use `{ message, errors? }`.
- Catalog/inventory API surface: product/variant CRUD, `/products/search`, `/products/import`, categories/brands/units, balances, adjustments, transfers (+ receive), counts, movements, and `/inventory/alerts/low-stock`.
- Policies enforce permissions server-side; sensitive mutations belong in the append-only audit log.

## UX & Interaction Patterns

- Admin/SM share one Vue app: Products (catalog, variants, import) and Inventory (balances, adjustments, transfers, counts) in nav; store switcher scopes list queries.
- Dashboard surfaces low-stock with ember accent (destructive red only for zero/negative); dense tables with sticky headers; mono type for SKUs, barcodes, and money columns.
- Operational clarity styling (forest primary, ember attention); WCAG 2.2 AA on Admin. POS barcode/search UX is out of scope until Epic 4.

## Cross-Story Dependencies

- Requires Epic 1: auth, org/stores/warehouses, RBAC/Policies, audit, Admin shell.
- Ledger (2.3) must land before adjustments, transfers, counts, and alerts that post or read stock state; catalog/variants precede inventory keyed by variant.
- Epic 3 GRNs and Epic 4 sales/returns must post stock only through the same ledger; search endpoint is shared with future POS.
- Transfer + count journey (warehouse→store receive, then variance adjustment) is the inventory manager end-to-end path this epic enables.
