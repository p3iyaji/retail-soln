# Epic 9 Context: Production Hardening

<!-- Compiled from planning artifacts. Edit freely. Regenerate with compile-epic-context if planning docs change. -->

## Goal

Close Phase 10 (post-MVP) by hardening the full retail platform for production: remediate security findings, optimize performance and database access, add operational observability, establish backup/disaster-recovery and load-test evidence, and expand automated E2E and offline-sync coverage. The platform is architected to scale to hundreds of stores and millions of transactions; financial accuracy, inventory accuracy, multi-store isolation, offline operation, synchronization, security, auditability, and scalability must not be sacrificed for delivery speed. Epic stories list titles only — requirements come from PRD success metrics, security design, offline-sync design, proposal Phase 10 / observability / testing sections, and architecture deferrals revisited here.

## Stories

- Story 9.1: Security review and penetration test fixes
- Story 9.2: Performance and database optimization
- Story 9.3: Observability (Sentry, metrics)
- Story 9.4: Backup, DR, and load testing
- Story 9.5: Full E2E and offline sync test suite

## Requirements & Constraints

- **Security review & pen-test remediation:** Validate and close gaps against the established security model — Sanctum/Fortify auth, RBAC/Policies on every sensitive endpoint, rate-limited auth, HTTPS in production, secrets out of repo, validated file uploads to private S3-compatible storage, webhook signature verification with idempotency, append-only audit logs, and threat mitigations (cross-store leakage, offline replay, privilege escalation, XSS/CSRF, inventory fraud). Never trust client-side validation; all business rules enforced server-side.
- **Performance & database optimization:** Meet or evidence PRD success metrics under realistic load — POS sale completion ≤3s from payment confirm (online), product search ≤200ms p95 online, zero inventory discrepancies from concurrent terminal sales in acceptance testing. Optimize hot paths (POS, sync, reporting) and database indexes/query patterns; paginated product sync and local IndexedDB cache remain the primary POS scale strategy. Dedicated search engines (Meilisearch etc.) remain optional unless query performance demands them.
- **Observability:** Structured logging and monitoring for application errors, failed queue jobs, API failures, synchronization failures, payment failures, webhook failures, slow queries, and inventory inconsistencies. Integrate error tracking (Sentry named in epic) and design for metrics/tracing (Prometheus, Grafana, OpenTelemetry) without blocking critical paths.
- **Queue monitoring:** Background work (invoices, email/SMS, notifications, reports, sync, webhooks, reconciliation, exports) must be observable; expensive ops stay async — POS must not block on them.
- **Backup, DR, load testing:** Document and implement backup strategy, disaster-recovery procedures, and load-test runs that exercise realistic multi-store traffic. Kubernetes deployment was deferred from v1 Docker stack — revisit here only if operational needs require it.
- **Test expansion:** Beyond existing unit/feature/authz/API tests, add comprehensive offline-sync scenarios (offline sale, reconnection, duplicate sync, failed sync, retry, conflict, partial batch failure, duplicate push, mid-batch disconnect, concurrent offline oversell, device disable mid-queue, clock skew) and browser/E2E tests for critical POS workflows. Authorization tests must continue to prove Store A cannot access Store B data (100% pass target).
- **Invariants unchanged:** DECIMAL money only; inventory mutations only via ledger service; offline pushes re-validated server-side; sync idempotency via client UUIDs; oversell accepts negative stock with manager approval — hardening must not weaken these.

## Technical Decisions

- **Stack:** Laravel modular monolith, MySQL, Redis queues, Nginx, S3-compatible storage, Docker Compose for v1 — all clients are Vue 3 SPAs on `/api/v1` (no Inertia).
- **Security architecture:** Store scope via `X-Store-Id` + Policies/global scopes; separate customer-portal guard with no admin route access; device UUID registration with admin disable; payment providers behind one abstraction with signature-verified webhooks; audit module append-only.
- **Sync architecture (Epic 6):** Push/pull/status endpoints, batch push with idempotency, incremental pull cursors (never full DB dump), server-authoritative price/tax/auth/inventory reconciliation, oversell exceptions workflow.
- **Performance levers:** Composite/store-scoped indexes per database design; queued side effects after transaction commit; local POS cache (products, prices, tax, inventory snapshot, pending sales).
- **Deferred decisions to revisit:** Kubernetes vs Docker-only ops; dedicated search engine if DB search insufficient under load.

## UX & Interaction Patterns

- Hardening is mostly non-UI, but E2E and sync tests must respect POS UX invariants: sync badge always visible on POS chrome (ONLINE / OFFLINE / SYNCING / SYNC ERROR); SYNC ERROR shows retry and must not auto-dismiss; sync state not color-only (text labels); `aria-live` polite for state changes, assertive for SYNC ERROR.
- Offline banner appears once per transition; user-facing copy stays operational (“Offline — sales will sync when connected”), not error codes.

## Cross-Story Dependencies

- **Requires Epics 1–8 complete:** Full platform (auth/RBAC, catalog, inventory ledger, purchasing, POS, offline sync, customer portal, fulfilment, reporting/notifications) must exist before meaningful hardening.
- **From Epic 8:** Heavy report/notification queue workloads and their monitoring expectations hand off to this epic — observability and load testing should cover those async paths.
- **Within epic:** 9.1 security fixes may unblock safe production deployment; 9.3 observability supports validating 9.2 optimizations and 9.4 load/DR exercises; 9.5 test suite provides regression evidence across all prior epics, especially Epic 6 offline-sync design and AD-5/AD-9 conflict rules.
