import {
  fetchStore,
  listInventoryBalances,
  listProductsPage,
  type CatalogProduct,
} from '@/api/posCatalog'
import {
  posDb,
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
const LAST_CACHE_WARM_AT_KEY = 'lastCacheWarmAt'

let warmInFlight: Promise<void> | null = null

function isBrowserOnline(): boolean {
  return typeof navigator === 'undefined' ? true : navigator.onLine !== false
}

function safeLastPage(metaLastPage: number | undefined): number {
  const n = Number(metaLastPage)
  return Number.isFinite(n) && n >= 1 ? Math.floor(n) : 1
}

function toCachedProduct(row: CatalogProduct): CachedProduct | null {
  if (row.isActive === false) return null

  const variants = (row.variants ?? [])
    .filter((v) => v.isActive !== false)
    .map((v) => {
      const raw = v.price ?? row.sellingPrice
      if (raw == null || String(raw).trim() === '') return null
      const unit = money(raw)
      if (unit === '0.0000' || unit === '0') return null
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
    .filter((v): v is NonNullable<typeof v> => v != null)

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

async function fetchAllProducts(): Promise<CachedProduct[]> {
  const perPage = 100
  const first = await listProductsPage({ page: 1, perPage })
  const pages = [first]
  const lastPage = safeLastPage(first.meta?.lastPage)
  for (let page = 2; page <= lastPage; page++) {
    pages.push(await listProductsPage({ page, perPage }))
  }

  const products: CachedProduct[] = []
  for (const page of pages) {
    for (const row of page.data) {
      const cached = toCachedProduct(row)
      if (cached) products.push(cached)
    }
  }
  return products
}

async function fetchAllBalances(storeId: number): Promise<CachedInventorySnapshot[]> {
  const perPage = 100
  const first = await listInventoryBalances({ storeId, page: 1, perPage })
  const pages = [first]
  const lastPage = safeLastPage(first.meta?.lastPage)
  for (let page = 2; page <= lastPage; page++) {
    pages.push(await listInventoryBalances({ storeId, page, perPage }))
  }

  const snapshots: CachedInventorySnapshot[] = []
  for (const page of pages) {
    for (const row of page.data) {
      if (row.locationId != null && row.locationId !== storeId) continue
      snapshots.push({
        variantId: row.productVariantId,
        storeId,
        available: money(row.available ?? '0'),
      })
    }
  }
  return snapshots
}

function pricesForStore(products: CachedProduct[], storeId: number): CachedPrice[] {
  const prices: CachedPrice[] = []
  for (const product of products) {
    for (const variant of product.variants) {
      const unitPrice = money(variant.price)
      prices.push({
        variantId: variant.id,
        storeId,
        unitPrice,
        listPrice: unitPrice,
      })
    }
  }
  return prices
}

function taxFromStore(
  storeId: number,
  taxConfig: { rate?: string; inclusive?: boolean; name?: string } | null,
): CachedTax {
  return {
    storeId,
    name: taxConfig?.name?.trim() || 'Tax',
    rate: money(taxConfig?.rate ?? '0'),
    inclusive: Boolean(taxConfig?.inclusive),
  }
}

async function clearStoreScopedTables(db: PosDatabase): Promise<void> {
  await db.transaction(
    'rw',
    [db.products, db.prices, db.taxes, db.inventory_snapshot, db.pos_config, db.sync_meta],
    async () => {
      await db.products.clear()
      await db.prices.clear()
      await db.taxes.clear()
      await db.inventory_snapshot.clear()
      await db.pos_config.clear()
      await db.sync_meta.delete(CACHE_STORE_ID_KEY)
      await db.sync_meta.delete(LAST_CACHE_WARM_AT_KEY)
    },
  )
}

/**
 * Warm IndexedDB catalog cache from existing online APIs.
 * Sets SYNCING during fetch; restores ONLINE/OFFLINE on success; SYNC ERROR on failure.
 * Replaces store-scoped snapshot rows so prior stores cannot bleed into the active store.
 * When offline, clears store-scoped tables so prior-store data cannot be used under a new store.
 */
export async function warmCache(
  storeId: number,
  options: { db?: PosDatabase; sync?: ReturnType<typeof useSyncStore> } = {},
): Promise<void> {
  if (!Number.isInteger(storeId) || storeId <= 0) return

  const run = async (): Promise<void> => {
    const db = options.db ?? posDb
    const sync = options.sync ?? useSyncStore()

    if (!isBrowserOnline()) {
      await clearStoreScopedTables(db)
      return
    }

    // Allow retry after SYNC ERROR to show SYNCING again.
    if (sync.status === 'SYNC_ERROR') {
      sync.clearError(true)
    }
    sync.setSyncing()

    try {
      const [products, balances, storeRes] = await Promise.all([
        fetchAllProducts(),
        fetchAllBalances(storeId),
        fetchStore(storeId),
      ])

      const store = storeRes.data
      const prices = pricesForStore(products, storeId)
      const tax = taxFromStore(storeId, store.taxConfig)
      const posConfig: CachedPosConfig = {
        storeId,
        storeName: store.name,
        currency: store.currency || 'USD',
      }

      await db.transaction(
        'rw',
        [
          db.products,
          db.prices,
          db.taxes,
          db.inventory_snapshot,
          db.pos_config,
          db.sync_meta,
        ],
        async () => {
          await db.products.clear()
          await db.prices.clear()
          await db.taxes.clear()
          await db.inventory_snapshot.clear()
          await db.pos_config.clear()

          if (products.length > 0) await db.products.bulkPut(products)
          if (prices.length > 0) await db.prices.bulkPut(prices)
          await db.taxes.put(tax)
          if (balances.length > 0) await db.inventory_snapshot.bulkPut(balances)
          await db.pos_config.put(posConfig)

          await db.sync_meta.put({ key: CACHE_STORE_ID_KEY, value: String(storeId) })
          await db.sync_meta.put({
            key: LAST_CACHE_WARM_AT_KEY,
            value: new Date().toISOString(),
          })
        },
      )

      sync.clearError(isBrowserOnline())
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Cache warm failed'
      sync.setSyncError(message)
      throw err
    }
  }

  if (warmInFlight) {
    await warmInFlight.catch(() => undefined)
  }
  warmInFlight = run()
  try {
    await warmInFlight
  } finally {
    warmInFlight = null
  }
}

/** Test helper — reset in-flight lock between cases. */
export function __resetWarmCacheLockForTests(): void {
  warmInFlight = null
}
