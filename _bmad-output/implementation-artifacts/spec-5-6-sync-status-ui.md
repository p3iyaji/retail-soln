---
title: 'Story 5.6: Sync status UI and error recovery'
type: 'feature'
created: '2026-08-29'
status: 'done'
baseline_commit: 'bbbae3620b2438eea16fe0fae201552eea8b8781'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-5-context.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-retail-soln-2026-08-24/EXPERIENCE.md'
  - '{project-root}/_bmad-output/planning-artifacts/architecture/architecture-retail-soln-2026-08-24/offline-sync-design.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-5-5-pull-sync-cursors.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Cashiers see a sync badge but cannot open last error, Retry, pending queue counts, or an offline transition banner. Oversell exceptions after push have no POS notice, and managers have no list of pending exceptions (approve-by-id only). Sync failures must stay visible until recovery succeeds.

**Approach:** Make the POS sync badge interactive (panel: last error, pending/error counts, Retry = push then pull). Add a one-shot offline banner per transition. Surface a non-blocking oversell notice when push returns `exceptionId`. Add `GET /api/v1/sync/status` and a thin Admin pending-oversell list that calls existing approve. Device Admin console stays Story 5.7.

## Boundaries & Constraints

**Always:**
- Sync badge remains always visible with text labels ONLINE / OFFLINE / SYNCING / SYNC ERROR (not color alone); `aria-live` polite / assertive on error (keep existing).
- Click (or keyboard-activate) badge opens a panel: last error text, pending + error queue counts from Dexie, **Retry** that clears error then runs push→pull drain (same path as reconnect).
- SYNC ERROR never auto-dismisses; only Retry success or explicit clear after successful drain clears it.
- Offline banner once per transition to OFFLINE (“Offline — sales will sync when connected”); dismissible for that session until next online→offline edge.
- After push items with `exceptionId`, show non-blocking POS notice with sale uuid/ref (do not block checkout).
- `GET /api/v1/sync/status` (Sanctum + store scope): last push/pull times if known, pending oversell count for store, device enabled for `deviceUuid` when provided.
- Admin: list pending `inventory_oversell_exceptions` for current store + approve action (reuse `inventory.approve-oversell` + existing approve endpoint). Void/adjust inventory stay later.
- Never hide connectivity or sync errors behind silent toasts that vanish while status is still ERROR.

**Ask First:**
- Building a full Admin “oversell detail” page beyond list+approve.
- Changing badge placement outside POS chrome.

**Never:**
- Device register/disable Admin console (5.7).
- Auto-dismiss SYNC ERROR on timer.
- Reworking push/pull protocols beyond status + UI wiring.
- Offline installment create.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Click badge ONLINE | No error | Panel opens; counts; Retry still allowed (no-op or light refresh) | N/A |
| Click badge SYNC ERROR | lastError set | Panel shows message + Retry | Retry fail → error stays |
| Retry success | Pending/error rows + online | Drain + pull; badge ONLINE; panel can close | N/A |
| Offline transition | ONLINE → OFFLINE | Banner once; badge OFFLINE | Banner not re-shown until next transition |
| Push oversell | Item `exceptionId` | Non-blocking notice with sale ref | N/A |
| GET /sync/status | Auth + store | JSON status payload | 401/403 as usual |
| Admin list + approve | Pending exceptions | Listed; approve flips status | Permission denied |

</frozen-after-approval>

## Code Map

- `frontend/pos/src/components/SyncBadge.vue` -- display-only span today; make button/disclosure + panel (or sibling `SyncStatusPanel.vue`)
- `frontend/pos/src/stores/sync.ts` -- `lastError` / `clearError` (`:17–52`); extend with pendingCount/errorCount or computed helpers if needed
- `frontend/pos/src/sync/pendingSales.ts` -- `listPendingSales` / `listPushableSales` (`:76–86`) for counts
- `frontend/pos/src/composables/useConnectivity.ts` -- `drainThenPull` (`:10–21`); Retry should call same helper (export it)
- `frontend/pos/src/sync/pushPendingSales.ts` -- `exceptionId` on items (`:17`); emit/store notice for UI after drain
- `frontend/pos/src/views/PosFloorView.vue` -- chrome hosts `SyncBadge` (`:228`); toast/banner patterns (`:20–68`, `:247`)
- UX: `EXPERIENCE.md` sync badge / OFFLINE banner / UJ-2 (`:68`, `:83–85`, `:139–144`)
- New: `GET /sync/status` in `backend/Modules/Sync/routes/api.php` + `SyncStatusController` / service
- Oversell: `OversellExceptionController` approve only — add `index`/`list`; Admin SPA new view + router entry under inventory/SM area
- Continuity: 5.4/5.5 deferred rich Retry + status UI to this story

