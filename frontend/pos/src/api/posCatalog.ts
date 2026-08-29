import { api } from '@/api/client'
import type { ResolvePriceData, SearchProduct } from '@/lib/cartPricing'

export type Category = {
  id: number
  name: string
  slug: string
  isActive: boolean
}

export type ProductSearchResponse = {
  data: SearchProduct[]
  meta: {
    count: number
    elapsedMs: number
  }
}

export type CategoriesResponse = {
  data: Category[]
}

export type ResolvePriceResponse = {
  data: ResolvePriceData
}

/** Product list row from GET /products (includes variants when loaded). */
export type CatalogProduct = {
  id: number
  name: string
  sku: string
  barcode: string | null
  categoryId: number | null
  isActive: boolean
  sellingPrice?: string | null
  variants?: Array<{
    id: number
    name: string
    sku: string
    barcode: string | null
    price: string
    isDefault?: boolean
    isActive: boolean
  }>
}

export type ProductsPageResponse = {
  data: CatalogProduct[]
  meta: {
    currentPage: number
    lastPage: number
    total: number
  }
}

export type InventoryBalance = {
  id: number
  locationType: string
  locationId: number
  productVariantId: number
  available: string
  reserved?: string
  damaged?: string
  inTransit?: string
}

export type InventoryBalancesResponse = {
  data: InventoryBalance[]
  meta: {
    currentPage: number
    lastPage: number
    total: number
  }
}

export type StoreTaxConfig = {
  rate?: string
  inclusive?: boolean
  name?: string
}

export type StoreDetail = {
  id: number
  name: string
  currency: string
  taxConfig: StoreTaxConfig | null
  isActive: boolean
}

export type StoreShowResponse = {
  data: StoreDetail
}

export function searchProducts(params: {
  q?: string
  categoryId?: number | null
  limit?: number
}): Promise<ProductSearchResponse> {
  const query = new URLSearchParams()
  if (params.q != null && params.q !== '') {
    query.set('q', params.q)
  }
  if (params.categoryId != null) {
    query.set('categoryId', String(params.categoryId))
  }
  if (params.limit != null) {
    query.set('limit', String(params.limit))
  }
  const qs = query.toString()
  return api.get<ProductSearchResponse>(`/products/search${qs ? `?${qs}` : ''}`)
}

export function listCategories(): Promise<CategoriesResponse> {
  return api.get<CategoriesResponse>('/categories')
}

export function resolvePrice(body: {
  variantId: number
  storeId: number
  qty: string
  customerId?: number | null
}): Promise<ResolvePriceResponse> {
  return api.post<ResolvePriceResponse>('/pricing/resolve', {
    variantId: body.variantId,
    storeId: body.storeId,
    qty: body.qty,
    ...(body.customerId != null ? { customerId: body.customerId } : {}),
  })
}

/** Paginated catalog for cache warm (GET /products). */
export function listProductsPage(params: {
  page?: number
  perPage?: number
}): Promise<ProductsPageResponse> {
  const query = new URLSearchParams()
  query.set('per_page', String(params.perPage ?? 100))
  if (params.page != null) {
    query.set('page', String(params.page))
  }
  return api.get<ProductsPageResponse>(`/products?${query.toString()}`)
}

/** Store inventory balances (advisory offline snapshot). */
export function listInventoryBalances(params: {
  storeId: number
  page?: number
  perPage?: number
}): Promise<InventoryBalancesResponse> {
  const query = new URLSearchParams()
  query.set('storeId', String(params.storeId))
  query.set('per_page', String(params.perPage ?? 100))
  if (params.page != null) {
    query.set('page', String(params.page))
  }
  return api.get<InventoryBalancesResponse>(`/inventory/balances?${query.toString()}`)
}

/** Store detail including taxConfig for offline tax snapshot. */
export function fetchStore(storeId: number): Promise<StoreShowResponse> {
  return api.get<StoreShowResponse>(`/stores/${storeId}`)
}
