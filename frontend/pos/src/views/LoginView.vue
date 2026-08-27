<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { safeRedirectPath } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { usePosStore } from '@/stores/pos'

const auth = useAuthStore()
const pos = usePosStore()
const router = useRouter()
const route = useRoute()

const email = ref('')
const password = ref('')
const code = ref('')
const needsTwoFactor = ref(false)
const submitting = ref(false)

async function afterAuth() {
  pos.syncSelectedStoreWithAccess()
  const redirect = safeRedirectPath(route.query.redirect)
  if (redirect && redirect !== '/login') {
    await router.replace(redirect)
    return
  }
  await router.replace(pos.hasSelectedStore ? '/' : '/select-store')
}

function cancelTwoFactor() {
  needsTwoFactor.value = false
  code.value = ''
  auth.error = null
  sessionStorage.removeItem('retail_soln_pos_2fa_challenge')
}

async function onSubmit() {
  submitting.value = true
  try {
    if (needsTwoFactor.value) {
      await auth.completeTwoFactor(code.value)
      await afterAuth()
      return
    }

    const result = await auth.login(email.value, password.value)
    if (result === 'two-factor') {
      needsTwoFactor.value = true
      return
    }

    await afterAuth()
  } catch {
    // Error shown via auth.error
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="flex min-h-full items-center justify-center bg-[radial-gradient(circle_at_top,_#1a2330_0%,_#0b6e4f_55%,_#f4f6f8_55%)] px-4 py-10">
    <div class="w-full max-w-md border border-[var(--line)] bg-[var(--panel)] p-8 shadow-sm">
      <p class="text-xs font-semibold tracking-[0.18em] text-[var(--primary)] uppercase">Retail Soln</p>
      <h1 class="font-display mt-2 text-2xl font-semibold tracking-tight">POS sign in</h1>
      <p class="mt-2 text-sm text-[var(--muted)]">
        Use your cashier credentials. Tokens stay on this device under a POS-only storage key.
      </p>

      <form class="mt-8 space-y-4" @submit.prevent="onSubmit">
        <template v-if="!needsTwoFactor">
          <label class="block text-sm">
            <span class="mb-1 block text-[var(--muted)]">Email</span>
            <input
              v-model="email"
              type="email"
              required
              autocomplete="username"
              class="min-h-11 w-full rounded-[var(--radius)] border border-[var(--line)] px-3 py-2 outline-none focus:border-[var(--primary)]"
            />
          </label>
          <label class="block text-sm">
            <span class="mb-1 block text-[var(--muted)]">Password</span>
            <input
              v-model="password"
              type="password"
              required
              autocomplete="current-password"
              class="min-h-11 w-full rounded-[var(--radius)] border border-[var(--line)] px-3 py-2 outline-none focus:border-[var(--primary)]"
            />
          </label>
        </template>

        <template v-else>
          <label class="block text-sm">
            <span class="mb-1 block text-[var(--muted)]">Authentication code</span>
            <input
              v-model="code"
              type="text"
              inputmode="numeric"
              required
              autocomplete="one-time-code"
              class="min-h-11 w-full rounded-[var(--radius)] border border-[var(--line)] px-3 py-2 outline-none focus:border-[var(--primary)]"
            />
          </label>
          <button
            type="button"
            class="min-h-11 text-sm text-[var(--muted)] underline-offset-2 hover:text-[var(--ink)] hover:underline"
            @click="cancelTwoFactor"
          >
            Back
          </button>
        </template>

        <p v-if="auth.error" class="text-sm text-[var(--danger)]">{{ auth.error }}</p>

        <button
          type="submit"
          class="min-h-11 w-full rounded-[var(--radius)] bg-[var(--primary)] px-4 py-2.5 text-sm font-medium text-[var(--primary-fg)] hover:opacity-95 disabled:opacity-60"
          :disabled="submitting || auth.loading"
        >
          {{ needsTwoFactor ? 'Verify' : 'Sign in' }}
        </button>
      </form>
    </div>
  </div>
</template>
