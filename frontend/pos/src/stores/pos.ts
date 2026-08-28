import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { ApiError, getStoredStoreId, parsePositiveIntId, setStoredStoreId, TERMINAL_KEY } from '@/api/client'
import { listCategories, resolvePrice, searchProducts, type Category } from '@/api/posCatalog'
import {
  canPriceOverride,
  decrementQty,
  findAutoAddVariant,
  firstActiveVariant,
  incrementQty,
  priceFieldsFromResolve,
  sumLineTotals,
  variantLabel,
  type CartLine,
  type SearchProduct,
  type SearchVariant,
} from '@/lib/cartPricing'
import { holdSale, listHeldSales, voidSale, type SaleResource } from '@/api/posSales'
import { useAuthStore } from '@/stores/auth'

export type StubPanel = 'customer' | 'discount' | 'payment' | 'held' | null

function readTerminal(): string {
  return localStorage.getItem(TERMINAL_KEY) ?? 'POS-001'
}

function newLineKey(): string {
  return crypto.randomUUID()
}

export const usePosStore = defineStore('pos', () => {
  const selectedStoreId = ref<number | null>(getStoredStoreId())
  const terminalLabel = ref(readTerminal())
  const activeStub = ref<StubPanel>(null)

  const cartLines = ref<CartLine[]>([])
  const cartBusy = ref(false)
  const cartError = ref<string | null>(null)
  const activeHeldSaleId = ref<number | null>(null)
  const heldSales = ref<SaleResource[]>([])
  const heldBusy = ref(false)

  const searchQuery = ref('')
  const categoryId = ref<number | null>(null)
  const categories = ref<Category[]>([])
  const searchResults = ref<SearchProduct[]>([])
  const searchElapsedMs = ref<number | null>(null)
  const searchLoading = ref(false)
  const searchError = ref<string | null>(null)

  let searchTimer: ReturnType<typeof setTimeout> | null = null
  let searchSeq = 0
  let pendingDebounceResolve: (() => void) | null = null

  const selectedStore = computed(() => {
    const id = selectedStoreId.value
    if (id == null) return null
    return useAuthStore().storeAccess.find((s) => s.id === id) ?? null
  })

  const hasSelectedStore = computed(() => selectedStore.value != null)

  const cartTotals = computed(() => sumLineTotals(cartLines.value))

  const lineCount = computed(() => cartLines.value.length)

  const allowPriceOverride = computed(() =>
    canPriceOverride(useAuthStore().user?.permissions),
  )

  function invalidateSearchInFlight(): void {
    searchSeq += 1
    if (searchTimer) {
      clearTimeout(searchTimer)
      searchTimer = null
    }
    if (pendingDebounceResolve) {
      pendingDebounceResolve()
      pendingDebounceResolve = null
    }
    searchLoading.value = false
  }

  function selectStore(storeId: number): void {
    const id = parsePositiveIntId(storeId)
    if (id == null) return
    const allowed = useAuthStore().storeAccess.some((s) => s.id === id)
    if (!allowed) return

    const changed = selectedStoreId.value !== id
    selectedStoreId.value = id
    setStoredStoreId(id)

    if (changed) {
      clearCart()
      clearSearch()
      void loadCategories()
    }
  }

  function clearStore(): void {
    selectedStoreId.value = null
    setStoredStoreId(null)
    clearCart()
    clearSearch()
  }

  function setTerminalLabel(label: string): void {
    const trimmed = label.trim() || 'POS-001'
    terminalLabel.value = trimmed
    localStorage.setItem(TERMINAL_KEY, trimmed)
  }

  function openStub(panel: Exclude<StubPanel, null>): void {
    activeStub.value = panel
  }

  function closeStub(): void {
    activeStub.value = null
  }

  function syncSelectedStoreWithAccess(): void {
    const id = selectedStoreId.value
    if (id == null) return
    if (!Number.isInteger(id) || id <= 0) {
      clearStore()
      return
    }
    const stillValid = useAuthStore().storeAccess.some((s) => s.id === id)
    if (!stillValid) {
      clearStore()
    }
  }

  function clearCart(): void {
    cartLines.value = []
    cartError.value = null
    activeHeldSaleId.value = null
  }

  function clearSearch(): void {
    invalidateSearchInFlight()
    searchQuery.value = ''
    categoryId.value = null
    searchResults.value = []
    searchElapsedMs.value = null
    searchError.value = null
  }

  async function loadCategories(): Promise<void> {
    try {
      const res = await listCategories()
      categories.value = res.data.filter((c) => c.isActive !== false)
    } catch (err) {
      searchError.value = err instanceof ApiError ? err.message : 'Failed to load categories'
    }
  }

  function setCategoryFilter(id: number | null): void {
    categoryId.value = id
    void runSearch(searchQuery.value, { immediate: true })
  }

  function setSearchQuery(q: string): void {
    searchQuery.value = q
    void runSearch(q, { debounceMs: 180 })
  }

  function cancelPendingDebounce(): void {
    if (searchTimer) {
      clearTimeout(searchTimer)
      searchTimer = null
    }
    if (pendingDebounceResolve) {
      pendingDebounceResolve()
      pendingDebounceResolve = null
    }
  }

  async function runSearch(
    q: string,
    opts: { debounceMs?: number; immediate?: boolean; allowAutoAdd?: boolean } = {},
  ): Promise<void> {
    cancelPendingDebounce()

    const execute = async () => {
      const seq = ++searchSeq
      searchLoading.value = true
      searchError.value = null
      try {
        const res = await searchProducts({
          q: q.trim() || undefined,
          categoryId: categoryId.value,
          limit: 25,
        })
        if (seq !== searchSeq) return

        searchResults.value = res.data
        searchElapsedMs.value = res.meta.elapsedMs

        if (opts.allowAutoAdd !== false && q.trim() !== '') {
          const auto = findAutoAddVariant(res.data, q)
          if (auto && seq === searchSeq) {
            const product = res.data.find((p) => activeHasVariant(p, auto.id))
            if (product) {
              await addVariantToCart(product, auto)
              // Ignore if search was cleared/switched while resolve ran.
              if (seq !== searchSeq) return
              searchQuery.value = ''
              searchResults.value = []
              searchElapsedMs.value = res.meta.elapsedMs
            }
          }
        }
      } catch (err) {
        if (seq !== searchSeq) return
        searchError.value = err instanceof ApiError ? err.message : 'Search failed'
        searchResults.value = []
      } finally {
        if (seq === searchSeq) {
          searchLoading.value = false
        }
      }
    }

    if (opts.immediate || !opts.debounceMs) {
      await execute()
      return
    }

    await new Promise<void>((resolve) => {
      pendingDebounceResolve = resolve
      searchTimer = setTimeout(() => {
        searchTimer = null
        pendingDebounceResolve = null
        void execute().finally(resolve)
      }, opts.debounceMs)
    })
  }

  function activeHasVariant(product: SearchProduct, variantId: number): boolean {
    return (product.variants ?? []).some((v) => v.id === variantId && v.isActive)
  }

  async function addFirstHit(): Promise<void> {
    const variant = firstActiveVariant(searchResults.value)
    if (!variant) {
      cartError.value = 'No matching variants to add'
      return
    }
    const product = searchResults.value.find((p) => activeHasVariant(p, variant.id))
    if (!product) return
    await addVariantToCart(product, variant)
  }

  async function addVariantToCart(product: SearchProduct, variant: SearchVariant): Promise<void> {
    if (cartBusy.value) return

    const storeId = selectedStoreId.value
    if (storeId == null) {
      cartError.value = 'Select a store first'
      return
    }

    const existing = cartLines.value.find((l) => l.variantId === variant.id)
    const nextQty = existing ? incrementQty(existing.qty) : '1'
    const override = existing?.priceOverride ?? null

    cartBusy.value = true
    cartError.value = null
    try {
      const res = await resolvePrice({
        variantId: variant.id,
        storeId,
        qty: nextQty,
      })
      const priced = priceFieldsFromResolve(res.data, nextQty, override)
      const label = variantLabel(product, variant)
      const sku = variant.sku
      const barcode = variant.barcode

      if (existing) {
        Object.assign(existing, { label, sku, barcode, ...priced })
      } else {
        cartLines.value.push({
          lineKey: newLineKey(),
          variantId: variant.id,
          label,
          sku,
          barcode,
          ...priced,
        })
      }
    } catch (err) {
      cartError.value = err instanceof ApiError ? err.message : 'Could not price item'
    } finally {
      cartBusy.value = false
    }
  }

  async function repriceLine(line: CartLine, qty: string): Promise<boolean> {
    if (cartBusy.value) return false

    const storeId = selectedStoreId.value
    if (storeId == null) {
      cartError.value = 'Select a store first'
      return false
    }

    cartBusy.value = true
    cartError.value = null
    try {
      const res = await resolvePrice({
        variantId: line.variantId,
        storeId,
        qty,
      })
      const priced = priceFieldsFromResolve(res.data, qty, line.priceOverride)
      Object.assign(line, priced)
      return true
    } catch (err) {
      cartError.value = err instanceof ApiError ? err.message : 'Could not update price'
      return false
    } finally {
      cartBusy.value = false
    }
  }

  async function bumpQty(lineKey: string, delta: 1 | -1): Promise<void> {
    if (cartBusy.value) return

    const line = cartLines.value.find((l) => l.lineKey === lineKey)
    if (!line) return

    if (delta < 0) {
      const next = decrementQty(line.qty)
      if (next == null) {
        removeLine(lineKey)
        return
      }
      await repriceLine(line, next)
      return
    }

    await repriceLine(line, incrementQty(line.qty))
  }

  function removeLine(lineKey: string): void {
    cartLines.value = cartLines.value.filter((l) => l.lineKey !== lineKey)
  }

  async function setPriceOverride(lineKey: string, override: string | null): Promise<void> {
    if (!allowPriceOverride.value) {
      cartError.value = 'Price override not permitted'
      return
    }
    const line = cartLines.value.find((l) => l.lineKey === lineKey)
    if (!line) return

    const previous = line.priceOverride
    line.priceOverride = override
    const ok = await repriceLine(line, line.qty)
    if (!ok) {
      line.priceOverride = previous
    }
  }

  async function loadHeldSales(): Promise<void> {
    heldBusy.value = true
    try {
      const res = await listHeldSales()
      heldSales.value = res.data
    } catch (err) {
      cartError.value = err instanceof ApiError ? err.message : 'Could not load held sales'
    } finally {
      heldBusy.value = false
    }
  }

  async function holdCurrentCart(): Promise<boolean> {
    if (cartBusy.value || cartLines.value.length === 0) return false

    cartBusy.value = true
    cartError.value = null
    try {
      await holdSale({
        uuid: crypto.randomUUID(),
        customerId: null,
        items: cartLines.value.map((line) => ({
          variantId: line.variantId,
          qty: line.qty,
          unitPrice: line.unitPrice,
          discount: line.discountAmount,
        })),
      })
      clearCart()
      await loadHeldSales()
      return true
    } catch (err) {
      cartError.value = err instanceof ApiError ? err.message : 'Could not hold sale'
      return false
    } finally {
      cartBusy.value = false
    }
  }

  function resumeHeldSale(sale: SaleResource): void {
    activeHeldSaleId.value = sale.id
    cartLines.value = sale.items.map((item) => ({
      lineKey: newLineKey(),
      variantId: item.variantId,
      label: item.variant?.name ?? `Item ${item.variantId}`,
      sku: item.variant?.sku ?? '',
      barcode: null,
      qty: item.qty,
      unitPrice: item.unitPrice ?? '0.0000',
      listPrice: item.unitPrice ?? '0.0000',
      discountAmount: item.discount ?? '0.0000',
      taxAmount: item.taxAmount ?? '0.0000',
      taxName: 'Tax',
      taxRate: '0.0000',
      taxInclusive: false,
      lineTotal: item.lineTotal,
      priceOverride: null,
    }))
    cartError.value = null
    closeStub()
  }

  async function voidCurrentTransaction(): Promise<boolean> {
    if (cartBusy.value) return false

    if (activeHeldSaleId.value != null) {
      cartBusy.value = true
      cartError.value = null
      try {
        await voidSale(activeHeldSaleId.value)
        clearCart()
        await loadHeldSales()
        return true
      } catch (err) {
        cartError.value = err instanceof ApiError ? err.message : 'Could not void held sale'
        return false
      } finally {
        cartBusy.value = false
      }
    }

    if (cartLines.value.length > 0) {
      clearCart()
      return true
    }

    return false
  }

  async function voidHeldAfterComplete(heldId: number): Promise<void> {
    try {
      await voidSale(heldId)
      await loadHeldSales()
    } catch {
      // Non-blocking cleanup after successful payment.
    }
  }

  async function clearPriceOverride(lineKey: string): Promise<void> {
    if (!allowPriceOverride.value) {
      cartError.value = 'Price override not permitted'
      return
    }
    const line = cartLines.value.find((l) => l.lineKey === lineKey)
    if (!line) return

    const previous = line.priceOverride
    line.priceOverride = null
    const ok = await repriceLine(line, line.qty)
    if (!ok) {
      line.priceOverride = previous
    }
  }

  return {
    selectedStoreId,
    terminalLabel,
    activeStub,
    selectedStore,
    hasSelectedStore,
    cartLines,
    cartBusy,
    cartError,
    cartTotals,
    lineCount,
    allowPriceOverride,
    searchQuery,
    categoryId,
    categories,
    searchResults,
    searchElapsedMs,
    searchLoading,
    searchError,
    selectStore,
    clearStore,
    setTerminalLabel,
    openStub,
    closeStub,
    syncSelectedStoreWithAccess,
    clearCart,
    clearSearch,
    loadCategories,
    setCategoryFilter,
    setSearchQuery,
    runSearch,
    addFirstHit,
    addVariantToCart,
    bumpQty,
    removeLine,
    setPriceOverride,
    activeHeldSaleId,
    heldSales,
    heldBusy,
    loadHeldSales,
    holdCurrentCart,
    resumeHeldSale,
    voidCurrentTransaction,
    voidHeldAfterComplete,
    clearPriceOverride,
  }
})
