import {
  decimalAdd,
  decimalCmp,
  decimalDiv,
  decimalMul,
  decimalSub,
  formatCurrency,
  fromScaled,
  money,
  roundCurrency,
  toScaled,
} from '@/lib/decimal'

export type ResolvedTax = {
  name: string
  rate: string
  inclusive: boolean
  amount: string
}

export type ResolvePriceData = {
  unitPrice: string
  listPrice: string
  discountAmount: string
  netPrice: string
  tax: ResolvedTax
  grossPrice: string
  lineTotal: string
  qty: string
  variantId: number
  storeId: number
}

export type CartLine = {
  /** Local line key for Vue lists. */
  lineKey: string
  variantId: number
  label: string
  sku: string
  barcode: string | null
  qty: string
  unitPrice: string
  listPrice: string
  discountAmount: string
  taxAmount: string
  taxName: string
  taxRate: string
  taxInclusive: boolean
  lineTotal: string
  /** When set, unit price is forced and promos are skipped for this unit. */
  priceOverride: string | null
}

export type SearchVariant = {
  id: number
  name: string
  sku: string
  barcode: string | null
  isActive: boolean
  isDefault?: boolean
}

export type SearchProduct = {
  id: number
  name: string
  sku: string
  barcode: string | null
  variants?: SearchVariant[]
}

function eqCi(a: string | null | undefined, b: string): boolean {
  if (a == null || a === '') return false
  return a.toLowerCase() === b.toLowerCase()
}

/** Case-insensitive exact SKU/barcode equality. */
export function exactSkuOrBarcode(candidate: string | null | undefined, term: string): boolean {
  return eqCi(candidate, term)
}

export function activeVariants(product: SearchProduct): SearchVariant[] {
  return (product.variants ?? []).filter((v) => v.isActive)
}

export function firstActiveVariant(products: SearchProduct[]): SearchVariant | null {
  for (const product of products) {
    const variants = activeVariants(product)
    if (variants.length > 0) {
      return variants[0] ?? null
    }
  }
  return null
}

/**
 * Auto-add only on a clear exact SKU/barcode hit (case-insensitive).
 * Partial unique name matches must NOT auto-add — use Enter → first hit instead.
 */
export function findAutoAddVariant(products: SearchProduct[], q: string): SearchVariant | null {
  const term = q.trim()
  if (term === '') return null

  const exactHits: SearchVariant[] = []

  for (const product of products) {
    const variants = activeVariants(product)
    const productExact =
      exactSkuOrBarcode(product.sku, term) || exactSkuOrBarcode(product.barcode, term)

    for (const variant of variants) {
      const variantExact =
        exactSkuOrBarcode(variant.sku, term) || exactSkuOrBarcode(variant.barcode, term)
      if (variantExact) {
        exactHits.push(variant)
        continue
      }
      // Product-level exact SKU/barcode with a single active variant → that variant.
      if (productExact && variants.length === 1) {
        exactHits.push(variant)
      }
    }
  }

  const unique = [...new Map(exactHits.map((v) => [v.id, v])).values()]
  return unique.length === 1 ? (unique[0] ?? null) : null
}

export function variantLabel(product: SearchProduct, variant: SearchVariant): string {
  if (variant.name && variant.name !== 'Default') {
    return `${product.name} — ${variant.name}`
  }
  return product.name
}

export type CachedPriceInputs = {
  unitPrice: string
  listPrice: string
  taxName: string
  taxRate: string
  taxInclusive: boolean
}

/**
 * Build priced cart fields from resolve. When override is set: unitPrice=override,
 * clear promo discount, scale tax proportionally from resolve’s unit (or recompute via rate if unit is 0).
 */
export function priceFieldsFromResolve(
  resolved: ResolvePriceData,
  qty: string,
  priceOverride: string | null,
): Pick<
  CartLine,
  | 'qty'
  | 'unitPrice'
  | 'listPrice'
  | 'discountAmount'
  | 'taxAmount'
  | 'taxName'
  | 'taxRate'
  | 'taxInclusive'
  | 'lineTotal'
  | 'priceOverride'
