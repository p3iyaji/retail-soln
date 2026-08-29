import Dexie from 'dexie'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { PosDatabase, ensureDeviceUuid } from '@/db/posDb'
import {
  applyPullPage,
  pullCache,
  __resetPullCacheLockForTests,
  type SyncPullResponse,
} from '@/sync/pullCache'
import { useSyncStore } from '@/stores/sync'

const getMock = vi.fn()

vi.mock('@/api/client', () => ({
  ApiError: class ApiError extends Error {
    status: number
    body: { message?: string; code?: string }
    constructor(status: number, body: { message?: string; code?: string }) {
      super(body.message ?? `Request failed (${status})`)
      this.status = status
      this.body = body
    }
    get code(): string | undefined {
      return this.body.code
    }
  },
  formatDeviceSyncError: (err: { code?: string; message: string }) => {
    if (err.code === 'device_unregistered') {
      return 'Device is not registered — register this UUID in Admin Devices'
    }
    if (err.code === 'device_disabled') {
      return 'Device is disabled and cannot sync'
    }
    return err.message
  },
  api: {
    get: (...args: unknown[]) => getMock(...args),
  },
}))

function page(partial: Partial<SyncPullResponse['data']> & { cursor?: string; hasMore?: boolean }): SyncPullResponse {
  return {
    data: {
      products: partial.products ?? [],
      prices: partial.prices ?? [],
      taxes: partial.taxes ?? [],
      customers: partial.customers ?? [],
      inventory: partial.inventory ?? [],
      config: partial.config ?? [],
      deleted: partial.deleted,
    },
    meta: {
      cursor: partial.cursor ?? 'cursor-1',
      hasMore: partial.hasMore ?? false,
    },
  }
}

