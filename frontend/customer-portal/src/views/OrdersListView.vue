<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { ApiError, formatPortalMoney, ordersApi, type PortalOrderListItem } from '@/api/client'

const orders = ref<PortalOrderListItem[]>([])
const meta = ref({ currentPage: 1, lastPage: 1, total: 0 })
const loading = ref(true)
const error = ref<string | null>(null)

async function load(page = 1) {
  loading.value = true
  error.value = null
  try {
    const res = await ordersApi.list(page)
    orders.value = res.data
    meta.value = res.meta
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Could not load orders.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void load()
})

function fulfilmentLabel(mode: string): string {
  return mode.replace(/_/g, ' ')
}
</script>

<template>
  <div class="mx-auto max-w-[720px] px-5 py-10">
    <h1 class="brand text-2xl font-semibold tracking-tight">Your orders</h1>
    <p class="mt-2 text-sm text-[var(--muted)]">Track fulfilment and download invoices for paid orders.</p>

    <p v-if="loading" class="mt-8 text-sm text-[var(--muted)]">Loading orders…</p>
    <p v-else-if="error" class="mt-8 text-sm text-[var(--danger)]">{{ error }}</p>
    <p v-else-if="orders.length === 0" class="mt-8 text-sm text-[var(--muted)]">
      No orders yet.
      <RouterLink :to="{ name: 'shop' }" class="font-semibold text-[var(--brand)] no-underline hover:underline">
        Continue shopping
      </RouterLink>
    </p>

    <ul v-else class="mt-8 list-none space-y-3 p-0">
      <li v-for="order in orders" :key="order.id">
        <RouterLink
          :to="{ name: 'order-detail', params: { id: order.id } }"
          class="block rounded-xl border border-[var(--line)] bg-[var(--panel)] p-4 no-underline transition hover:border-[var(--brand)]"
        >
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
              <div class="font-mono text-sm font-semibold text-[var(--ink)]">{{ order.orderNumber }}</div>
              <div class="mt-1 text-xs capitalize text-[var(--muted)]">
                {{ fulfilmentLabel(order.fulfilmentMode) }}
                <span v-if="order.store.name"> · {{ order.store.name }}</span>
              </div>
            </div>
            <div class="text-right">
              <div
                class="inline-block rounded bg-[var(--brand-soft)] px-2 py-1 text-[11px] font-bold uppercase tracking-wide text-[var(--brand)]"
              >
                {{ order.statusLabel || order.status }}
              </div>
              <div class="mt-2 font-mono text-sm font-semibold text-[var(--ink)]">
                {{ formatPortalMoney(order.totals.grandTotal) }}
              </div>
            </div>
          </div>
        </RouterLink>
      </li>
    </ul>

    <div v-if="meta.lastPage > 1" class="mt-6 flex items-center justify-between text-sm">
      <button
        type="button"
        class="rounded-[var(--radius)] border border-[var(--line)] bg-[var(--panel)] px-3 py-2 disabled:opacity-40"
        :disabled="meta.currentPage <= 1 || loading"
        @click="load(meta.currentPage - 1)"
      >
        Previous
      </button>
      <span class="text-[var(--muted)]">Page {{ meta.currentPage }} of {{ meta.lastPage }}</span>
      <button
        type="button"
        class="rounded-[var(--radius)] border border-[var(--line)] bg-[var(--panel)] px-3 py-2 disabled:opacity-40"
        :disabled="meta.currentPage >= meta.lastPage || loading"
        @click="load(meta.currentPage + 1)"
      >
        Next
      </button>
    </div>
  </div>
</template>
