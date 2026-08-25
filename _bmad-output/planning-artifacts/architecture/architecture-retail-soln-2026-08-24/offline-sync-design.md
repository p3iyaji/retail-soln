# Offline Sync Design — retail-soln

**Status:** Epic 0.3 complete  
**Date:** 2026-08-24  
**Implements:** AD-5, AD-9  
**Implementation epic:** Epic 5 (Phase 6) — design approved now

## Goals

- POS remains fully operable with **zero connectivity**
- No duplicate financial/inventory effects on retry
- Server remains authoritative on price, tax, auth, and inventory reconciliation
- Oversell from concurrent offline sales → **accept + manager approval** (not hard-reject)

## Client local store (IndexedDB via Dexie)

| Store | Contents | Refresh |
| --- | --- | --- |
| `products` | variants, barcodes, images thumbs | pull cursor |
| `prices` | resolved price lists for store | pull |
| `taxes` | store tax config | pull |
| `customers` | hot subset + recent | pull + local create queue |
| `inventory_snapshot` | available qty per variant | pull (advisory offline) |
| `pos_config` | terminal, shortcuts, receipt settings | pull |
| `pending_sales` | unsynced sales + payments + returns | local write |
| `sync_meta` | cursors, last_push_at, device_uuid | local |

## Transaction envelope (every offline write)

```text
uuid            // client-generated, primary idempotency key
deviceUuid
storeId
terminalId
userId
localSequence   // monotonic per device
createdAt       // client clock ISO
payloadType     // sale | return | cash_movement | session_close
payload         // type-specific JSON
syncStatus      // pending | pushing | synced | error
serverResult    // filled after ack
```

## Sync protocol

### Push `POST /api/v1/sync/push`

1. Client sends batch (ordered by `localSequence`), max N records
2. Server processes each by `uuid`:
   - If already processed → return prior result (idempotent)
   - Else validate: terminal/device enabled, user auth, permissions, product sellable, price within tolerance policy, tax recalculation
   - Post sale via same atomic path as online POS
3. Inventory check (AD-9):
   - If available ≥ qty → normal post
   - If available &lt; qty → **still post** sale + movements (balance may go negative); create `inventory_oversell_exceptions` row `status=pending`; include exception id in response
4. Response per item: `{ uuid, status: synced|error, saleId?, exceptionId?, errors? }`

### Pull `GET /api/v1/sync/pull?cursor=`

- Incremental changes: products, prices, taxes, customers delta, inventory snapshot, config
- Cursor opaque; store-scoped
- Never full DB dump

### Status `GET /api/v1/sync/status`

- Last sync times, pending exception counts, device enabled flag

## Conflict rules

| Conflict | Resolution |
| --- | --- |
| Duplicate uuid | Return original server result |
| Price changed since cache | Recalculate server price; if delta &gt; store tolerance, flag `price_adjusted` on sale (still accept unless policy blocks) |
| Product discontinued | Reject sale item; sale may fail if no line items remain |
| Auth revoked / device disabled | Reject batch; client shows SYNC ERROR |
| Concurrent offline oversell | Accept both; create oversell exceptions (AD-9) |
| Clock skew | Prefer server timestamps for books; keep client `createdAt` for audit |

## Manager approval workflow (AD-9)

1. Exception appears on Store Manager dashboard
2. Manager reviews sale + deficit qty
3. Actions: **approve** (ack negative / schedule replenishment), **adjust inventory** (stock count), **void sale** (if policy allows with reverse movements)
4. Exception status → `approved` | `resolved`
5. POS shows non-blocking notice after sync if any exceptions created for that device’s sales

## UI sync states (POS)

| State | Indicator | Behavior |
| --- | --- | --- |
| ONLINE | green | Normal API |
| OFFLINE | amber | Local-only writes |
| SYNCING | blue pulse | Push/pull in flight |
| SYNC ERROR | red | Show retry; never hide failures |

## Security

- Device registration required; disabled devices cannot sync
- Tokens refreshed when online; offline sales require previously authenticated session within policy TTL
- All pushes re-checked server-side (NFR-6)

## Testing focus (Phase 6 / 10)

- Duplicate push
- Mid-batch disconnect
- Two terminals overselling same SKU offline
- Device disable mid-queue
- Clock skew extremes
