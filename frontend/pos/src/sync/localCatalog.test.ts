import Dexie from 'dexie'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { PosDatabase } from '@/db/posDb'
import { searchLocalCatalog } from '@/sync/localCatalog'

describe('searchLocalCatalog', () => {
  let db: PosDatabase

  beforeEach(async () => {
    await Dexie.delete('retail_soln_pos')
    db = new PosDatabase()
    await db.open()
    await db.products.bulkPut([
      {
        id: 1,
        name: 'Potato Chips',
        sku: 'CHIP-001',
        barcode: '111',
        categoryId: 5,
        isActive: true,
        variants: [
          {
            id: 10,
            name: 'Default',
            sku: 'CHIP-001',
            barcode: '111',
            isActive: true,
            price: '10.0000',
          },
        ],
      },
      {
        id: 2,
        name: 'Cola',
        sku: 'COLA-001',
        barcode: '222',
        categoryId: 6,
        isActive: true,
        variants: [
          {
            id: 20,
            name: 'Default',
            sku: 'COLA-001',
            barcode: '222',
            isActive: true,
            price: '5.0000',
          },
        ],
      },
    ])
  })

  afterEach(() => {
    db.close()
  })

  it('finds by name substring', async () => {
    const res = await searchLocalCatalog({ q: 'chip' }, db)
    expect(res.data).toHaveLength(1)
    expect(res.data[0]!.sku).toBe('CHIP-001')
    expect(res.data[0]!.variants?.[0]?.id).toBe(10)
  })

  it('finds by sku / barcode', async () => {
    expect((await searchLocalCatalog({ q: 'COLA-001' }, db)).data[0]!.id).toBe(2)
    expect((await searchLocalCatalog({ q: '111' }, db)).data[0]!.id).toBe(1)
  })

  it('filters by categoryId', async () => {
    const res = await searchLocalCatalog({ q: '', categoryId: 6 }, db)
    expect(res.data).toHaveLength(1)
    expect(res.data[0]!.id).toBe(2)
  })

  it('returns empty when cache is cold', async () => {
    await db.products.clear()
    const res = await searchLocalCatalog({ q: 'chip' }, db)
    expect(res.data).toHaveLength(0)
  })

  it('treats non-finite limit as default 25', async () => {
    const res = await searchLocalCatalog({ q: '', limit: Number.NaN }, db)
    expect(res.data.length).toBeLessThanOrEqual(25)
    expect(res.data.length).toBe(2)
  })
})
