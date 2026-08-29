<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import {
  ApiError,
  ordersApi,
  returnsApi,
  type PortalOrder,
  type PortalReturnReason,
} from '@/api/client'

const REASONS: Array<{ value: PortalReturnReason; label: string }> = [
  { value: 'defective', label: 'Defective' },
  { value: 'wrong_item', label: 'Wrong item' },
  { value: 'changed_mind', label: 'Changed mind' },
  { value: 'other', label: 'Other' },
]

const route = useRoute()
const router = useRouter()

const order = ref<PortalOrder | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const submitError = ref<string | null>(null)
const submitting = ref(false)

const reason = ref<PortalReturnReason>('defective')
const notes = ref('')
const lineQty = reactive<Record<number, number>>({})

const orderId = computed(() => {
  const raw = route.params.id
  const n = typeof raw === 'string' ? Number(raw) : NaN
  return Number.isFinite(n) ? n : null
})

const returnableLines = computed(() =>
  (order.value?.items ?? []).filter((item) => Number(item.returnableQty ?? 0) > 0),
)

const activeRequest = computed(() => order.value?.activeReturnRequest ?? null)

async function load() {
  if (orderId.value == null) {
    error.value = 'Order not found.'
    loading.value = false
    return
  }
  loading.value = true
  error.value = null
  try {
    const res = await ordersApi.get(orderId.value)
    order.value = res.data
    for (const item of res.data.items) {
      const max = Number(item.returnableQty ?? 0)
      lineQty[item.id] = max > 0 ? Math.min(1, max) : 0
    }
    if (!res.data.returnEligible) {
      error.value = res.data.activeReturnRequest
        ? 'This order already has an open return request. Only one open request is allowed at a time.'
        : 'This order is not eligible for a return request (paid + delivered, with remaining returnable qty).'
    }
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

function maxQty(itemId: number): number {
  const item = order.value?.items.find((i) => i.id === itemId)
  return Math.max(0, Number(item?.returnableQty ?? 0))
}

async function submit() {
  if (!order.value || !order.value.returnEligible || submitting.value) return

  submitting.value = true
  submitError.value = null

  try {
    const items = returnableLines.value
      .map((item) => ({
        orderItemId: item.id,
        qty: Number(lineQty[item.id] ?? 0),
      }))
      .filter((line) => line.qty > 0)

    if (items.length === 0) {
      submitError.value = 'Select at least one item quantity to return.'
      return
    }

    for (const line of items) {
      if (line.qty > maxQty(line.orderItemId)) {
        submitError.value = 'Quantity exceeds remaining returnable amount.'
        return
      }
    }

    const idempotencyKey =
      typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID().replace(/-/g, '').slice(0, 32)
        : `ret-${Date.now()}-${Math.random().toString(16).slice(2, 10)}`

    const res = await returnsApi.create(
      {
        orderId: order.value.id,
        reason: reason.value,
        notes: notes.value.trim() || null,
        items,
      },
      idempotencyKey,
    )
    await router.push({ name: 'return-detail', params: { id: res.data.id } })
  } catch (err) {
    submitError.value = err instanceof ApiError ? err.message : 'Could not submit return request.'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-[640px] px-5 py-10">
    <RouterLink
      v-if="orderId != null"
      :to="{ name: 'order-detail', params: { id: orderId } }"
      class="text-sm text-[var(--muted)] no-underline hover:text-[var(--ink)]"
    >
      ← Order
    </RouterLink>

    <h1 class="brand mt-4 text-2xl font-semibold tracking-tight">Request return</h1>
    <p v-if="order" class="mt-2 text-sm text-[var(--muted)]">
      Order <span class="font-mono font-semibold text-[var(--ink)]">{{ order.orderNumber }}</span>
    </p>

    <p v-if="loading" class="mt-6 text-sm text-[var(--muted)]">Loading…</p>
    <div v-else-if="error" class="mt-6 space-y-2 text-sm">
      <p class="text-[var(--danger)]">{{ error }}</p>
      <RouterLink
        v-if="activeRequest"
        :to="{ name: 'return-detail', params: { id: activeRequest.id } }"
        class="inline-block font-semibold text-[var(--brand)] no-underline hover:underline"
      >
        View open request ({{ activeRequest.statusLabel }})
      </RouterLink>
    </div>

    <form
      v-else-if="order?.returnEligible"
      class="mt-6 space-y-5 rounded-xl border border-[var(--line)] bg-[var(--panel)] p-6"
      @submit.prevent="submit"
    >
      <p class="text-xs text-[var(--muted)]">
        Only one open return request is allowed per order. Include every line (and quantity) you need in this
        request — you cannot add another until this one is closed.
      </p>

      <fieldset>
        <legend class="mb-3 text-sm font-semibold text-[var(--ink)]">Items to return</legend>
        <ul class="m-0 list-none space-y-3 p-0">
          <li
            v-for="item in returnableLines"
            :key="item.id"
            class="flex flex-wrap items-center justify-between gap-3 text-sm"
          >
            <div>
              <div class="font-medium text-[var(--ink)]">
                {{ item.productName }}
                <span v-if="item.variantName" class="text-[var(--muted)]"> · {{ item.variantName }}</span>
              </div>
              <div class="text-xs text-[var(--muted)]">
                Ordered {{ item.qty }} · returnable {{ item.returnableQty }}
              </div>
            </div>
            <label class="flex items-center gap-2 text-xs text-[var(--muted)]">
              Qty
              <input
                v-model.number="lineQty[item.id]"
                type="number"
                min="0"
                :max="maxQty(item.id)"
                step="1"
                class="h-10 w-20 rounded-[var(--radius)] border border-[var(--line)] bg-[var(--panel)] px-2 text-sm text-[var(--ink)]"
              />
            </label>
          </li>
        </ul>
      </fieldset>

      <label class="block text-sm">
        <span class="mb-1.5 block font-semibold text-[var(--ink)]">Reason</span>
        <select
          v-model="reason"
          class="h-11 w-full rounded-[var(--radius)] border border-[var(--line)] bg-[var(--panel)] px-3 text-[15px]"
        >
          <option v-for="opt in REASONS" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
        </select>
      </label>

      <label class="block text-sm">
        <span class="mb-1.5 block font-semibold text-[var(--ink)]">Notes (optional)</span>
        <textarea
          v-model="notes"
          rows="3"
          maxlength="2000"
          class="w-full rounded-[var(--radius)] border border-[var(--line)] bg-[var(--panel)] px-3 py-2 text-[15px]"
          placeholder="Anything that helps us review your request"
        />
      </label>

      <p v-if="submitError" class="text-sm text-[var(--danger)]">{{ submitError }}</p>

      <button
        type="submit"
        class="inline-flex h-11 items-center rounded-[var(--radius)] bg-[var(--brand)] px-4 text-sm font-semibold text-[var(--brand-fg)] disabled:opacity-50"
        :disabled="submitting"
      >
        {{ submitting ? 'Submitting…' : 'Submit return request' }}
      </button>
    </form>
  </div>
</template>
