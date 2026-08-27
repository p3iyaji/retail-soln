<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const email = ref('')
const password = ref('')
const code = ref('')
const needsTwoFactor = ref(false)
const submitting = ref(false)

async function onSubmit() {
  submitting.value = true
  try {
    if (needsTwoFactor.value) {
      await auth.completeTwoFactor(code.value)
      await router.replace((route.query.redirect as string) || '/')
      return
    }

    const result = await auth.login(email.value, password.value)
    if (result === 'two-factor') {
      needsTwoFactor.value = true
      return
    }

    await router.replace((route.query.redirect as string) || '/')
  } catch {
    // Error shown via auth.error
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-[radial-gradient(circle_at_top,_#dceae6,_#f4f6f5_45%)] px-4">
    <div class="w-full max-w-md border border-[var(--line)] bg-[var(--panel)] p-8 shadow-sm">
      <p class="text-xs font-semibold tracking-[0.18em] text-[var(--brand)] uppercase">Retail Soln</p>
      <h1 class="mt-2 text-2xl font-semibold tracking-tight">Admin sign in</h1>
      <p class="mt-2 text-sm text-[var(--muted)]">
        Use your organization credentials. Tokens are stored locally for API calls.
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
              class="w-full rounded-md border border-[var(--line)] px-3 py-2 outline-none focus:border-[var(--brand)]"
            />
          </label>
          <label class="block text-sm">
            <span class="mb-1 block text-[var(--muted)]">Password</span>
            <input
              v-model="password"
              type="password"
              required
              autocomplete="current-password"
              class="w-full rounded-md border border-[var(--line)] px-3 py-2 outline-none focus:border-[var(--brand)]"
            />
          </label>
        </template>

        <label v-else class="block text-sm">
          <span class="mb-1 block text-[var(--muted)]">Authentication code</span>
          <input
            v-model="code"
            type="text"
            inputmode="numeric"
            required
            autocomplete="one-time-code"
            class="w-full rounded-md border border-[var(--line)] px-3 py-2 outline-none focus:border-[var(--brand)]"
          />
        </label>

        <p v-if="auth.error" class="text-sm text-red-700">{{ auth.error }}</p>

        <button
          type="submit"
          class="w-full rounded-md bg-[var(--brand)] px-4 py-2.5 text-sm font-medium text-white hover:bg-[var(--brand-ink)] disabled:opacity-60"
          :disabled="submitting || auth.loading"
        >
          {{ needsTwoFactor ? 'Verify' : 'Sign in' }}
        </button>
      </form>
    </div>
  </div>
</template>
