---
title: 'Story 5.7: Device registration and management'
type: 'feature'
created: '2026-08-29'
status: 'done'
baseline_commit: 'd2cf652629aba723a9c01c5bcfb7d3cd4ee3bac4'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-5-context.md'
  - '{project-root}/_bmad-output/planning-artifacts/architecture/architecture-retail-soln-2026-08-24/offline-sync-design.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-retail-soln-2026-08-24/EXPERIENCE.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-5-4-push-sync-api.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-5-6-sync-status-ui.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Push/pull auto-upsert devices (`enabled=true`) on first sync, so there is no Admin register/disable console and “registration required” is not enforced. Managers cannot name, list, or revoke POS terminals.

**Approach:** Add store-scoped Admin device management (`devices.manage`): list, register (uuid + name), rename, enable/disable. Stop auto-creating devices on push/pull — unknown `deviceUuid` is rejected; disabled devices stay rejected. POS surfaces the local `deviceUuid` so staff can register it. Existing `devices` rows remain valid.

## Boundaries & Constraints

**Always:**
- Admin API + UI for devices on the current store: list, create (uuid, name), update name, set `enabled` true/false. Permission `devices.manage` (store-scoped).
- Push and pull **require** an existing `devices` row for `(organization_id, uuid)` scoped to the request store (or move store only via explicit Admin update — default: device is store-bound at register).
- Unknown device → 403 with clear code (`device_unregistered`); disabled → existing `device_disabled`. Do **not** `firstOrCreate` on push/pull.
- Touch `last_seen_at` on successful push/pull when device is known and enabled.
- POS SyncBadge panel (or adjacent chrome) shows full local `deviceUuid` (copy-friendly) so Admin can register it.
- Sanctum + `X-Store-Id`; never invent a second device identity scheme (reuse Dexie `deviceUuid`).

**Ask First:**
- Allowing POS self-register as `enabled=false` “pending” without Admin create (default: **no** — Admin creates the row).
- Moving a device between stores after registration (default: **no** in this story — re-register or keep store fixed).

**Never:**
- Removing uuid idempotency / sync push-pull protocols.
- Auto-dismiss SYNC ERROR; rewriting oversell Admin.
- Binding `terminalLabel` localStorage as the server device PK (label may inform `name` at register only).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Admin register | uuid + name + store | Device row enabled; appears in list | Duplicate uuid → 422 |
| Admin disable | Existing device | `enabled=false` | N/A |
| Push unknown uuid | No devices row | 403 `device_unregistered`; POS SYNC ERROR | Clear message |
| Push disabled | `enabled=false` | 403 `device_disabled` | Existing path |
| Pull unknown/disabled | Same | Same 403 codes | N/A |
| Push registered | Enabled device | Sync succeeds; `last_seen_at` updated | N/A |
| POS shows uuid | Synced Dexie deviceUuid | Visible in badge panel | N/A |

</frozen-after-approval>

## Code Map

- `backend/Modules/Sync/app/Models/Device.php` -- fields already: uuid, name, enabled, last_seen_at, store/org
- Migration `2026_08_29_100000_create_sync_tables.php` -- `devices` table exists; no schema change unless adding indexes/notes
- `SyncPushService::resolveDevice` / `SyncPullService::resolveDevice` -- today `firstOrCreate` (`~:92-121`, `~:452-481`) → change to lookup-only + unregistered exception
- New: `DeviceController` + FormRequests + Resource; routes under `/api/v1/devices` or `/sync/devices` (prefer `/devices` REST)
- Permission: `devices.manage` already seeded (`PermissionMatrix.php:105`)
- Admin: new `DevicesView.vue` + router + AppShell nav “Devices” (`EXPERIENCE.md:38`)
- POS: `SyncBadge.vue` panel — show `deviceUuid` from `ensureDeviceUuid()`
- Tests: update SyncPush/Pull tests that assumed auto-create; add Device API + Admin build; POS badge uuid test
- Continuity: 5.4 Ask First strict pre-reg lands here; 5.6 status already treats unknown as disabled

## Tasks & Acceptance

**Execution:**
- [x] Device Admin API (list/create/update/enable-disable) + `devices.manage` authz
- [x] Remove push/pull upsert; unknown → `device_unregistered`; update feature tests
- [x] Admin Devices SPA page (list, register form, toggle enabled, rename)
- [x] POS SyncBadge shows copyable `deviceUuid`
- [x] Feature + Vitest coverage for matrix; README + sprint-status `5-7-…`

**Acceptance Criteria:**
- Given a manager with `devices.manage`, when they register a POS uuid for a store, then that device can push/pull successfully.
- Given an unknown deviceUuid, when push or pull runs, then the API returns 403 `device_unregistered` and does not create a row.
- Given a disabled device, when sync is attempted, then the API returns 403 `device_disabled`.
- Given the POS floor, when the sync panel is open, then the cashier/manager can see the full local device UUID.
- Given Admin Devices, when a device is disabled, then it appears disabled in the list and cannot sync until re-enabled.

## Design Notes

**Register UX:** Admin pastes uuid from POS panel; optional default name from `terminalLabel` if staff types it. No QR required this story.

**Exception type:** Add `DeviceUnregisteredException` parallel to `DeviceDisabledException` for clear client messages.

**Migration of already-upserted devices:** Rows created under 5.4/5.5 remain; Admin can rename/disable them. No data wipe.

**Epic close:** After 5.7, Epic 5 is feature-complete; optional retrospective is separate.

## Verification

**Commands:**
- `cd backend && php artisan test --filter='Sync|Device'` -- expected: pass including device API + unregistered cases
- `cd frontend/pos && npm run test && npm run build` -- expected: badge uuid test + build
- `cd frontend/admin && npm run build` -- expected: success

**Manual checks:**
- Copy uuid from POS → Admin register → Retry sync succeeds
- Disable device → push/pull 403 → SYNC ERROR
- Re-enable → sync works again

## Suggested Review Order

**Registration gate**

- Lookup-only device resolve (no upsert); lowercase uuid
  [`SyncPushService.php:91`](../../backend/Modules/Sync/app/Services/SyncPushService.php#L91)

- last_seen_at after successful sync
  [`SyncPushService.php:83`](../../backend/Modules/Sync/app/Services/SyncPushService.php#L83)

**Admin API / UI**

- Register / update / enable-disable
  [`DeviceService.php:25`](../../backend/Modules/Sync/app/Services/DeviceService.php#L25)

- Devices SPA
  [`DevicesView.vue:1`](../../frontend/admin/src/views/DevicesView.vue#L1)

**POS**

- Copyable deviceUuid + unregistered hint
  [`SyncBadge.vue:247`](../../frontend/pos/src/components/SyncBadge.vue#L247)

**Tests**

- Device API + sync unregistered/cross-store
  [`DeviceApiTest.php:53`](../../backend/tests/Feature/DeviceApiTest.php#L53)
  [`SyncPushApiTest.php:289`](../../backend/tests/Feature/SyncPushApiTest.php#L289)
