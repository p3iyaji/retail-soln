---
title: 'Story 5.4: Push sync API with idempotency'
type: 'feature'
created: '2026-08-29'
status: 'done'
baseline_commit: 'ebbc7d36e88369ac52b5b27b81c5f344db005d82'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-5-context.md'
  - '{project-root}/_bmad-output/planning-artifacts/architecture/architecture-retail-soln-2026-08-24/offline-sync-design.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-5-3-offline-sale-queue.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Offline sales sit in Dexie `pending_sales` (5.3) with no way to upload them. Without an idempotent push API, reconnect either loses money/stock safety or double-posts.

**Approach:** Add `POST /api/v1/sync/push` that accepts ordered offline envelopes, posts sales through the same `SaleCompletionService` path as online POS (AD-5 uuid / Idempotency-Key), applies AD-9 oversell (accept + exception row, never hard-reject stock on push), and drain the POS queue on reconnect. Pull cursors, full sync-status UI, and Admin device console stay later stories.

## Boundaries & Constraints

**Always:**
- Idempotent per client `uuid`: duplicate push returns prior `sync_records` / sale result without re-processing (no double money or ledger).
- Process batch ordered by `localSequence`; max batch size N (default 50); response per item `{ uuid, status: synced|error, saleId?, exceptionId?, errors? }`.
- Sale envelopes call the same atomic completion path as `POST /pos/sales` (`SaleCompletionService`); money DECIMAL / `bc*`; inventory only via `InventoryLedgerService` (AD-2).
- AD-9 on push: if available &lt; qty, still post (balance may go negative) and create `inventory_oversell_exceptions` (`status=pending`); include `exceptionId` in item response.
- Stale `sessionId`: if the queued session is missing/closed, remap to the cashier’s currently open session for the same store; if none open, mark that item `error` (do not invent a session).
- Device row keyed by client `deviceUuid`: upsert enabled device on first push; if device `enabled=false`, reject the batch. Full Admin register/disable UI is Story 5.7.
- Client: on reconnect (`useConnectivity` online) and when online with pending rows, push pending sales; set Dexie `syncStatus` to `pushing` → `synced`/`error` + `serverResult`; drive badge SYNCING / SYNC ERROR via existing sync store.
- Auth: Sanctum + `X-Store-Id`; re-check user can sell in store. Reject `payloadType` other than `sale` for this story (returns / cash / session_close later).

**Ask First:**
- Changing AD-9 to also remove hard-reject on **online** `POST /pos/sales` (default for 5.4: AD-9 only on sync push path).
- Strict pre-registration (reject unknown `deviceUuid`) instead of upsert-on-first-push.

**Never:**
- Parallel sale write path that bypasses `SaleCompletionService` / ledger.
- `GET /sync/pull` or cursor protocol (5.5); rich Retry panel UX beyond badge + drain (5.6).
- Offline installment plan create; float money; client inventory mutation.
- Auto-voiding or silently dropping failed queue rows.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| First push sale | Pending envelope, stock OK, open session | Sale created; record synced; Dexie `synced` | N/A |
| Duplicate uuid | Same uuid pushed again | Prior sale/result; no second sale/ledger | N/A |
| Oversell | available &lt; qty | Sale posted; exception pending; `exceptionId` returned | N/A |
| Disabled device | `devices.enabled=false` | Batch rejected; client SYNC ERROR | Clear error payload |
| Closed session + open alternate | Old sessionId closed; user has open session same store | Remap + post | N/A |
| Closed session + none open | No open session | Item `error`; row stays retryable | Surface message |
| Mid-batch item fail | One bad line (e.g. discontinued) | That uuid `error`; others may still sync | Per-item errors |
| Reconnect drain | `navigator` online + pending rows | SYNCING → push → ONLINE or SYNC ERROR | Retry leaves errors visible |

</frozen-after-approval>

## Code Map

- New module `backend/Modules/Sync/` (nWidart like Pricing) -- enable in `modules_statuses.json`; `RouteServiceProvider` → `routes/api.php` under `v1` + `auth:sanctum`
- `POST /api/v1/sync/push` -- controller + FormRequest (batch envelopes); service `SyncPushService`
- Migrations: `devices`, `sync_batches`, `sync_records` (unique org+uuid), `inventory_oversell_exceptions` -- see `database-design.md`
- Reuse `Modules/Sales/.../SaleCompletionService.php` (~L55+) -- complete with `idempotencyKey === uuid`; do **not** duplicate sale writes
- Stock hard-reject today: `SaleCompletionService` ~L145–155 (`Insufficient available stock`) -- push path must use AD-9 branch (flag/option or sync-only wrapper) instead of throw
- `Modules/Inventory/.../InventoryLedgerService.php` -- sole qty mutation (AD-2); already allows negative balances
- Permissions already seeded: `devices.manage`, `inventory.approve-oversell` (`PermissionMatrix`) -- wire approve endpoint for exceptions; Admin UI can be thin or API-only this story
- Optional: `POST /api/v1/sync/oversell-exceptions/{id}/approve` -- manager permission; status → approved (void/adjust stay later)
- Client: `frontend/pos/src/sync/pendingSales.ts` -- `listPendingSales`; add mark pushing/synced/error helpers
- New: `frontend/pos/src/sync/pushPendingSales.ts` (+ test) -- batch POST `/sync/push`, update Dexie, badge via `stores/sync.ts`
- Hook: `frontend/pos/src/composables/useConnectivity.ts` (~L7–8) -- `onOnline` currently only `applyConnectivity(true)`; call drain when online
- Continuity 5.3: envelope payload already `CompleteSaleInput`-shaped; deferred stale `sessionId` handled here
- Tests: Feature tests for push idempotency + oversell; Vitest for client drain / status updates

