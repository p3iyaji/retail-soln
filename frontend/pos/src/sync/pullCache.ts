import { api, ApiError, formatDeviceSyncError } from '@/api/client'
import {
  ensureDeviceUuid,
  posDb,
  type CachedCustomer,
  type CachedInventorySnapshot,
  type CachedPosConfig,
  type CachedPrice,
  type CachedProduct,
  type CachedTax,
  type PosDatabase,
} from '@/db/posDb'
import { money } from '@/lib/decimal'
import { useSyncStore } from '@/stores/sync'

const CACHE_STORE_ID_KEY = 'cacheStoreId'
const DEFAULT_PAGE_BUDGET = 20

export type PullProductVariant = {
  id: number
  name: string
  sku: string
  barcode: string | null
  isActive: boolean
  isDefault?: boolean
  price: string
}

export type PullProduct = {
  id: number
  name?: string
  sku?: string
  barcode?: string | null
  categoryId?: number | null
  isActive: boolean
  sellingPrice?: string | null
  variants?: PullProductVariant[]
  updatedAt?: string
}

export type PullPrice = {
  id?: number
  variantId: number
  storeId: number
  unitPrice: string
  listPrice: string
  updatedAt?: string
}

export type PullTax = {
  storeId: number
  name: string
  rate: string
  inclusive: boolean
}

export type PullCustomer = {
  id: number
  name: string
  phone: string | null
  email: string | null
  isActive: boolean
}

export type PullInventory = {
  variantId: number
  storeId: number
  available: string
}

export type PullConfig = {
  storeId: number
  storeName: string
  currency: string
}

export type PullDeleted = {
  products?: number[]
  prices?: Array<{ variantId: number }>
  customers?: number[]
  inventory?: Array<{ variantId: number }>
}

export type SyncPullResponse = {
  data: {
    products: PullProduct[]
    prices: PullPrice[]
    taxes: PullTax[]
    customers: PullCustomer[]
    inventory: PullInventory[]
    config: PullConfig[]
    deleted?: PullDeleted
  }
  meta: {
    cursor: string
    hasMore: boolean
  }
}

export type PullCacheOptions = {
  db?: PosDatabase
  sync?: ReturnType<typeof useSyncStore>
  /** Max GET pages per refresh (default 20). */
  pageBudget?: number
  /** When set, re-apply this SYNC ERROR after a successful pull (e.g. prior push failure). */
  priorError?: string | null
}

let pullInFlight: Promise<void> | null = null

function isBrowserOnline(): boolean {
  return typeof navigator === 'undefined' ? true : navigator.onLine !== false
}

function pullCursorKey(storeId: number): string {
  return `pullCursor:${storeId}`
}

function lastPullAtKey(storeId: number): string {
  return `lastPullAt:${storeId}`
}

function isPullMetaKey(key: string): boolean {
  return key.startsWith('pullCursor:') || key.startsWith('lastPullAt:')
}

/** Clear catalog tables + all pull cursors (store switch / intentional reset). */
async function clearStoreScopedTables(db: PosDatabase): Promise<void> {
  await db.transaction(
    'rw',
    [db.products, db.prices, db.taxes, db.customers, db.inventory_snapshot, db.pos_config, db.sync_meta],
    async () => {
      await db.products.clear()
      await db.prices.clear()
      await db.taxes.clear()
      await db.customers.clear()
      await db.inventory_snapshot.clear()
      await db.pos_config.clear()
      await db.sync_meta.delete(CACHE_STORE_ID_KEY)
      const metaKeys = await db.sync_meta.toCollection().primaryKeys()
      for (const key of metaKeys) {
        if (typeof key === 'string' && isPullMetaKey(key)) {
          await db.sync_meta.delete(key)
        }
      }
    },
  )
}

async function removeProductAndRelated(
  db: PosDatabase,
  storeId: number,
  productId: number,
  variantIdsHint?: number[],
): Promise<void> {
  const existing = await db.products.get(productId)
  const variantIds =
    variantIdsHint && variantIdsHint.length > 0
      ? variantIdsHint
      : (existing?.variants?.map((v) => v.id) ?? [])
  await db.products.delete(productId)
  for (const variantId of variantIds) {
    await db.prices.delete([variantId, storeId])
    await db.inventory_snapshot.delete([variantId, storeId])
  }
}

