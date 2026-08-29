---
title: 'Story 5.2: Local product/inventory cache sync'
type: 'feature'
created: '2026-08-29'
status: 'done'
baseline_commit: '1fe0b2781b1d8a712df0282676c35c404558a5ad'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-5-context.md'
  - '{project-root}/_bmad-output/planning-artifacts/architecture/architecture-retail-soln-2026-08-24/offline-sync-design.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-5-1-pwa-offline-shell.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Dexie tables from Story 5.1 are empty placeholders. Offline, cashiers still cannot search catalog or see advisory stock — the shell loads but there is nothing to sell against.

**Approach:** Upgrade IndexedDB schemas for products, prices, taxes, inventory_snapshot, and pos_config; warm them from **existing** online APIs when a store is selected; when offline, POS search (and cart add pricing) reads the local cache. Formal cursor pull (`GET /sync/pull`) stays Story 5.5. Sale queue stays 5.3.

## Boundaries & Constraints

**Always:**
- When **online**, search and cart pricing continue to use live `GET /products/search` and `POST /pricing/resolve` (server authoritative).
- When **offline**, search Dexie `products`; price lines from cached `prices` + `taxes` (DECIMAL strings); `inventory_snapshot` is advisory only — never block add-to-cart (AD-9).
- Warm cache on **store select** (and refresh when floor mounts with a store already selected); clear/replace store-scoped rows on store switch.
- During warm: set sync badge **SYNCING**, then restore ONLINE/OFFLINE; on warm failure set **SYNC ERROR** (sticky until clear/retry).
- Preserve money as DECIMAL strings; do not invent `/sync/push` or `/sync/pull`.

**Ask First:**
- Adding a new dedicated backend bulk-cache endpoint (prefer existing `/products`, `/inventory/balances`, store show).
- Changing Dexie store **names** away from offline-sync design.

**Never:**
- Offline installment plan create; offline sale persistence queue (5.3); cursor pull protocol (5.5); device registration (5.7).
- Mutating inventory balances from the client.
- Caching `/api` responses in the service worker.
- Replacing online resolve with cache while `navigator.onLine` / status ONLINE.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Store select online | Auth + storeId | Cache warm fills products/prices/taxes/inventory/pos_config; badge SYNCING→ONLINE | Warm fail → SYNC ERROR + lastError |
| Search online | Query string | Live `/products/search` (unchanged) | Existing API errors |
| Search offline | Query + Dexie populated | Hits from local products (name/sku/barcode) | Empty list if cache cold |
| Add to cart offline | Variant in cache | Line priced from cached unit price + tax; no `/pricing/resolve` | Missing price → cart error, no silent zero |
| Store switch | New storeId | Clear prior store cache keys; warm new store | Same as warm fail |
| Offline cold cache | Offline, empty products | Search returns []; badge may still OFFLINE | Message that catalog not synced |

</frozen-after-approval>

## Code Map

- `frontend/pos/src/db/posDb.ts` -- v1 placeholders; add **version 2** typed tables + indexes (`id`, `sku`, `barcode`, `storeId` where needed); keep `ensureDeviceUuid`
- `frontend/pos/src/bootstrap.ts` -- leave IDB open only; **do not** warm here (no store yet)
- `frontend/pos/src/stores/sync.ts` -- reuse `setSyncing` / `setSyncError` / `clearError` / `applyConnectivity`
- `frontend/pos/src/stores/pos.ts` -- `selectStore` (~L108), `runSearch` (~L283), `addVariantToCart` (~L359): branch offline reads; call cache warm after store select
- `frontend/pos/src/api/posCatalog.ts` -- existing search/categories/resolve; add thin helpers for `GET /products` pages + balances if needed
- `frontend/pos/src/views/PosFloorView.vue` (~L39) -- optional warm refresh on mount when store set
- `frontend/pos/src/lib/cartPricing.ts` -- reuse types; offline line builder from cached unit + tax (no promo engine offline in 5.2)
- New: `frontend/pos/src/sync/cacheWarm.ts` -- paginate products, upsert Dexie, fetch inventory balances + tax/config for store
- New: `frontend/pos/src/sync/localCatalog.ts` -- query Dexie for search hits shaped like `SearchProduct`
- New tests: `cacheWarm.test.ts`, `localCatalog.test.ts`; extend pos cart tests for offline branch
- Backend: **prefer read-only reuse** of `GET /products`, `GET /inventory/balances?storeId=`, store tax via existing org/store endpoints if POS auth allows — **Ask First** before new routes
- Spec continuity: `spec-5-1-pwa-offline-shell.md` (Dexie + badge); defer customers hot-cache if timeboxed

