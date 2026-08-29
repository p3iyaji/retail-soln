import { describe, expect, it } from 'vitest'
import {
  canPriceOverride,
  decrementQty,
  findAutoAddVariant,
  firstActiveVariant,
  incrementQty,
  priceFieldsFromCache,
  priceFieldsFromResolve,
  sumLineTotals,
  type CartLine,
  type ResolvePriceData,
  type SearchProduct,
} from '@/lib/cartPricing'
import { decimalAdd, decimalCmp, decimalMul } from '@/lib/decimal'

function resolveFixture(over: Partial<ResolvePriceData> = {}): ResolvePriceData {
  return {
    unitPrice: '10.0000',
    listPrice: '10.0000',
    discountAmount: '0.0000',
    netPrice: '10.0000',
    tax: { name: 'VAT', rate: '0.1000', inclusive: false, amount: '1.0000' },
    grossPrice: '11.0000',
    lineTotal: '11.0000',
    qty: '1.0000',
    variantId: 1,
    storeId: 1,
    ...over,
  }
}

function line(partial: Partial<CartLine> & Pick<CartLine, 'lineKey' | 'variantId'>): CartLine {
  return {
    label: 'Item',
    sku: 'SKU',
    barcode: null,
    qty: '1.0000',
    unitPrice: '10.0000',
    listPrice: '10.0000',
    discountAmount: '0.0000',
    taxAmount: '1.0000',
    taxName: 'VAT',
    taxRate: '0.1000',
    taxInclusive: false,
    lineTotal: '11.0000',
    priceOverride: null,
    ...partial,
  }
}

describe('decimal money math', () => {
  it('sums without float drift', () => {
    expect(decimalAdd('0.1000', '0.2000')).toBe('0.3000')
    expect(decimalMul('10.0000', '3.0000')).toBe('30.0000')
    expect(decimalCmp('1.0000', '1.0001')).toBe(-1)
  })
})

describe('search hit selection', () => {
  const products: SearchProduct[] = [
    {
      id: 1,
      name: 'Chips',
      sku: 'CHIP-001',
      barcode: '999',
      variants: [
        { id: 10, name: 'Default', sku: 'CHIP-001', barcode: '999', isActive: true },
      ],
    },
    {
      id: 2,
      name: 'Cola',
      sku: 'COLA-001',
      barcode: '888',
      variants: [
        { id: 20, name: 'Default', sku: 'COLA-001', barcode: '888', isActive: true },
      ],
    },
  ]

  it('Enter path: first active variant from results', () => {
    expect(firstActiveVariant(products)?.id).toBe(10)
  })

  it('exact barcode/SKU auto-add when clear single match', () => {
    expect(findAutoAddVariant(products, '999')?.id).toBe(10)
    expect(findAutoAddVariant(products, 'COLA-001')?.id).toBe(20)
  })

  it('exact match is case-insensitive', () => {
    expect(findAutoAddVariant(products, 'chip-001')?.id).toBe(10)
    expect(findAutoAddVariant(products, 'Cola-001')?.id).toBe(20)
  })

  it('partial unique name alone does not auto-add', () => {
    expect(findAutoAddVariant([products[0]!], 'Chips')).toBeNull()
    expect(findAutoAddVariant([products[0]!], 'anything')).toBeNull()
  })

  it('ambiguous results do not auto-add', () => {
    expect(findAutoAddVariant(products, '00')).toBeNull()
  })
})

describe('qty stepper + merge pricing fields', () => {
  it('increments and decrements; below 1 signals remove', () => {
    expect(incrementQty('1.0000')).toBe('2.0000')
    expect(decrementQty('2.0000')).toBe('1.0000')
    expect(decrementQty('1.0000')).toBeNull()
  })

  it('resolve without override recomputes tax/line totals from unit × qty − discount', () => {
    const priced = priceFieldsFromResolve(
      resolveFixture({
        unitPrice: '9.0000',
        discountAmount: '1.0000',
        tax: { name: 'VAT', rate: '0.1000', inclusive: false, amount: '1.8000' },
        lineTotal: '19.8000',
      }),
      '2',
      null,
    )
    expect(priced.unitPrice).toBe('9.0000')
    expect(priced.discountAmount).toBe('1.0000')
    expect(priced.taxAmount).toBe('1.7000')
    expect(priced.lineTotal).toBe('18.7000')
  })

  it('price override recomputes tax from line net at 2dp', () => {
    const priced = priceFieldsFromResolve(resolveFixture(), '2', '8.0000')
    expect(priced.unitPrice).toBe('8.0000')
    expect(priced.discountAmount).toBe('0.0000')
    expect(priced.priceOverride).toBe('8.0000')
    expect(priced.taxAmount).toBe('1.6000')
    expect(priced.lineTotal).toBe('17.6000')
  })

  it('priceFieldsFromCache builds DECIMAL line from unit + tax (no promo)', () => {
    const priced = priceFieldsFromCache(
      {
        unitPrice: '10.0000',
        listPrice: '10.0000',
        taxName: 'VAT',
        taxRate: '0.1000',
        taxInclusive: false,
      },
      '1',
      null,
    )
    expect(priced.unitPrice).toBe('10.0000')
    expect(priced.discountAmount).toBe('0.0000')
    expect(priced.taxAmount).toBe('1.0000')
    expect(priced.lineTotal).toBe('11.0000')
    expect(priced.taxName).toBe('VAT')
  })
})

describe('cart rail totals', () => {
  it('sums line totals DECIMAL-safe', () => {
    const totals = sumLineTotals([
      line({ lineKey: 'a', variantId: 1, lineTotal: '11.0000', taxAmount: '1.0000' }),
      line({ lineKey: 'b', variantId: 2, lineTotal: '5.5000', taxAmount: '0.5000' }),
    ])
    expect(totals.total).toBe('16.5000')
    expect(totals.tax).toBe('1.5000')
    expect(totals.subtotal).toBe('15.0000')
    expect(totals.discount).toBe('0.0000')
  })
})

describe('price override permission', () => {
  it('gated by sales.price-override', () => {
    expect(canPriceOverride(['sales.create'])).toBe(false)
    expect(canPriceOverride(['sales.price-override'])).toBe(true)
    expect(canPriceOverride(null)).toBe(false)
  })
})
