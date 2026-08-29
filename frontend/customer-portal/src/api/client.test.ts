import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  hasToken,
  setToken,
  api,
  catalogueApi,
  catalogueQuery,
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
})
