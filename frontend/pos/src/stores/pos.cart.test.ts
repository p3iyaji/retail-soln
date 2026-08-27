import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { ApiError } from '@/api/client'

const resolvePrice = vi.fn()
const searchProducts = vi.fn()
const listCategories = vi.fn()

const authState = {
  user: { permissions: ['sales.create', 'sales.price-override'] as string[] },
  storeAccess: [
    { id: 1, name: 'Main', isDefault: true },
    { id: 2, name: 'Other', isDefault: false },
  ],
}

vi.mock('@/api/posCatalog', () => ({
  resolvePrice: (...args: unknown[]) => resolvePrice(...args),
  searchProducts: (...args: unknown[]) => searchProducts(...args),
  listCategories: (...args: unknown[]) => listCategories(...args),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => authState,
}))

function resolveOk(over: Record<string, unknown> = {}) {
  return {
    data: {
      unitPrice: '10.0000',
      listPrice: '10.0000',
      discountAmount: '0.0000',
      netPrice: '10.0000',
      tax: { name: 'VAT', rate: '0.1000', inclusive: false, amount: '1.0000' },
      grossPrice: '11.0000',
      lineTotal: '11.0000',
      qty: '1.0000',
      variantId: 10,
      storeId: 1,
      ...over,
    },
  }
}

const sampleProduct = {
  id: 1,
  name: 'Chips',
  sku: 'CHIP',
  barcode: '999',
  variants: [{ id: 10, name: 'Default', sku: 'CHIP', barcode: '999', isActive: true }],
}

