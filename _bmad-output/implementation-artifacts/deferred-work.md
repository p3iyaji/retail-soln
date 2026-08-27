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
