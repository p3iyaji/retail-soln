const TOKEN_KEY = 'retail_soln_portal_token'

export type PortalAddress = {
  id: number
  customerId: number
  type: string
  line1: string
  line2: string | null
  city: string | null
  region: string | null
  postalCode: string | null
  country: string | null
  isDefault: boolean
}

export type PortalAccount = {
  id: number
  email: string
  emailVerified: boolean
  emailVerifiedAt: string | null
  customer: {
    id: number
    name: string
    phone: string | null
    email: string | null
    type: string
    accountNumber: string
    addresses: PortalAddress[]
  } | null
}

type ApiErrorBody = {
  message?: string
  errors?: Record<string, string[]>
  verificationRequired?: boolean
  email?: string
  verificationToken?: string
}

export class ApiError extends Error {
  status: number
  body: ApiErrorBody

  constructor(status: number, body: ApiErrorBody) {
    super(body.message ?? `Request failed (${status})`)
    this.status = status
    this.body = body
  }
}

function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token: string | null): void {
  if (token) {
    localStorage.setItem(TOKEN_KEY, token)
  } else {
    localStorage.removeItem(TOKEN_KEY)
  }
}

export function hasToken(): boolean {
  return Boolean(getToken())
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const base = import.meta.env.VITE_API_BASE_URL?.replace(/\/$/, '') ?? '/api/v1'
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  if (init.body !== undefined) {
    headers.set('Content-Type', 'application/json')
  }

  const token = getToken()
  if (token) {
    headers.set('Authorization', `Bearer ${token}`)
  }

  const response = await fetch(`${base}${path}`, {
    ...init,
    headers,
  })

  if (response.status === 204) {
    return undefined as T
  }

  const body = (await response.json().catch(() => ({}))) as ApiErrorBody & T

  if (!response.ok) {
    throw new ApiError(response.status, body)
  }

  return body
}

export const api = {
  get: <T>(path: string, init?: RequestInit) => request<T>(path, init),
  post: <T>(path: string, data?: unknown, init?: RequestInit) =>
    request<T>(path, {
      ...init,
      method: 'POST',
      body: data ? JSON.stringify(data) : undefined,
    }),
  patch: <T>(path: string, data?: unknown, init?: RequestInit) =>
    request<T>(path, {
      ...init,
      method: 'PATCH',
      body: data ? JSON.stringify(data) : undefined,
    }),
  delete: <T>(path: string, init?: RequestInit) =>
    request<T>(path, { ...init, method: 'DELETE' }),
}

export type RegisterResponse = {
  verificationRequired: true
  email: string
  message?: string
  verificationToken?: string
}

export type LoginResponse =
  | {
      token: string
      tokenType: string
      account: PortalAccount
    }
  | RegisterResponse

export type VerifyResponse = {
  token: string
  tokenType: string
  account: PortalAccount
}

export type CatalogueCategory = {
  id: number
  name: string
  slug: string
  parentId: number | null
}

export type CataloguePrice = {
  amount: string
  listPrice: string
  grossPrice: string
  netPrice: string
  taxAmount: string
  variantId: number
}

export type CatalogueStock = {
  inStock: boolean | null
  storeId: number | null
  storeName: string | null
  label: string
}

export type CatalogueProductCard = {
  id: number
  name: string
  sku: string
  category: { id: number; name: string; slug: string } | null
  imageUrl: string | null
  primaryVariantId: number | null
  price: CataloguePrice | null
  stock: CatalogueStock
}

export type CatalogueProductDetail = CatalogueProductCard & {
  description: string | null
  variants: Array<{
    id: number
    name: string
    sku: string
    isDefault: boolean
    price: CataloguePrice | null
    stock: CatalogueStock
  }>
  store: { id: number; name: string } | null
}

export type CatalogueListResponse = {
  data: CatalogueProductCard[]
  meta: {
    currentPage: number
    lastPage: number
    total: number
    storeId: number | null
    storeName: string | null
  }
}

export function formatPortalMoney(amount: string | null | undefined): string {
  if (amount == null || amount === '') {
    return '—'
  }
  const n = Number(amount)
  if (Number.isNaN(n)) {
    return amount
  }
  return new Intl.NumberFormat('en-NG', {
    style: 'currency',
    currency: 'NGN',
    minimumFractionDigits: 2,
  }).format(n)
}

