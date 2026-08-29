import Dexie from 'dexie'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { PosDatabase } from '@/db/posDb'
import { enqueueSale } from '@/sync/pendingSales'
import { useSyncStore } from '@/stores/sync'
import type { CompleteSaleInput } from '@/api/posSales'

let sharedDb: PosDatabase

vi.mock('@/db/posDb', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/db/posDb')>()
  return {
    ...actual,
    get posDb() {
      return sharedDb
    },
  }
})

const postMock = vi.fn()
const pullCacheMock = vi.fn()

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
  formatDeviceSyncError: (err: { code?: string; message: string }) => err.message,
  getStoredStoreId: () => 1,
  api: {
    post: (...args: unknown[]) => postMock(...args),
  },
}))

vi.mock('@/sync/pullCache', () => ({
  pullCache: (...args: unknown[]) => pullCacheMock(...args),
}))

function salePayload(uuid: string): CompleteSaleInput {
  return {
    uuid,
    customerId: null,
    sessionId: 1,
    terminalId: 7,
    items: [{ variantId: 10, qty: '1.0000', unitPrice: '10.0000', discount: '0.0000' }],
    payments: [{ method: 'cash', amount: '10.7500', provider: 'manual' }],
  }
}

describe('offline reconnect drain', () => {
  beforeEach(async () => {
    setActivePinia(createPinia())
    postMock.mockReset()
    pullCacheMock.mockReset()
    pullCacheMock.mockResolvedValue(undefined)
    await Dexie.delete('retail_soln_pos')
    sharedDb = new PosDatabase()
    await sharedDb.open()
  })

  afterEach(async () => {
    sharedDb.close()
  })

  it('drains queued sale after transport failure on reconnect', async () => {
    const { drainThenPull } = await import('@/composables/useConnectivity')
    const { pushPendingSales } = await import('@/sync/pushPendingSales')
    const uuid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'
    const sync = useSyncStore()

    await enqueueSale(
      { storeId: 1, userId: 2, terminalId: 7, payload: salePayload(uuid) },
      sharedDb,
    )

    const { ApiError } = await import('@/api/client')
    postMock.mockRejectedValueOnce(new ApiError(503, { message: 'Network down' }))

    await pushPendingSales(sharedDb)

    expect((await sharedDb.pending_sales.get(uuid))?.syncStatus).toBe('pushing')
    expect(sync.status).toBe('SYNC_ERROR')

    postMock.mockResolvedValueOnce({
      data: { batchId: 2, items: [{ uuid, status: 'synced', saleId: 55 }] },
    })

    sync.applyConnectivity(true)
    await drainThenPull()

    expect((await sharedDb.pending_sales.get(uuid))?.syncStatus).toBe('synced')
    expect(sync.status).toBe('ONLINE')
    expect(sync.lastError).toBeNull()
    expect(pullCacheMock).toHaveBeenCalledWith(1, { priorError: null })
  })
})