> {
  const qtyMoney = money(qty)
  const listPrice = money(resolved.listPrice)
  const taxName = resolved.tax.name
  const taxRate = money(resolved.tax.rate)
  const taxInclusive = Boolean(resolved.tax.inclusive)

  const unitPrice =
    priceOverride == null || priceOverride === '' ? money(resolved.unitPrice) : money(priceOverride)
  const discountAmount =
    priceOverride == null || priceOverride === '' ? money(resolved.discountAmount) : '0.0000'

  const lineNet = decimalSub(decimalMul(unitPrice, qtyMoney), discountAmount)
  const normalizedNet = decimalCmp(lineNet, '0') < 0 ? '0.0000' : money(lineNet)
  const taxed = taxOnLineNet(normalizedNet, taxRate, taxInclusive)

  return {
    qty: qtyMoney,
    unitPrice,
    listPrice,
    discountAmount,
    taxAmount: taxed.taxAmount,
    taxName,
    taxRate,
    taxInclusive,
    lineTotal: taxed.lineTotal,
    priceOverride: priceOverride == null || priceOverride === '' ? null : unitPrice,
  }
}

/**
 * Offline line pricing from cached unit + tax (no promo engine in 5.2).
 * Missing/empty unit price must be handled by the caller — never silent zero.
 */
export function priceFieldsFromCache(
  cached: CachedPriceInputs,
  qty: string,
  priceOverride: string | null,
): ReturnType<typeof priceFieldsFromResolve> {
  return priceFieldsFromResolve(
    {
      unitPrice: money(cached.unitPrice),
      listPrice: money(cached.listPrice),
      discountAmount: '0.0000',
      netPrice: money(cached.unitPrice),
      tax: {
        name: cached.taxName,
        rate: money(cached.taxRate),
        inclusive: Boolean(cached.taxInclusive),
        amount: '0.0000',
      },
      grossPrice: money(cached.unitPrice),
      lineTotal: money(cached.unitPrice),
      qty: money(qty),
      variantId: 0,
      storeId: 0,
    },
    qty,
    priceOverride,
  )
}

function taxOnLineNet(
  lineNet: string,
  rate: string,
  inclusive: boolean,
): { taxAmount: string; lineTotal: string } {
  if (decimalCmp(rate, '0') === 0) {
    const total = roundCurrency(lineNet)
    return { taxAmount: roundCurrency('0'), lineTotal: total }
  }

  if (inclusive) {
    const divisor = decimalAdd('1', rate)
    if (decimalCmp(divisor, '0') <= 0) {
      const total = roundCurrency(lineNet)
      return { taxAmount: roundCurrency('0'), lineTotal: total }
    }
    const net = roundCurrency(decimalDiv(lineNet, divisor))
    const taxAmount = roundCurrency(decimalSub(lineNet, net))
    return { taxAmount, lineTotal: roundCurrency(lineNet) }
  }

  const taxAmount = roundCurrency(decimalMul(lineNet, rate))
  const lineTotal = roundCurrency(decimalAdd(lineNet, taxAmount))
  return { taxAmount, lineTotal }
}

export function sumLineTotals(lines: CartLine[]): {
  subtotal: string
  tax: string
  discount: string
  total: string
} {
  let subtotal = 0n
  let tax = 0n
  let discount = 0n
  let total = 0n

  for (const line of lines) {
    const lineTotal = toScaled(line.lineTotal)
    const taxAmount = toScaled(line.taxAmount)
    total += lineTotal
    tax += taxAmount
    discount += toScaled(line.discountAmount)
    subtotal += lineTotal - taxAmount
  }

  return {
    subtotal: roundCurrency(fromScaled(subtotal)),
    tax: roundCurrency(fromScaled(tax)),
    discount: roundCurrency(fromScaled(discount)),
    total: roundCurrency(fromScaled(total)),
  }
}

export { formatCurrency }

export function canPriceOverride(permissions: string[] | undefined | null): boolean {
  return Boolean(permissions?.includes('sales.price-override'))
}

export function incrementQty(qty: string): string {
  return fromScaled(toScaled(qty) + toScaled('1'))
}

export function decrementQty(qty: string): string | null {
  const next = toScaled(qty) - toScaled('1')
  if (next < toScaled('1')) return null
  return fromScaled(next)
}
