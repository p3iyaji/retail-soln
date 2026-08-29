import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import {
  api,
  ApiError,
  hasToken,
  setToken,
  type LoginResponse,
  type PortalAccount,
  type PortalAddress,
  type RegisterResponse,
  type VerifyResponse,
} from '@/api/client'

export const useAuthStore = defineStore('auth', () => {
  const tokenPresent = ref(hasToken())
  const account = ref<PortalAccount | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const pendingVerificationEmail = ref<string | null>(null)
  const pendingVerificationToken = ref<string | null>(null)

  const isAuthenticated = computed(() => tokenPresent.value && Boolean(account.value?.emailVerified))
  const displayName = computed(
    () => account.value?.customer?.name ?? account.value?.email ?? 'Account',
  )

  function clearPendingVerification(): void {
    pendingVerificationEmail.value = null
    pendingVerificationToken.value = null
  }

  function captureVerification(response: RegisterResponse): void {
    pendingVerificationEmail.value = response.email
    pendingVerificationToken.value = response.verificationToken ?? null
    if (response.verificationToken) {
      sessionStorage.setItem('retail_soln_portal_verify_token', response.verificationToken)
      sessionStorage.setItem('retail_soln_portal_verify_email', response.email)
    }
  }

  async function register(
    name: string,
    email: string,
    password: string,
    passwordConfirmation: string,
    phone?: string,
  ): Promise<'verify'> {
    loading.value = true
    error.value = null
    try {
      const response = await api.post<RegisterResponse>('/portal/auth/register', {
        name,
        email,
        password,
        password_confirmation: passwordConfirmation,
        phone: phone || null,
      })
      captureVerification(response)
      return 'verify'
    } catch (err) {
      error.value = err instanceof ApiError ? err.message : 'Registration failed'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function login(email: string, password: string): Promise<'ok' | 'verify'> {
    loading.value = true
    error.value = null
    try {
      const response = await api.post<LoginResponse>('/portal/auth/login', { email, password })

      if ('verificationRequired' in response && response.verificationRequired) {
        captureVerification(response)
        return 'verify'
      }

      if ('token' in response) {
        setToken(response.token)
        tokenPresent.value = true
        account.value = response.account
        clearPendingVerification()
        return 'ok'
      }

      throw new Error('Unexpected login response')
    } catch (err) {
      error.value = err instanceof ApiError ? err.message : 'Login failed'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function verify(email: string, token: string): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const response = await api.post<VerifyResponse>('/portal/auth/verify', { email, token })
      setToken(response.token)
      tokenPresent.value = true
      account.value = response.account
      clearPendingVerification()
      sessionStorage.removeItem('retail_soln_portal_verify_token')
      sessionStorage.removeItem('retail_soln_portal_verify_email')
    } catch (err) {
      error.value = err instanceof ApiError ? err.message : 'Verification failed'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function fetchMe(): Promise<void> {
    if (!tokenPresent.value) {
      account.value = null
      return
    }

    const response = await api.get<{ data: PortalAccount }>('/portal/auth/me')
    account.value = response.data
  }

  async function logout(): Promise<void> {
    try {
      if (tokenPresent.value) {
        await api.post('/portal/auth/logout')
      }
    } catch {
      // clear local session anyway
    }
    setToken(null)
    tokenPresent.value = false
    account.value = null
  }

  async function updateProfile(name: string, phone: string | null): Promise<void> {
    const response = await api.patch<{ data: PortalAccount }>('/portal/account/profile', {
      name,
      phone,
    })
    account.value = response.data
  }

  async function listAddresses(): Promise<PortalAddress[]> {
    const response = await api.get<{ data: PortalAddress[] }>('/portal/account/addresses')
    return response.data
  }

  async function createAddress(payload: {
    line1: string
    line2?: string | null
    city?: string | null
    region?: string | null
    postalCode?: string | null
    country?: string | null
    type?: string
    isDefault?: boolean
  }): Promise<PortalAddress> {
    const response = await api.post<{ data: PortalAddress }>('/portal/account/addresses', payload)
    await fetchMe()
    return response.data
  }

  async function updateAddress(
    id: number,
    payload: Partial<{
      line1: string
      line2: string | null
      city: string | null
      region: string | null
      postalCode: string | null
      country: string | null
      type: string
      isDefault: boolean
    }>,
  ): Promise<PortalAddress> {
    const response = await api.patch<{ data: PortalAddress }>(
      `/portal/account/addresses/${id}`,
      payload,
    )
    await fetchMe()
    return response.data
  }

  async function deleteAddress(id: number): Promise<void> {
    await api.delete(`/portal/account/addresses/${id}`)
    await fetchMe()
  }

  return {
    tokenPresent,
    account,
    loading,
    error,
    pendingVerificationEmail,
    pendingVerificationToken,
    isAuthenticated,
    displayName,
    register,
    login,
    verify,
    fetchMe,
    logout,
    updateProfile,
    listAddresses,
    createAddress,
    updateAddress,
    deleteAddress,
  }
})
