---
title: 'Story 5.5: Pull sync with cursors'
type: 'feature'
created: '2026-08-29'
status: 'done'
baseline_commit: 'd15d20a3be875106866df2b08a769d67c47964be'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-5-context.md'
  - '{project-root}/_bmad-output/planning-artifacts/architecture/architecture-retail-soln-2026-08-24/offline-sync-design.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-5-4-push-sync-api.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-5-2-local-cache-sync.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** POS cache warm (5.2) does a full replace via list APIs. After reconnect, cashiers cannot incrementally refresh catalog/prices/tax/inventory/config without dumping the whole store again, and there is no opaque pull cursor as designed.

**Approach:** Add `GET /api/v1/sync/pull?cursor=` (store-scoped, opaque cursor) that returns capped deltas for products, prices, taxes, customers, inventory snapshot, and pos_config. POS applies upserts/deletes into Dexie, persists the cursor in `sync_meta`, and runs pull on reconnect (after push) and on store online refresh — replacing full warm for online paths. Never a full DB dump.

## Boundaries & Constraints

**Always:**
- `GET /api/v1/sync/pull` with optional `cursor`; Sanctum + `X-Store-Id`; reject if device disabled (same device upsert/enabled rules as push via `deviceUuid` query or `X-Device-Uuid`).
- Response: `{ data: { products, prices, taxes, customers, inventory, config, deleted? }, meta: { cursor, hasMore } }` — opaque `cursor` only; client must not parse it for logic beyond round-trip.
- Empty cursor = bootstrap page(s) for that store (capped per resource, e.g. ≤100 rows each); client loops while `hasMore` until caught up or a safe page budget.
- Deltas keyed by server `updated_at` (+ id tie-break); inactive/soft-deleted entities appear as deletes (or `isActive: false` + client removes from sellable cache).
- Client upserts into existing Dexie tables (`products`, `prices`, `taxes`, `customers`, `inventory_snapshot`, `pos_config`); store `pullCursor` (+ `lastPullAt`) in `sync_meta` per store.
- Online refresh: pull instead of full clear+warm; offline: keep cached rows (clear-on-offline behavior from 5.2 may remain for store switch safety).
- Money DECIMAL strings; inventory snapshot advisory only (no client ledger). Badge SYNCING during pull; SYNC ERROR on failure (never silent).

**Ask First:**
- Dropping `warmCache` entirely vs keeping it as an emergency/admin “full refresh” fallback behind an explicit action.
- Expanding customer pull beyond “updated since cursor” hot delta (e.g. full customer dump).

**Never:**
- Returning an unbounded full catalog in one response.
- Cursor formats that embed secrets or cross-store data.
- Mutating inventory quantities on the client; push/sale logic changes; `GET /sync/status` rich UI (5.6); Admin device console (5.7).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Empty cursor bootstrap | Online, store set, no cursor | First capped page(s); Dexie filled; new cursor saved | Pull fail → SYNC ERROR; prior cache kept if any |
| Incremental pull | Cursor from prior pull; catalog changed | Only changed rows upserted; cursor advances | Same |
| hasMore loop | Large catalog | Client requests until `hasMore=false` (page budget) | Stop + SYNC ERROR if budget exceeded |
| Deletes / inactive | Product deactivated since cursor | Removed or unsellable in Dexie | N/A |
| Disabled device | `devices.enabled=false` | 403; SYNC ERROR | Clear error |
| Reconnect | `online` after offline | Push then pull (or pull after push settles) | Push errors don’t block documenting pull attempt; badge reflects failure |
| Store switch | New `X-Store-Id` | Fresh empty-cursor pull for that store (do not reuse other store’s cursor) | N/A |

</frozen-after-approval>

## Code Map

- Add `GET /sync/pull` in `backend/Modules/Sync/routes/api.php` beside push (`:9-10`)
- New: `SyncPullController`, `SyncPullRequest`, `SyncPullService` — mirror `SyncPushController` / `SyncPushService`
- Reuse device gate from push (`Device` model + enabled check); upsert device on first pull if missing (same as 5.4 default)
- Delta sources (have `updated_at`, no `since=` today):
  - Products/variants — `ProductController` / models under Products module; `ProductResource` exposes `updatedAt`
  - Prices — Pricing module tables/resources (warm currently derives price from product variants — pull should prefer store price rows when present, else variant list price)
  - Tax / config — `stores.tax_config` via Organization store show (`cacheWarm.ts` `:182-186`)
  - Inventory — `InventoryController::balances` / `inventory_balances.updated_at`
  - Customers — Customer module softDeletes + `updated_at`
