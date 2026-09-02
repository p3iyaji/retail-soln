<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { hasToken } from '@/api/client'
import UserProfileMenu from '@/components/UserProfileMenu.vue'
import { useCartStore } from '@/stores/cart'

const route = useRoute()
const router = useRouter()
const cart = useCartStore()

const searchInput = ref('')

watch(
  () => route.query.q,
  (q) => {
    searchInput.value = typeof q === 'string' ? q : ''
  },
  { immediate: true },
)

const cartLabel = computed(() => {
  const n = cart.itemCount
  return n > 0 ? `Cart (${n})` : 'Cart'
})

onMounted(() => {
  if (hasToken()) {
    void cart.fetchCart().catch(() => {
      /* badge optional */
    })
  }
})

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
      <div class="mx-auto flex max-w-[1100px] flex-wrap items-center gap-4 px-7 py-4">
        <RouterLink
          :to="{ name: 'shop' }"
          class="brand shrink-0 no-underline"
        >
          <img src="/logo.png" alt="Bag & Cartons Ltd" class="h-20 w-auto object-contain sm:h-[5.5rem]" />
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
          <RouterLink
            class="hover:text-[var(--ink)] no-underline"
            :class="{ 'font-semibold text-[var(--ink)]': route.name === 'shop' || route.name === 'shop-product' }"
            :to="{ name: 'shop' }"
          >
            Shop
          </RouterLink>
          <RouterLink class="hover:text-[var(--ink)] no-underline" :to="{ name: 'contact' }">Contact</RouterLink>
          <RouterLink
            :to="hasToken() ? { name: 'cart' } : { name: 'login', query: { redirect: '/shop/cart' } }"
            class="font-semibold text-[var(--accent)] no-underline hover:underline"
          >
            {{ cartLabel }}
          </RouterLink>
          <UserProfileMenu />
        </nav>
      </div>
    </header>
    <RouterView />
  </div>
</template>