export function catalogueQuery(params: {
  q?: string
  categoryId?: number | null
  storeId?: number | null
  page?: number
}): string {
  const search = new URLSearchParams()
  if (params.q?.trim()) search.set('q', params.q.trim())
  if (params.categoryId != null) search.set('categoryId', String(params.categoryId))
  if (params.storeId != null) search.set('storeId', String(params.storeId))
  if (params.page != null && params.page > 1) search.set('page', String(params.page))
  const qs = search.toString()
  return qs ? `?${qs}` : ''
}

export const catalogueApi = {
  categories: (init?: RequestInit) =>
    api.get<{ data: CatalogueCategory[] }>('/portal/catalogue/categories', init),
  products: (
    params: { q?: string; categoryId?: number | null; storeId?: number | null; page?: number } = {},
    init?: RequestInit,
  ) => api.get<CatalogueListResponse>(`/portal/catalogue/products${catalogueQuery(params)}`, init),
  product: (id: number, storeId?: number | null, init?: RequestInit) =>
    api.get<{ data: CatalogueProductDetail }>(
      `/portal/catalogue/products/${id}${catalogueQuery({ storeId: storeId ?? undefined })}`,
      init,
    ),
}

export type CartTotals = {
  subtotal: string
  discountTotal: string
  taxTotal: string
  deliveryFee: string
  grandTotal: string
}

export type CartLine = {
  id: number
  variantId: number
  productId: number
  productName: string
  variantName: string
  sku: string
  imageUrl: string | null
  qty: string
  unitListPrice: string
  unitNetPrice: string
  unitGrossPrice: string
  lineTax: string
  lineDiscount: string
  lineTotal: string
}

export type CartPayload = {
  lines: CartLine[]
  totals: CartTotals
  store: { id: number; name: string } | null
}

export type PortalStore = {
  id: number
  name: string
  code: string | null
}

export type FulfilmentMode = 'home_delivery' | 'click_collect' | 'store_pickup'
export type PaymentProvider = 'manual' | 'stripe' | 'paystack'

export type CheckoutPreview = {
  lines: CartLine[]
  totals: CartTotals
  fulfilment: {
    mode: FulfilmentMode
    addressId: number | null
    contactPhone: string | null
  }
  store: { id: number; name: string }
}

export type OrderTimelineStep = {
  key: string
  label: string
  state: 'completed' | 'current' | 'upcoming'
  detail: string | null
  at: string | null
}

export type PortalOrderListItem = {
  id: number
  orderNumber: string
  status: string
  statusLabel: string
  paymentStatus: string
  fulfilmentMode: FulfilmentMode
  store: { id: number; name: string | null }
  totals: { grandTotal: string }
  invoiceAvailable: boolean
  createdAt: string | null
  paidAt: string | null
}

export type PortalReturnRequestSummary = {
  id: number
  status: string
  statusLabel: string
  reason: string
  createdAt: string | null
}

export type PortalReturnRequestListItem = {
  id: number
  orderId: number
  orderNumber: string | null
  status: string
  statusLabel: string
  reason: string
  reasonLabel: string
  createdAt: string | null
}

export type PortalReturnRequest = {
  id: number
  orderId: number
  orderNumber: string | null
  status: string
  statusLabel: string
  reason: string
  reasonLabel: string
  notes: string | null
  createdAt: string | null
  items: Array<{
    id: number
    orderItemId: number
    qty: string
    productName: string | null
    variantName: string | null
    sku: string | null
  }>
  refunds: unknown[]
}

export type PortalReturnReason = 'defective' | 'wrong_item' | 'changed_mind' | 'other'