function toCachedProduct(row: PullProduct): CachedProduct | null {
  if (row.isActive === false) return null
  if (row.name == null || row.sku == null) return null

  const variants = (row.variants ?? [])
    .filter((v) => v.isActive !== false)
    .map((v) => {
      const unit = money(v.price ?? '0')
      return {
        id: v.id,
        name: v.name,
        sku: v.sku,
        barcode: v.barcode ?? null,
        isActive: true,
        isDefault: v.isDefault,
        price: unit,
      }
    })

  if (variants.length === 0) return null

  return {
    id: row.id,
    name: row.name,
    sku: row.sku,
    barcode: row.barcode ?? null,
    categoryId: row.categoryId ?? null,
    isActive: true,
    variants,
  }
}

/** Apply one pull page into Dexie (upserts + deletes). Prefer store price rows; else variant list price. */
export async function applyPullPage(
  storeId: number,
  page: SyncPullResponse['data'],
  db: PosDatabase = posDb,
): Promise<void> {
  const deleted = page.deleted ?? {}
  const storePriceVariantIds = new Set(page.prices.map((p) => p.variantId))

  const products: CachedProduct[] = []
  const fallbackPrices: CachedPrice[] = []
  /** productId → optional variant ids from the pull row (when known). */
  const productIdsToRemove = new Map<number, number[] | undefined>()

  for (const id of deleted.products ?? []) {
    productIdsToRemove.set(id, undefined)
  }

  for (const row of page.products) {
    if (row.isActive === false) {
      productIdsToRemove.set(
        row.id,
        (row.variants ?? []).map((v) => v.id),
      )
      continue
    }
    const cached = toCachedProduct(row)
    if (!cached) {
      // Active flag but unsellable (no active variants) — drop stale sellable cache.
      productIdsToRemove.set(
        row.id,
        (row.variants ?? []).map((v) => v.id),
      )
      continue
    }
    products.push(cached)
    for (const variant of cached.variants) {
      if (storePriceVariantIds.has(variant.id)) continue
      const unitPrice = money(variant.price)
      fallbackPrices.push({
        variantId: variant.id,
        storeId,
        unitPrice,
        listPrice: unitPrice,
      })
    }
  }

  const prices: CachedPrice[] = page.prices
    .filter((p) => p.storeId === storeId)
    .map((p) => ({
      variantId: p.variantId,
      storeId,
      unitPrice: money(p.unitPrice),
      listPrice: money(p.listPrice ?? p.unitPrice),
    }))

  const taxes: CachedTax[] = page.taxes
    .filter((t) => t.storeId === storeId)
    .map((t) => ({
      storeId,
      name: t.name?.trim() || 'Tax',
      rate: money(t.rate ?? '0'),
      inclusive: Boolean(t.inclusive),
    }))

  const customers: CachedCustomer[] = page.customers
    .filter((c) => c.isActive !== false)
    .map((c) => ({
      id: c.id,
      name: c.name,
      phone: c.phone ?? null,
      email: c.email ?? null,
      isActive: true,
    }))

  const inventory: CachedInventorySnapshot[] = page.inventory
    .filter((row) => row.storeId === storeId)
    .map((row) => ({
      variantId: row.variantId,
      storeId,
      available: money(row.available ?? '0'),
    }))

  const configs: CachedPosConfig[] = page.config
    .filter((c) => c.storeId === storeId)
    .map((c) => ({
      storeId,
      storeName: c.storeName,
      currency: c.currency || 'USD',
    }))

  await db.transaction(
    'rw',
    [
      db.products,
      db.prices,
      db.taxes,
      db.customers,
      db.inventory_snapshot,
      db.pos_config,
    ],
    async () => {
      if (products.length > 0) await db.products.bulkPut(products)
      if (prices.length > 0) await db.prices.bulkPut(prices)
      if (fallbackPrices.length > 0) {
        for (const price of fallbackPrices) {
          const existing = await db.prices.get([price.variantId, storeId])
          if (!existing) await db.prices.put(price)
        }
      }
      if (taxes.length > 0) await db.taxes.bulkPut(taxes)
      if (customers.length > 0) await db.customers.bulkPut(customers)
      if (inventory.length > 0) await db.inventory_snapshot.bulkPut(inventory)
      if (configs.length > 0) await db.pos_config.bulkPut(configs)

      for (const [id, variantHint] of productIdsToRemove) {
        await removeProductAndRelated(db, storeId, id, variantHint)
      }
      for (const row of deleted.prices ?? []) {
        await db.prices.delete([row.variantId, storeId])
      }
      for (const id of deleted.customers ?? []) {
        await db.customers.delete(id)
      }
      for (const row of deleted.inventory ?? []) {
        await db.inventory_snapshot.delete([row.variantId, storeId])
      }
    },
  )
}

