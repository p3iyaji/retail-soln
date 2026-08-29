import Dexie from 'dexie'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import type { CompleteSaleInput } from '@/api/posSales'
import { PosDatabase, ensureDeviceUuid } from '@/db/posDb'
import { enqueueSale, listPendingSales, nextLocalSequence } from '@/sync/pendingSales'

function salePayload(uuid: string): CompleteSaleInput {
  return {
    uuid,
    customerId: null,
    sessionId: 1,
    terminalId: 7,
    items: [{ variantId: 10, qty: '1.0000', unitPrice: '10.0000', discount: '0.0000' }],
    payments: [{ method: 'cash', amount: '10.0000', provider: 'manual' }],
  }
}

describe('pendingSales', () => {
  let db: PosDatabase

  beforeEach(async () => {
    await Dexie.delete('retail_soln_pos')
    db = new PosDatabase()
    await db.open()
  })

  afterEach(() => {
    db.close()
  })

  it('enqueues a pending sale envelope matching offline-sync fields', async () => {
    const deviceUuid = await ensureDeviceUuid(db)
    const uuid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'
    const payload = salePayload(uuid)

    const envelope = await enqueueSale(
      {
        storeId: 3,
        userId: 9,
        terminalId: 7,
        payload,
      },
      db,
    )

    expect(envelope).toMatchObject({
      uuid,
      deviceUuid,
      storeId: 3,
      terminalId: 7,
      userId: 9,
      localSequence: 1,
      payloadType: 'sale',
      syncStatus: 'pending',
    })
    expect(envelope.payload).toEqual(payload)
    expect(envelope.createdAt).toMatch(/^\d{4}-\d{2}-\d{2}T/)

    const listed = await listPendingSales(db)
    expect(listed).toHaveLength(1)
    expect(listed[0]?.uuid).toBe(uuid)
  })

  it('increases localSequence strictly for consecutive offline sales', async () => {
    const first = await enqueueSale(
      {
        storeId: 1,
        userId: 1,
        terminalId: null,
        payload: salePayload('11111111-1111-4111-8111-111111111111'),
      },
      db,
    )
    const second = await enqueueSale(
      {
        storeId: 1,
        userId: 1,
        terminalId: null,
        payload: salePayload('22222222-2222-4222-8222-222222222222'),
      },
      db,
    )

    expect(second.localSequence).toBe(first.localSequence + 1)
    expect(await nextLocalSequence(db)).toBe(second.localSequence + 1)

    const listed = await listPendingSales(db)
    expect(listed.map((r) => r.localSequence)).toEqual([1, 2])
  })

  it('rejects duplicate local uuid (PK)', async () => {
    const uuid = '33333333-3333-4333-8333-333333333333'
    await enqueueSale(
      {
        storeId: 1,
        userId: 1,
        terminalId: null,
        payload: salePayload(uuid),
      },
      db,
    )

    await expect(
      enqueueSale(
        {
          storeId: 1,
          userId: 1,
          terminalId: null,
          payload: salePayload(uuid),
        },
        db,
      ),
    ).rejects.toThrow(/already queued/i)

    expect(await listPendingSales(db)).toHaveLength(1)
  })

  it('listPendingSales omits non-pending rows', async () => {
    const pending = await enqueueSale(
      {
        storeId: 1,
        userId: 1,
        terminalId: null,
        payload: salePayload('44444444-4444-4444-8444-444444444444'),
      },
      db,
    )
    await db.pending_sales.put({ ...pending, syncStatus: 'synced' })
    await enqueueSale(
      {
        storeId: 1,
        userId: 1,
        terminalId: null,
        payload: salePayload('55555555-5555-4555-8555-555555555555'),
      },
      db,
    )

    const listed = await listPendingSales(db)
    expect(listed.map((r) => r.uuid)).toEqual(['55555555-5555-4555-8555-555555555555'])
  })

  it('recovers localSequence when sync_meta is corrupt relative to table', async () => {
    await enqueueSale(
      {
        storeId: 1,
        userId: 1,
        terminalId: null,
        payload: salePayload('66666666-6666-4666-8666-666666666666'),
      },
      db,
    )
    await db.sync_meta.put({ key: 'localSequence', value: 'not-a-number' })

    const next = await enqueueSale(
      {
        storeId: 1,
        userId: 1,
        terminalId: null,
        payload: salePayload('77777777-7777-4777-8777-777777777777'),
      },
      db,
    )

    expect(next.localSequence).toBe(2)
  })
})
