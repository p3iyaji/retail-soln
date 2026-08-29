<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import {
  catalogueApi,
  formatPortalMoney,
  type CatalogueCategory,
  type CatalogueProductCard,
} from '@/api/client'

const route = useRoute()
const router = useRouter()

const categories = ref<CatalogueCategory[]>([])
const products = ref<CatalogueProductCard[]>([])
const total = ref(0)
const currentPage = ref(1)
const lastPage = ref(1)
const storeName = ref<string | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

let productsAbort: AbortController | null = null

const selectedCategoryId = computed(() => {
  const raw = route.query.categoryId
  if (typeof raw !== 'string' || raw === '') return null
  const n = Number(raw)
  return Number.isFinite(n) ? n : null
})

const searchQ = computed(() => (typeof route.query.q === 'string' ? route.query.q : ''))

const pageFromRoute = computed(() => {
  const raw = route.query.page
  if (typeof raw !== 'string' || raw === '') return 1
  const n = Number(raw)
  return Number.isFinite(n) && n >= 1 ? Math.floor(n) : 1
})

const showPager = computed(() => lastPage.value > 1)

async function loadCategories() {
  const res = await catalogueApi.categories()
  categories.value = res.data
}

async function loadProducts() {
  productsAbort?.abort()
  const controller = new AbortController()
  productsAbort = controller

  loading.value = true
  error.value = null
  try {
    const res = await catalogueApi.products(
      {
        q: searchQ.value || undefined,
        categoryId: selectedCategoryId.value,
        page: pageFromRoute.value,
      },
      { signal: controller.signal },
    )
    if (controller.signal.aborted) return
    products.value = res.data
    total.value = res.meta.total
    currentPage.value = res.meta.currentPage
    lastPage.value = res.meta.lastPage
    storeName.value = res.meta.storeName
  } catch (err) {
    if (controller.signal.aborted || (err instanceof DOMException && err.name === 'AbortError')) {
      return
    }
    error.value = err instanceof Error ? err.message : 'Failed to load catalogue'
    products.value = []
    total.value = 0
    currentPage.value = 1
    lastPage.value = 1
  } finally {
    if (!controller.signal.aborted) {
      loading.value = false
    }
  }
}

function setCategory(id: number | null) {
  void router.push({
    name: 'shop',
    query: {
      ...(searchQ.value ? { q: searchQ.value } : {}),
      ...(id != null ? { categoryId: String(id) } : {}),
    },
  })
}

function goToPage(page: number) {
  const next = Math.max(1, Math.min(page, lastPage.value))
  void router.push({
    name: 'shop',
    query: {
      ...(searchQ.value ? { q: searchQ.value } : {}),
      ...(selectedCategoryId.value != null ? { categoryId: String(selectedCategoryId.value) } : {}),
      ...(next > 1 ? { page: String(next) } : {}),
    },
  })
}

onMounted(async () => {
  try {
    await loadCategories()
  } catch {
    // categories optional for listing
  }
  await loadProducts()
})

onBeforeUnmount(() => {
  productsAbort?.abort()
})

watch(
  () => [route.query.q, route.query.categoryId, route.query.page],
  () => {
    void loadProducts()
  },
)
</script>

