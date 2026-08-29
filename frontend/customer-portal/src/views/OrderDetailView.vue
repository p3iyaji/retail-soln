<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import {
  ApiError,
  formatPortalMoney,
  ordersApi,
  type PortalOrder,
} from '@/api/client'

const route = useRoute()
const order = ref<PortalOrder | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const invoiceBusy = ref(false)
const invoiceError = ref<string | null>(null)

const orderId = computed(() => {
  const raw = route.params.id
  const n = typeof raw === 'string' ? Number(raw) : NaN
  return Number.isFinite(n) ? n : null
})

async function load() {
  if (orderId.value == null) {
    error.value = 'Order not found.'
    loading.value = false
    return
  }
  loading.value = true
  error.value = null
  invoiceError.value = null
  try {
    const res = await ordersApi.get(orderId.value)
    order.value = res.data
  } catch (err) {
    order.value = null
    error.value = err instanceof ApiError ? err.message : 'Order not found.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void load()
})

watch(orderId, () => {
  void load()
})

function fulfilmentLabel(mode: string): string {
  return mode.replace(/_/g, ' ')
}

async function downloadInvoice() {
  if (!order.value?.invoiceAvailable) return
  invoiceBusy.value = true
  invoiceError.value = null
  try {
    await ordersApi.downloadInvoice(order.value.id, order.value.orderNumber)
  } catch (err) {
    invoiceError.value = err instanceof ApiError ? err.message : 'Could not download invoice.'
  } finally {
    invoiceBusy.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-[640px] px-5 py-10">
    <RouterLink
      :to="{ name: 'orders' }"
      class="text-sm text-[var(--muted)] no-underline hover:text-[var(--ink)]"
    >
      ← Orders
    </RouterLink>

    <p v-if="loading" class="mt-6 text-sm text-[var(--muted)]">Loading order…</p>
    <p v-else-if="error" class="mt-6 text-sm text-[var(--danger)]">{{ error }}</p>

    <template v-else-if="order">
      <h1 class="brand mt-4 text-2xl font-semibold tracking-tight">Order tracking</h1>
      <p class="meta mt-2 text-sm text-[var(--muted)]">
        Order
        <span class="font-mono font-semibold text-[var(--ink)]">{{ order.orderNumber }}</span>
        <span v-if="order.customer?.name"> · {{ order.customer.name }}</span>
        · <span class="capitalize">{{ fulfilmentLabel(order.fulfilmentMode) }}</span>
      </p>

      <div class="mt-6 rounded-xl border border-[var(--line)] bg-[var(--panel)] p-6">
        <div
          class="mb-5 inline-block rounded bg-[#F0F7F4] px-2.5 py-1.5 text-xs font-bold uppercase tracking-wide text-[var(--brand)]"
        >
          {{ order.statusLabel || order.status }}
        </div>

        <ol v-if="order.timeline?.length" class="m-0 list-none space-y-0 p-0">
          <li
            v-for="(step, index) in order.timeline"
            :key="step.key"
            class="grid grid-cols-[28px_1fr] gap-3 py-3"
          >
            <div
              class="flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold"
              :class="
                step.state === 'upcoming'
                  ? 'bg-[var(--line)] text-[var(--muted)]'
                  : 'bg-[var(--brand)] text-white'
              "
            >
              <template v-if="step.state === 'completed'">✓</template>
              <template v-else-if="step.state === 'current'">{{ index + 1 }}</template>
              <template v-else>{{ index + 1 }}</template>
            </div>
            <div>
              <strong class="block text-sm text-[var(--ink)]">{{ step.label }}</strong>
              <span v-if="step.detail" class="text-xs text-[var(--muted)]">{{ step.detail }}</span>
            </div>
          </li>
        </ol>

        <div class="mt-6 border-t border-[var(--line)] pt-5">
          <h2 class="mb-3 text-sm font-semibold text-[var(--ink)]">Items</h2>
          <ul class="m-0 list-none space-y-2 p-0 text-sm">
            <li
              v-for="item in order.items"
              :key="item.id"
              class="flex justify-between gap-3"
            >
              <span>
                {{ item.productName }}
                <span class="text-[var(--muted)]">× {{ item.qty }}</span>
              </span>
              <span class="font-mono font-semibold">{{ formatPortalMoney(item.lineTotal) }}</span>
            </li>
          </ul>
          <div class="mt-4 flex justify-between text-sm">
            <span class="text-[var(--muted)]">Total</span>
            <span class="font-mono text-base font-bold">{{
              formatPortalMoney(order.totals.grandTotal)
            }}</span>
          </div>
          <div class="mt-2 flex justify-between text-xs text-[var(--muted)]">
            <span>Payment</span>
            <span class="capitalize">{{ order.paymentStatus.replace(/_/g, ' ') }}</span>
          </div>
          <div v-if="order.store.name" class="mt-1 flex justify-between text-xs text-[var(--muted)]">
            <span>Store</span>
            <span>{{ order.store.name }}</span>
          </div>
        </div>

        <p v-if="invoiceError" class="mt-4 text-sm text-[var(--danger)]">{{ invoiceError }}</p>

        <div class="mt-6 flex flex-wrap gap-2.5">
          <button
            type="button"
            class="inline-flex h-10 items-center rounded-[var(--radius)] bg-[var(--brand)] px-3.5 text-[13px] font-semibold text-[var(--brand-fg)] disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="!order.invoiceAvailable || invoiceBusy"
            @click="downloadInvoice"
          >
            {{ invoiceBusy ? 'Downloading…' : 'Download invoice' }}
          </button>
          <button
            type="button"
            disabled
            title="Coming in Story 6.5"
            class="inline-flex h-10 cursor-not-allowed items-center rounded-[var(--radius)] border border-[var(--line)] bg-[var(--surface)] px-3.5 text-[13px] font-semibold text-[var(--muted)] opacity-70"
          >
            Request return
          </button>
          <RouterLink
            :to="{ name: 'shop' }"
            class="inline-flex h-10 items-center rounded-[var(--radius)] border border-[var(--line)] bg-[var(--surface)] px-3.5 text-[13px] font-semibold text-[var(--ink)] no-underline"
          >
            Continue shopping
          </RouterLink>
        </div>
      </div>
    </template>
  </div>
</template>