describe('pullCache', () => {
  let db: PosDatabase

  beforeEach(async () => {
    setActivePinia(createPinia())
    getMock.mockReset()
    __resetPullCacheLockForTests()
    await Dexie.delete('retail_soln_pos')
    db = new PosDatabase()
    await db.open()
    Object.defineProperty(globalThis.navigator, 'onLine', {
      configurable: true,
      get: () => true,
    })
  })

  afterEach(() => {
    db.close()
  })

  it('bootstraps with empty cursor, applies page, and stores pullCursor', async () => {
    await ensureDeviceUuid(db)
    getMock.mockResolvedValue(
      page({
        products: [
          {
            id: 1,
            name: 'Widget',
            sku: 'W1',
            barcode: null,
            categoryId: null,
            isActive: true,
            variants: [
              {
                id: 10,
                name: 'Default',
                sku: 'W1-D',
                barcode: null,
                isActive: true,
                price: '5.0000',
              },
            ],
          },
        ],
        prices: [],
        taxes: [{ storeId: 1, name: 'VAT', rate: '0.0750', inclusive: false }],
        customers: [{ id: 7, name: 'Ada', phone: '555', email: null, isActive: true }],
        config: [{ storeId: 1, storeName: 'Main', currency: 'USD' }],
        inventory: [{ variantId: 10, storeId: 1, available: '3.0000' }],
        cursor: 'opaque-boot',
        hasMore: false,
      }),
    )

    const sync = useSyncStore()
    await pullCache(1, { db, sync })

    expect(await db.products.get(1)).toMatchObject({ sku: 'W1' })
    expect(await db.prices.get([10, 1])).toMatchObject({ unitPrice: '5.0000' })
    expect(await db.taxes.get(1)).toMatchObject({ rate: '0.0750' })
    expect(await db.pos_config.get(1)).toMatchObject({ storeName: 'Main', currency: 'USD' })
    expect(await db.customers.get(7)).toMatchObject({ name: 'Ada', phone: '555' })
    expect(await db.inventory_snapshot.get([10, 1])).toMatchObject({ available: '3.0000' })
    expect(await db.sync_meta.get('pullCursor:1')).toMatchObject({ value: 'opaque-boot' })
    expect(await db.sync_meta.get('lastPullAt:1')).toBeTruthy()
    expect(sync.status).toBe('ONLINE')
    expect(getMock).toHaveBeenCalledWith(expect.stringContaining('/sync/pull?'))
    expect(getMock.mock.calls[0]![0]).not.toContain('cursor=')
  })

  it('loops hasMore until false within budget and persists cursor per page', async () => {
    await ensureDeviceUuid(db)
    getMock
      .mockResolvedValueOnce(
        page({
          products: [
            {
              id: 1,
              name: 'A',
              sku: 'A',
              barcode: null,
              categoryId: null,
              isActive: true,
              variants: [
                { id: 1, name: 'D', sku: 'A1', barcode: null, isActive: true, price: '1.0000' },
              ],
            },
          ],
          cursor: 'c1',
          hasMore: true,
        }),
      )
      .mockResolvedValueOnce(
        page({
          products: [
            {
              id: 2,
              name: 'B',
              sku: 'B',
              barcode: null,
              categoryId: null,
              isActive: true,
              variants: [
                { id: 2, name: 'D', sku: 'B1', barcode: null, isActive: true, price: '2.0000' },
              ],
            },
          ],
          cursor: 'c2',
          hasMore: false,
        }),
      )

    await pullCache(1, { db, sync: useSyncStore() })

    expect(getMock).toHaveBeenCalledTimes(2)
    expect(getMock.mock.calls[1]![0]).toContain('cursor=c1')
    expect(await db.products.count()).toBe(2)
    expect(await db.sync_meta.get('pullCursor:1')).toMatchObject({ value: 'c2' })
  })

  it('stops with SYNC ERROR when page budget exceeded', async () => {
    await ensureDeviceUuid(db)
    getMock.mockResolvedValue(
      page({
        products: [],
        cursor: 'more',
        hasMore: true,
      }),
    )

    const sync = useSyncStore()
    await expect(pullCache(1, { db, sync, pageBudget: 2 })).rejects.toThrow(/cache incomplete/)
    expect(sync.status).toBe('SYNC_ERROR')
    expect(getMock).toHaveBeenCalledTimes(2)
  })

  it('product delete / inactive cleans related prices and inventory', async () => {
    await db.products.put({
      id: 9,
      name: 'Gone',
      sku: 'G',
      barcode: null,
      categoryId: null,
      isActive: true,
      variants: [{ id: 90, name: 'D', sku: 'G1', barcode: null, isActive: true, price: '1.0000' }],
    })
    await db.prices.put({ variantId: 90, storeId: 1, unitPrice: '1.0000', listPrice: '1.0000' })
    await db.inventory_snapshot.put({ variantId: 90, storeId: 1, available: '4.0000' })
    await db.customers.put({
      id: 5,
      name: 'Old',
      phone: null,
      email: null,
      isActive: true,
    })

    await applyPullPage(
      1,
      {
        products: [{ id: 9, isActive: false }],
        prices: [],
        taxes: [],
        customers: [],
        inventory: [],
        config: [],
        deleted: { products: [9], customers: [5] },
      },
      db,
    )

    expect(await db.products.get(9)).toBeUndefined()
    expect(await db.prices.get([90, 1])).toBeUndefined()
    expect(await db.inventory_snapshot.get([90, 1])).toBeUndefined()
    expect(await db.customers.get(5)).toBeUndefined()
  })

  it('active product with no sellable variants removes stale product and related rows', async () => {
    await db.products.put({
      id: 3,
      name: 'Stale',
      sku: 'S',
      barcode: null,
      categoryId: null,
      isActive: true,
      variants: [{ id: 30, name: 'D', sku: 'S1', barcode: null, isActive: true, price: '2.0000' }],
    })
    await db.prices.put({ variantId: 30, storeId: 1, unitPrice: '2.0000', listPrice: '2.0000' })
    await db.inventory_snapshot.put({ variantId: 30, storeId: 1, available: '1.0000' })

    await applyPullPage(
      1,
      {
        products: [
          {
            id: 3,
            name: 'Stale',
            sku: 'S',
            barcode: null,
            categoryId: null,
            isActive: true,
            variants: [
              { id: 30, name: 'D', sku: 'S1', barcode: null, isActive: false, price: '2.0000' },
            ],
          },
        ],
        prices: [],
        taxes: [],
        customers: [],
        inventory: [],
        config: [],
      },
      db,
    )

    expect(await db.products.get(3)).toBeUndefined()
    expect(await db.prices.get([30, 1])).toBeUndefined()
    expect(await db.inventory_snapshot.get([30, 1])).toBeUndefined()
  })

  it('prefers store price rows over variant list price', async () => {
    await applyPullPage(
      1,
      {
        products: [
          {
            id: 1,
            name: 'P',
            sku: 'P',
            barcode: null,
            categoryId: null,
            isActive: true,
            variants: [
              { id: 10, name: 'D', sku: 'P1', barcode: null, isActive: true, price: '20.0000' },
            ],
          },
        ],
        prices: [
          { variantId: 10, storeId: 1, unitPrice: '15.0000', listPrice: '15.0000' },
        ],
        taxes: [],
        customers: [],
        inventory: [],
        config: [],
      },
      db,
    )

    expect(await db.prices.get([10, 1])).toMatchObject({ unitPrice: '15.0000' })
  })

  it('device disabled 403 sets SYNC ERROR', async () => {
    await ensureDeviceUuid(db)
    const { ApiError } = await import('@/api/client')
    getMock.mockRejectedValue(
      new ApiError(403, {
        message: 'Device is disabled and cannot sync.',
        code: 'device_disabled',
      }),
    )

    const sync = useSyncStore()
    await expect(pullCache(1, { db, sync })).rejects.toThrow(/disabled/)
    expect(sync.status).toBe('SYNC_ERROR')
    expect(sync.lastError).toContain('disabled')
  })

  it('device unregistered 403 sets SYNC ERROR with Admin register hint', async () => {
    await ensureDeviceUuid(db)
    const { ApiError } = await import('@/api/client')
    getMock.mockRejectedValue(
      new ApiError(403, {
        message: 'Device is not registered for this store.',
        code: 'device_unregistered',
      }),
    )

    const sync = useSyncStore()
    await expect(pullCache(1, { db, sync })).rejects.toThrow(/not registered/)
    expect(sync.status).toBe('SYNC_ERROR')
    expect(sync.lastError).toMatch(/register this UUID in Admin/i)
  })

  it('offline same store keeps cache and does not call API', async () => {
    Object.defineProperty(globalThis.navigator, 'onLine', {
      configurable: true,
      get: () => false,
    })
    await db.sync_meta.put({ key: 'cacheStoreId', value: '1' })
    await db.products.put({
      id: 1,
      name: 'X',
      sku: 'X',
      barcode: null,
      categoryId: null,
      isActive: true,
      variants: [{ id: 1, name: 'D', sku: 'X1', barcode: null, isActive: true, price: '1.0000' }],
    })

    await pullCache(1, { db, sync: useSyncStore() })
    expect(getMock).not.toHaveBeenCalled()
    expect(await db.products.count()).toBe(1)
  })

  it('offline store switch clears cache and pull cursors', async () => {
    Object.defineProperty(globalThis.navigator, 'onLine', {
      configurable: true,
      get: () => false,
    })
    await db.sync_meta.put({ key: 'cacheStoreId', value: '1' })
    await db.sync_meta.put({ key: 'pullCursor:1', value: 'old' })
    await db.sync_meta.put({ key: 'lastPullAt:1', value: '2026-01-01T00:00:00.000Z' })
    await db.products.put({
      id: 1,
      name: 'X',
      sku: 'X',
      barcode: null,
      categoryId: null,
      isActive: true,
      variants: [{ id: 1, name: 'D', sku: 'X1', barcode: null, isActive: true, price: '1.0000' }],
    })

    await pullCache(2, { db, sync: useSyncStore() })
    expect(getMock).not.toHaveBeenCalled()
    expect(await db.products.count()).toBe(0)
    expect(await db.sync_meta.get('pullCursor:1')).toBeUndefined()
    expect(await db.sync_meta.get('lastPullAt:1')).toBeUndefined()
  })

  it('online store switch clears prior store data and GETs without cursor', async () => {
    await ensureDeviceUuid(db)
    await db.sync_meta.put({ key: 'cacheStoreId', value: '1' })
    await db.sync_meta.put({ key: 'pullCursor:1', value: 'store-1-cursor' })
    await db.products.put({
      id: 99,
      name: 'OldStore',
      sku: 'OLD',
      barcode: null,
      categoryId: null,
      isActive: true,
      variants: [{ id: 99, name: 'D', sku: 'OLD1', barcode: null, isActive: true, price: '1.0000' }],
    })
    await db.prices.put({ variantId: 99, storeId: 1, unitPrice: '1.0000', listPrice: '1.0000' })

    getMock.mockResolvedValue(
      page({
        products: [
          {
            id: 2,
            name: 'New',
            sku: 'N',
            barcode: null,
            categoryId: null,
            isActive: true,
            variants: [
              { id: 20, name: 'D', sku: 'N1', barcode: null, isActive: true, price: '3.0000' },
            ],
          },
        ],
        cursor: 'store-2-boot',
        hasMore: false,
      }),
    )

    await pullCache(2, { db, sync: useSyncStore() })

    expect(getMock.mock.calls[0]![0]).not.toContain('cursor=')
    expect(await db.products.get(99)).toBeUndefined()
    expect(await db.prices.get([99, 1])).toBeUndefined()
    expect(await db.products.get(2)).toMatchObject({ sku: 'N' })
    expect(await db.sync_meta.get('pullCursor:1')).toBeUndefined()
    expect(await db.sync_meta.get('pullCursor:2')).toMatchObject({ value: 'store-2-boot' })
  })

  it('uses saved cursor for incremental pull on same store', async () => {
    await ensureDeviceUuid(db)
    await db.sync_meta.put({ key: 'cacheStoreId', value: '1' })
    await db.sync_meta.put({ key: 'pullCursor:1', value: 'saved-cursor' })

    getMock.mockResolvedValue(page({ cursor: 'next', hasMore: false }))
    await pullCache(1, { db, sync: useSyncStore() })

    expect(getMock.mock.calls[0]![0]).toContain('cursor=saved-cursor')
  })

  it('persists cursor after each page so mid-loop failure can resume', async () => {
    await ensureDeviceUuid(db)
    getMock
      .mockResolvedValueOnce(
        page({
          products: [
            {
              id: 1,
              name: 'A',
              sku: 'A',
              barcode: null,
              categoryId: null,
              isActive: true,
              variants: [
                { id: 1, name: 'D', sku: 'A1', barcode: null, isActive: true, price: '1.0000' },
              ],
            },
          ],
          cursor: 'after-page-1',
          hasMore: true,
        }),
      )
      .mockRejectedValueOnce(new Error('network drop'))

    const sync = useSyncStore()
    await expect(pullCache(1, { db, sync })).rejects.toThrow(/network drop/)
    expect(await db.sync_meta.get('pullCursor:1')).toMatchObject({ value: 'after-page-1' })
    expect(await db.products.get(1)).toBeTruthy()
  })
})