export type PortalOrder = {
  id: number
  uuid: string
  orderNumber: string
  status: string
  statusLabel?: string
  paymentStatus: string
  fulfilmentMode: FulfilmentMode
  store: { id: number; name: string | null }
  addressId: number | null
  address?: {
    line1: string | null
    line2: string | null
    city: string | null
    region: string | null
    postalCode: string | null
    country: string | null
  } | null
  contactPhone: string | null
  totals: CartTotals
  paymentProvider: PaymentProvider | string | null
  paymentReference: string | null
  paidAt?: string | null
  createdAt?: string | null
  invoiceAvailable?: boolean
  cancellable?: boolean
  reservationExpiresAt?: string | null
  returnEligible?: boolean
  activeReturnRequest?: PortalReturnRequestSummary | null
  returnRequest?: PortalReturnRequestSummary | null
  refunds?: unknown[]
  timeline?: OrderTimelineStep[]
  customer?: { name: string | null; email: string | null }
  items: Array<{
    id: number
    variantId: number
    productName: string
    variantName: string
    sku: string
    qty: string
    returnableQty?: string
    unitGrossPrice: string
    lineTax: string
    lineTotal: string
  }>
  payment?: {
    provider: string
    reference: string
    status: string
    redirectUrl: string | null
    requiresClientAction: boolean
    meta: Record<string, unknown>
  }
}

export const cartApi = {
  get: (storeId?: number | null, init?: RequestInit) =>
    api.get<{ data: CartPayload }>(
      `/portal/cart${catalogueQuery({ storeId: storeId ?? undefined })}`,
      init,
    ),
  add: (variantId: number, qty: number, storeId?: number | null) =>
    api.post<{ data: CartPayload }>('/portal/cart/items', {
      variantId,
      qty,
      ...(storeId != null ? { storeId } : {}),
    }),
  update: (lineId: number, qty: number, storeId?: number | null) =>
    api.patch<{ data: CartPayload }>(`/portal/cart/items/${lineId}`, {
      qty,
      ...(storeId != null ? { storeId } : {}),
    }),
  remove: (lineId: number, storeId?: number | null) =>
    api.delete<{ data: CartPayload }>(
      `/portal/cart/items/${lineId}${catalogueQuery({ storeId: storeId ?? undefined })}`,
    ),
}

export const checkoutApi = {
  stores: (init?: RequestInit) => api.get<{ data: PortalStore[] }>('/portal/stores', init),
  preview: (payload: {
    fulfilmentMode: FulfilmentMode
    storeId?: number | null
    addressId?: number | null
    contactPhone?: string | null
  }) => api.post<{ data: CheckoutPreview }>('/portal/checkout/preview', payload),
  place: (
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
  ) =>
    api.post<{ data: PortalOrder }>('/portal/checkout/place', payload, {
      headers: { 'Idempotency-Key': idempotencyKey },
    }),
  confirmManual: (orderId: number) =>
    api.post<{ data: PortalOrder }>(`/portal/orders/${orderId}/confirm-payment`),
  order: (orderId: number) => api.get<{ data: PortalOrder }>(`/portal/orders/${orderId}`),
}

export type OrdersListResponse = {
  data: PortalOrderListItem[]
  meta: {
    currentPage: number
    lastPage: number
    total: number
  }
}

function parseContentDispositionFilename(disposition: string, fallback: string): string {
  const star = /filename\*\s*=\s*(?:UTF-8''|utf-8'')([^;\s]+)|filename\*\s*=\s*([^;\s]+)/i.exec(
    disposition,
  )
  if (star) {
    const raw = (star[1] ?? star[2] ?? '').trim().replace(/^"|"$/g, '')
    try {
      return decodeURIComponent(raw) || fallback
    } catch {
      return raw || fallback
    }
  }

  const quoted = /filename\s*=\s*"([^"]+)"/i.exec(disposition)
  if (quoted?.[1]) {
    return quoted[1]
  }

  const plain = /filename\s*=\s*([^;]+)/i.exec(disposition)
  if (plain?.[1]) {
    return plain[1].trim().replace(/^"|"$/g, '') || fallback
  }

  return fallback
}

function looksLikeJsonBody(contentType: string, sample: string): boolean {
  if (contentType.includes('application/json')) {
    return true
  }
  const trimmed = sample.trimStart()
  return trimmed.startsWith('{') || trimmed.startsWith('[')
}

