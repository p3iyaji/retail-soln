<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { hasToken } from '@/api/client'

const route = useRoute()
const router = useRouter()

const searchInput = ref('')

watch(
  () => route.query.q,
  (q) => {
    searchInput.value = typeof q === 'string' ? q : ''
  },
  { immediate: true },
)

const accountHref = computed(() => (hasToken() ? { name: 'account' as const } : { name: 'login' as const }))

function onSearchSubmit() {
  const q = searchInput.value.trim()
  void router.push({
    name: 'shop',
    query: {
      ...(q ? { q } : {}),
      ...(typeof route.query.categoryId === 'string' ? { categoryId: route.query.categoryId } : {}),
    },
  })
}
</script>

<template>
  <div class="min-h-screen bg-[var(--surface)]">
    <header class="border-b border-[var(--line)] bg-[var(--panel)]">
      <div class="mx-auto flex max-w-[1100px] flex-wrap items-center gap-4 px-7 py-3.5">
        <RouterLink
          :to="{ name: 'shop' }"
          class="brand shrink-0 text-xl font-bold tracking-tight text-[var(--brand)] no-underline"
        >
          retail-soln
        </RouterLink>
        <form class="min-w-[200px] flex-1" @submit.prevent="onSearchSubmit">
          <input
            v-model="searchInput"
            type="search"
            placeholder="Search products…"
            class="h-11 w-full rounded-[var(--radius)] border border-[var(--line)] bg-[var(--panel)] px-3.5 text-[15px] outline-none focus:border-[var(--brand)]"
            aria-label="Search products"
          />
        </form>
        <nav class="flex items-center gap-4 text-sm text-[var(--muted)]">
          <RouterLink class="hover:text-[var(--ink)]" :to="accountHref">Account</RouterLink>
          <span class="cursor-not-allowed text-[var(--muted)] opacity-60" title="Coming in a later story">
            Orders
          </span>
          <span
            class="cursor-not-allowed font-semibold text-[var(--brand)] opacity-50"
            title="Cart arrives in Story 6.3"
          >
            Cart
          </span>
        </nav>
      </div>
    </header>
    <RouterView />
  </div>
</template>
