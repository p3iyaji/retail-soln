<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const email = ref('')
const password = ref('')
const submitting = ref(false)

async function onSubmit() {
  submitting.value = true
  try {
    const result = await auth.login(email.value, password.value)
    if (result === 'verify') {
      await router.replace({ name: 'verify' })
      return
    }
    await router.replace((route.query.redirect as string) || '/account')
  } catch {
    // auth.error
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-[radial-gradient(circle_at_top,_#dce4ea,_#f4f6f8_45%)] px-4">
    <div class="w-full max-w-md border border-[var(--line)] bg-[var(--panel)] p-8 shadow-sm">
      <img src="/logo.png" alt="Bag & Cartons Ltd" class="mx-auto h-28 w-auto object-contain sm:h-32" />
      <p class="brand mt-4 text-xs font-semibold tracking-[0.18em] text-[var(--brand)] uppercase">Shop</p>
      <h1 class="mt-2 text-2xl font-semibold tracking-tight">Customer sign in</h1>
      <p class="mt-2 text-sm text-[var(--muted)]">Access your account, profile, and delivery addresses.</p>

      <form class="mt-8 space-y-4" @submit.prevent="onSubmit">
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

        <p v-if="auth.error" class="text-sm text-[var(--danger)]">{{ auth.error }}</p>

        <button
          type="submit"
          class="w-full rounded-md bg-[var(--brand)] px-4 py-2.5 text-sm font-semibold text-[var(--brand-fg)] disabled:opacity-60"
          :disabled="submitting"
        >
          {{ submitting ? 'Signing in…' : 'Sign in' }}
        </button>
      </form>

      <p class="mt-6 text-sm text-[var(--muted)]">
        New here?
        <RouterLink class="font-medium text-[var(--brand)]" :to="{ name: 'register' }">Create an account</RouterLink>
      </p>
    </div>
  </div>
</template>
