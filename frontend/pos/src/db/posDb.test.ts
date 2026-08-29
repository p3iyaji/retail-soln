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

  it('opens with offline-sync store names', () => {
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
