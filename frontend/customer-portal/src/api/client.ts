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
  get: <T>(path: string) => request<T>(path),
  post: <T>(path: string, data?: unknown) =>
    request<T>(path, {
      method: 'POST',
      body: data ? JSON.stringify(data) : undefined,
    }),
  patch: <T>(path: string, data?: unknown) =>
    request<T>(path, {
      method: 'PATCH',
      body: data ? JSON.stringify(data) : undefined,
    }),
  delete: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
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
