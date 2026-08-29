import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { ApiError, getStoredStoreId, parsePositiveIntId, setStoredStoreId, TERMINAL_KEY } from '@/api/client'
import { listCategories, resolvePrice, searchProducts, type Category } from '@/api/posCatalog'
import { posDb } from '@/db/posDb'
import {
  canPriceOverride,
  decrementQty,
  findAutoAddVariant,
  firstActiveVariant,
  incrementQty,
  priceFieldsFromCache,
  priceFieldsFromResolve,
  sumLineTotals,
  variantLabel,
  type CartLine,
  type SearchProduct,
  type SearchVariant,
} from '@/lib/cartPricing'
import { holdSale, listHeldSales, voidSale, type SaleResource } from '@/api/posSales'
import { type CustomerResource } from '@/api/posCustomers'
import {
  fetchCurrentSession,
  openCashierSession,
  type CashierSessionResource,
} from '@/api/posSessions'
import { useAuthStore } from '@/stores/auth'
import { money } from '@/lib/decimal'
import { warmCache } from '@/sync/cacheWarm'
import { localCatalogProductCount, searchLocalCatalog } from '@/sync/localCatalog'

function isBrowserOffline(): boolean {
  return typeof navigator !== 'undefined' && navigator.onLine === false
}

export type StubPanel = 'customer' | 'discount' | 'payment' | 'held' | 'session' | 'return' | 'installment' | null

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

  const cashierSession = ref<CashierSessionResource | null>(null)
  const sessionBusy = ref(false)
  const sessionError = ref<string | null>(null)

  const searchQuery = ref('')
  const categoryId = ref<number | null>(null)
  const categories = ref<Category[]>([])
  const searchResults = ref<SearchProduct[]>([])
  const searchElapsedMs = ref<number | null>(null)
  const searchLoading = ref(false)
  const searchError = ref<string | null>(null)

  const selectedCustomer = ref<CustomerResource | null>(null)

  let searchTimer: ReturnType<typeof setTimeout> | null = null
  let searchSeq = 0
  let pendingDebounceResolve: (() => void) | null = null

  const selectedStore = computed(() => {
    const id = selectedStoreId.value
    if (id == null) return null
    return useAuthStore().storeAccess.find((s) => s.id === id) ?? null
  })

  const hasSelectedStore = computed(() => selectedStore.value != null)

  const hasOpenSession = computed(
    () => cashierSession.value != null && cashierSession.value.status === 'open',
  )

  const cartTotals = computed(() => sumLineTotals(cartLines.value))

  const lineCount = computed(() => cartLines.value.length)

  const allowPriceOverride = computed(() =>
    canPriceOverride(useAuthStore().user?.permissions),
  )

  const canProcessRefund = computed(() =>
    Boolean(useAuthStore().user?.permissions?.includes('sales.refund')),
  )

  const canCollectInstallments = computed(() =>
    Boolean(useAuthStore().user?.permissions?.includes('sales.installments.collect')),
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
      clearSession()
      selectedCustomer.value = null
      void loadCategories()
    }

    // Warm catalog cache for offline search/pricing (Story 5.2). Online-only.
    void warmCache(id).catch(() => {
      // SYNC ERROR already set inside warmCache
    })
  }

  /** Refresh local cache when floor mounts with a store already selected. */
  async function refreshCatalogCache(): Promise<void> {
    const id = selectedStoreId.value
    if (id == null) return
    try {
      await warmCache(id)
    } catch {
      // SYNC ERROR already set inside warmCache
    }
  }

  function clearSession(): void {
    cashierSession.value = null
    sessionError.value = null
  }

  async function refreshSession(): Promise<void> {
    if (!hasSelectedStore.value) {
      clearSession()
      return
    }

    sessionBusy.value = true
    sessionError.value = null
    try {
      const res = await fetchCurrentSession(terminalLabel.value)
      cashierSession.value = res.data
    } catch (err) {
      sessionError.value = err instanceof ApiError ? err.message : 'Could not load session'
      cashierSession.value = null
    } finally {
      sessionBusy.value = false
    }
  }

  function openPaymentOrSession(): void {
    if (!hasOpenSession.value) {
      openStub('session')
      return
    }
    openStub('payment')
  }

  async function openSession(openingFloat: string): Promise<void> {
    if (!hasSelectedStore.value) {
      throw new Error('Select a store first')
    }

    sessionBusy.value = true
    sessionError.value = null
    try {
      const res = await openCashierSession({
        terminalName: terminalLabel.value,
        openingFloat: Number(openingFloat || 0).toFixed(4),
      })
      cashierSession.value = res.data
    } catch (err) {
      sessionError.value = err instanceof ApiError ? err.message : 'Could not open session'
      throw err
    } finally {
      sessionBusy.value = false
    }
  }

  function clearStore(): void {
    selectedStoreId.value = null
    setStoredStoreId(null)
    clearCart()
    clearSearch()
    clearSession()
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

  function pricingCustomerId(): number | undefined {
    const customer = selectedCustomer.value
    if (!customer || customer.isWalkIn) return undefined
    return customer.id
  }

  async function repriceAllCartLines(): Promise<void> {
    for (const line of [...cartLines.value]) {
      await repriceLine(line, line.qty)
    }
  }

  async function setSelectedCustomer(customer: CustomerResource): Promise<void> {
    selectedCustomer.value = customer
    if (cartLines.value.length > 0) {
      await repriceAllCartLines()
    }
  }

  function clearSelectedCustomer(): void {
    selectedCustomer.value = null
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
        const offline = isBrowserOffline()
        const res = offline
          ? await searchLocalCatalog({
              q: q.trim() || undefined,
              categoryId: categoryId.value,
              limit: 25,
            })
          : await searchProducts({
              q: q.trim() || undefined,
              categoryId: categoryId.value,
              limit: 25,
            })
        if (seq !== searchSeq) return

        searchResults.value = res.data
        searchElapsedMs.value = res.meta.elapsedMs

        if (offline && res.data.length === 0) {
          const cachedCount = await localCatalogProductCount()
          if (seq !== searchSeq) return
          if (cachedCount === 0) {
            searchError.value = 'Catalog not synced — connect online to refresh cache'
          }
        }

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

  async function priceFromCache(
    variantId: number,
    storeId: number,
    qty: string,
    priceOverride: string | null,
  ): Promise<ReturnType<typeof priceFieldsFromCache>> {
    const price = await posDb.prices.get([variantId, storeId])
    if (
      price == null ||
      price.unitPrice == null ||
      price.unitPrice === '' ||
      money(price.unitPrice) === '0.0000'
    ) {
      throw new Error('Price not available offline for this item')
    }

    const tax = await posDb.taxes.get(storeId)
    if (tax == null) {
      throw new Error('Tax not available offline for this store')
    }
    return priceFieldsFromCache(
      {
        unitPrice: price.unitPrice,
        listPrice: price.listPrice || price.unitPrice,
        taxName: tax.name,
        taxRate: tax.rate,
        taxInclusive: tax.inclusive,
      },
      qty,
      priceOverride,
    )
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
      const priced = isBrowserOffline()
        ? await priceFromCache(variant.id, storeId, nextQty, override)
        : priceFieldsFromResolve(
            (
              await resolvePrice({
                variantId: variant.id,
                storeId,
                qty: nextQty,
                customerId: pricingCustomerId(),
              })
            ).data,
            nextQty,
            override,
          )
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
      cartError.value = err instanceof ApiError ? err.message : err instanceof Error ? err.message : 'Could not price item'
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
      const priced = isBrowserOffline()
        ? await priceFromCache(line.variantId, storeId, qty, line.priceOverride)
        : priceFieldsFromResolve(
            (
              await resolvePrice({
                variantId: line.variantId,
                storeId,
                qty,
                customerId: pricingCustomerId(),
              })
            ).data,
            qty,
            line.priceOverride,
          )
      Object.assign(line, priced)
      return true
    } catch (err) {
      cartError.value = err instanceof ApiError ? err.message : err instanceof Error ? err.message : 'Could not update price'
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
        customerId:
          selectedCustomer.value && !selectedCustomer.value.isWalkIn
            ? selectedCustomer.value.id
            : null,
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
    if (sale.customer) {
      if (sale.customer.isWalkIn) {
        selectedCustomer.value = null
      } else {
        selectedCustomer.value = {
          id: sale.customer.id,
          name: sale.customer.name,
          phone: null,
          email: null,
          accountNumber: '',
          isWalkIn: false,
          creditLimit: '0.0000',
          balance: '0.0000',
          isActive: true,
        }
      }
    }
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
    cashierSession,
    sessionBusy,
    sessionError,
    hasOpenSession,
    selectedStore,
    hasSelectedStore,
    cartLines,
    cartBusy,
    cartError,
    cartTotals,
    lineCount,
    selectedCustomer,
    allowPriceOverride,
    canProcessRefund,
    canCollectInstallments,
    searchQuery,
    categoryId,
    categories,
    searchResults,
    searchElapsedMs,
    searchLoading,
    searchError,
    selectStore,
    clearStore,
    refreshCatalogCache,
    setTerminalLabel,
    openStub,
    closeStub,
    syncSelectedStoreWithAccess,
    clearCart,
    setSelectedCustomer,
    clearSelectedCustomer,
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
    refreshSession,
    openSession,
    clearSession,
    openPaymentOrSession,
  }
})
