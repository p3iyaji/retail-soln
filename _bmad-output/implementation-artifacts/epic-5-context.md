# Epic 5 Context: Offline POS & Sync

<!-- Compiled from planning artifacts. Edit freely. Regenerate with compile-epic-context if planning docs change. -->

## Goal

Enable cashiers to sell with zero connectivity: local product/price/tax/customer/inventory cache, offline sale queue with client UUIDs, and push/pull sync that never double-posts money or stock. Server stays authoritative on price, tax, auth, and inventory reconciliation; concurrent offline oversell is accepted with manager approval (not hard-rejected). Builds on Epic 4’s online POS — this is Phase 6 / post-MVP.

## Stories

- Story 5.1: PWA offline shell with IndexedDB
- Story 5.2: Local product/inventory cache sync
- Story 5.3: Offline sale queue with UUID
- Story 5.4: Push sync API with idempotency
- Story 5.5: Pull sync with cursors
- Story 5.6: Sync status UI and error recovery
- Story 5.7: Device registration and management

## Requirements & Constraints

- POS must remain fully operable offline using a local cache; sales/returns/cash movements queue locally and sync on reconnect without duplicate financial or inventory effects.
- Every offline write uses a client UUID as the idempotency key; duplicate push returns the prior server result without re-processing.
- Server re-validates terminal/device enabled, user auth/permissions, sellability, price (within store tolerance), and tax on every push; client cache is advisory only.
- Oversell path: if available qty &lt; ordered qty, still post the sale and ledger movements (balance may go negative), create a pending `inventory_oversell_exceptions` row, and surface a non-blocking notice after sync — hard-reject is forbidden for this path. Managers approve, adjust inventory, or void (with reverse movements) per policy.
- New installment plan creation stays **online-only**; do not extend offline envelopes for plan create unless explicitly designed later. Offline installment collect is out of scope unless envelopes are extended.
- Inventory quantity changes only via InventoryLedgerService; money is DECIMAL only (no floats).
- Pull is incremental and store-scoped via opaque cursor — never a full DB dump. Push sends ordered batches by `localSequence` (max N).
- Device registration required; disabled devices cannot sync. Offline sales require a previously authenticated session within policy TTL; tokens refresh when online. All pushes re-checked server-side.
- Sync failures stay visible until retry succeeds; never hide connectivity or sync errors.

## Technical Decisions

- **Client store:** IndexedDB via Dexie — `products`, `prices`, `taxes`, `customers` (hot subset + recent; local create queue), `inventory_snapshot` (advisory), `pos_config`, `pending_sales`, `sync_meta` (cursors, last_push_at, device_uuid).
- **Transaction envelope:** uuid, deviceUuid, storeId, terminalId, userId, localSequence, createdAt, payloadType (`sale` | `return` | `cash_movement` | `session_close`), payload, syncStatus (`pending` | `pushing` | `synced` | `error`), serverResult.
- **Sync module + APIs:** `POST /api/v1/sync/push` (idempotent per uuid; posts via same atomic path as online POS); `GET /api/v1/sync/pull?cursor=`; `GET /api/v1/sync/status`; `POST /api/v1/sync/oversell-exceptions/{id}/approve`. Tables when epic starts: `devices`, `sync_batches`, `sync_records`, `inventory_oversell_exceptions`.
- **Conflict rules:** duplicate uuid → prior result; price drift → server recalculate, flag `price_adjusted` if beyond tolerance (still accept unless policy blocks); discontinued product → reject line (sale may fail if empty); auth revoked / device disabled → reject batch; clock skew → server timestamps for books, keep client `createdAt` for audit.
- **IDs / stack:** UUID for client/offline entities; bigint PK for server entities; Sanctum Bearer; store scope via `X-Store-Id` + Policies; domain logic in named sync/sales/inventory services (not fat controllers). Permissions: `devices.manage`, `inventory.approve-oversell` (store-scoped).
- **Clients:** extend existing Vue POS PWA (`frontend/pos/`); Admin for device manage + oversell exception review. No Inertia.

## UX & Interaction Patterns

- Sync badge always visible on POS chrome: **ONLINE** | **OFFLINE** (amber) | **SYNCING** (blue pulse) | **SYNC ERROR** (red + Retry, never auto-dismiss). Text label required — not color alone; announce changes via `aria-live` (assertive for SYNC ERROR).
- Offline transition: one banner (“Offline — sales will sync when connected”); click badge opens last error + Retry.
- Offline journey: network drop → local sale → reconnect → SYNCING → ONLINE or SYNC ERROR; oversell exception → non-blocking notice with sale ref.
- Admin: device register/disable; Store Manager dashboard for pending oversell exceptions.

## Cross-Story Dependencies

- **Requires Epics 1–4:** auth/RBAC/store context, catalog, InventoryLedgerService, and online POS sale/session/return paths that push reuses atomically.
- Within epic: IndexedDB shell (5.1) before cache (5.2) and sale queue (5.3); queue + device identity before push (5.4); pull cursors (5.5) feed cache; status UI (5.6) spans all sync states; device registration (5.7) gates push/pull security — prefer early enough that disabled devices cannot sync.
- **Defers / blocks:** installment plan create offline; gift cards; inventing sync behaviour outside the approved offline-sync design.
