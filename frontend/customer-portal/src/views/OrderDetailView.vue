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
const cancelBusy = ref(false)
const cancelError = ref<string | null>(null)

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
  cancelError.value = null
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

async function cancelOrder() {
  if (!order.value?.cancellable) return
  if (!window.confirm('Cancel this unpaid order and release held stock?')) return
  cancelBusy.value = true
  cancelError.value = null
  try {
    const res = await ordersApi.cancel(order.value.id)
    order.value = res.data
  } catch (err) {
    cancelError.value = err instanceof ApiError ? err.message : 'Could not cancel order.'
  } finally {
    cancelBusy.value = false
  }
}

const activeReturn = computed(() => order.value?.activeReturnRequest ?? null)
const returnSummary = computed(() => activeReturn.value ?? order.value?.returnRequest ?? null)
const showRefunds = computed(() => {
  if (!order.value) return false
  if (returnSummary.value) return true
  return Array.isArray(order.value.refunds) && order.value.refunds.length > 0
})

const ineligibleTitle = computed(() => {
  if (activeReturn.value) {
    return `Open return request (${activeReturn.value.statusLabel}) — view it instead of starting another`
  }
  if (order.value?.returnRequest) {
    return `Latest return: ${order.value.returnRequest.statusLabel}. New requests need remaining returnable qty on a paid delivered order`
  }
  if (order.value?.paymentStatus !== 'paid') {
    return 'Returns available after the order is paid and delivered'
  }
  if (order.value?.status !== 'delivered') {
    return 'Returns available after delivery'
  }
  return 'No remaining returnable quantity on this order'
})
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
                <span v-if="item.variantName" class="text-[var(--muted)]"> · {{ item.variantName }}</span>
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
          <div
            v-if="order.reservationExpiresAt"
            class="mt-1 flex justify-between text-xs text-[var(--muted)]"
          >
            <span>Hold expires</span>
            <span>{{ new Date(order.reservationExpiresAt).toLocaleString() }}</span>
          </div>
          <div v-if="order.store.name" class="mt-1 flex justify-between text-xs text-[var(--muted)]">
            <span>Store</span>
            <span>{{ order.store.name }}</span>
          </div>
        </div>

        <div
          v-if="returnSummary"
          class="mt-6 rounded-lg border border-[var(--line)] bg-[var(--surface)] px-4 py-3 text-sm"
        >
          <div class="font-semibold text-[var(--ink)]">
            {{ activeReturn ? 'Open return request' : 'Return request' }}
          </div>
          <p class="mt-1 text-[var(--muted)]">
            Status:
            <span class="font-semibold text-[var(--ink)]">{{ returnSummary.statusLabel }}</span>
            <template v-if="returnSummary.createdAt">
              ·
              {{ new Date(returnSummary.createdAt).toLocaleDateString() }}
            </template>
          </p>
          <RouterLink
            :to="{ name: 'return-detail', params: { id: returnSummary.id } }"
            class="mt-2 inline-block text-[13px] font-semibold text-[var(--brand)] no-underline hover:underline"
          >
            View request
          </RouterLink>
        </div>

        <div
          v-if="showRefunds"
          class="mt-4 rounded-lg border border-dashed border-[var(--line)] px-4 py-3 text-sm text-[var(--muted)]"
        >
          <div class="font-semibold text-[var(--ink)]">Refunds</div>
          <p class="mt-1">
            {{
              order.refunds && order.refunds.length > 0 ? `${order.refunds.length} refund(s)` : 'No refunds yet'
            }}
          </p>
        </div>

        <p v-if="invoiceError" class="mt-4 text-sm text-[var(--danger)]">{{ invoiceError }}</p>
        <p v-if="cancelError" class="mt-4 text-sm text-[var(--danger)]">{{ cancelError }}</p>

        <div class="mt-6 flex flex-wrap gap-2.5">
          <button
            v-if="order.cancellable"
            type="button"
            class="inline-flex h-10 items-center rounded-[var(--radius)] border border-[var(--danger)] bg-[var(--surface)] px-3.5 text-[13px] font-semibold text-[var(--danger)] disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="cancelBusy"
            @click="cancelOrder"
          >
            {{ cancelBusy ? 'Cancelling…' : 'Cancel order' }}
          </button>
          <button
            type="button"
            class="inline-flex h-10 items-center rounded-[var(--radius)] bg-[var(--brand)] px-3.5 text-[13px] font-semibold text-[var(--brand-fg)] disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="!order.invoiceAvailable || invoiceBusy"
            @click="downloadInvoice"
          >
            {{ invoiceBusy ? 'Downloading…' : 'Download invoice' }}
          </button>
          <RouterLink
            v-if="order.returnEligible"
            :to="{ name: 'order-return', params: { id: order.id } }"
            class="inline-flex h-10 items-center rounded-[var(--radius)] border border-[var(--line)] bg-[var(--surface)] px-3.5 text-[13px] font-semibold text-[var(--ink)] no-underline"
          >
            Request return
          </RouterLink>
          <RouterLink
            v-else-if="activeReturn"
            :to="{ name: 'return-detail', params: { id: activeReturn.id } }"
            class="inline-flex h-10 items-center rounded-[var(--radius)] border border-[var(--line)] bg-[var(--surface)] px-3.5 text-[13px] font-semibold text-[var(--ink)] no-underline"
            :title="ineligibleTitle"
          >
            View return request
          </RouterLink>
          <button
            v-else
            type="button"
            disabled
            :title="ineligibleTitle"
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