## Tasks & Acceptance

**Execution:**
- [x] `frontend/pos/src/db/posDb.ts` -- Dexie v2 schemas/indexes for products, prices, taxes, inventory_snapshot, pos_config -- typed cache
- [x] `frontend/pos/src/sync/cacheWarm.ts` (+ test) -- warm from existing APIs; SYNCING/ERROR; store-scoped replace
- [x] `frontend/pos/src/sync/localCatalog.ts` (+ test) -- offline search against Dexie
- [x] `frontend/pos/src/api/posCatalog.ts` -- helpers to list products pages / balances as needed
- [x] `frontend/pos/src/stores/pos.ts` -- wire warm on selectStore; offline search + offline add pricing
- [x] `frontend/pos/src/lib/cartPricing.ts` -- helper to build priced line from cache (DECIMAL)
- [x] `frontend/pos/README.md` + sprint-status `5-2-…` -- document offline catalog cache
- [x] Unit-test I/O matrix (online path unchanged; offline search; warm failure → SYNC ERROR)

**Acceptance Criteria:**
- Given online store select, when warm completes, then Dexie contains products for that store’s catalog warm set and badge returns to ONLINE.
- Given offline with a warm cache, when cashier searches, then results come from IndexedDB without calling `/products/search`.
- Given offline add-to-cart for a cached variant, when priced, then line totals use cached DECIMAL price/tax and do not call `/pricing/resolve`.
- Given online search/cart, when this story ships, then live search + resolve behaviour is unchanged.
- Given warm failure, when store is selected online, then badge shows SYNC ERROR until cleared.
- Given store switch, when warm runs, then previous store’s store-scoped snapshot rows are replaced (no cross-store bleed).

## Design Notes

Offline pricing in 5.2 is **list/cache price + store tax snapshot**, not full promotion re-resolution (promos refresh on next online resolve / later sync stories).

`sync_meta` may store `lastCacheWarmAt` / `cacheStoreId` for debugging; cursor keys reserved for 5.5.

## Verification

**Commands:**
- `cd frontend/pos && npm run test` -- expected: all tests pass including cache/localCatalog
- `cd frontend/pos && npm run build` -- expected: success

**Manual checks:**
- Online: select store → DevTools Application → IndexedDB populated
- Offline: search finds cached SKU; add to cart without network; badge OFFLINE

## Suggested Review Order

**Cache warm**

- Online warm from products/balances/store; SYNCING→ONLINE; SYNC ERROR sticky + retry
  [`cacheWarm.ts:160`](../../frontend/pos/src/sync/cacheWarm.ts#L160)

- Offline store switch clears prior store-scoped tables (no bleed)
  [`cacheWarm.ts:138`](../../frontend/pos/src/sync/cacheWarm.ts#L138)

**Dexie schema**

- Typed v3 tables for products/prices/taxes/inventory/pos_config
  [`posDb.ts:111`](../../frontend/pos/src/db/posDb.ts#L111)

**POS offline vs online paths**

- Warm on selectStore; offline search + priceFromCache
  [`pos.ts:134`](../../frontend/pos/src/stores/pos.ts#L134)
  [`pos.ts:399`](../../frontend/pos/src/stores/pos.ts#L399)

- Local catalog search shaped like `/products/search`
  [`localCatalog.ts:49`](../../frontend/pos/src/sync/localCatalog.ts#L49)

- Offline DECIMAL line builder (no promos)
  [`cartPricing.ts`](../../frontend/pos/src/lib/cartPricing.ts)

**Tests**

- Warm pagination, offline clear, SYNC_ERROR retry
  [`cacheWarm.test.ts`](../../frontend/pos/src/sync/cacheWarm.test.ts)
  [`pos.cart.test.ts`](../../frontend/pos/src/stores/pos.cart.test.ts)
