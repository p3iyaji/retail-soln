<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const nav = [
  { name: 'organization', label: 'Organization', to: '/organization' },
  { name: 'stores', label: 'Stores', to: '/stores' },
  { name: 'products', label: 'Products', to: '/products' },
  { name: 'suppliers', label: 'Suppliers', to: '/suppliers' },
  { name: 'inventory', label: 'Inventory', to: '/inventory' },
  { name: 'users', label: 'Users', to: '/users' },
  { name: 'settings', label: 'Settings', to: '/settings' },
]

const title = computed(() => {
  const match = nav.find((item) => item.name === route.name)
  if (route.name === 'dashboard') return 'Dashboard'
  return match?.label ?? 'Admin'
})

const loggingOut = ref(false)

async function onLogout() {
  loggingOut.value = true
  try {
    await auth.logout()
    await router.push({ name: 'login' })
  } finally {
    loggingOut.value = false
  }
}
</script>

<template>
  <div class="min-h-screen lg:grid lg:grid-cols-[240px_1fr]">
    <aside class="border-b border-[var(--line)] bg-[var(--panel)] lg:min-h-screen lg:border-b-0 lg:border-r">
      <div class="flex items-center justify-between px-5 py-4 lg:block">
        <div>
          <p class="text-xs font-semibold tracking-[0.16em] text-[var(--brand)] uppercase">Retail Soln</p>
          <p class="mt-1 text-sm text-[var(--muted)]">Admin</p>
        </div>
      </div>

      <nav class="flex gap-1 overflow-x-auto px-3 pb-3 lg:flex-col lg:overflow-visible lg:px-3 lg:pb-6">
        <RouterLink
          to="/"
          class="rounded-md px-3 py-2 text-sm whitespace-nowrap transition"
          :class="route.name === 'dashboard' ? 'bg-teal-50 font-medium text-[var(--brand-ink)]' : 'text-[var(--muted)] hover:bg-stone-50 hover:text-[var(--ink)]'"
        >
          Dashboard
        </RouterLink>
        <RouterLink
          v-for="item in nav"
          :key="item.name"
          :to="item.to"
          class="rounded-md px-3 py-2 text-sm whitespace-nowrap transition"
          :class="route.name === item.name ? 'bg-teal-50 font-medium text-[var(--brand-ink)]' : 'text-[var(--muted)] hover:bg-stone-50 hover:text-[var(--ink)]'"
        >
          {{ item.label }}
        </RouterLink>
      </nav>
    </aside>

    <div class="flex min-h-screen flex-col">
      <header class="flex items-center justify-between gap-4 border-b border-[var(--line)] bg-[var(--panel)] px-5 py-3">
        <div>
          <h1 class="text-lg font-semibold tracking-tight">{{ title }}</h1>
          <p class="text-xs text-[var(--muted)]">{{ auth.user?.role ?? 'signed in' }}</p>
        </div>
        <div class="flex items-center gap-3">
          <span class="hidden text-sm text-[var(--muted)] sm:inline">{{ auth.displayName }}</span>
          <button
            type="button"
            class="rounded-md border border-[var(--line)] px-3 py-1.5 text-sm hover:bg-stone-50 disabled:opacity-60"
            :disabled="loggingOut"
            @click="onLogout"
          >
            Log out
          </button>
        </div>
      </header>

      <main class="flex-1 px-5 py-6">
        <RouterView />
      </main>
    </div>
  </div>
</template>
