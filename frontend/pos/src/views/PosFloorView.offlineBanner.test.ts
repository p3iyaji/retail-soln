import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { defineComponent, nextTick } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useSyncStore } from '@/stores/sync'

vi.mock('@/composables/usePosShortcuts', () => ({
  usePosShortcuts: () => undefined,
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    displayName: 'Cashier',
    logout: vi.fn(),
  }),
}))

vi.mock('@/stores/pos', () => ({
  usePosStore: () => ({
    selectedStore: { id: 1, name: 'Main' },
    terminalLabel: 'T1',
    hasOpenSession: true,
    hasSelectedStore: true,
    canCollectInstallments: false,
    canProcessRefund: false,
    allowPriceOverride: false,
    searchQuery: '',
    searchResults: [],
    searchError: null,
    cartError: null,
    cartLines: [],
    cartTotals: { subtotal: '0', tax: '0', total: '0' },
    heldSales: [],
    selectedCustomer: null,
    stub: null,
    setSearchQuery: vi.fn(),
    setTerminalLabel: vi.fn(),
    clearStore: vi.fn(),
    refreshCatalogCache: vi.fn(),
    loadCategories: vi.fn(),
    runSearch: vi.fn(),
    loadHeldSales: vi.fn(),
    refreshSession: vi.fn(),
    openStub: vi.fn(),
    openPaymentOrSession: vi.fn(),
    holdCurrentCart: vi.fn(),
    voidCurrentTransaction: vi.fn(),
    addFirstHit: vi.fn(),
    setPriceOverride: vi.fn(),
    clearPriceOverride: vi.fn(),
  }),
}))

describe('PosFloor offline banner + oversell notice', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    Object.defineProperty(navigator, 'onLine', {
      configurable: true,
      get: () => true,
    })
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  async function mountFloor() {
    const PosFloorView = (await import('@/views/PosFloorView.vue')).default
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/', component: PosFloorView }],
    })
    await router.push('/')
    await router.isReady()
    return mount(PosFloorView, {
      global: {
        plugins: [router],
        stubs: {
          StubPanel: defineComponent({ template: '<div />' }),
          SyncBadge: defineComponent({ template: '<span>SYNC</span>' }),
        },
      },
    })
  }

  it('shows offline banner once per ONLINE→OFFLINE transition and again after reconnect', async () => {
    const wrapper = await mountFloor()
    expect(wrapper.text()).not.toContain('Offline — sales will sync when connected')

    window.dispatchEvent(new Event('offline'))
    await nextTick()
    expect(wrapper.text()).toContain('Offline — sales will sync when connected')

    const dismiss = wrapper.findAll('button').find((b) => b.text() === 'Dismiss')
    expect(dismiss).toBeTruthy()
    await dismiss!.trigger('click')
    await nextTick()
    expect(wrapper.text()).not.toContain('Offline — sales will sync when connected')

    window.dispatchEvent(new Event('online'))
    await nextTick()
    window.dispatchEvent(new Event('offline'))
    await nextTick()
    expect(wrapper.text()).toContain('Offline — sales will sync when connected')
  })

  it('clears offline banner on online without needing dismiss', async () => {
    const wrapper = await mountFloor()
    window.dispatchEvent(new Event('offline'))
    await nextTick()
    expect(wrapper.text()).toContain('Offline — sales will sync when connected')

    window.dispatchEvent(new Event('online'))
    await nextTick()
    expect(wrapper.text()).not.toContain('Offline — sales will sync when connected')
  })

  it('shows offline banner when already offline on mount', async () => {
    Object.defineProperty(navigator, 'onLine', {
      configurable: true,
      get: () => false,
    })
    const wrapper = await mountFloor()
    await flushPromises()
    expect(wrapper.text()).toContain('Offline — sales will sync when connected')
  })

  it('shows non-blocking oversell notice with sale ref and full uuid in title', async () => {
    const sync = useSyncStore()
    const uuid = '550e8400-e29b-41d4-a716-446655440099'
    const wrapper = await mountFloor()
    sync.addOversellNotices([{ uuid, exceptionId: 42, saleId: 7 }])
    await flushPromises()
    await nextTick()

    expect(wrapper.text()).toContain('Oversell exception #42')
    expect(wrapper.text()).toContain('550e8400')
    expect(wrapper.text()).toContain('sale #7')
    const notice = wrapper.find(`[title="Sale ${uuid}"]`)
    expect(notice.exists()).toBe(true)
    expect(notice.attributes('aria-label')).toContain(uuid)
  })
})
