<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError, catalogueApi, formatPortalMoney, hasToken, type CatalogueProductDetail } from '@/api/client'
import { useCartStore } from '@/stores/cart'

const route = useRoute()
const router = useRouter()
const cart = useCartStore()

const product = ref<CatalogueProductDetail | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const selectedVariantId = ref<number | null>(null)
const qty = ref(1)
const adding = ref(false)
const addMessage = ref<string | null>(null)

let detailAbort: AbortController | null = null

const selectedVariant = computed(() => {
  if (!product.value) return null
  const id = selectedVariantId.value
  if (id != null) {
    return product.value.variants.find((v) => v.id === id) ?? null
  }
  return (
    product.value.variants.find((v) => v.id === product.value?.primaryVariantId) ??
    product.value.variants[0] ??
    null
  )
})

const displayPrice = computed(() => selectedVariant.value?.price ?? product.value?.price ?? null)
const displayStock = computed(() => selectedVariant.value?.stock ?? product.value?.stock)

async function load() {
  detailAbort?.abort()
  const controller = new AbortController()
  detailAbort = controller

  loading.value = true
  error.value = null
  product.value = null
  selectedVariantId.value = null
  addMessage.value = null

  const id = Number(route.params.id)
  if (!Number.isFinite(id)) {
    error.value = 'Product not found.'
    loading.value = false
    return
  }
  try {
    const res = await catalogueApi.product(id, undefined, { signal: controller.signal })
    if (controller.signal.aborted) return
    product.value = res.data
    selectedVariantId.value = res.data.primaryVariantId ?? res.data.variants[0]?.id ?? null
  } catch (err) {
    if (controller.signal.aborted || (err instanceof DOMException && err.name === 'AbortError')) {
      return
    }
    error.value = err instanceof Error ? err.message : 'Product not found.'
  } finally {
    if (!controller.signal.aborted) {
      loading.value = false
    }
  }
}

async function addToCart() {
  addMessage.value = null
  if (!selectedVariant.value) return
  if (!hasToken()) {
    await router.push({
      name: 'login',
      query: { redirect: route.fullPath },
    })
    return
  }
  adding.value = true
  try {
    const safeQty = Number.isFinite(qty.value) && qty.value >= 1 ? Math.floor(qty.value) : 1
    qty.value = safeQty
    await cart.addItem(selectedVariant.value.id, safeQty)
    addMessage.value = 'Added to cart'
  } catch (err) {
    addMessage.value = err instanceof ApiError ? err.message : 'Could not add to cart'
  } finally {
    adding.value = false
  }
}

onMounted(() => {
  void load()
})

onBeforeUnmount(() => {
  detailAbort?.abort()
})

watch(
  () => route.params.id,
  () => {
    void load()
  },
)
</script>

<template>
  <div class="mx-auto max-w-[960px] px-5 py-8">
    <p v-if="loading" class="text-sm text-[var(--muted)]">Loading…</p>
    <p v-else-if="error" class="text-sm text-[var(--danger)]">{{ error }}</p>

    <div v-else-if="product" class="grid gap-8 md:grid-cols-2">
      <div
        class="flex h-[360px] items-center justify-center rounded-xl border border-[var(--line)] bg-[var(--panel)] text-[var(--muted)]"
      >
        <img
          v-if="product.imageUrl"
          :src="product.imageUrl"
          :alt="product.name"
          class="h-full w-full rounded-xl object-cover"
        />
        <span v-else>Product image</span>
      </div>

      <div>
        <div class="font-mono text-xs text-[var(--muted)]">{{ product.sku }}</div>
        <h1 class="brand mt-2 mb-3 text-[28px] font-semibold tracking-tight">{{ product.name }}</h1>
        <div class="brand mb-2 text-[32px] font-bold tracking-tight text-[var(--brand)]">
          {{ formatPortalMoney(displayPrice?.amount) }}
        </div>
        <div
          v-if="displayStock"
          class="mb-5 text-sm font-semibold"
          :class="displayStock.inStock ? 'text-[var(--brand)]' : 'text-[var(--muted)]'"
        >
          {{ displayStock.label }}
        </div>
        <p v-if="product.description" class="mb-6 text-[15px] leading-relaxed text-[var(--muted)]">
          {{ product.description }}
        </p>

        <div v-if="product.variants.length > 1" class="mb-6">
          <label class="mb-2 block text-sm text-[var(--muted)]" for="variant-select">Variant</label>
          <select
            id="variant-select"
            v-model.number="selectedVariantId"
            class="w-full rounded-[var(--radius)] border border-[var(--line)] bg-[var(--panel)] px-3 py-2 text-sm outline-none focus:border-[var(--brand)]"
          >
            <option v-for="variant in product.variants" :key="variant.id" :value="variant.id">
              {{ variant.name }} · {{ formatPortalMoney(variant.price?.amount) }} ·
              {{ variant.stock.label }}
            </option>
          </select>
        </div>
        <ul
          v-else-if="product.variants.length === 1"
          class="mb-6 space-y-1 text-sm text-[var(--muted)]"
        >
          <li>
            {{ product.variants[0].name }} ·
            {{ formatPortalMoney(product.variants[0].price?.amount) }} ·
            {{ product.variants[0].stock.label }}
          </li>
        </ul>

        <div class="mb-4">
          <label class="mb-2 block text-sm text-[var(--muted)]" for="qty">Quantity</label>
          <input
            id="qty"
            v-model.number="qty"
            type="number"
            min="1"
            step="1"
            class="h-11 w-24 rounded-[var(--radius)] border border-[var(--line)] px-3 text-sm"
          />
        </div>

        <button
          type="button"
          class="brand h-[52px] w-full rounded-[var(--radius)] bg-[var(--brand)] text-base font-semibold text-[var(--brand-fg)] disabled:opacity-50"
          :disabled="adding || !selectedVariant"
          @click="addToCart"
        >
          {{ adding ? 'Adding…' : 'Add to cart' }}
        </button>
        <p v-if="addMessage" class="mt-4 text-[13px] text-[var(--muted)]">{{ addMessage }}</p>
      </div>
    </div>
  </div>
</template>
