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
