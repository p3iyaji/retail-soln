<script setup lang="ts">
import { ref } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const name = ref('')
const email = ref('')
const phone = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const submitting = ref(false)

async function onSubmit() {
  submitting.value = true
  try {
    await auth.register(name.value, email.value, password.value, passwordConfirmation.value, phone.value)
    await router.replace({ name: 'verify' })
  } catch {
    // auth.error
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-[radial-gradient(circle_at_top,_#dce4ea,_#f4f6f8_45%)] px-4 py-10">
    <div class="w-full max-w-md border border-[var(--line)] bg-[var(--panel)] p-8 shadow-sm">
      <img src="/logo.png" alt="Bag & Cartons Ltd" class="mx-auto h-28 w-auto object-contain sm:h-32" />
      <p class="brand mt-4 text-xs font-semibold tracking-[0.18em] text-[var(--brand)] uppercase">Shop</p>
      <h1 class="mt-2 text-2xl font-semibold tracking-tight">Create account</h1>
      <p class="mt-2 text-sm text-[var(--muted)]">Register to manage your profile and delivery addresses.</p>

      <form class="mt-8 space-y-4" @submit.prevent="onSubmit">
        <label class="block text-sm">
          <span class="mb-1 block text-[var(--muted)]">Full name</span>
          <input
            v-model="name"
            required
            autocomplete="name"
            class="w-full rounded-md border border-[var(--line)] px-3 py-2 outline-none focus:border-[var(--brand)]"
          />
        </label>
        <label class="block text-sm">
          <span class="mb-1 block text-[var(--muted)]">Email</span>
          <input
            v-model="email"
            type="email"
            required
            autocomplete="email"
            class="w-full rounded-md border border-[var(--line)] px-3 py-2 outline-none focus:border-[var(--brand)]"
          />
        </label>
        <label class="block text-sm">
          <span class="mb-1 block text-[var(--muted)]">Phone (optional)</span>
          <input
            v-model="phone"
            type="tel"
            autocomplete="tel"
            class="w-full rounded-md border border-[var(--line)] px-3 py-2 outline-none focus:border-[var(--brand)]"
          />
        </label>
        <label class="block text-sm">
          <span class="mb-1 block text-[var(--muted)]">Password</span>
          <input
            v-model="password"
            type="password"
            required
            autocomplete="new-password"
            class="w-full rounded-md border border-[var(--line)] px-3 py-2 outline-none focus:border-[var(--brand)]"
          />
        </label>
        <label class="block text-sm">
          <span class="mb-1 block text-[var(--muted)]">Confirm password</span>
          <input
            v-model="passwordConfirmation"
            type="password"
            required
            autocomplete="new-password"
            class="w-full rounded-md border border-[var(--line)] px-3 py-2 outline-none focus:border-[var(--brand)]"
          />
        </label>

        <p v-if="auth.error" class="text-sm text-[var(--danger)]">{{ auth.error }}</p>

        <button
          type="submit"
          class="w-full rounded-md bg-[var(--brand)] px-4 py-2.5 text-sm font-semibold text-[var(--brand-fg)] disabled:opacity-60"
          :disabled="submitting"
        >
          {{ submitting ? 'Creating…' : 'Register' }}
        </button>
      </form>

      <p class="mt-6 text-sm text-[var(--muted)]">
        Already registered?
        <RouterLink class="font-medium text-[var(--brand)]" :to="{ name: 'login' }">Sign in</RouterLink>
      </p>
    </div>
  </div>
</template>