- Cursor: opaque token (e.g. base64url JSON v1 with per-resource `{updatedAt,id}` watermarks + `storeId`); reject cursor/store mismatch
- Client: `frontend/pos/src/sync/cacheWarm.ts` — online path becomes pull-driven apply; extract shared Dexie writers if needed
- New: `frontend/pos/src/sync/pullCache.ts` (+ test) — GET loop, apply deltas, persist `pullCursor` / `lastPullAt` in `sync_meta`
- Hook: `useConnectivity.ts` — after `pushPendingSales`, call pull for `selectedStoreId`
- Call sites: `pos.ts` `selectStore` / `refreshCatalogCache`, `PosFloorView` onMounted — use pull when online
- Continuity: 5.2 Dexie schema; 5.4 push-first on reconnect; deferred advisory inventory UI stays deferred

## Tasks & Acceptance

**Execution:**
- [x] `backend/Modules/Sync` pull route + `SyncPullService` -- opaque cursor deltas (products/prices/taxes/customers/inventory/config)
- [x] Device-disabled + store-scoped cursor validation -- 403 / 422 as appropriate
- [x] `frontend/pos/src/sync/pullCache.ts` (+ tests) -- apply upserts/deletes; persist cursor; hasMore loop with page budget
- [x] Wire connectivity + store refresh to pull (replace online full warm) -- keep offline cache behavior safe
- [x] Feature tests for empty cursor, incremental, delete/inactive, disabled device, store mismatch; Vitest apply/loop
- [x] README + sprint-status `5-5-…`

**Acceptance Criteria:**
- Given no cursor, when pull runs for a store, then Dexie receives a capped bootstrap page and a cursor is stored for that store.
- Given a prior cursor and a product/price/inventory change, when pull runs, then only the delta is applied and the cursor advances.
- Given `hasMore=true`, when the client follows the loop within budget, then it reaches `hasMore=false` without a single unbounded response.
- Given a disabled device, when pull is attempted, then the API rejects and the POS shows SYNC ERROR.
- Given reconnect, when online fires, then push drain still runs and pull refreshes the local cache afterward.

## Design Notes

**Cursor wire format (server-owned):** versioned opaque string; include `storeId` so cross-store reuse fails closed. Per resource watermark `{at: ISO8601, id: int}` for stable pagination. Do not document the encoding as a client contract beyond “opaque”.

**Prices:** Prefer store-scoped price list rows in the pull payload; if none, fall back to variant selling/list price so offline search/pricing keeps working like 5.2.

**Page budget:** Server `limit` default 100 per resource; client max pages per refresh (e.g. 20) then SYNC ERROR “cache incomplete — retry”.

**warmCache:** Default — online callers switch to pull; leave `warmCache` callable only if needed for tests/migration, or delete once pull covers bootstrap (Ask First if keeping a manual full refresh).

## Verification

**Commands:**
- `cd backend && php artisan test --filter=Sync` -- expected: pull + existing push tests pass
- `cd frontend/pos && npm run test && npm run build` -- expected: pullCache tests + build OK

**Manual checks:**
- Fresh store select online → Dexie populated via pull; `pullCursor` in sync_meta
- Change a product price server-side → reconnect/pull → Dexie price updates without full clear flash
- Disable device → pull 403 → SYNC ERROR

## Suggested Review Order

**Server pull**

- Opaque store-scoped cursor deltas (products/prices/taxes/customers/inventory/config)
  [`SyncPullService.php:38`](../../backend/Modules/Sync/app/Services/SyncPullService.php#L38)

- Route: `GET /sync/pull`
  [`api.php:1`](../../backend/Modules/Sync/routes/api.php#L1)

**Client apply**

- hasMore loop, per-page cursor persist, store-switch clear
  [`pullCache.ts:364`](../../frontend/pos/src/sync/pullCache.ts#L364)

- Reconnect: push then pull (priorError preserved)
  [`useConnectivity.ts:10`](../../frontend/pos/src/composables/useConnectivity.ts#L10)

**Tests**

- Feature + Vitest matrix coverage
  [`SyncPullApiTest.php:119`](../../backend/tests/Feature/SyncPullApiTest.php#L119)
  [`pullCache.test.ts:1`](../../frontend/pos/src/sync/pullCache.test.ts#L1)
