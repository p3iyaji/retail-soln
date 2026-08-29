import Dexie from 'dexie'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { PosDatabase, ensureDeviceUuid } from './posDb'

describe('posDb', () => {
  let db: PosDatabase

  beforeEach(async () => {
    await Dexie.delete('retail_soln_pos')
    db = new PosDatabase()
    await db.open()
  })

  afterEach(() => {
    db.close()
  })

  it('opens with offline-sync store names at schema v7', () => {
    expect(db.verno).toBe(7)
    expect(db.tables.map((t) => t.name).sort()).toEqual(
      [
        'customers',
        'inventory_snapshot',
        'pending_sales',
        'pos_config',
        'prices',
        'products',
        'sync_meta',
        'taxes',
      ].sort(),
    )
  })

  it('accepts typed product / price / tax cache rows', async () => {
    await db.products.put({
      id: 1,
      name: 'Chips',
      sku: 'CHIP',
      barcode: '999',
      categoryId: 2,
      isActive: true,
      variants: [
        {
          id: 10,
          name: 'Default',
          sku: 'CHIP',
          barcode: '999',
          isActive: true,
          price: '10.0000',
        },
      ],
    })
    await db.prices.put({
      variantId: 10,
      storeId: 1,
      unitPrice: '10.0000',
      listPrice: '10.0000',
    })
    await db.taxes.put({
      storeId: 1,
      name: 'VAT',
      rate: '0.1000',
      inclusive: false,
    })

    expect(await db.products.get(1)).toMatchObject({ sku: 'CHIP' })
    expect(await db.prices.get([10, 1])).toMatchObject({ unitPrice: '10.0000' })
    expect((await db.taxes.get(1))?.name).toBe('VAT')
  })

  it('accepts pending_sales rows keyed by uuid', async () => {
    await db.pending_sales.put({
      uuid: 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
      deviceUuid: 'device-1',
      storeId: 1,
      terminalId: null,
      userId: 2,
      localSequence: 1,
      createdAt: new Date().toISOString(),
      payloadType: 'sale',
      payload: { uuid: 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' },
      syncStatus: 'pending',
    })
    const row = await db.pending_sales.get('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee')
    expect(row?.syncStatus).toBe('pending')
    expect(row?.localSequence).toBe(1)
  })

  it('persists a stable deviceUuid in sync_meta across reopen', async () => {
    const first = await ensureDeviceUuid(db)
    expect(first).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i,
    )

    const row = await db.sync_meta.get('deviceUuid')
    expect(row?.value).toBe(first)

    const second = await ensureDeviceUuid(db)
    expect(second).toBe(first)

    db.close()
    const reopened = new PosDatabase()
    await reopened.open()
    const afterReload = await ensureDeviceUuid(reopened)
    expect(afterReload).toBe(first)
    reopened.close()
  })

  it('upgrades a v3 database through pending_sales PK drop/recreate', async () => {
    db.close()
    await Dexie.delete('retail_soln_pos')

    class PosDbV3 extends Dexie {
      constructor() {
        super('retail_soln_pos')
        this.version(1).stores({
          products: '++id',
          prices: '++id',
          taxes: '++id',
          customers: '++id',
          inventory_snapshot: '++id',
          pos_config: '++id',
          pending_sales: '++id',
          sync_meta: 'key',
        })
        this.version(2).stores({
          products: null,
          prices: null,
          taxes: null,
          inventory_snapshot: null,
          pos_config: null,
        })
        this.version(3).stores({
          products: 'id, sku, barcode, categoryId',
          prices: '[variantId+storeId], storeId, variantId',
          taxes: 'storeId',
          customers: '++id',
          inventory_snapshot: '[variantId+storeId], storeId, variantId',
          pos_config: 'storeId',
          pending_sales: '++id',
          sync_meta: 'key',
        })
      }
    }

    const v3 = new PosDbV3()
    await v3.open()
    expect(v3.verno).toBe(3)
    await v3.table('pending_sales').add({ note: 'placeholder' })
    await v3.table('sync_meta').put({ key: 'deviceUuid', value: 'keep-me' })
    v3.close()

    const upgraded = new PosDatabase()
    await upgraded.open()
    expect(upgraded.verno).toBe(7)
    expect(await upgraded.sync_meta.get('deviceUuid')).toMatchObject({ value: 'keep-me' })
    await upgraded.pending_sales.put({
      uuid: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
      deviceUuid: 'keep-me',
      storeId: 1,
      terminalId: null,
      userId: 1,
      localSequence: 1,
      createdAt: new Date().toISOString(),
      payloadType: 'sale',
      payload: {},
      syncStatus: 'pending',
    })
    expect(await upgraded.pending_sales.get('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')).toBeTruthy()
    upgraded.close()
  })
})