describe('pos cart store resolve/merge', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    resolvePrice.mockReset()
    searchProducts.mockReset()
    listCategories.mockReset()
    listCategories.mockResolvedValue({ data: [{ id: 1, name: 'Snacks', slug: 'snacks', isActive: true }] })
    authState.user.permissions = ['sales.create', 'sales.price-override']
    localStorage.clear()
    localStorage.setItem('retail_soln_pos_store_id', '1')
  })

  it('adds via resolve; duplicate variant increments qty', async () => {
    const { usePosStore } = await import('@/stores/pos')
    const pos = usePosStore()
    pos.selectStore(1)

    resolvePrice
      .mockResolvedValueOnce(resolveOk())
      .mockResolvedValueOnce(
        resolveOk({
          netPrice: '20.0000',
          tax: { name: 'VAT', rate: '0.1000', inclusive: false, amount: '2.0000' },
          grossPrice: '22.0000',
          lineTotal: '22.0000',
          qty: '2.0000',
        }),
      )

    const variant = sampleProduct.variants[0]!
    await pos.addVariantToCart(sampleProduct, variant)
    expect(pos.cartLines).toHaveLength(1)
    expect(pos.cartLines[0]!.qty).toBe('1.0000')

    await pos.addVariantToCart(sampleProduct, variant)
    expect(pos.cartLines).toHaveLength(1)
    expect(pos.cartLines[0]!.qty).toBe('2.0000')
    expect(pos.cartLines[0]!.lineTotal).toBe('22.0000')
  })

  it('resolve fail does not add a line and surfaces error', async () => {
    const { usePosStore } = await import('@/stores/pos')
    const pos = usePosStore()
    pos.selectStore(1)

    resolvePrice.mockRejectedValueOnce(new ApiError(422, { message: 'Variant is inactive.' }))

    await pos.addVariantToCart(sampleProduct, sampleProduct.variants[0]!)

    expect(pos.cartLines).toHaveLength(0)
    expect(pos.cartError).toContain('inactive')
  })

  it('qty decrement below 1 removes the line', async () => {
    const { usePosStore } = await import('@/stores/pos')
    const pos = usePosStore()
    pos.selectStore(1)

    resolvePrice.mockResolvedValueOnce(resolveOk())
    await pos.addVariantToCart(sampleProduct, sampleProduct.variants[0]!)

    await pos.bumpQty(pos.cartLines[0]!.lineKey, -1)
    expect(pos.cartLines).toHaveLength(0)
  })

  it('store switch clears cart + search and reloads categories', async () => {
    const { usePosStore } = await import('@/stores/pos')
    const pos = usePosStore()
    pos.selectStore(1)

    resolvePrice.mockResolvedValueOnce(resolveOk())
    await pos.addVariantToCart(sampleProduct, sampleProduct.variants[0]!)
    pos.searchQuery = 'CHIP'
    pos.searchResults = [sampleProduct]
    pos.categoryId = 5

    pos.selectStore(2)

    expect(pos.cartLines).toHaveLength(0)
    expect(pos.searchQuery).toBe('')
    expect(pos.searchResults).toHaveLength(0)
    expect(pos.categoryId).toBeNull()
    expect(pos.selectedStoreId).toBe(2)
    expect(listCategories).toHaveBeenCalled()
  })

  it('setPriceOverride success applies override unit', async () => {
    const { usePosStore } = await import('@/stores/pos')
    const pos = usePosStore()
    pos.selectStore(1)

    resolvePrice
      .mockResolvedValueOnce(resolveOk())
      .mockResolvedValueOnce(
        resolveOk({
          unitPrice: '10.0000',
          tax: { name: 'VAT', rate: '0.1000', inclusive: false, amount: '1.0000' },
          lineTotal: '11.0000',
        }),
      )

    await pos.addVariantToCart(sampleProduct, sampleProduct.variants[0]!)
    await pos.setPriceOverride(pos.cartLines[0]!.lineKey, '8.0000')

    expect(pos.cartLines[0]!.priceOverride).toBe('8.0000')
    expect(pos.cartLines[0]!.unitPrice).toBe('8.0000')
    expect(pos.cartLines[0]!.discountAmount).toBe('0.0000')
  })

  it('setPriceOverride fail rolls back previous override', async () => {
    const { usePosStore } = await import('@/stores/pos')
    const pos = usePosStore()
    pos.selectStore(1)

    resolvePrice
      .mockResolvedValueOnce(resolveOk())
      .mockResolvedValueOnce(resolveOk())
      .mockRejectedValueOnce(new ApiError(422, { message: 'Could not update price' }))

    await pos.addVariantToCart(sampleProduct, sampleProduct.variants[0]!)
    await pos.setPriceOverride(pos.cartLines[0]!.lineKey, '8.0000')
    expect(pos.cartLines[0]!.priceOverride).toBe('8.0000')

    await pos.setPriceOverride(pos.cartLines[0]!.lineKey, '7.0000')
    expect(pos.cartLines[0]!.priceOverride).toBe('8.0000')
    expect(pos.cartError).toContain('Could not update')
  })

  it('setPriceOverride without permission is rejected', async () => {
    authState.user.permissions = ['sales.create']
    const { usePosStore } = await import('@/stores/pos')
    const pos = usePosStore()
    pos.selectStore(1)

    resolvePrice.mockResolvedValueOnce(resolveOk())
    await pos.addVariantToCart(sampleProduct, sampleProduct.variants[0]!)

    await pos.setPriceOverride(pos.cartLines[0]!.lineKey, '8.0000')
    expect(pos.cartLines[0]!.priceOverride).toBeNull()
    expect(pos.cartError).toContain('not permitted')
  })

  it('bumpQty +1 reprices the line', async () => {
    const { usePosStore } = await import('@/stores/pos')
    const pos = usePosStore()
    pos.selectStore(1)

    resolvePrice
      .mockResolvedValueOnce(resolveOk())
      .mockResolvedValueOnce(
        resolveOk({
          netPrice: '20.0000',
          tax: { name: 'VAT', rate: '0.1000', inclusive: false, amount: '2.0000' },
          grossPrice: '22.0000',
          lineTotal: '22.0000',
          qty: '2.0000',
        }),
      )

    await pos.addVariantToCart(sampleProduct, sampleProduct.variants[0]!)
    await pos.bumpQty(pos.cartLines[0]!.lineKey, 1)

    expect(pos.cartLines[0]!.qty).toBe('2.0000')
    expect(pos.cartLines[0]!.lineTotal).toBe('22.0000')
    expect(resolvePrice).toHaveBeenCalledTimes(2)
  })

  it('runSearch auto-adds only on exact SKU/barcode match', async () => {
    const { usePosStore } = await import('@/stores/pos')
    const pos = usePosStore()
    pos.selectStore(1)

    resolvePrice.mockResolvedValue(resolveOk())
    searchProducts.mockResolvedValue({
      data: [sampleProduct],
      meta: { count: 1, elapsedMs: 12 },
    })

    await pos.runSearch('chip', { immediate: true })
    expect(pos.cartLines).toHaveLength(1)
    expect(pos.searchQuery).toBe('')
    expect(pos.searchResults).toHaveLength(0)
  })

  it('runSearch name-only unique product does not auto-add', async () => {
    const { usePosStore } = await import('@/stores/pos')
    const pos = usePosStore()
    pos.selectStore(1)

    searchProducts.mockResolvedValue({
      data: [sampleProduct],
      meta: { count: 1, elapsedMs: 8 },
    })

    await pos.runSearch('Chips', { immediate: true })
    expect(pos.cartLines).toHaveLength(0)
    expect(pos.searchResults).toHaveLength(1)
  })

  it('runSearch ambiguous exact hits do not auto-add', async () => {
    const { usePosStore } = await import('@/stores/pos')
    const pos = usePosStore()
    pos.selectStore(1)

    const twin = {
      id: 2,
      name: 'Chips Twin',
      sku: 'OTHER',
      barcode: 'CHIP',
      variants: [{ id: 11, name: 'Default', sku: 'OTHER', barcode: 'CHIP', isActive: true }],
    }

    searchProducts.mockResolvedValue({
      data: [sampleProduct, twin],
      meta: { count: 2, elapsedMs: 9 },
    })

    // Both product/variant sku CHIP and twin barcode CHIP match term "CHIP"
    await pos.runSearch('CHIP', { immediate: true })
    expect(pos.cartLines).toHaveLength(0)
    expect(pos.searchResults).toHaveLength(2)
  })

  it('addFirstHit adds the first active variant via resolve', async () => {
    const { usePosStore } = await import('@/stores/pos')
    const pos = usePosStore()
    pos.selectStore(1)

    resolvePrice.mockResolvedValueOnce(resolveOk())
    pos.searchResults = [
      sampleProduct,
      {
        id: 2,
        name: 'Cola',
        sku: 'COLA',
        barcode: '888',
        variants: [{ id: 20, name: 'Default', sku: 'COLA', barcode: '888', isActive: true }],
      },
    ]

    await pos.addFirstHit()
    expect(pos.cartLines).toHaveLength(1)
    expect(pos.cartLines[0]!.variantId).toBe(10)
  })
})
