import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import PaymentPanel from './PaymentPanel.vue'
import { usePosStore } from '@/stores/pos'

const runSaleCheckout = vi.fn()
const isBrowserOffline = vi.fn(() => true)
const fetchReceipt = vi.fn()
const printReceipt = vi.fn()

const authState = {
  user: {
    id: 9,
    name: 'Cashier',
    email: 'c@test.local',
    permissions: ['sales.create'] as string[],
  },
  storeAccess: [{ id: 1, name: 'Main', isDefault: true }],
}

vi.mock('@/sync/saleCheckout', () => ({
  runSaleCheckout: (...args: unknown[]) => runSaleCheckout(...args),
  isBrowserOffline: () => isBrowserOffline(),
}))

vi.mock('@/api/posSales', async () => {
  const actual = await vi.importActual<typeof import('@/api/posSales')>('@/api/posSales')
  return {
    ...actual,
    fetchReceipt: (...args: unknown[]) => fetchReceipt(...args),
    printReceipt: (...args: unknown[]) => printReceipt(...args),
  }
})

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => authState,
}))

describe('PaymentPanel offline queue wiring', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    runSaleCheckout.mockReset()
    fetchReceipt.mockReset()
    printReceipt.mockReset()
    isBrowserOffline.mockReturnValue(true)

    const pos = usePosStore()
    pos.selectedStoreId = 1
    pos.cashierSession = {
      id: 3,
      terminalId: null,
      status: 'open',
      openedAt: new Date().toISOString(),
      openingFloat: '0.0000',
      expectedCash: '0.0000',
    } as typeof pos.cashierSession
    pos.cartLines.splice(0, pos.cartLines.length, {
      lineKey: 'l1',
      variantId: 10,
      productId: 1,
      name: 'Item',
      sku: 'SKU',
      qty: '1.0000',
      unitPrice: '10.0000',
      listPrice: '10.0000',
      discountAmount: '0.0000',
      taxAmount: '0.0000',
      lineTotal: '10.0000',
      priceOverride: null,
    } as (typeof pos.cartLines)[number])
    pos.activeStub = 'payment'
  })

  it('queues offline cash sale, clears cart, skips receipt/print', async () => {
    runSaleCheckout.mockResolvedValue({
      kind: 'queued',
      envelope: { uuid: 'q-uuid', syncStatus: 'pending' },
    })

    const pos = usePosStore()
    const clearCart = vi.spyOn(pos, 'clearCart')

    const wrapper = mount(PaymentPanel)
    await flushPromises()

    const pay = wrapper.findAll('button').find((b) => /complete payment/i.test(b.text()))
    expect(pay).toBeTruthy()
    expect((pay!.element as HTMLButtonElement).disabled).toBe(false)
    await pay!.trigger('click')
    await flushPromises()

    expect(runSaleCheckout).toHaveBeenCalled()
    expect(runSaleCheckout.mock.calls[0]![0]).toMatchObject({ offline: true })
    expect(clearCart).toHaveBeenCalled()
    expect(fetchReceipt).not.toHaveBeenCalled()
    expect(printReceipt).not.toHaveBeenCalled()
    expect(wrapper.text()).toMatch(/Queued — will sync when online/)
  })

  it('keeps cart when enqueue fails', async () => {
    runSaleCheckout.mockRejectedValue(new Error('IndexedDB write failed'))

    const pos = usePosStore()
    const linesBefore = pos.cartLines.length

    const wrapper = mount(PaymentPanel)
    await flushPromises()

    const pay = wrapper.findAll('button').find((b) => /complete payment/i.test(b.text()))
    await pay!.trigger('click')
    await flushPromises()

    expect(pos.cartLines.length).toBe(linesBefore)
    expect(wrapper.text()).toMatch(/IndexedDB write failed/)
    expect(fetchReceipt).not.toHaveBeenCalled()
  })
})