async function persistPullProgress(
  db: PosDatabase,
  storeId: number,
  cursor: string | null,
): Promise<void> {
  await db.transaction('rw', db.sync_meta, async () => {
    await db.sync_meta.put({ key: CACHE_STORE_ID_KEY, value: String(storeId) })
    if (cursor) {
      await db.sync_meta.put({ key: pullCursorKey(storeId), value: cursor })
    }
    await db.sync_meta.put({
      key: lastPullAtKey(storeId),
      value: new Date().toISOString(),
    })
  })
}

async function fetchPullPage(
  cursor: string | null,
  deviceUuid: string,
): Promise<SyncPullResponse> {
  const params = new URLSearchParams()
  params.set('deviceUuid', deviceUuid)
  if (cursor) params.set('cursor', cursor)
  return api.get<SyncPullResponse>(`/sync/pull?${params.toString()}`)
}

/**
 * Incremental pull into Dexie via GET /sync/pull (opaque cursor, capped pages).
 * Online: loops while hasMore within page budget.
 * Offline: keep cache on same store; clear only on store switch.
 */
export async function pullCache(
  storeId: number,
  options: PullCacheOptions = {},
): Promise<void> {
  if (!Number.isInteger(storeId) || storeId <= 0) return

  const run = async (): Promise<void> => {
    const db = options.db ?? posDb
    const sync = options.sync ?? useSyncStore()
    const pageBudget = Math.max(1, options.pageBudget ?? DEFAULT_PAGE_BUDGET)

    const metaStore = await db.sync_meta.get(CACHE_STORE_ID_KEY)
    const priorStoreId = metaStore?.value ? Number(metaStore.value) : null
    const storeChanged = priorStoreId != null && priorStoreId !== storeId

    if (!isBrowserOnline()) {
      if (storeChanged) {
        await clearStoreScopedTables(db)
      }
      return
    }

    if (sync.status === 'SYNC_ERROR') {
      sync.clearError(true)
    }
    sync.setSyncing()

    try {
      const deviceUuid = await ensureDeviceUuid(db)

      if (storeChanged) {
        await clearStoreScopedTables(db)
      }

      let cursor: string | null = null
      if (!storeChanged) {
        const saved = await db.sync_meta.get(pullCursorKey(storeId))
        cursor = saved?.value && saved.value.length > 0 ? saved.value : null
      }

      let pages = 0
      let hasMore = true
      let lastCursor = cursor

      while (hasMore) {
        pages += 1
        if (pages > pageBudget) {
          throw new Error('cache incomplete — retry')
        }

        const response = await fetchPullPage(lastCursor, deviceUuid)
        await applyPullPage(storeId, response.data, db)
        lastCursor = response.meta.cursor
        hasMore = Boolean(response.meta.hasMore)
        await persistPullProgress(db, storeId, lastCursor)
      }

      if (options.priorError) {
        sync.setSyncError(options.priorError)
      } else {
        sync.clearError(isBrowserOnline())
      }
    } catch (err) {
      const message =
        err instanceof ApiError
          ? formatDeviceSyncError(err)
          : err instanceof Error
            ? err.message
            : 'Cache pull failed'
      sync.setSyncError(message)
      throw err
    }
  }

  if (pullInFlight) {
    await pullInFlight.catch(() => undefined)
  }
  const thisRun = run()
  pullInFlight = thisRun
  try {
    await thisRun
  } finally {
    if (pullInFlight === thisRun) {
      pullInFlight = null
    }
  }
}

/** Test helper — reset in-flight lock between cases. */
export function __resetPullCacheLockForTests(): void {
  pullInFlight = null
}
