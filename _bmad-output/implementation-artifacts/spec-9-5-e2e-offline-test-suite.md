---
title: 'Story 9.5: Full E2E and offline sync test suite'
type: 'feature'
created: '2026-08-29'
status: 'done'
baseline_commit: '735318c8e8f0e8b0e8c8e8f0e8b0e8c8e8f0e8b0'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-9-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Offline-sync edge cases and critical POS workflows lack a consolidated regression suite — coverage is scattered across unit tests with no browser-level E2E harness.

**Approach:** Add backend offline-sync scenario tests for remaining Epic 6 matrix cases, a POS reconnect integration test (Dexie queue → push on online), Playwright smoke/critical specs, and CI jobs for PHPUnit + POS Vitest + Playwright login/floor flows.

## Boundaries & Constraints

**Always:**
- Backend `OfflineSyncScenarioTest` — concurrent oversell, device disable mid-queue, clock skew, retry-after-error idempotency, sync status store isolation.
- POS `offlineReconnect.test.ts` — enqueue offline sale, simulate transport failure, reconnect drain succeeds.
- `e2e/` Playwright package — login smoke (no API) + critical floor flow (seeded stack).
- `E2ePlaywrightSeeder` — idempotent store + admin store access for browser tests.
- PHPUnit artifact test asserts e2e package + scenario test exist.
- CI: POS Vitest job; Playwright job with sqlite API + Vite webServer.

**Ask First:**
- Admin SPA E2E — default **defer** (POS critical path only).
- Playwright against Docker Compose in CI — default **defer** (sqlite + artisan serve).

**Never:**
- Weaken store scoping or sync idempotency invariants to make tests pass.
- Commit Playwright traces/screenshots.

</frozen-after-approval>

## Tasks & Acceptance

**Execution:**
- [x] `OfflineSyncScenarioTest`, `E2eSuiteArtifactsTest`, `E2ePlaywrightSeeder`
- [x] `frontend/pos/src/sync/offlineReconnect.test.ts`
- [x] `e2e/` Playwright package + README
- [x] CI vitest + playwright jobs; ops manual section

**Acceptance Criteria:**
- Given two devices overselling concurrently, when both push, then both sales sync with separate oversell exceptions and ledger deficit is correct
- Given device disabled after first queued sale synced, when next push runs, then batch is rejected and prior sale remains
- Given POS offline queue with transport failure, when reconnect drain runs, then sale syncs and badge returns ONLINE
- Given Playwright against seeded stack, when cashier signs in and picks store, then floor loads with visible sync badge label
- Given full PHPUnit suite, when run, then all tests pass

## Verification

**Commands:**
- `cd backend && php artisan test --filter=OfflineSync`
- `cd backend && php artisan test --filter=E2eSuite`
- `cd frontend/pos && npm test`
- `cd e2e && npm test` (with API + Vite running, or CI job)

**Result (2026-08-29):** 532 passed / 3307 assertions (10 new); POS Vitest 113 passed (+1 reconnect).

## Suggested Review Order

**Offline-sync scenarios**

- Concurrent oversell, device disable mid-queue, clock skew
  [`OfflineSyncScenarioTest.php:1`](../../backend/tests/Feature/OfflineSyncScenarioTest.php#L1)

**POS reconnect + browser E2E**

- Dexie queue drain on reconnect
  [`offlineReconnect.test.ts:1`](../../frontend/pos/src/sync/offlineReconnect.test.ts#L1)

- Playwright login + floor critical path
  [`pos-floor.spec.ts:1`](../../e2e/tests/pos-floor.spec.ts#L1)

## Spec Change Log

- 2026-08-29 — Offline scenario tests, POS reconnect integration, Playwright E2E, CI jobs.
