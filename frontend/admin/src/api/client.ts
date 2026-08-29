const TOKEN_KEY = 'retail_soln_admin_token'

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
  get: <T>(path: string, headers?: Record<string, string>) =>
    request<T>(path, { headers }),
  post: <T>(path: string, data?: unknown, headers?: Record<string, string>) =>
    request<T>(path, {
      method: 'POST',
      body: data ? JSON.stringify(data) : undefined,
      headers,
    }),
  patch: <T>(path: string, data?: unknown, headers?: Record<string, string>) =>
    request<T>(path, {
      method: 'PATCH',
      body: data ? JSON.stringify(data) : undefined,
      headers,
    }),
  delete: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
  upload: <T>(path: string, formData: FormData) =>
    request<T>(path, { method: 'POST', body: formData }, false),
  download: async (
    path: string,
    filename: string,
    extraHeaders?: Record<string, string>,
  ): Promise<void> => {
    const base = import.meta.env.VITE_API_BASE_URL?.replace(/\/$/, '') ?? '/api/v1'
    const headers = new Headers({ Accept: '*/*', ...extraHeaders })
    const token = getToken()
    if (token) headers.set('Authorization', `Bearer ${token}`)
    const response = await fetch(`${base}${path}`, { headers })
    if (!response.ok) {
      const body = (await response.json().catch(() => ({}))) as ApiErrorBody
      throw new ApiError(response.status, body)
    }
    const blob = await response.blob()
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    a.click()
    URL.revokeObjectURL(url)
  },
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
