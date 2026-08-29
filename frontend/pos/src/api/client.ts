const TOKEN_KEY = 'retail_soln_pos_token'
const STORE_KEY = 'retail_soln_pos_store_id'
export const TERMINAL_KEY = 'retail_soln_pos_terminal'

export type AuthUser = {
  id: number
  name: string
  email: string
  role: string
  roles: string[]
  permissions: string[]
  storeAccess: Array<{ id: number; name: string; isDefault: boolean }>
}

type ApiErrorBody = {
  message?: string
  code?: string
  errors?: Record<string, string[]>
}

export class ApiError extends Error {
  status: number
  body: ApiErrorBody

  constructor(status: number, body: ApiErrorBody) {
    super(body.message ?? `Request failed (${status})`)
    this.status = status
    this.body = body
  }

  get code(): string | undefined {
    return this.body.code
  }
}

/** Map sync 403 device codes to cashier-facing copy. */
export function formatDeviceSyncError(err: ApiError): string {
  if (err.code === 'device_unregistered') {
    return 'Device is not registered — register this UUID in Admin Devices'
  }
  if (err.code === 'device_disabled') {
    return 'Device is disabled and cannot sync'
  }
  return err.message
}

/** Allowed store IDs from the current user's storeAccess (avoids Pinia import cycle). */
let allowedStoreIds: ReadonlySet<number> = new Set()

export function setAllowedStoreIds(ids: Iterable<number>): void {
  allowedStoreIds = new Set(
    [...ids].filter((id) => Number.isInteger(id) && id > 0),
  )
}

export function clearAllowedStoreIds(): void {
  allowedStoreIds = new Set()
}

export function parsePositiveIntId(value: unknown): number | null {
  if (typeof value === 'number') {
    return Number.isInteger(value) && value > 0 ? value : null
  }
  if (typeof value !== 'string' || value.trim() === '') return null
  const id = Number(value)
  return Number.isInteger(id) && id > 0 ? id : null
}

/** Same-origin relative path only: starts with `/`, not `//`. */
export function safeRedirectPath(redirect: unknown): string | null {
  if (typeof redirect !== 'string') return null
  if (!redirect.startsWith('/') || redirect.startsWith('//')) return null
  return redirect
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

export function getStoredStoreId(): number | null {
  return parsePositiveIntId(localStorage.getItem(STORE_KEY))
}

export function setStoredStoreId(storeId: number | null): void {
  const id = storeId == null ? null : parsePositiveIntId(storeId)
  if (id == null) {
    localStorage.removeItem(STORE_KEY)
  } else {
    localStorage.setItem(STORE_KEY, String(id))
  }
}

function authorizedStoreIdForHeader(): number | null {
  const storeId = getStoredStoreId()
  if (storeId == null) return null
  return allowedStoreIds.has(storeId) ? storeId : null
}

async function request<T>(path: string, init: RequestInit = {}, jsonBody = true): Promise<T> {
  const base = import.meta.env.VITE_API_BASE_URL?.replace(/\/$/, '') ?? '/api/v1'
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  if (jsonBody) {
    headers.set('Content-Type', 'application/json')
  }

  const token = getToken()
  if (token) {
    headers.set('Authorization', `Bearer ${token}`)
  }

  const storeId = authorizedStoreIdForHeader()
  if (storeId != null) {
    headers.set('X-Store-Id', String(storeId))
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
  get: <T>(path: string) => request<T>(path),
  post: <T>(path: string, data?: unknown, headers?: Record<string, string>) =>
    request<T>(path, {
      method: 'POST',
      body: data ? JSON.stringify(data) : undefined,
      headers,
    }),
  patch: <T>(path: string, data?: unknown) =>
    request<T>(path, { method: 'PATCH', body: data ? JSON.stringify(data) : undefined }),
  delete: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
}

export type LoginResponse =
  | {
      token: string
      tokenType: string
      user: AuthUser
    }
  | {
      twoFactorRequired: true
      challengeToken: string
    }
