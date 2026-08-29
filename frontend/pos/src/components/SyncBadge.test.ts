import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { mount } from '@vue/test-utils'
import SyncBadge from './SyncBadge.vue'
import { useSyncStore } from '@/stores/sync'

describe('SyncBadge', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    Object.defineProperty(navigator, 'onLine', {
      configurable: true,
      get: () => true,
    })
  })

  it('renders ONLINE then OFFLINE text and tone classes', async () => {
    const sync = useSyncStore()
    const wrapper = mount(SyncBadge)
    expect(wrapper.text()).toContain('ONLINE')
    expect(wrapper.classes()).toContain('sync-badge--online')
    expect(wrapper.attributes('aria-live')).toBe('polite')

    sync.applyConnectivity(false)
    await wrapper.vm.$nextTick()
    expect(wrapper.text()).toContain('OFFLINE')
    expect(wrapper.classes()).toContain('sync-badge--offline')
  })

  it('announces SYNC ERROR assertively with lastError in aria-label', async () => {
    const sync = useSyncStore()
    const wrapper = mount(SyncBadge)
    sync.setSyncError('IndexedDB open failed')
    await wrapper.vm.$nextTick()
    expect(wrapper.text()).toContain('SYNC ERROR')
    expect(wrapper.classes()).toContain('sync-badge--error')
    expect(wrapper.attributes('aria-live')).toBe('assertive')
    expect(wrapper.attributes('aria-label')).toContain('IndexedDB open failed')
  })
})
