import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import {
  api,
  ApiError,
  hasToken,
  setToken,
  type AuthUser,
  type LoginResponse,
} from '@/api/client'
import { useAppearanceStore } from '@/stores/appearance'

export const useAuthStore = defineStore('auth', () => {
  const tokenPresent = ref(hasToken())
  const user = ref<AuthUser | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  const isAuthenticated = computed(() => tokenPresent.value)
  const displayName = computed(() => user.value?.name ?? user.value?.email ?? 'Admin')

  async function login(email: string, password: string): Promise<'ok' | 'two-factor'> {
    loading.value = true
    error.value = null

    try {
      const response = await api.post<LoginResponse>('/auth/login', { email, password })

      if ('twoFactorRequired' in response && response.twoFactorRequired) {
        sessionStorage.setItem('retail_soln_2fa_challenge', response.challengeToken)
        return 'two-factor'
      }

      if ('token' in response) {
        setToken(response.token)
        tokenPresent.value = true
        user.value = response.user
        useAppearanceStore().load(response.user.id)
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

  async function completeTwoFactor(code: string): Promise<void> {
    loading.value = true
    error.value = null

    try {
      const challengeToken = sessionStorage.getItem('retail_soln_2fa_challenge')
      if (!challengeToken) {
        throw new Error('Missing two-factor challenge')
      }

      const response = await api.post<{ token: string; user: AuthUser }>(
        '/auth/two-factor-challenge',
        { challengeToken, code },
      )

      setToken(response.token)
      tokenPresent.value = true
      user.value = response.user
      sessionStorage.removeItem('retail_soln_2fa_challenge')
      useAppearanceStore().load(response.user.id)
    } catch (err) {
      error.value = err instanceof ApiError ? err.message : 'Two-factor failed'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function fetchMe(): Promise<void> {
    if (!tokenPresent.value) {
      user.value = null
      return
    }

    const response = await api.get<{ data: AuthUser }>('/auth/me')
    user.value = response.data
    useAppearanceStore().load(response.data.id)
  }

  async function logout(): Promise<void> {
    try {
      if (tokenPresent.value) {
        await api.post('/auth/logout')
      }
    } catch {
      // Ignore network errors on logout; clear local session anyway.
    } finally {
      setToken(null)
      tokenPresent.value = false
      user.value = null
      sessionStorage.removeItem('retail_soln_2fa_challenge')
    }
  }

  return {
    user,
    loading,
    error,
    isAuthenticated,
    displayName,
    login,
    completeTwoFactor,
    fetchMe,
    logout,
  }
})
