<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { ApiError, checkoutApi, formatPortalMoney, type PortalOrder } from '@/api/client'
import { useCartStore } from '@/stores/cart'

const route = useRoute()
const cart = useCartStore()
const order = ref<PortalOrder | null>(cart.lastOrder)
const error = ref<string | null>(null)
const loading = ref(false)

onMounted(async () => {
  const idRaw = route.query.orderId
  const id = typeof idRaw === 'string' ? Number(idRaw) : NaN
  if (!Number.isFinite(id)) {
    if (!order.value) {
      error.value = 'No order to show.'
    }
    return
  }
  if (order.value?.id === id) {
    return
  }
  loading.value = true
  try {
    const res = await checkoutApi.order(id)
    order.value = res.data
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Order not found.'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="mx-auto max-w-[640px] px-5 py-10">
    <p v-if="loading" class="text-sm text-[var(--muted)]">Loading confirmation…</p>
    <p v-else-if="error" class="text-sm text-[var(--danger)]">{{ error }}</p>
    <template v-else-if="order">
      <h1 class="brand text-2xl font-semibold tracking-tight">Order placed</h1>
      <p class="mt-2 text-sm text-[var(--muted)]">
        Thanks — your online order is recorded. Full tracking arrives in a later story.
      </p>
      <div class="mt-6 rounded-[var(--radius)] border border-[var(--line)] bg-[var(--panel)] p-5">
        <div class="mb-2 text-sm text-[var(--muted)]">Order number</div>
        <div class="brand text-xl font-bold text-[var(--brand)]">{{ order.orderNumber }}</div>
        <div class="mt-4 grid gap-2 text-sm">
          <div class="flex justify-between">
            <span class="text-[var(--muted)]">Status</span>
            <span class="font-semibold capitalize">{{ order.status }}</span>
          </div>
          <div class="flex justify-between">
            <span class="text-[var(--muted)]">Payment</span>
            <span class="font-semibold capitalize">{{ order.paymentStatus.replace(/_/g, ' ') }}</span>
          </div>
          <div class="flex justify-between">
            <span class="text-[var(--muted)]">Total</span>
            <span class="font-mono font-semibold">{{ formatPortalMoney(order.totals.grandTotal) }}</span>
          </div>
        </div>
      </div>
      <RouterLink
        :to="{ name: 'shop' }"
        class="mt-6 inline-flex h-11 items-center rounded-[var(--radius)] bg-[var(--brand)] px-5 text-sm font-semibold text-[var(--brand-fg)] no-underline"
      >
        Back to shop
      </RouterLink>
    </template>
  </div>
</template>
