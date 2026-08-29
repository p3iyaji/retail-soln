import { posDb, type CachedProduct, type PosDatabase } from '@/db/posDb'
import type { SearchProduct } from '@/lib/cartPricing'

function includesCi(haystack: string | null | undefined, needle: string): boolean {
  if (haystack == null || haystack === '') return false
  return haystack.toLowerCase().includes(needle.toLowerCase())
}

function productMatches(product: CachedProduct, term: string, categoryId: number | null): boolean {
  if (categoryId != null && product.categoryId !== categoryId) {
    return false
  }
  if (term === '') return true

  if (
    includesCi(product.name, term) ||
    includesCi(product.sku, term) ||
    includesCi(product.barcode, term)
  ) {
    return true
  }

  return product.variants.some(
    (v) => includesCi(v.name, term) || includesCi(v.sku, term) || includesCi(v.barcode, term),
  )
}

function toSearchProduct(product: CachedProduct): SearchProduct {
  return {
    id: product.id,
    name: product.name,
    sku: product.sku,
    barcode: product.barcode,
    variants: product.variants.map((v) => ({
      id: v.id,
      name: v.name,
      sku: v.sku,
      barcode: v.barcode,
      isActive: v.isActive,
      isDefault: v.isDefault,
    })),
  }
}

/**
 * Offline catalog search against Dexie `products` (name / sku / barcode).
 * Returns hits shaped like live GET /products/search.
 */
export async function searchLocalCatalog(
  params: {
    q?: string
    categoryId?: number | null
    limit?: number
  },
  db: PosDatabase = posDb,
): Promise<{ data: SearchProduct[]; meta: { count: number; elapsedMs: number } }> {
  const started = performance.now()
  const term = (params.q ?? '').trim()
  const categoryId = params.categoryId ?? null
  const rawLimit = Number(params.limit)
  const limit = Number.isFinite(rawLimit) ? Math.max(1, Math.min(rawLimit, 50)) : 25

  const all = await db.products.toArray()
  const hits: SearchProduct[] = []

  for (const product of all) {
    if (!productMatches(product, term, categoryId)) continue
    hits.push(toSearchProduct(product))
    if (hits.length >= limit) break
  }

  return {
    data: hits,
    meta: {
      count: hits.length,
      elapsedMs: Math.round((performance.now() - started) * 100) / 100,
    },
  }
}

export async function localCatalogProductCount(db: PosDatabase = posDb): Promise<number> {
  return db.products.count()
}
