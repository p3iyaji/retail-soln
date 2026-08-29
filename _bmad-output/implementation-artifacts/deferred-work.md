# Deferred work

- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-laravel-scaffold.md`
  summary: Refresh `_bmad-output/handover-epic-1.md` so it no longer claims no application code / Epic 1 backlog.
  evidence: Handover still describes pre-scaffold state after Story 1.1 landed.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-laravel-scaffold.md`
  summary: Add CORS / SPA origin configuration for Admin and POS clients on separate ports.
  evidence: Sanctum is installed and Bearer auth is planned; CORS is needed when Vue apps are scaffolded (1.6 / Epic 4), not required to prove 1.1 API mount.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-laravel-scaffold.md`
  summary: Optional CI job that builds/runs Docker Compose and curls `/api/v1/health`.
  evidence: Story CI AC only requires PHPUnit on push; Docker path is documented and config-validated but not exercised in GitHub Actions.

- source_spec: `_bmad-output/implementation-artifacts/spec-1-1-laravel-scaffold.md`
  summary: Harden bind-mount UID/GID alignment for www-data vs host-owned storage/bootstrap/cache.
  evidence: Dockerfile forces uid 1000; mismatches can cause write failures on some hosts.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-7-low-stock-alerts.md`
  summary: Add automated Admin dashboard tests for the low-stock panel (critical vs warning styling).
  evidence: Repo has no frontend test runner; verification is API tests + npm build + manual dashboard check only.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-7-low-stock-alerts.md`
  summary: Enforce store-access scoping on inventory read APIs (including low-stock alerts).
  evidence: Inventory balances/movements already omit canAccessStore; alerts followed that pattern — pre-existing gap for store-scoped users.

- source_spec: `_bmad-output/implementation-artifacts/spec-2-7-low-stock-alerts.md`
  summary: Persist schedule low-stock check output (log/notify) for operators when cron runs.
  evidence: Hourly Schedule runs the command but stdout is discarded without appendOutputTo or Epic 8 notifications.

- source_spec: `_bmad-output/implementation-artifacts/spec-3-1-supplier-management.md`
  summary: Add automated Admin Suppliers UI tests (list/CRUD/CSV feedback).
  evidence: Repo has no frontend test runner; verification is API tests + npm build + manual checks.

- source_spec: `_bmad-output/implementation-artifacts/spec-3-1-supplier-management.md`
  summary: Hide or disable Suppliers nav for users without suppliers.view.
  evidence: AppShell exposes Suppliers to all authenticated admins; API still enforces permissions.

- source_spec: `_bmad-output/implementation-artifacts/spec-3-2-purchase-order-approval.md`
  summary: Add Laravel Policies for purchase-order abilities (AD-4) instead of ad-hoc hasPermission checks.
  evidence: Controllers use assertCreate/assertApprove helpers; suppliers already follow the same ad-hoc pattern.

- source_spec: `_bmad-output/implementation-artifacts/spec-3-2-purchase-order-approval.md`
  summary: Hide Purchase orders nav for users lacking purchase.create and purchase.approve.
  evidence: AppShell always shows the link; API still enforces permissions.

- source_spec: `_bmad-output/implementation-artifacts/spec-3-2-purchase-order-approval.md`
  summary: Add automated Admin PurchaseOrders UI tests.
  evidence: Repo has no frontend test runner; verification is API tests + npm build + manual checks.

- source_spec: `_bmad-output/implementation-artifacts/spec-3-3-goods-receipt-partial.md`
  summary: Enforce store-scoped access on goods receipt using PO store_id / X-Store-Id.
  evidence: Receive is org-scoped only today; purchase.receive is store-scoped in the permission matrix.

- source_spec: `_bmad-output/implementation-artifacts/spec-3-3-goods-receipt-partial.md`
  summary: Add human-readable GRN numbers and optional GRN history list on the PO.
  evidence: GRNs expose numeric ids only; Admin has no prior-receipt history panel.

- source_spec: `_bmad-output/implementation-artifacts/spec-3-3-goods-receipt-partial.md`
  summary: Add automated Admin receive UI tests for partial PO receive button.
  evidence: Repo has no frontend test runner; verification is API tests + npm build + manual checks.

- source_spec: `_bmad-output/implementation-artifacts/spec-3-4-supplier-invoices-payments.md`
  summary: Add invoice/payment update-void-delete APIs beyond create/list/show.
  evidence: Spec Always mentioned CRUD; MVP shipped index/store/show only.

- source_spec: `_bmad-output/implementation-artifacts/spec-3-4-supplier-invoices-payments.md`
  summary: Link Purchase Orders UI into AP invoice creation for a received PO/GRN.
  evidence: Design Notes optional continuity; PaymentsView is standalone.

- source_spec: `_bmad-output/implementation-artifacts/spec-3-4-supplier-invoices-payments.md`
  summary: Wire AD-12 journal hooks when Accounting module lands.
  evidence: Intentionally stubbed; Ask First default was skip journals in 3.4.

- source_spec: `_bmad-output/implementation-artifacts/spec-3-5-purchase-returns.md`
  summary: Enforce store-scoped access on purchase returns (X-Store-Id / store_id).
  evidence: Same org-only pattern as GRN; purchase.receive is store-scoped in the matrix.

- source_spec: `_bmad-output/implementation-artifacts/spec-3-5-purchase-returns.md`
  summary: Add human-readable purchase return numbers and richer GRN picker labels.
  evidence: Returns expose numeric ids; Admin GRN picker is GRN #id only.

- source_spec: `_bmad-output/implementation-artifacts/spec-3-5-purchase-returns.md`
  summary: Add automated Admin return UI tests.
  evidence: Repo has no frontend test runner; verification is API tests + npm build + manual checks.

- source_spec: `_bmad-output/implementation-artifacts/spec-3-5-purchase-returns.md`
  summary: Update database-design.md ERD companions with purchase_returns column detail.
  evidence: Tables exist in code; planning ERD still lists names only.

- source_spec: `_bmad-output/implementation-artifacts/spec-3-5-purchase-returns.md`
  summary: Preview estimated return credit in the Admin return modal before submit.
  evidence: Credit currently appears only in the success message.

- source_spec: `_bmad-output/implementation-artifacts/spec-4-1-pricing-promotion-services.md`
  summary: Add Admin edit/deactivate/delete UI for price lists, prices, and promotions beyond create/list.
  evidence: Thin MVP ships create + list + smoke resolve; API update/delete exist but Admin forms do not.

- source_spec: `_bmad-output/implementation-artifacts/spec-4-1-pricing-promotion-services.md`
  summary: Hide Pricing nav for users lacking pricing.manage / promotions.manage / sales.create.
  evidence: AppShell always shows Pricing; API still enforces permissions.

- source_spec: `_bmad-output/implementation-artifacts/spec-4-1-pricing-promotion-services.md`
  summary: Build tax_rates / tax_categories / product_taxes tables beyond store tax_config JSON.
  evidence: Story Ask First default was store JSON only; FR-21 full model deferred.

- source_spec: `_bmad-output/implementation-artifacts/spec-4-1-pricing-promotion-services.md`
  summary: Wire customerId / customer-group price list targeting after Story 4.2.
  evidence: Resolve accepts customerId but ignores it until customers exist.

- source_spec: `_bmad-output/implementation-artifacts/spec-4-2-customer-records-pos.md`
  summary: Add Laravel Policies for customer abilities (AD-4) instead of ad-hoc hasPermission checks.
  evidence: Controllers use assertView/assertManage helpers; same pattern as Purchasing.

- source_spec: `_bmad-output/implementation-artifacts/spec-4-2-customer-records-pos.md`
  summary: Soft-delete unique account_number should allow reuse (partial unique where deleted_at is null).
  evidence: Soft-deleted rows still block recreating the same account number.

- source_spec: `_bmad-output/implementation-artifacts/spec-4-2-customer-records-pos.md`
  summary: Admin edit/delete for customer groups beyond create chips.
  evidence: Group API supports update/destroy; Admin only creates groups.

- source_spec: `_bmad-output/implementation-artifacts/spec-4-2-customer-records-pos.md`
  summary: Wire customer groups into Pricing resolve after groups exist.
  evidence: Spec Ask First default skipped pricing; PricingService still ignores customerId.

- source_spec: `_bmad-output/implementation-artifacts/spec-4-2-customer-records-pos.md`
  summary: POS customer picker UI (walk-in default + search).
  evidence: Spec deferred UI to Story 4.3+; API ready.

- source_spec: `_bmad-output/implementation-artifacts/spec-4-3-pos-vue-shell.md`
  summary: Add Vitest/Playwright coverage for POS router gates and F-key shortcuts.
  evidence: Spec verification is npm build + manual; no frontend test runner in repo.

- source_spec: `_bmad-output/implementation-artifacts/spec-4-3-pos-vue-shell.md`
  summary: Add CI job to build frontend/pos (and admin) on PRs.
  evidence: GitHub CI currently runs PHPUnit only.

- source_spec: `_bmad-output/implementation-artifacts/spec-4-3-pos-vue-shell.md`
  summary: Full StubPanel focus trap and PNG/maskable PWA icons.
  evidence: Shell opens focus on first control; SVG-only manifest icons for MVP.

- source_spec: `_bmad-output/implementation-artifacts/spec-4-3-pos-vue-shell.md`
  summary: Backend terminals CRUD and session open/close chrome wiring.
  evidence: Terminal is localStorage label; Session shows placeholder until 4.7.

- source_spec: `_bmad-output/implementation-artifacts/spec-4-4-cart-product-search.md`
  summary: Add frontend/pos vitest to CI and story verification gates.
  evidence: Vitest covers cart/search but CI and listed Verification still center on build/PHPUnit.

- source_spec: `_bmad-output/implementation-artifacts/spec-4-4-cart-product-search.md`
  summary: F8 line-discount engine beyond price override.
  evidence: Spec Ask First kept F8 as stub; override is the authorized price path for 4.4.

- source_spec: `_bmad-output/implementation-artifacts/spec-4-4-cart-product-search.md`
  summary: Block add-to-cart when stock unavailable.
  evidence: Spec deferred inventory checks to Story 4.5 sale completion.

- source_spec: `_bmad-output/implementation-artifacts/spec-5-1-pwa-offline-shell.md`
  summary: Migrate Dexie `pending_sales` (and related) primary keys from `++id` to client `uuid` when Story 5.3 implements the offline sale queue.
  evidence: Offline-sync design keys offline writes by uuid; 5.1 uses placeholder auto-increment tables only.

- source_spec: `_bmad-output/implementation-artifacts/spec-5-1-pwa-offline-shell.md`
  summary: Show sync badge on Login and Store picker routes, not only POS floor chrome.
  evidence: Spec Code Map targeted PosFloorView; UX spine wants always-visible sync state across POS chrome.

- source_spec: `_bmad-output/implementation-artifacts/spec-5-1-pwa-offline-shell.md`
  summary: Add automated post-build assertion that generated SW precaches shell and denylists `/api`.
  evidence: Spec Verification relies on manual DevTools + successful `npm run build`; vitest does not inspect workbox output.

- source_spec: `_bmad-output/implementation-artifacts/spec-5-2-local-cache-sync.md`
  summary: Surface advisory inventory_snapshot qty on POS search/cart UI when offline.
  evidence: Warm writes snapshot but floor UI does not display it; AD-9 still forbids hard-blocking.

- source_spec: `_bmad-output/implementation-artifacts/spec-5-2-local-cache-sync.md`
  summary: Use warmed pos_config (store name/currency) in POS chrome/formatting.
  evidence: Rows are stored for later stories but unused in 5.2 UI.

- source_spec: `_bmad-output/implementation-artifacts/spec-5-2-local-cache-sync.md`
  summary: Add Dexie v1→v3 upgrade fixture test and API query-contract unit tests for warm helpers.
  evidence: Current tests greenfield-open DB and mock catalog APIs entirely.

- source_spec: `_bmad-output/implementation-artifacts/spec-5-3-offline-sale-queue.md`
  summary: Offline sale payloads embed live sessionId; Story 5.4 push must handle sessions that no longer exist when reconnecting.
  evidence: Envelope payload matches CompleteSaleInput including sessionId; no offline session-close path in 5.3.

- source_spec: `_bmad-output/implementation-artifacts/spec-5-3-offline-sale-queue.md`
  summary: Fall back to queue when navigator.onLine is true but API is unreachable (lie-fi).
  evidence: 5.3 gates offline enqueue solely on navigator.onLine === false; failed online POSTs surface as errors without queueing.

- source_spec: `_bmad-output/implementation-artifacts/spec-5-4-push-sync-api.md`
  summary: Return all oversell exception ids when a sale oversells multiple variants (response currently keeps first only).
  evidence: SyncPushService stores a single exception_id on sync_records / item response.

- source_spec: `_bmad-output/implementation-artifacts/spec-5-4-push-sync-api.md`
  summary: Create oversell exceptions when concurrent depletion drives balances negative after allowOversell stock check passed.
  evidence: TOCTOU between pre-check and ledger post can yield negative stock without exception rows.

- source_spec: `_bmad-output/implementation-artifacts/spec-5-4-push-sync-api.md`
  summary: List/show pending oversell exceptions for managers (Admin UI) beyond approve-by-id API.
  evidence: Approve endpoint exists; discovery of ids is only via push response.

- source_spec: `_bmad-output/implementation-artifacts/spec-5-4-push-sync-api.md`
  summary: Move sync authorize/approve to Policies (AD-4) and audit oversell approvals.
  evidence: Inline hasPermission/canAccessStore checks; no audit event on approve.
