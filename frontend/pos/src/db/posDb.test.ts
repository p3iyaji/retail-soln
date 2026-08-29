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

  it('opens with offline-sync store names at schema v3', () => {
    expect(db.verno).toBe(3)
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
})
