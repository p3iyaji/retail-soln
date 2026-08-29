import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useSyncStore } from './sync'

describe('sync store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    Object.defineProperty(navigator, 'onLine', {
      configurable: true,
      get: () => true,
    })
  })

  it('defaults to ONLINE when navigator is online', () => {
    const sync = useSyncStore()
    expect(sync.status).toBe('ONLINE')
    expect(sync.label).toBe('ONLINE')
  })

  it('maps offline then online connectivity to badge states', () => {
    const sync = useSyncStore()
    sync.applyConnectivity(false)
    expect(sync.status).toBe('OFFLINE')
    expect(sync.label).toBe('OFFLINE')

    sync.applyConnectivity(true)
    expect(sync.status).toBe('ONLINE')
    expect(sync.label).toBe('ONLINE')
  })

  it('defaults to OFFLINE when navigator starts offline', () => {
    Object.defineProperty(navigator, 'onLine', {
      configurable: true,
      get: () => false,
    })
    setActivePinia(createPinia())
    const sync = useSyncStore()
    expect(sync.status).toBe('OFFLINE')
  })

  it('does not let setOnline/setSyncing clear SYNC_ERROR', () => {
    const sync = useSyncStore()
    sync.setSyncError('db failed')
    sync.setOnline()
    expect(sync.status).toBe('SYNC_ERROR')
    sync.setSyncing()
    expect(sync.status).toBe('SYNC_ERROR')
    expect(sync.lastError).toBe('db failed')
  })

  it('clearError uses navigator.onLine when online arg omitted', () => {
    const sync = useSyncStore()
    sync.setSyncError('x')
    Object.defineProperty(navigator, 'onLine', {
      configurable: true,
      get: () => false,
    })
    sync.clearError()
    expect(sync.status).toBe('OFFLINE')
  })

  it('exposes SYNCING and SYNC ERROR via setters for later stories', () => {
    const sync = useSyncStore()
    sync.setSyncing()
    expect(sync.status).toBe('SYNCING')
    expect(sync.label).toBe('SYNCING')

    sync.setSyncError('IndexedDB open failed')
    expect(sync.status).toBe('SYNC_ERROR')
    expect(sync.label).toBe('SYNC ERROR')
    expect(sync.lastError).toBe('IndexedDB open failed')
  })

  it('does not overwrite SYNCING or SYNC_ERROR with connectivity events', () => {
    const sync = useSyncStore()
    sync.setSyncing()
    sync.applyConnectivity(false)
    expect(sync.status).toBe('SYNCING')

    sync.setSyncError('push failed')
    sync.applyConnectivity(true)
    expect(sync.status).toBe('SYNC_ERROR')
    expect(sync.lastError).toBe('push failed')
  })

  it('keeps SYNC ERROR until cleared', () => {
    const sync = useSyncStore()
    sync.setSyncError('db failed')
    sync.applyConnectivity(true)
    expect(sync.status).toBe('SYNC_ERROR')

    sync.clearError(true)
    expect(sync.status).toBe('ONLINE')
    expect(sync.lastError).toBeNull()
  })

  it('caps oversell notices to the last five', () => {
    const sync = useSyncStore()
    for (let i = 1; i <= 7; i++) {
      sync.addOversellNotices([
        { uuid: `550e8400-e29b-41d4-a716-44665544000${i}`, exceptionId: i },
      ])
    }
    expect(sync.oversellNotices).toHaveLength(5)
    expect(sync.oversellNotices[0]?.exceptionId).toBe(3)
    expect(sync.oversellNotices[4]?.exceptionId).toBe(7)
  })
})
