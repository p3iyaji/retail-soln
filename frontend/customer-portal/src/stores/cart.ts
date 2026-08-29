import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import {
  ApiError,
  cartApi,
  checkoutApi,
  hasToken,
  type CartLine,
  type CartPayload,
  type CartTotals,
  type FulfilmentMode,
  type PaymentProvider,
  type PortalOrder,
  type PortalStore,
} from '@/api/client'

function emptyTotals(): CartTotals {
  return {
    subtotal: '0.0000',
    discountTotal: '0.0000',
    taxTotal: '0.0000',
    deliveryFee: '0.0000',
    grandTotal: '0.0000',
  }
}

export const useCartStore = defineStore('cart', () => {
  const lines = ref<CartLine[]>([])
  const totals = ref<CartTotals>(emptyTotals())
  const store = ref<{ id: number; name: string } | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const stores = ref<PortalStore[]>([])
  const lastOrder = ref<PortalOrder | null>(null)

  const itemCount = computed(() =>
    lines.value.reduce((sum, line) => sum + Number(line.qty || 0), 0),
  )
  const isEmpty = computed(() => lines.value.length === 0)

  function applyCart(payload: CartPayload): void {
    lines.value = payload.lines
    totals.value = payload.totals
    store.value = payload.store
  }

  async function fetchCart(): Promise<void> {
    if (!hasToken()) {
      lines.value = []
      totals.value = emptyTotals()
      store.value = null
      return
    }
    loading.value = true
    error.value = null
    try {
      const res = await cartApi.get()
      applyCart(res.data)
    } catch (err) {
      error.value = err instanceof ApiError ? err.message : 'Failed to load cart'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function addItem(variantId: number, qty = 1): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const res = await cartApi.add(variantId, qty)
      applyCart(res.data)
    } catch (err) {
      error.value = err instanceof ApiError ? err.message : 'Could not add to cart'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function updateQty(lineId: number, qty: number): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const res = await cartApi.update(lineId, qty)
      applyCart(res.data)
    } catch (err) {
      error.value = err instanceof ApiError ? err.message : 'Could not update cart'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function removeLine(lineId: number): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const res = await cartApi.remove(lineId)
      applyCart(res.data)
    } catch (err) {
      error.value = err instanceof ApiError ? err.message : 'Could not remove line'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function loadStores(): Promise<void> {
    const res = await checkoutApi.stores()
    stores.value = res.data
  }

  async function preview(payload: {
    fulfilmentMode: FulfilmentMode
    storeId?: number | null
    addressId?: number | null
    contactPhone?: string | null
  }) {
    return checkoutApi.preview(payload)
  }

  async function placeOrder(
    payload: {
      fulfilmentMode: FulfilmentMode
      storeId?: number | null
      addressId?: number | null
      contactPhone?: string | null
      paymentProvider: PaymentProvider
      successUrl?: string
      cancelUrl?: string
    },
    idempotencyKey: string,
  ): Promise<PortalOrder> {
    loading.value = true
    error.value = null
    try {
      const res = await checkoutApi.place(payload, idempotencyKey)
      lastOrder.value = res.data
      lines.value = []
      totals.value = emptyTotals()
      return res.data
    } catch (err) {
      error.value = err instanceof ApiError ? err.message : 'Could not place order'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function confirmManual(orderId: number): Promise<PortalOrder> {
    const res = await checkoutApi.confirmManual(orderId)
    lastOrder.value = res.data
    return res.data
  }

  function clearLocal(): void {
    lines.value = []
    totals.value = emptyTotals()
    store.value = null
    lastOrder.value = null
    error.value = null
  }

  return {
    lines,
    totals,
    store,
    loading,
    error,
    stores,
    lastOrder,
    itemCount,
    isEmpty,
    fetchCart,
    addItem,
    updateQty,
    removeLine,
    loadStores,
    preview,
    placeOrder,
    confirmManual,
    clearLocal,
  }
})
