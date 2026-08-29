---
title: 'Story 5.1: PWA offline shell with IndexedDB'
type: 'feature'
created: '2026-08-29'
status: 'done'
baseline_commit: '34e83930b8bde1b10e8ae56c5fb7f5728432a5a8'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-5-context.md'
  - '{project-root}/_bmad-output/planning-artifacts/architecture/architecture-retail-soln-2026-08-24/offline-sync-design.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** POS has only a static webmanifest and a hard-coded ONLINE badge. There is no service worker, IndexedDB, or real connectivity state — cashiers cannot keep the app shell or prepare local storage for offline work.

**Approach:** Add a Dexie IndexedDB schema matching the offline-sync design, register a production service worker that caches the app shell, detect online/offline, and drive the POS chrome sync badge through the four UX states (ONLINE / OFFLINE / SYNCING / SYNC ERROR). Do not yet pull catalog data or queue sales — that is Stories 5.2–5.3.

## Boundaries & Constraints

**Always:**
- Keep online sale/cart/API paths (`stores/pos.ts`, `api/*`) unchanged and authoritative when online.
- Sync badge always visible with **text** labels (not color alone); announce state changes via `aria-live`.
- IndexedDB stores match offline-sync design names: `products`, `prices`, `taxes`, `customers`, `inventory_snapshot`, `pos_config`, `pending_sales`, `sync_meta`.
- Persist a stable `deviceUuid` in `sync_meta` on first open (generate once).
- Money remains DECIMAL strings; no floats.
- Service worker must not cache `/api` responses or break Vite HMR in `npm run dev` (disable SW in dev).

**Ask First:**
- Changing Dexie store schema names away from the offline-sync design.
- Caching authenticated API payloads in the service worker.

**Never:**
- Offline sale completion, push/pull sync APIs, or installment create offline (5.2–5.7 / Epic 4 rules).
- Inventing sync protocol behaviour outside the approved offline-sync design.
- Hiding SYNC ERROR or auto-dismissing failures.
- Mutating inventory quantities from the client shell.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| First load online | Fresh browser, network up | DB opens; `deviceUuid` written; badge ONLINE | Surface IndexedDB open failure in badge SYNC ERROR |
| Go offline | `window` offline event | Badge OFFLINE (amber); shell still loads from SW when installed | N/A |
| Back online | `window` online event | Badge ONLINE (green) | N/A |
| Manual status set | Sync store set to syncing/error (for later stories) | Badge SYNCING / SYNC ERROR with text | SYNC ERROR stays until cleared/retry |
| Dev mode | `npm run dev` | No SW registration; connectivity + IDB still work | N/A |

</frozen-after-approval>

## Code Map

- `frontend/pos/public/manifest.webmanifest` -- existing install metadata; keep, do not replace purpose
- `frontend/pos/index.html` -- already links manifest; SW inject via plugin
- `frontend/pos/vite.config.ts` -- add `vite-plugin-pwa` (prod only / `devOptions.enabled: false`)
- `frontend/pos/package.json` -- add `dexie`; add `vite-plugin-pwa` as devDependency
- `frontend/pos/src/main.ts` -- bootstrap sync store + connectivity after Pinia; SW virtual module in prod
- `frontend/pos/src/views/PosFloorView.vue` (~L224–230) -- replace hard-coded ONLINE with reactive badge from sync store
- `frontend/pos/src/style.css` -- add CSS tokens for offline / syncing / sync-error (today only `--online`)
- `frontend/pos/src/stores/pos.ts` -- **read-only** for this story; do not couple cart to IDB
- `frontend/pos/README.md` -- update PWA note (SW + Dexie shell live)
- New: `frontend/pos/src/db/posDb.ts` -- Dexie schema + `ensureDeviceUuid()`
- New: `frontend/pos/src/stores/sync.ts` -- status enum, lastError, setters used by later stories
- New: `frontend/pos/src/composables/useConnectivity.ts` -- online/offline listeners → sync store
- New: `frontend/pos/src/components/SyncBadge.vue` -- labeled badge + `aria-live`
- New tests: `frontend/pos/src/db/posDb.test.ts`, `frontend/pos/src/stores/sync.test.ts`

## Tasks & Acceptance

