import Dexie from 'dexie'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { PosDatabase, ensureDeviceUuid } from '@/db/posDb'
import {
  enqueueSale,
  listPushableSales,
  markSaleError,
  markSalePushing,
} from '@/sync/pendingSales'
import { pushPendingSales } from '@/sync/pushPendingSales'
import { useSyncStore } from '@/stores/sync'
import type { CompleteSaleInput } from '@/api/posSales'

const postMock = vi.fn()

vi.mock('@/api/client', () => ({
  ApiError: class ApiError extends Error {
    status: number
    body: unknown
    constructor(status: number, body: { message?: string }) {
      super(body.message ?? `Request failed (${status})`)
      this.status = status
      this.body = body
    }
  },
  api: {
    post: (...args: unknown[]) => postMock(...args),
  },
}))

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

describe('pushPendingSales', () => {
  let db: PosDatabase

  beforeEach(async () => {
    setActivePinia(createPinia())
    postMock.mockReset()
    await Dexie.delete('retail_soln_pos')
    db = new PosDatabase()
    await db.open()
  })

  afterEach(() => {
    db.close()
  })

  it('pushes pending sales, marks synced, and sets ONLINE', async () => {
    const uuid = '11111111-2222-4333-8444-555555555555'
    await enqueueSale(
      { storeId: 1, userId: 2, terminalId: 7, payload: salePayload(uuid) },
      db,
    )
    await ensureDeviceUuid(db)

    postMock.mockResolvedValue({
      data: {
        batchId: 9,
        items: [{ uuid, status: 'synced', saleId: 42 }],
      },
    })

    await pushPendingSales(db)

    const row = await db.pending_sales.get(uuid)
    expect(row?.syncStatus).toBe('synced')
    expect(row?.serverResult).toMatchObject({ saleId: 42, status: 'synced' })
    expect(useSyncStore().status).toBe('ONLINE')
    expect(postMock).toHaveBeenCalledWith(
      '/sync/push',
      expect.objectContaining({
        deviceUuid: expect.any(String),
        records: [
          expect.objectContaining({
            uuid,
            payloadType: 'sale',
            localSequence: 1,
          }),
        ],
      }),
    )
  })

  it('marks item error and sets SYNC ERROR on per-item failure', async () => {
    const uuid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'
    await enqueueSale(
      { storeId: 1, userId: 2, terminalId: null, payload: salePayload(uuid) },
      db,
    )

    postMock.mockResolvedValue({
      data: {
        batchId: 1,
        items: [{ uuid, status: 'error', errors: ['No open cashier session'] }],
      },
    })

    await pushPendingSales(db)

    const row = await db.pending_sales.get(uuid)
    expect(row?.syncStatus).toBe('error')
    expect(useSyncStore().status).toBe('SYNC_ERROR')
    expect(useSyncStore().lastError).toContain('No open cashier session')
  })

  it('sets SYNC ERROR when device is disabled (batch rejected)', async () => {
    const { ApiError } = await import('@/api/client')
    const uuid = 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff'
    await enqueueSale(
      { storeId: 1, userId: 2, terminalId: null, payload: salePayload(uuid) },
      db,
    )

    postMock.mockRejectedValue(new ApiError(403, { message: 'Device is disabled and cannot sync.' }))

    await pushPendingSales(db)

    expect((await db.pending_sales.get(uuid))?.syncStatus).toBe('error')
    expect(useSyncStore().status).toBe('SYNC_ERROR')
  })

  it('leaves rows pushing on transport failure so reconnect can retry', async () => {
    const { ApiError } = await import('@/api/client')
    const uuid = 'cccccccc-dddd-4eee-8fff-000000000001'
    await enqueueSale(
      { storeId: 1, userId: 2, terminalId: null, payload: salePayload(uuid) },
      db,
    )

    postMock.mockRejectedValue(new ApiError(503, { message: 'Network down' }))

    await pushPendingSales(db)

    expect((await db.pending_sales.get(uuid))?.syncStatus).toBe('pushing')
    expect(useSyncStore().status).toBe('SYNC_ERROR')
  })

  it('listPushableSales includes error and pushing rows', async () => {
    const pendingUuid = 'dddddddd-eeee-4fff-8000-111111111111'
    const pushingUuid = 'eeeeeeee-ffff-4000-8111-222222222222'
    const errorUuid = 'ffffffff-0000-4111-8222-333333333333'

    await enqueueSale(
      { storeId: 1, userId: 2, terminalId: null, payload: salePayload(pendingUuid) },
      db,
    )
    await enqueueSale(
      { storeId: 1, userId: 2, terminalId: null, payload: salePayload(pushingUuid) },
      db,
    )
    await enqueueSale(
      { storeId: 1, userId: 2, terminalId: null, payload: salePayload(errorUuid) },
      db,
    )
    await markSalePushing(pushingUuid, db)
    await markSaleError(errorUuid, { errors: ['retry me'] }, db)

    const pushable = await listPushableSales(db)
    expect(pushable.map((r) => r.uuid).sort()).toEqual(
      [pendingUuid, pushingUuid, errorUuid].sort(),
    )
  })

  it('clears SYNC_ERROR to ONLINE after a successful drain', async () => {
    const uuid = '10101010-2020-4303-8404-505050505050'
    await enqueueSale(
      { storeId: 1, userId: 2, terminalId: null, payload: salePayload(uuid) },
      db,
    )
    useSyncStore().setSyncError('previous failure')

    postMock.mockResolvedValue({
      data: { batchId: 2, items: [{ uuid, status: 'synced', saleId: 7 }] },
    })

    await pushPendingSales(db)

    expect(useSyncStore().status).toBe('ONLINE')
    expect(useSyncStore().lastError).toBeNull()
    expect((await db.pending_sales.get(uuid))?.syncStatus).toBe('synced')
  })

  it('updates Dexie correctly for mixed synced and error items', async () => {
    const good = '12121212-3434-4545-8656-787878787878'
    const bad = '34343434-5656-4767-8878-909090909090'
    await enqueueSale(
      { storeId: 1, userId: 2, terminalId: null, payload: salePayload(good) },
      db,
    )
    await enqueueSale(
      { storeId: 1, userId: 2, terminalId: null, payload: salePayload(bad) },
      db,
    )

    postMock.mockResolvedValue({
      data: {
        batchId: 3,
        items: [
          { uuid: good, status: 'synced', saleId: 11 },
          { uuid: bad, status: 'error', errors: ['No open cashier session'] },
        ],
      },
    })

    await pushPendingSales(db)

    expect((await db.pending_sales.get(good))?.syncStatus).toBe('synced')
    expect((await db.pending_sales.get(bad))?.syncStatus).toBe('error')
    expect(useSyncStore().status).toBe('SYNC_ERROR')
  })

  it('continues remaining rows after a partial batch error', async () => {
    const first = 'aaaaaaaa-1111-4222-8333-444444444444'
    const second = 'bbbbbbbb-2222-4333-8444-555555555555'
    await enqueueSale(
      { storeId: 1, userId: 2, terminalId: null, payload: salePayload(first) },
      db,
    )
    await enqueueSale(
      { storeId: 1, userId: 2, terminalId: null, payload: salePayload(second) },
      db,
    )

    postMock
      .mockResolvedValueOnce({
        data: {
          batchId: 1,
          items: [{ uuid: first, status: 'error', errors: ['No open session'] }],
        },
      })
      .mockResolvedValueOnce({
        data: {
          batchId: 2,
          items: [{ uuid: second, status: 'synced', saleId: 99 }],
        },
      })

    await pushPendingSales(db, { batchMax: 1 })

    expect(postMock).toHaveBeenCalledTimes(2)
    expect((await db.pending_sales.get(first))?.syncStatus).toBe('error')
    expect((await db.pending_sales.get(second))?.syncStatus).toBe('synced')
    expect(useSyncStore().status).toBe('SYNC_ERROR')
  })

  it('records oversell notices when push returns exceptionId', async () => {
    const uuid = 'cccccccc-dddd-4eee-8fff-000000000001'
    await enqueueSale(
      { storeId: 1, userId: 2, terminalId: 7, payload: salePayload(uuid) },
      db,
    )

    postMock.mockResolvedValue({
      data: {
        batchId: 3,
        items: [{ uuid, status: 'synced', saleId: 99, exceptionId: 17 }],
      },
    })

    await pushPendingSales(db)

    expect(useSyncStore().oversellNotices).toEqual([
      { uuid, exceptionId: 17, saleId: 99 },
    ])
    expect(useSyncStore().status).toBe('ONLINE')
  })

  it('no-ops when the queue is empty', async () => {
    await pushPendingSales(db)
    expect(postMock).not.toHaveBeenCalled()
  })

  it('sets SYNC ERROR when ensureDeviceUuid throws before request', async () => {
    const uuid = '99999999-8888-4777-8666-555555555555'
    await enqueueSale(
      { storeId: 1, userId: 2, terminalId: null, payload: salePayload(uuid) },
      db,
    )

    const ensureSpy = vi.spyOn(await import('@/db/posDb'), 'ensureDeviceUuid')
    ensureSpy.mockRejectedValueOnce(new Error('IDB unavailable'))

    await pushPendingSales(db)

    expect(postMock).not.toHaveBeenCalled()
    expect(useSyncStore().status).toBe('SYNC_ERROR')
    expect(useSyncStore().lastError).toContain('IDB unavailable')
    ensureSpy.mockRestore()
  })
})
