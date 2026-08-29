<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const name = ref('')
const phone = ref('')
const saving = ref(false)
const saved = ref(false)
const formError = ref<string | null>(null)

onMounted(() => {
  name.value = auth.account?.customer?.name ?? ''
  phone.value = auth.account?.customer?.phone ?? ''
})

async function saveProfile() {
  saving.value = true
  saved.value = false
  formError.value = null
  try {
    await auth.updateProfile(name.value, phone.value || null)
    saved.value = true
  } catch (err) {
    formError.value = err instanceof Error ? err.message : 'Save failed'
  } finally {
    saving.value = false
  }
}

async function onLogout() {
  await auth.logout()
  await router.replace({ name: 'login' })
}
</script>

<template>
  <div class="min-h-screen bg-[var(--surface)]">
    <header class="border-b border-[var(--line)] bg-[var(--panel)]">
      <div class="mx-auto flex max-w-3xl items-center justify-between px-4 py-4">
        <div>
          <p class="brand text-xs font-semibold tracking-[0.18em] text-[var(--brand)] uppercase">Retail Soln</p>
          <h1 class="text-lg font-semibold">Account</h1>
        </div>
        <nav class="flex items-center gap-4 text-sm">
          <RouterLink class="text-[var(--brand)]" :to="{ name: 'addresses' }">Addresses</RouterLink>
          <button type="button" class="text-[var(--muted)] hover:text-[var(--ink)]" @click="onLogout">Sign out</button>
        </nav>
      </div>
    </header>

    <main class="mx-auto max-w-3xl px-4 py-8">
      <p class="text-sm text-[var(--muted)]">Signed in as {{ auth.account?.email }}</p>

      <form class="mt-6 max-w-lg space-y-4 border border-[var(--line)] bg-[var(--panel)] p-6" @submit.prevent="saveProfile">
        <h2 class="text-base font-semibold">Profile</h2>
        <label class="block text-sm">
          <span class="mb-1 block text-[var(--muted)]">Name</span>
          <input
            v-model="name"
            required
            class="w-full rounded-md border border-[var(--line)] px-3 py-2 outline-none focus:border-[var(--brand)]"
          />
        </label>
        <label class="block text-sm">
          <span class="mb-1 block text-[var(--muted)]">Phone</span>
          <input
            v-model="phone"
            type="tel"
            class="w-full rounded-md border border-[var(--line)] px-3 py-2 outline-none focus:border-[var(--brand)]"
          />
        </label>
        <p v-if="formError" class="text-sm text-[var(--danger)]">{{ formError }}</p>
        <p v-if="saved" class="text-sm text-[var(--brand)]">Saved.</p>
        <button
          type="submit"
          class="rounded-md bg-[var(--brand)] px-4 py-2 text-sm font-semibold text-[var(--brand-fg)] disabled:opacity-60"
          :disabled="saving"
        >
          {{ saving ? 'Saving…' : 'Save profile' }}
        </button>
      </form>

      <p class="mt-8 text-sm text-[var(--muted)]">
        Catalogue, cart, and orders arrive in later stories — this portal is account-only for now.
      </p>
    </main>
  </div>
</template>