**Execution:**
- [x] `frontend/pos/package.json` -- add `dexie` + `vite-plugin-pwa` -- PWA + IDB foundation
- [x] `frontend/pos/vite.config.ts` -- configure PWA app-shell precache; SW off in dev -- safe local DX
- [x] `frontend/pos/src/db/posDb.ts` (+ test) -- Dexie tables per design; ensure `deviceUuid` in `sync_meta`
- [x] `frontend/pos/src/stores/sync.ts` (+ test) -- status ONLINE|OFFLINE|SYNCING|SYNC_ERROR; lastError; setters
- [x] `frontend/pos/src/composables/useConnectivity.ts` -- map browser online/offline to store (SYNCING/ERROR untouched)
- [x] `frontend/pos/src/components/SyncBadge.vue` + `PosFloorView.vue` -- reactive badge; wire tokens in `style.css`
- [x] `frontend/pos/src/main.ts` -- init DB/deviceUuid, start connectivity, register SW in production build only
- [x] `frontend/pos/README.md` + sprint-status `5-1-…` → in-progress then done -- tracking
- [x] Unit-test I/O matrix cases for status transitions and deviceUuid persistence

**Acceptance Criteria:**
- Given a production POS build, when the app is installed/opened offline after a prior online visit, then the app shell loads from the service worker (not a blank network error page).
- Given the POS floor is open, when the browser goes offline then online, then the badge text switches OFFLINE → ONLINE with distinct colors and an accessible live region.
- Given a first visit, when IndexedDB initializes, then a stable `deviceUuid` exists in `sync_meta` and survives reload.
- Given `npm run dev`, when the app runs, then no service worker is registered and Vitest for db/sync passes.
- Given online POS workflows (search/cart), when this story ships, then existing cart/sale API behaviour is unchanged.

## Design Notes

Badge states (offline-sync design / UX):

| State | Color cue | Behavior in 5.1 |
|-------|-----------|-----------------|
| ONLINE | green (`--online`) | Default when `navigator.onLine` |
| OFFLINE | amber | Browser offline |
| SYNCING | blue pulse | API available for later stories; not auto-set in 5.1 |
| SYNC ERROR | red | API for later; set if IDB open fails |

Dexie version 1 schema: empty tables with keys only as needed (`sync_meta` keyed by `key` string for `deviceUuid` / cursors placeholders).

## Verification

**Commands:**
- `cd frontend/pos && npm install && npm run test` -- expected: all unit tests pass including new db/sync tests
- `cd frontend/pos && npm run build` -- expected: build succeeds; SW assets emitted

**Manual checks:**
- Chrome DevTools → Application → Manifest + Service Workers (prod preview); toggle Offline and confirm badge + shell reload

## Suggested Review Order

**Bootstrap & IndexedDB**

- App start: open Dexie, deviceUuid, connectivity, prod-only SW, then mount
  [`bootstrap.ts:14`](../../frontend/pos/src/bootstrap.ts#L14)

- Dexie v1 store names aligned to offline-sync design
  [`posDb.ts:45`](../../frontend/pos/src/db/posDb.ts#L45)

- Transactional deviceUuid so concurrent first-open cannot race
  [`posDb.ts:61`](../../frontend/pos/src/db/posDb.ts#L61)

**Sync status UX**

- Status machine; SYNC_ERROR sticky until clearError
  [`sync.ts:17`](../../frontend/pos/src/stores/sync.ts#L17)

- Browser online/offline → store without clearing SYNCING/ERROR
  [`useConnectivity.ts:4`](../../frontend/pos/src/composables/useConnectivity.ts#L4)

- Labeled badge with aria-live / error in aria-label
  [`SyncBadge.vue:20`](../../frontend/pos/src/components/SyncBadge.vue#L20)

- Floor chrome mounts reactive badge (replaces hard-coded ONLINE)
  [`PosFloorView.vue:225`](../../frontend/pos/src/views/PosFloorView.vue#L225)

**PWA config**

- App-shell precache; `/api` denylist; SW disabled in dev
  [`vite.config.ts:16`](../../frontend/pos/vite.config.ts#L16)

**Tests & docs**

- Badge + bootstrap failure coverage
  [`SyncBadge.test.ts:1`](../../frontend/pos/src/components/SyncBadge.test.ts#L1)
  [`bootstrap.test.ts:1`](../../frontend/pos/src/bootstrap.test.ts#L1)
  [`README.md:4`](../../frontend/pos/README.md#L4)
