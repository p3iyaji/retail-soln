<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { ApiError, returnsApi, type PortalReturnRequest } from '@/api/client'

const route = useRoute()
const request = ref<PortalReturnRequest | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

const requestId = computed(() => {
  const raw = route.params.id
  const n = typeof raw === 'string' ? Number(raw) : NaN
  return Number.isFinite(n) ? n : null
})

async function load() {
  if (requestId.value == null) {
    error.value = 'Return request not found.'
    loading.value = false
    return
  }
  loading.value = true
  error.value = null
  try {
    const res = await returnsApi.get(requestId.value)
    request.value = res.data
  } catch (err) {
    request.value = null
    error.value = err instanceof ApiError ? err.message : 'Return request not found.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void load()
})

watch(requestId, () => {
  void load()
})
</script>

<template>
  <div class="mx-auto max-w-[640px] px-5 py-10">
    <RouterLink
      :to="{ name: 'returns' }"
      class="text-sm text-[var(--muted)] no-underline hover:text-[var(--ink)]"
    >
      ← Returns
    </RouterLink>

    <p v-if="loading" class="mt-6 text-sm text-[var(--muted)]">Loading…</p>
    <p v-else-if="error" class="mt-6 text-sm text-[var(--danger)]">{{ error }}</p>

    <template v-else-if="request">
      <h1 class="brand mt-4 text-2xl font-semibold tracking-tight">Return request</h1>
      <p class="mt-2 text-sm text-[var(--muted)]">
        Order
        <RouterLink
          v-if="request.orderId"
          :to="{ name: 'order-detail', params: { id: request.orderId } }"
          class="font-mono font-semibold text-[var(--brand)] no-underline hover:underline"
        >
          {{ request.orderNumber ?? request.orderId }}
        </RouterLink>
      </p>

      <div class="mt-6 rounded-xl border border-[var(--line)] bg-[var(--panel)] p-6">
        <div
          class="mb-4 inline-block rounded bg-[var(--brand-soft)] px-2.5 py-1.5 text-xs font-bold uppercase tracking-wide text-[var(--brand)]"
        >
          {{ request.statusLabel }}
        </div>

        <dl class="m-0 space-y-2 text-sm">
          <div class="flex justify-between gap-3">
            <dt class="text-[var(--muted)]">Reason</dt>
            <dd class="m-0 font-medium text-[var(--ink)]">{{ request.reasonLabel }}</dd>
          </div>
          <div v-if="request.notes" class="flex justify-between gap-3">
            <dt class="text-[var(--muted)]">Notes</dt>
            <dd class="m-0 max-w-[60%] text-right text-[var(--ink)]">{{ request.notes }}</dd>
          </div>
          <div v-if="request.createdAt" class="flex justify-between gap-3">
            <dt class="text-[var(--muted)]">Submitted</dt>
            <dd class="m-0 text-[var(--ink)]">{{ new Date(request.createdAt).toLocaleString() }}</dd>
          </div>
        </dl>

        <h2 class="mb-2 mt-6 text-sm font-semibold text-[var(--ink)]">Lines</h2>
        <ul class="m-0 list-none space-y-2 p-0 text-sm">
          <li v-for="item in request.items" :key="item.id" class="flex justify-between gap-3">
            <span>
              {{ item.productName }}
              <span v-if="item.variantName" class="text-[var(--muted)]"> · {{ item.variantName }}</span>
              <span class="text-[var(--muted)]">× {{ item.qty }}</span>
            </span>
            <span class="font-mono text-xs text-[var(--muted)]">{{ item.sku }}</span>
          </li>
        </ul>

        <div class="mt-6 rounded-lg border border-dashed border-[var(--line)] px-4 py-3 text-sm text-[var(--muted)]">
          <div class="font-semibold text-[var(--ink)]">Refunds</div>
          <p class="mt-1">
            {{
              request.refunds && request.refunds.length > 0
                ? `${request.refunds.length} refund(s)`
                : 'No refunds yet'
            }}
          </p>
        </div>
      </div>
    </template>
  </div>
</template>