async function downloadBlob(path: string, fallbackFilename: string): Promise<void> {
  const base = import.meta.env.VITE_API_BASE_URL?.replace(/\/$/, '') ?? '/api/v1'
  const headers = new Headers({ Accept: 'text/html,application/json' })
  const token = getToken()
  if (token) {
    headers.set('Authorization', `Bearer ${token}`)
  }

  const response = await fetch(`${base}${path}`, {
    headers,
    signal: AbortSignal.timeout(30_000),
  })

  const contentType = response.headers.get('Content-Type') ?? ''

  if (!response.ok) {
    const body = (await response.json().catch(() => ({}))) as ApiErrorBody
    throw new ApiError(response.status, body)
  }

  const buffer = await response.arrayBuffer()
  const sample = new TextDecoder().decode(buffer.slice(0, 64))
  if (looksLikeJsonBody(contentType, sample)) {
    let body: ApiErrorBody = { message: 'Unexpected JSON response for invoice download.' }
    try {
      body = JSON.parse(new TextDecoder().decode(buffer)) as ApiErrorBody
    } catch {
      /* keep default */
    }
    throw new ApiError(response.status, body)
  }

  const disposition = response.headers.get('Content-Disposition') ?? ''
  const filename = parseContentDispositionFilename(disposition, fallbackFilename)
  const blob = new Blob([buffer], {
    type: contentType.includes('text/html') ? 'text/html' : contentType || 'text/html',
  })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  // Delay revoke so the browser can start the download (Safari/Firefox).
  window.setTimeout(() => URL.revokeObjectURL(url), 1500)
}

export const ordersApi = {
  list: (page = 1, init?: RequestInit) => {
    const safePage = Math.max(1, Math.floor(Number(page)) || 1)
    return api.get<OrdersListResponse>(
      `/portal/orders${safePage > 1 ? `?page=${safePage}` : ''}`,
      init,
    )
  },
  get: (orderId: number, init?: RequestInit) =>
    api.get<{ data: PortalOrder }>(`/portal/orders/${orderId}`, init),
  cancel: (orderId: number) =>
    api.post<{ data: PortalOrder }>(`/portal/orders/${orderId}/cancel`),
  downloadInvoice: (orderId: number, orderNumber?: string) =>
    downloadBlob(
      `/portal/orders/${orderId}/invoice`,
      `invoice-${orderNumber ?? orderId}.html`,
    ),
}

export type ReturnRequestsListResponse = {
  data: PortalReturnRequestListItem[]
  meta: {
    currentPage: number
    lastPage: number
    total: number
  }
}

export const returnsApi = {
  list: (page = 1, init?: RequestInit) => {
    const safePage = Math.max(1, Math.floor(Number(page)) || 1)
    return api.get<ReturnRequestsListResponse>(
      `/portal/return-requests${safePage > 1 ? `?page=${safePage}` : ''}`,
      init,
    )
  },
  get: (id: number, init?: RequestInit) =>
    api.get<{ data: PortalReturnRequest }>(`/portal/return-requests/${id}`, init),
  create: (
    payload: {
      orderId: number
      reason: PortalReturnReason
      notes?: string | null
      items: Array<{ orderItemId: number; qty: number }>
    },
    idempotencyKey: string,
  ) =>
    api.post<{ data: PortalReturnRequest }>('/portal/return-requests', payload, {
      headers: { 'Idempotency-Key': idempotencyKey },
    }),
}

export type PortalSupportStore = {
  id: number
  name: string
  code: string
  address: string | null
}

export type PortalSupportInfo = {
  organizationName: string
  supportEmail: string | null
  supportPhone: string | null
  businessHours: string | null
  stores: PortalSupportStore[]
}

export type PortalChatProductMatch = {
  id: number
  name: string
  sku: string
  imageUrl: string | null
  price: CataloguePrice | null
}

export type PortalChatResponse = {
  reply: string
  suggestions: string[]
  productMatches: PortalChatProductMatch[]
}

export const supportApi = {
  info: (init?: RequestInit) =>
    api.get<{ data: PortalSupportInfo }>('/portal/support/info', init),
  contact: (payload: {
    name: string
    email: string
    phone?: string | null
    subject: string
    message: string
  }) =>
    api.post<{ data: { id: number; message: string } }>('/portal/contact', payload),
  chat: (message: string) =>
    api.post<{ data: PortalChatResponse }>('/portal/chat', { message }),
}