## Tasks & Acceptance

**Execution:**
- [x] `backend/Modules/Sync` (+ migrations, enable module) -- Sync module, devices/batches/records/oversell tables
- [x] `SyncPushService` + `POST /sync/push` -- ordered batch, idempotent uuid, sale via `SaleCompletionService`, session remap, AD-9 exceptions
- [x] Adjust sale stock gate for sync push -- accept oversell + exception row (online hard-reject unchanged unless Ask First)
- [x] `POST /sync/oversell-exceptions/{id}/approve` -- permission-gated status flip (minimal)
- [x] `frontend/pos/src/sync/pushPendingSales.ts` + connectivity hook -- drain queue, update Dexie + sync badge
- [x] Feature + unit tests for I/O matrix; README / sprint-status `5-4-…`

**Acceptance Criteria:**
- Given a pending offline sale, when the device reconnects and push runs, then the sale exists once server-side and the Dexie row is `synced`.
- Given the same uuid is pushed twice, when the second request completes, then no second sale or ledger movement is created.
- Given insufficient stock on push, when the sale posts, then an `inventory_oversell_exceptions` row exists and the sale is not hard-rejected.
- Given a disabled device, when push is attempted, then the batch is rejected and the POS shows SYNC ERROR.
- Given pull is not implemented, when this story ships, then no cursor pull protocol is required for push to succeed.

## Design Notes

**Session remap:** Prefer original `sessionId` if still open for that user/store; else any open session for same user+store; else per-item error. Do not auto-open sessions offline.

**Device upsert:** First push creates `devices` row (`uuid` = client deviceUuid, `enabled=true`, store/org from auth). Story 5.7 adds Admin disable/register flows without changing push contract.

**Batch N:** Cap at 50 unless config says otherwise; client sends ascending `localSequence`.

**Idempotency storage:** Persist `sync_records` keyed by `(organization_id, uuid)` with final status + sale_id/exception ids so replays do not re-enter completion.

## Verification

**Commands:**
- `cd backend && php artisan test --filter=Sync` -- expected: push/idempotency/oversell pass
- `cd frontend/pos && npm run test && npm run build` -- expected: push client tests + build OK

**Manual checks:**
- Offline cash sale → reconnect → SYNCING → sale visible via online receipt/history; Dexie row `synced`
- Replay same push (or Retry) → no duplicate sale
- Force oversell offline → exception row pending after sync

## Suggested Review Order

**Server push**

- Batch push entry: device gate, ordered envelopes, per-item results
  [`SyncPushService.php:47`](../../backend/Modules/Sync/app/Services/SyncPushService.php#L47)

- Device upsert via firstOrCreate (race-safe)
  [`SyncPushService.php:95`](../../backend/Modules/Sync/app/Services/SyncPushService.php#L95)

- Reject installment plans on push; uuid envelope/payload match
  [`SyncPushService.php:192`](../../backend/Modules/Sync/app/Services/SyncPushService.php#L192)

**AD-9 oversell**

- Sync-only allowOversell branch in sale completion (online hard-reject unchanged)
  [`SaleCompletionService.php:62`](../../backend/Modules/Sales/app/Services/SaleCompletionService.php#L62)
  [`SaleCompletionService.php:164`](../../backend/Modules/Sales/app/Services/SaleCompletionService.php#L164)

**Client drain**

- Reconnect / startup drain hook
  [`useConnectivity.ts:8`](../../frontend/pos/src/composables/useConnectivity.ts#L8)

- Push pending/pushing/error rows; continue after partial failure
  [`pushPendingSales.ts:44`](../../frontend/pos/src/sync/pushPendingSales.ts#L44)

- Pushable status set includes retryable errors
  [`pendingSales.ts:82`](../../frontend/pos/src/sync/pendingSales.ts#L82)

**Tests**

- Feature matrix + Vitest drain/retry coverage
  [`SyncPushApiTest.php:158`](../../backend/tests/Feature/SyncPushApiTest.php#L158)
  [`pushPendingSales.test.ts:1`](../../frontend/pos/src/sync/pushPendingSales.test.ts#L1)