## Tasks & Acceptance

**Execution:**
- [x] POS SyncBadge panel + Retry (drainThenPull) + Dexie pending/error counts
- [x] Offline transition banner (once per edge) on PosFloor chrome
- [x] Non-blocking oversell notice after sync when `exceptionId` present
- [x] `GET /api/v1/sync/status` (+ feature test)
- [x] Admin pending oversell list + approve wiring
- [x] Component/unit tests for badge panel, Retry, banner; README + sprint-status `5-6-…`

**Acceptance Criteria:**
- Given SYNC ERROR, when the cashier opens the badge and taps Retry and drain succeeds, then the badge returns to ONLINE/OFFLINE and the error is cleared.
- Given a transition to offline, when the floor is visible, then an offline banner appears once until connectivity returns.
- Given a pushed sale that created an oversell exception, when drain completes, then the POS shows a non-blocking notice with a sale reference.
- Given an authenticated store user, when `GET /sync/status` is called, then pending exception count and device/sync summary fields are returned.
- Given a manager with `inventory.approve-oversell`, when they open Admin pending oversells and approve, then the exception status becomes approved.

## Design Notes

**Retry:** Export `drainThenPull` from connectivity (or a shared `syncDrain.ts`) so badge Retry and `online` event share one path.

**Counts:** Refresh counts when panel opens and after drain; cheap Dexie queries — no new IndexedDB schema.

**Status API fields (minimal):** `{ deviceEnabled?, lastPushAt?, lastPullAt?, pendingOversellCount, pendingClientHint? }` — server may omit client Dexie counts; POS still shows local Dexie counts in the panel.

**Admin:** Match existing Admin list patterns (Inventory/Products tables); no new design system.

## Verification

**Commands:**
- `cd backend && php artisan test --filter=Sync` -- expected: status + prior sync tests pass
- `cd frontend/pos && npm run test && npm run build` -- expected: SyncBadge/panel tests pass
- `cd frontend/admin && npm run build` -- expected: success

**Manual checks:**
- Force SYNC ERROR → open badge → Retry → ONLINE
- Toggle offline → banner once → online → banner gone; offline again → banner again
- Oversell push → notice with sale ref
- Admin: pending exception list → approve

## Suggested Review Order

**POS recovery UI**

- Shared Retry/reconnect drain
  [`useConnectivity.ts:10`](../../frontend/pos/src/composables/useConnectivity.ts#L10)

- Badge panel + Retry (counts, status fetch, store guard)
  [`SyncBadge.vue:74`](../../frontend/pos/src/components/SyncBadge.vue#L74)

- Offline banner + oversell notices on floor
  [`PosFloorView.vue:1`](../../frontend/pos/src/views/PosFloorView.vue#L1)

**API / Admin**

- `GET /sync/status` device-scoped summary
  [`SyncStatusService.php:21`](../../backend/Modules/Sync/app/Services/SyncStatusService.php#L21)

- Admin pending oversells list + approve
  [`OversellExceptionsView.vue:1`](../../frontend/admin/src/views/OversellExceptionsView.vue#L1)

**Tests**

- Badge / banner / status API coverage
  [`SyncBadge.test.ts:22`](../../frontend/pos/src/components/SyncBadge.test.ts#L22)
  [`PosFloorView.offlineBanner.test.ts:55`](../../frontend/pos/src/views/PosFloorView.offlineBanner.test.ts#L55)
  [`SyncStatusApiTest.php:145`](../../backend/tests/Feature/SyncStatusApiTest.php#L145)
