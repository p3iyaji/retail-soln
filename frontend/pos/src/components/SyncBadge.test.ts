import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import SyncBadge from './SyncBadge.vue'
import { useSyncStore } from '@/stores/sync'

const drainThenPull = vi.fn().mockResolvedValue(undefined)
const countPendingAndError = vi.fn().mockResolvedValue({ pending: 2, error: 1 })
const getStoredStoreId = vi.fn().mockReturnValue(1)
const ensureDeviceUuid = vi.fn().mockResolvedValue('550e8400-e29b-41d4-a716-446655440099')
const apiGet = vi.fn().mockResolvedValue({
  data: { pendingOversellCount: 0, deviceEnabled: true },
})

vi.mock('@/composables/useConnectivity', () => ({
  drainThenPull: (...args: unknown[]) => drainThenPull(...args),
}))

vi.mock('@/sync/pendingSales', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/sync/pendingSales')>()
  return {
    ...actual,
    countPendingAndError: (...args: unknown[]) => countPendingAndError(...args),
  }
})

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return {
    ...actual,
    getStoredStoreId: (...args: unknown[]) => getStoredStoreId(...args),
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGet(...args),
    },
  }
})

vi.mock('@/db/posDb', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/db/posDb')>()
  return {
    ...actual,
    ensureDeviceUuid: (...args: unknown[]) => ensureDeviceUuid(...args),
  }
})

describe('SyncBadge', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    drainThenPull.mockClear()
    countPendingAndError.mockClear()
    countPendingAndError.mockResolvedValue({ pending: 2, error: 1 })
    getStoredStoreId.mockReturnValue(1)
    apiGet.mockClear()
    apiGet.mockResolvedValue({ data: { pendingOversellCount: 0, deviceEnabled: true } })
    Object.defineProperty(navigator, 'onLine', {
      configurable: true,
      get: () => true,
    })
    localStorage.clear()
  })

  it('renders ONLINE then OFFLINE text and tone classes', async () => {
    const sync = useSyncStore()
    const wrapper = mount(SyncBadge)
    expect(wrapper.text()).toContain('ONLINE')
    expect(wrapper.find('button.sync-badge').classes()).toContain('sync-badge--online')

    const liveRegion = wrapper.find('[role="status"][aria-live]')
    expect(liveRegion.exists()).toBe(true)
    expect(liveRegion.attributes('aria-live')).toBe('polite')

    sync.applyConnectivity(false)
    await wrapper.vm.$nextTick()
    expect(wrapper.text()).toContain('OFFLINE')
    expect(wrapper.find('button.sync-badge').classes()).toContain('sync-badge--offline')
  })

  it('announces SYNC ERROR assertively via separate status region', async () => {
    const sync = useSyncStore()
    const wrapper = mount(SyncBadge)
    sync.setSyncError('IndexedDB open failed')
    await wrapper.vm.$nextTick()
    expect(wrapper.text()).toContain('SYNC ERROR')
    expect(wrapper.find('button.sync-badge').classes()).toContain('sync-badge--error')
    const liveRegion = wrapper.find('[role="status"][aria-live]')
    expect(liveRegion.attributes('aria-live')).toBe('assertive')
    expect(liveRegion.text()).toContain('IndexedDB open failed')
    expect(wrapper.find('button.sync-badge').attributes('aria-label')).toContain(
      'IndexedDB open failed',
    )
  })

  it('opens panel with Dexie counts and last error on click', async () => {
    const sync = useSyncStore()
    sync.setSyncError('push failed')
    const wrapper = mount(SyncBadge)

    await wrapper.find('button.sync-badge').trigger('click')
    await flushPromises()

    expect(countPendingAndError).toHaveBeenCalled()
    expect(wrapper.find('#sync-status-panel').exists()).toBe(true)
    expect(wrapper.text()).toContain('push failed')
    expect(wrapper.text()).toContain('Pending')
    expect(wrapper.text()).toContain('2')
    expect(wrapper.text()).toContain('Errors')
    expect(wrapper.text()).toContain('1')
  })

  it('Retry clears error then calls drainThenPull', async () => {
    const sync = useSyncStore()
    sync.setSyncError('push failed')
    drainThenPull.mockImplementation(async () => {
      expect(sync.lastError).toBeNull()
      expect(sync.status).not.toBe('SYNC_ERROR')
      sync.setOnline()
    })

    const wrapper = mount(SyncBadge)
    await wrapper.find('button.sync-badge').trigger('click')
    await flushPromises()

    await wrapper.find('#sync-status-panel button').trigger('click')
    await flushPromises()

    expect(drainThenPull).toHaveBeenCalled()
    expect(sync.status).toBe('ONLINE')
    expect(wrapper.find('#sync-status-panel').exists()).toBe(false)
  })

  it('keeps SYNC ERROR visible when Retry drain fails', async () => {
    const sync = useSyncStore()
    sync.setSyncError('first failure')
    drainThenPull.mockImplementation(async () => {
      sync.setSyncError('still failing')
    })

    const wrapper = mount(SyncBadge)
    await wrapper.find('button.sync-badge').trigger('click')
    await flushPromises()
    await wrapper.find('#sync-status-panel button').trigger('click')
    await flushPromises()

    expect(sync.status).toBe('SYNC_ERROR')
    expect(sync.lastError).toBe('still failing')
    expect(wrapper.find('#sync-status-panel').exists()).toBe(true)
  })

  it('Retry without store keeps SYNC ERROR and does not drain', async () => {
    getStoredStoreId.mockReturnValue(null)
    const sync = useSyncStore()
    sync.setSyncError('push failed')

    const wrapper = mount(SyncBadge)
    await wrapper.find('button.sync-badge').trigger('click')
    await flushPromises()
    await wrapper.find('#sync-status-panel button').trigger('click')
    await flushPromises()

    expect(drainThenPull).not.toHaveBeenCalled()
    expect(sync.status).toBe('SYNC_ERROR')
    expect(sync.lastError).toBe('push failed')
    expect(wrapper.text()).toContain('Select a store before retrying sync')
    expect(wrapper.find('#sync-status-panel').exists()).toBe(true)
  })
})
