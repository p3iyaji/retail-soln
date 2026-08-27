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
