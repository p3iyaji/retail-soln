import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { drainThenPull, startConnectivity } from './useConnectivity'
import { useSyncStore } from '@/stores/sync'

const pushPendingSales = vi.fn().mockResolvedValue(undefined)
const pullCache = vi.fn().mockResolvedValue(undefined)

vi.mock('@/sync/pushPendingSales', () => ({
  pushPendingSales: (...args: unknown[]) => pushPendingSales(...args),
}))

vi.mock('@/sync/pullCache', () => ({
  pullCache: (...args: unknown[]) => pullCache(...args),
}))

describe('startConnectivity', () => {
  let stop: (() => void) | undefined

  beforeEach(() => {
    setActivePinia(createPinia())
    pushPendingSales.mockClear()
    pullCache.mockClear()
    pushPendingSales.mockResolvedValue(undefined)
    pullCache.mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'onLine', {
      configurable: true,
      get: () => true,
    })
    localStorage.clear()
  })

  afterEach(() => {
    stop?.()
    stop = undefined
  })

  it('exports drainThenPull used by Retry and reconnect', async () => {
    localStorage.setItem('retail_soln_pos_store_id', '9')
    await drainThenPull()
    expect(pushPendingSales).toHaveBeenCalled()
    expect(pullCache).toHaveBeenCalledWith(9, expect.objectContaining({ priorError: null }))
  })

  it('updates badge on window offline and online events; push then pull', async () => {
    const sync = useSyncStore()
    const callOrder: string[] = []
    pushPendingSales.mockImplementation(async () => {
      callOrder.push('push')
    })
    pullCache.mockImplementation(async () => {
      callOrder.push('pull')
    })

    localStorage.setItem('retail_soln_pos_store_id', '1')
    stop = startConnectivity()
    expect(sync.status).toBe('ONLINE')
    await vi.waitFor(() => expect(callOrder).toEqual(['push', 'pull']))
    expect(pullCache).toHaveBeenCalledWith(1, expect.objectContaining({ priorError: null }))

    Object.defineProperty(navigator, 'onLine', {
      configurable: true,
      get: () => false,
    })
    window.dispatchEvent(new Event('offline'))
    expect(sync.status).toBe('OFFLINE')

    callOrder.length = 0
    pushPendingSales.mockClear()
    pullCache.mockClear()
    Object.defineProperty(navigator, 'onLine', {
      configurable: true,
      get: () => true,
    })
    window.dispatchEvent(new Event('online'))
    expect(sync.status).toBe('ONLINE')
    await vi.waitFor(() => expect(callOrder).toEqual(['push', 'pull']))
  })

  it('still pulls with priorError when push leaves SYNC_ERROR', async () => {
    const sync = useSyncStore()
    localStorage.setItem('retail_soln_pos_store_id', '3')

    pushPendingSales.mockImplementation(async () => {
      sync.setSyncError('push failed')
    })
    pullCache.mockImplementation(async (_storeId, options?: { priorError?: string | null }) => {
      expect(options?.priorError).toBe('push failed')
    })

    stop = startConnectivity()
    await vi.waitFor(() => expect(pushPendingSales).toHaveBeenCalled())
    await vi.waitFor(() =>
      expect(pullCache).toHaveBeenCalledWith(
        3,
        expect.objectContaining({ priorError: 'push failed' }),
      ),
    )
  })

  it('leaves SYNC ERROR alone when connectivity flips offline', () => {
    const sync = useSyncStore()
    sync.setSyncError('IndexedDB open failed')
    stop = startConnectivity()

    window.dispatchEvent(new Event('offline'))
    expect(sync.status).toBe('SYNC_ERROR')
  })
})
