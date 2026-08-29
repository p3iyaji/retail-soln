import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { startConnectivity } from './useConnectivity'
import { useSyncStore } from '@/stores/sync'

describe('startConnectivity', () => {
  let stop: (() => void) | undefined

  beforeEach(() => {
    setActivePinia(createPinia())
    Object.defineProperty(navigator, 'onLine', {
      configurable: true,
      get: () => true,
    })
  })

  afterEach(() => {
    stop?.()
    stop = undefined
  })

  it('updates badge on window offline and online events', () => {
    const sync = useSyncStore()
    stop = startConnectivity()
    expect(sync.status).toBe('ONLINE')

    Object.defineProperty(navigator, 'onLine', {
      configurable: true,
      get: () => false,
    })
    window.dispatchEvent(new Event('offline'))
    expect(sync.status).toBe('OFFLINE')

    Object.defineProperty(navigator, 'onLine', {
      configurable: true,
      get: () => true,
    })
    window.dispatchEvent(new Event('online'))
    expect(sync.status).toBe('ONLINE')
  })

  it('leaves SYNC ERROR alone when connectivity flips', () => {
    const sync = useSyncStore()
    sync.setSyncError('IndexedDB open failed')
    stop = startConnectivity()

    window.dispatchEvent(new Event('offline'))
    expect(sync.status).toBe('SYNC_ERROR')
    window.dispatchEvent(new Event('online'))
    expect(sync.status).toBe('SYNC_ERROR')
  })
})
