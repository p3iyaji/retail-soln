<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import type { PortalAddress } from '@/api/client'

const auth = useAuthStore()

const addresses = ref<PortalAddress[]>([])
const line1 = ref('')
const city = ref('')
const loading = ref(false)
const error = ref<string | null>(null)

async function refresh() {
  addresses.value = await auth.listAddresses()
}

onMounted(() => {
  void refresh()
})

async function addAddress() {
  loading.value = true
  error.value = null
  try {
    await auth.createAddress({
      line1: line1.value,
      city: city.value || null,
      type: 'shipping',
      isDefault: addresses.value.length === 0,
    })
    line1.value = ''
    city.value = ''
    await refresh()
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Could not add address'
  } finally {
    loading.value = false
  }
}

async function removeAddress(id: number) {
  error.value = null
  try {
    await auth.deleteAddress(id)
    await refresh()
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Could not delete address'
  }
}
</script>

<template>
  <div class="min-h-screen bg-[var(--surface)]">
    <header class="border-b border-[var(--line)] bg-[var(--panel)]">
      <div class="mx-auto flex max-w-3xl items-center justify-between px-4 py-4">
        <div class="flex items-center gap-3">
          <img src="/logo.png" alt="Bag & Cartons Ltd" class="h-12 w-auto object-contain sm:h-14" />
          <h1 class="text-lg font-semibold">Addresses</h1>
        </div>
        <RouterLink class="text-sm text-[var(--brand)]" :to="{ name: 'account' }">Back to account</RouterLink>
      </div>
    </header>

    <main class="mx-auto max-w-3xl px-4 py-8">
      <ul v-if="addresses.length" class="space-y-3">
        <li
          v-for="addr in addresses"
          :key="addr.id"
          class="flex items-start justify-between border border-[var(--line)] bg-[var(--panel)] px-4 py-3"
        >
          <div>
            <p class="font-medium">{{ addr.line1 }}</p>
            <p class="text-sm text-[var(--muted)]">
              {{ [addr.city, addr.region, addr.postalCode, addr.country].filter(Boolean).join(', ') || '—' }}
            </p>
            <p class="mt-1 text-xs uppercase tracking-wide text-[var(--muted)]">
              {{ addr.type }}{{ addr.isDefault ? ' · default' : '' }}
            </p>
          </div>
          <button type="button" class="text-sm text-[var(--danger)]" @click="removeAddress(addr.id)">Delete</button>
        </li>
      </ul>
      <p v-else class="text-sm text-[var(--muted)]">No addresses yet.</p>

      <form class="mt-8 max-w-lg space-y-4 border border-[var(--line)] bg-[var(--panel)] p-6" @submit.prevent="addAddress">
        <h2 class="text-base font-semibold">Add address</h2>
        <label class="block text-sm">
          <span class="mb-1 block text-[var(--muted)]">Line 1</span>
          <input
            v-model="line1"
            required
            class="w-full rounded-md border border-[var(--line)] px-3 py-2 outline-none focus:border-[var(--brand)]"
          />
        </label>
        <label class="block text-sm">
          <span class="mb-1 block text-[var(--muted)]">City</span>
          <input
            v-model="city"
            class="w-full rounded-md border border-[var(--line)] px-3 py-2 outline-none focus:border-[var(--brand)]"
          />
        </label>
        <p v-if="error" class="text-sm text-[var(--danger)]">{{ error }}</p>
        <button
          type="submit"
          class="rounded-md bg-[var(--brand)] px-4 py-2 text-sm font-semibold text-[var(--brand-fg)] disabled:opacity-60"
          :disabled="loading"
        >
          {{ loading ? 'Adding…' : 'Add address' }}
        </button>
      </form>
    </main>
  </div>
</template>