<template>
  <section class="bg-[linear-gradient(120deg,#0B6E4F_0%,#1A2330_100%)] px-7 py-12 text-center text-[var(--brand-fg)]">
    <h1 class="brand m-0 text-4xl font-semibold tracking-tight">
      Shop {{ storeName ?? 'our store' }}
    </h1>
    <p class="m-0 mt-2 text-base opacity-90">Browse products and check prices at your store</p>
  </section>

  <div class="mx-auto grid max-w-[1100px] gap-6 px-7 py-7 md:grid-cols-[200px_1fr]">
    <aside>
      <h2 class="brand m-0 mb-3 text-[13px] font-semibold tracking-[0.04em] text-[var(--muted)] uppercase">
        Category
      </h2>
      <div class="flex flex-col gap-2" role="radiogroup" aria-label="Category">
        <button
          type="button"
          role="radio"
          :aria-checked="selectedCategoryId === null"
          class="rounded-[var(--radius)] border px-3 py-2 text-left text-sm transition"
          :class="
            selectedCategoryId === null
              ? 'border-[var(--brand)] bg-[var(--brand)] text-[var(--brand-fg)]'
              : 'border-[var(--line)] bg-[var(--panel)] text-[var(--ink)] hover:border-[var(--brand)]'
          "
          @click="setCategory(null)"
        >
          All
        </button>
        <button
          v-for="cat in categories"
          :key="cat.id"
          type="button"
          role="radio"
          :aria-checked="selectedCategoryId === cat.id"
          class="rounded-[var(--radius)] border px-3 py-2 text-left text-sm transition"
          :class="
            selectedCategoryId === cat.id
              ? 'border-[var(--brand)] bg-[var(--brand)] text-[var(--brand-fg)]'
              : 'border-[var(--line)] bg-[var(--panel)] text-[var(--ink)] hover:border-[var(--brand)]'
          "
          @click="setCategory(cat.id)"
        >
          {{ cat.name }}
        </button>
        <p v-if="categories.length === 0" class="text-sm text-[var(--muted)]">No categories yet.</p>
      </div>
    </aside>

    <div>
      <div class="mb-4 flex items-center justify-between text-sm text-[var(--muted)]">
        <span>{{ total }} product{{ total === 1 ? '' : 's' }}</span>
        <span v-if="searchQ">Results for “{{ searchQ }}”</span>
      </div>

      <p v-if="error" class="text-sm text-[var(--danger)]">{{ error }}</p>
      <p v-else-if="loading" class="text-sm text-[var(--muted)]">Loading…</p>

      <div v-else-if="products.length === 0" class="text-sm text-[var(--muted)]">No products found.</div>

      <template v-else>
        <div class="grid grid-cols-[repeat(auto-fill,minmax(180px,1fr))] gap-4">
          <RouterLink
            v-for="product in products"
            :key="product.id"
            :to="{ name: 'shop-product', params: { id: product.id } }"
            class="block overflow-hidden rounded-[var(--radius)] border border-[var(--line)] bg-[var(--panel)] text-[var(--ink)] no-underline transition hover:border-[var(--brand)]"
          >
            <div
              class="flex h-[140px] items-center justify-center bg-[#E8ECF0] text-xs text-[var(--muted)]"
            >
              <img
                v-if="product.imageUrl"
                :src="product.imageUrl"
                :alt="product.name"
                class="h-full w-full object-cover"
              />
              <span v-else>Product image</span>
            </div>
            <div class="p-3">
              <div class="font-mono text-[11px] text-[var(--muted)]">{{ product.sku }}</div>
              <div class="mt-1 mb-2 text-sm font-semibold">{{ product.name }}</div>
              <div class="font-mono text-sm font-semibold text-[var(--brand)]">
                {{ formatPortalMoney(product.price?.amount) }}
              </div>
              <div
                class="mt-1 text-xs font-semibold"
                :class="product.stock.inStock ? 'text-[var(--brand)]' : 'text-[var(--muted)]'"
              >
                {{ product.stock.label }}
              </div>
            </div>
          </RouterLink>
        </div>

        <div
          v-if="showPager"
          class="mt-6 flex items-center justify-between gap-4 text-sm"
        >
          <button
            type="button"
            class="rounded-[var(--radius)] border border-[var(--line)] bg-[var(--panel)] px-3 py-2 disabled:opacity-40"
            :disabled="currentPage <= 1"
            @click="goToPage(currentPage - 1)"
          >
            Previous
          </button>
          <span class="text-[var(--muted)]">Page {{ currentPage }} of {{ lastPage }}</span>
          <button
            type="button"
            class="rounded-[var(--radius)] border border-[var(--line)] bg-[var(--panel)] px-3 py-2 disabled:opacity-40"
            :disabled="currentPage >= lastPage"
            @click="goToPage(currentPage + 1)"
          >
            Next
          </button>
        </div>
      </template>
    </div>
  </div>
</template>
