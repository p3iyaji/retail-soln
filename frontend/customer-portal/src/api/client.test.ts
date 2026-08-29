import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  hasToken,
  setToken,
  api,
  cartApi,
  catalogueApi,
  catalogueQuery,
  checkoutApi,
  formatPortalMoney,
} from '@/api/client'

describe('portal client', () => {
  afterEach(() => {
    setToken(null)
    vi.unstubAllGlobals()
  })

  it('stores and clears the portal bearer token under a distinct key', () => {
    setToken(null)
    expect(hasToken()).toBe(false)
    setToken('portal-test-token')
    expect(hasToken()).toBe(true)
    expect(localStorage.getItem('retail_soln_portal_token')).toBe('portal-test-token')
    setToken(null)
    expect(hasToken()).toBe(false)
  })

  it('sends Authorization Bearer when a token is stored', async () => {
    setToken('portal-bearer-xyz')

    const fetchMock = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: { ok: true } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    )
    vi.stubGlobal('fetch', fetchMock)

    await api.get('/portal/auth/me')

    expect(fetchMock).toHaveBeenCalledOnce()
    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    const headers = new Headers(init.headers)
    expect(headers.get('Authorization')).toBe('Bearer portal-bearer-xyz')
  })

  it('builds catalogue query strings for search and filters', () => {
    expect(catalogueQuery({})).toBe('')
    expect(catalogueQuery({ q: ' milk ', categoryId: 3, page: 2 })).toBe(
      '?q=milk&categoryId=3&page=2',
    )
  })

  it('formats money and empty amounts', () => {
    expect(formatPortalMoney('1850.0000')).toMatch(/1,850/)
    expect(formatPortalMoney(null)).toBe('—')
    expect(formatPortalMoney('')).toBe('—')
  })

  it('catalogueApi hits portal catalogue paths', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [], meta: { currentPage: 1, lastPage: 1, total: 0 } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    )
    vi.stubGlobal('fetch', fetchMock)

    await catalogueApi.categories()
    await catalogueApi.products({ q: 'milk', categoryId: 2 })
    await catalogueApi.product(42)

    const paths = fetchMock.mock.calls.map((call) => call[0] as string)
    expect(paths[0]).toContain('/portal/catalogue/categories')
    expect(paths[1]).toContain('/portal/catalogue/products?q=milk&categoryId=2')
    expect(paths[2]).toContain('/portal/catalogue/products/42')
  })

  it('cart and checkout APIs hit portal paths with Idempotency-Key', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: { lines: [], totals: {} } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    )
    vi.stubGlobal('fetch', fetchMock)

    await cartApi.add(9, 2)
    await checkoutApi.place(
      {
        fulfilmentMode: 'store_pickup',
        storeId: 1,
        paymentProvider: 'manual',
      },
      'idem-key-abc',
    )

    const addInit = fetchMock.mock.calls[0][1] as RequestInit
    expect(fetchMock.mock.calls[0][0]).toContain('/portal/cart/items')
    expect(JSON.parse(addInit.body as string)).toEqual({ variantId: 9, qty: 2 })

    const placeInit = fetchMock.mock.calls[1][1] as RequestInit
    const placeHeaders = new Headers(placeInit.headers)
    expect(fetchMock.mock.calls[1][0]).toContain('/portal/checkout/place')
    expect(placeHeaders.get('Idempotency-Key')).toBe('idem-key-abc')
  })
})
