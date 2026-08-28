<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ApiError } from '@/api/client'
import { completeSale, fetchReceipt, type ReceiptResource } from '@/api/posSales'
import { usePosStore } from '@/stores/pos'

const pos = usePosStore()

const cashAmount = ref('')
const cardAmount = ref('')
const busy = ref(false)
const error = ref<string | null>(null)
const receipt = ref<ReceiptResource | null>(null)

const total = computed(() => pos.cartTotals.total)
const remaining = computed(() => {
  const paid = parseAmount(cashAmount.value) + parseAmount(cardAmount.value)
  return Math.max(0, parseFloat(total.value) - paid)
})

const canComplete = computed(
  () =>
    pos.cartLines.length > 0 &&
    !busy.value &&
    pos.hasSelectedStore &&
    Math.abs(remaining.value) < 0.0001 &&
    parseAmount(cashAmount.value) + parseAmount(cardAmount.value) > 0,
)

function parseAmount(value: string): number {
  const n = Number(value)
  return Number.isFinite(n) ? n : 0
}

function formatMoney(value: string): string {
  const n = Number(value)
  return Number.isFinite(n) ? n.toFixed(2) : value
}

function resetTenders(): void {
  cashAmount.value = total.value
  cardAmount.value = ''
  error.value = null
  receipt.value = null
}

function fillCash(): void {
  cashAmount.value = total.value
  cardAmount.value = ''
}

watch(
  () => pos.activeStub,
  (panel) => {
    if (panel === 'payment') {
      resetTenders()
    }
  },
  { immediate: true },
)

watch(total, (next) => {
  if (pos.activeStub === 'payment' && !cardAmount.value) {
    cashAmount.value = next
  }
})

function buildPayments(): Array<{ method: 'cash' | 'card'; amount: string; provider: 'manual' }> {
  const payments: Array<{ method: 'cash' | 'card'; amount: string; provider: 'manual' }> = []
  const cash = parseAmount(cashAmount.value)
  const card = parseAmount(cardAmount.value)
  if (cash > 0) {
    payments.push({ method: 'cash', amount: cash.toFixed(4), provider: 'manual' })
  }
  if (card > 0) {
    payments.push({ method: 'card', amount: card.toFixed(4), provider: 'manual' })
  }
  return payments
}

async function submit(): Promise<void> {
  if (!canComplete.value || busy.value) return

  busy.value = true
  error.value = null
  try {
    const idempotencyKey = crypto.randomUUID()
    const saleUuid = crypto.randomUUID()
    const res = await completeSale(
      {
        uuid: saleUuid,
        customerId: null,
        items: pos.cartLines.map((line) => ({
          variantId: line.variantId,
          qty: line.qty,
          unitPrice: line.unitPrice,
          discount: line.discountAmount,
        })),
        payments: buildPayments(),
      },
      idempotencyKey,
    )

    const receiptRes = await fetchReceipt(res.data.id)
    receipt.value = receiptRes.data
    const heldId = pos.activeHeldSaleId
    pos.clearCart()
    if (heldId != null) {
      await pos.voidHeldAfterComplete(heldId)
    }
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Payment failed'
  } finally {
    busy.value = false
  }
}

function close(): void {
  receipt.value = null
  pos.closeStub()
}
</script>

<template>
  <div class="px-4 py-4">
    <template v-if="receipt">
      <p class="font-display text-lg font-semibold text-[var(--ink)]">Sale complete</p>
      <p class="mt-1 text-sm text-[var(--muted)]">
        Receipt #{{ receipt.invoiceNumber ?? receipt.saleId }} · {{ receipt.store?.name }}
      </p>
      <dl class="mt-4 space-y-2 text-sm">
        <div class="flex justify-between">
          <dt>Total</dt>
          <dd class="font-medium text-[var(--ink)]">{{ formatMoney(receipt.total) }}</dd>
        </div>
        <div class="flex justify-between text-[var(--muted)]">
          <dt>Tax</dt>
          <dd>{{ formatMoney(receipt.taxTotal) }}</dd>
        </div>
      </dl>
      <p class="mt-4 text-xs text-[var(--muted)]">Receipt delivery queued.</p>
      <button
        type="button"
        class="mt-6 min-h-11 w-full rounded-[var(--radius)] bg-[var(--accent)] px-4 font-medium text-white"
        @click="close"
      >
        Done
      </button>
    </template>

    <template v-else>
      <p class="text-sm text-[var(--muted)]">Amount due</p>
      <p class="font-display text-2xl font-semibold text-[var(--ink)]">{{ formatMoney(total) }}</p>

      <div class="mt-4 space-y-3">
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Cash</span>
          <input
            v-model="cashAmount"
            type="number"
            min="0"
            step="0.01"
            class="mt-1 w-full rounded-[var(--radius)] border border-[var(--line)] bg-[var(--surface)] px-3 py-2"
          />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Card</span>
          <input
            v-model="cardAmount"
            type="number"
            min="0"
            step="0.01"
            class="mt-1 w-full rounded-[var(--radius)] border border-[var(--line)] bg-[var(--surface)] px-3 py-2"
          />
        </label>
      </div>

      <p class="mt-2 text-xs text-[var(--muted)]">
        Remaining: {{ formatMoney(remaining.toFixed(4)) }}
        <button type="button" class="ml-2 underline" @click="fillCash">All cash</button>
      </p>

      <p v-if="error" class="mt-3 text-sm text-red-600" role="alert">{{ error }}</p>

      <button
        type="button"
        class="mt-6 min-h-11 w-full rounded-[var(--radius)] bg-[var(--accent)] px-4 font-medium text-white disabled:opacity-50"
        :disabled="!canComplete"
        @click="submit"
      >
        {{ busy ? 'Processing…' : 'Complete payment' }}
      </button>
    </template>
  </div>
</template>
