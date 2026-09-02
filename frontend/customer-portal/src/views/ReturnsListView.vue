<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { ApiError, returnsApi, type PortalReturnRequestListItem } from '@/api/client'

const rows = ref<PortalReturnRequestListItem[]>([])
const meta = ref({ currentPage: 1, lastPage: 1, total: 0 })
const loading = ref(true)
const error = ref<string | null>(null)

async function load(page = 1) {
  loading.value = true
  error.value = null
  try {
    const res = await returnsApi.list(page)
    rows.value = res.data
    meta.value = res.meta
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Could not load return requests.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="mx-auto max-w-[720px] px-5 py-10">
    <h1 class="brand text-2xl font-semibold tracking-tight">Your returns</h1>
    <p class="mt-2 text-sm text-[var(--muted)]">
      Track return request status. Refunds appear here after staff complete them.
    </p>

    <p v-if="loading" class="mt-8 text-sm text-[var(--muted)]">Loading returns…</p>
    <p v-else-if="error" class="mt-8 text-sm text-[var(--danger)]">{{ error }}</p>
    <p v-else-if="rows.length === 0" class="mt-8 text-sm text-[var(--muted)]">
      No return requests yet. Open a delivered order and choose Request return.
      <RouterLink :to="{ name: 'orders' }" class="font-semibold text-[var(--brand)] no-underline hover:underline">
        View orders
      </RouterLink>
    </p>

    <ul v-else class="mt-8 list-none space-y-3 p-0">
      <li v-for="row in rows" :key="row.id">
        <RouterLink
          :to="{ name: 'return-detail', params: { id: row.id } }"
          class="block rounded-xl border border-[var(--line)] bg-[var(--panel)] p-4 no-underline transition hover:border-[var(--brand)]"
        >
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
              <div class="font-mono text-sm font-semibold text-[var(--ink)]">
                {{ row.orderNumber ?? `Order #${row.orderId}` }}
              </div>
              <div class="mt-1 text-xs text-[var(--muted)]">{{ row.reasonLabel }}</div>
            </div>
            <div
              class="inline-block rounded bg-[var(--brand-soft)] px-2 py-1 text-[11px] font-bold uppercase tracking-wide text-[var(--brand)]"
            >
              {{ row.statusLabel }}
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
