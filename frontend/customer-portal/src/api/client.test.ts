import { afterEach, describe, expect, it, vi } from 'vitest'
import { hasToken, setToken, api } from '@/api/client'

describe('portal token storage', () => {
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
})
