import Dexie from 'dexie'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { PosDatabase } from '@/db/posDb'
import { useSyncStore } from '@/stores/sync'
import { warmCache, __resetWarmCacheLockForTests } from '@/sync/cacheWarm'

const listProductsPage = vi.fn()
const listInventoryBalances = vi.fn()
const fetchStore = vi.fn()

vi.mock('@/api/posCatalog', () => ({
  listProductsPage: (...args: unknown[]) => listProductsPage(...args),
  listInventoryBalances: (...args: unknown[]) => listInventoryBalances(...args),
  fetchStore: (...args: unknown[]) => fetchStore(...args),
}))

describe('warmCache', () => {
  let db: PosDatabase

  beforeEach(async () => {
    setActivePinia(createPinia())
    __resetWarmCacheLockForTests()
    await Dexie.delete('retail_soln_pos')
    db = new PosDatabase()
    await db.open()
    listProductsPage.mockReset()
    listInventoryBalances.mockReset()
    fetchStore.mockReset()
    Object.defineProperty(navigator, 'onLine', { configurable: true, get: () => true })
  })

  afterEach(() => {
    db.close()
  })

  it('fills Dexie and restores ONLINE after warm', async () => {
    const sync = useSyncStore()
    listProductsPage.mockResolvedValue({
      data: [
        {
          id: 1,
          name: 'Chips',
          sku: 'CHIP',
          barcode: '999',
          categoryId: 3,
          isActive: true,
          variants: [
            {
              id: 10,
              name: 'Default',
              sku: 'CHIP',
              barcode: '999',
              price: '10.0000',
              isActive: true,
            },
          ],
        },
      ],
      meta: { currentPage: 1, lastPage: 1, total: 1 },
    })
    listInventoryBalances.mockResolvedValue({
      data: [
        {
          id: 1,
          locationType: 'store',
          locationId: 1,
          productVariantId: 10,
          available: '5.0000',
        },
      ],
      meta: { currentPage: 1, lastPage: 1, total: 1 },
    })
    fetchStore.mockResolvedValue({
      data: {
        id: 1,
        name: 'Main',
        currency: 'USD',
        taxConfig: { name: 'VAT', rate: '0.1000', inclusive: false },
        isActive: true,
      },
    })

    await warmCache(1, { db, sync })

    expect(sync.status).toBe('ONLINE')
    expect(await db.products.count()).toBe(1)
    expect(await db.prices.get([10, 1])).toEqual({
      variantId: 10,
      storeId: 1,
      unitPrice: '10.0000',
      listPrice: '10.0000',
    })
    expect(await db.taxes.get(1)).toMatchObject({
      storeId: 1,
      name: 'VAT',
      rate: '0.1000',
      inclusive: false,
    })
    expect(await db.inventory_snapshot.get([10, 1])).toMatchObject({
      available: '5.0000',
    })
    expect(await db.pos_config.get(1)).toMatchObject({ storeName: 'Main' })
    expect((await db.sync_meta.get('cacheStoreId'))?.value).toBe('1')
  })

  it('sets SYNC ERROR on warm failure', async () => {
    const sync = useSyncStore()
    listProductsPage.mockRejectedValue(new Error('Network down'))
    listInventoryBalances.mockResolvedValue({
      data: [],
      meta: { currentPage: 1, lastPage: 1, total: 0 },
    })
    fetchStore.mockResolvedValue({
      data: { id: 1, name: 'Main', currency: 'USD', taxConfig: null, isActive: true },
    })

    await expect(warmCache(1, { db, sync })).rejects.toThrow('Network down')
    expect(sync.status).toBe('SYNC_ERROR')
    expect(sync.lastError).toContain('Network')
  })

  it('replaces prior store-scoped rows on store switch warm', async () => {
    const sync = useSyncStore()

    listProductsPage.mockResolvedValue({
      data: [
        {
          id: 1,
          name: 'Chips',
          sku: 'CHIP',
          barcode: null,
          categoryId: null,
          isActive: true,
          variants: [
            { id: 10, name: 'Default', sku: 'CHIP', barcode: null, price: '10.0000', isActive: true },
          ],
        },
      ],
      meta: { currentPage: 1, lastPage: 1, total: 1 },
    })
    listInventoryBalances.mockResolvedValue({
      data: [{ id: 1, locationType: 'store', locationId: 1, productVariantId: 10, available: '1.0000' }],
      meta: { currentPage: 1, lastPage: 1, total: 1 },
    })
    fetchStore.mockResolvedValue({
      data: {
        id: 1,
        name: 'Main',
        currency: 'USD',
        taxConfig: { name: 'VAT', rate: '0.1000', inclusive: false },
        isActive: true,
      },
    })
    await warmCache(1, { db, sync })

    listInventoryBalances.mockResolvedValue({
      data: [{ id: 2, locationType: 'store', locationId: 2, productVariantId: 10, available: '9.0000' }],
      meta: { currentPage: 1, lastPage: 1, total: 1 },
    })
    fetchStore.mockResolvedValue({
      data: {
        id: 2,
        name: 'Other',
        currency: 'USD',
        taxConfig: { name: 'Sales', rate: '0.0500', inclusive: false },
        isActive: true,
      },
    })
    await warmCache(2, { db, sync })

    expect(await db.prices.where('storeId').equals(1).count()).toBe(0)
    expect(await db.prices.get([10, 2])).toBeTruthy()
    expect(await db.taxes.get(1)).toBeUndefined()
    expect((await db.taxes.get(2))?.rate).toBe('0.0500')
    expect(await db.inventory_snapshot.get([10, 1])).toBeUndefined()
    expect((await db.inventory_snapshot.get([10, 2]))?.available).toBe('9.0000')
  })

  it('skips warm when browser is offline and clears store-scoped tables', async () => {
    const sync = useSyncStore()
    listProductsPage.mockResolvedValue({
      data: [
        {
          id: 1,
          name: 'Chips',
          sku: 'CHIP',
          barcode: null,
          categoryId: null,
          isActive: true,
          variants: [
            { id: 10, name: 'Default', sku: 'CHIP', barcode: null, price: '10.0000', isActive: true },
          ],
        },
      ],
      meta: { currentPage: 1, lastPage: 1, total: 1 },
    })
    listInventoryBalances.mockResolvedValue({
      data: [{ id: 1, locationType: 'store', locationId: 1, productVariantId: 10, available: '1.0000' }],
      meta: { currentPage: 1, lastPage: 1, total: 1 },
    })
    fetchStore.mockResolvedValue({
      data: {
        id: 1,
        name: 'Main',
        currency: 'USD',
        taxConfig: { name: 'VAT', rate: '0.1000', inclusive: false },
        isActive: true,
      },
    })
    await warmCache(1, { db, sync })
    expect(await db.products.count()).toBe(1)

    Object.defineProperty(navigator, 'onLine', { configurable: true, get: () => false })
    sync.setOffline()
    listProductsPage.mockClear()

    await warmCache(2, { db, sync })

    expect(listProductsPage).not.toHaveBeenCalled()
    expect(await db.products.count()).toBe(0)
    expect(await db.prices.count()).toBe(0)
    expect(await db.sync_meta.get('cacheStoreId')).toBeUndefined()
    expect(sync.status).toBe('OFFLINE')
  })

  it('paginates products when lastPage > 1', async () => {
    const sync = useSyncStore()
    listProductsPage
      .mockResolvedValueOnce({
        data: [
          {
            id: 1,
            name: 'A',
            sku: 'A1',
            barcode: null,
            categoryId: null,
            isActive: true,
            variants: [
              { id: 10, name: 'Default', sku: 'A1', barcode: null, price: '1.0000', isActive: true },
            ],
          },
        ],
        meta: { currentPage: 1, lastPage: 2, total: 2 },
      })
      .mockResolvedValueOnce({
        data: [
          {
            id: 2,
            name: 'B',
            sku: 'B1',
            barcode: null,
            categoryId: null,
            isActive: true,
            variants: [
              { id: 20, name: 'Default', sku: 'B1', barcode: null, price: '2.0000', isActive: true },
            ],
          },
        ],
        meta: { currentPage: 2, lastPage: 2, total: 2 },
      })
    listInventoryBalances.mockResolvedValue({
      data: [],
      meta: { currentPage: 1, lastPage: 1, total: 0 },
    })
    fetchStore.mockResolvedValue({
      data: { id: 1, name: 'Main', currency: 'USD', taxConfig: null, isActive: true },
    })

    await warmCache(1, { db, sync })

    expect(listProductsPage).toHaveBeenCalledTimes(2)
    expect(await db.products.count()).toBe(2)
  })

  it('clears SYNC_ERROR then shows SYNCING on retry', async () => {
    const sync = useSyncStore()
    sync.setSyncError('previous failure')

    let release!: () => void
    const gate = new Promise<void>((resolve) => {
      release = resolve
    })

    listProductsPage.mockImplementation(async () => {
      await gate
      return {
        data: [],
        meta: { currentPage: 1, lastPage: 1, total: 0 },
      }
    })
    listInventoryBalances.mockResolvedValue({
      data: [],
      meta: { currentPage: 1, lastPage: 1, total: 0 },
    })
    fetchStore.mockResolvedValue({
      data: { id: 1, name: 'Main', currency: 'USD', taxConfig: null, isActive: true },
    })

    const pending = warmCache(1, { db, sync })
    await Promise.resolve()
    expect(sync.status).toBe('SYNCING')
    release()
    await pending
    expect(sync.status).toBe('ONLINE')
  })

  it('omits variants without a positive price', async () => {
    const sync = useSyncStore()
    listProductsPage.mockResolvedValue({
      data: [
        {
          id: 1,
          name: 'Freebie',
          sku: 'FREE',
          barcode: null,
          categoryId: null,
          isActive: true,
          sellingPrice: null,
          variants: [
            { id: 10, name: 'Default', sku: 'FREE', barcode: null, price: '0', isActive: true },
          ],
        },
      ],
      meta: { currentPage: 1, lastPage: 1, total: 1 },
    })
    listInventoryBalances.mockResolvedValue({
      data: [],
      meta: { currentPage: 1, lastPage: 1, total: 0 },
    })
    fetchStore.mockResolvedValue({
      data: { id: 1, name: 'Main', currency: 'USD', taxConfig: null, isActive: true },
    })

    await warmCache(1, { db, sync })
    expect(await db.products.count()).toBe(0)
  })
})
