<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ApiError } from '@/api/client'
import {
  fetchReturnableSale,
  processReturn,
  type ReturnableSale,
  type SaleReturnResource,
} from '@/api/posReturns'
import { usePosStore } from '@/stores/pos'

const pos = usePosStore()

const saleIdInput = ref('')
const sale = ref<ReturnableSale | null>(null)
const returnQty = ref<Record<number, string>>({})
const reason = ref('defective')
const refundMethod = ref<'cash' | 'card' | 'store_credit'>('cash')
const busy = ref(false)
const error = ref<string | null>(null)
const result = ref<SaleReturnResource | null>(null)

const reasons = [
  { value: 'defective', label: 'Defective' },
  { value: 'wrong_item', label: 'Wrong item' },
  { value: 'changed_mind', label: 'Changed mind' },
  { value: 'other', label: 'Other' },
]

const returnTotal = computed(() => {
  if (!sale.value) return '0.0000'
  let total = 0
  for (const item of sale.value.items) {
    const qty = Number(returnQty.value[item.id] ?? 0)
    if (qty <= 0) continue
    const returnable = Number(item.returnableQty)
    if (returnable <= 0) continue
    const ratio = qty / Number(item.qty)
    total += Number(item.lineTotal) * ratio
  }
  return total.toFixed(4)
})

const canSubmit = computed(
  () =>
    sale.value != null &&
    !busy.value &&
    Number(returnTotal.value) > 0 &&
    (pos.hasOpenSession || refundMethod.value !== 'cash'),
)

watch(
  () => pos.activeStub,
  (panel) => {
    if (panel !== 'return') return
    error.value = null
    result.value = null
    sale.value = null
    saleIdInput.value = ''
    returnQty.value = {}
    reason.value = 'defective'
    refundMethod.value = 'cash'
  },
)

async function lookupSale(): Promise<void> {
  const id = Number(saleIdInput.value.trim())
  if (!Number.isInteger(id) || id <= 0) {
    error.value = 'Enter a valid sale ID'
    return
  }

  busy.value = true
  error.value = null
  try {
    const res = await fetchReturnableSale(id)
    sale.value = res.data
    const qtyMap: Record<number, string> = {}
    for (const item of res.data.items) {
      qtyMap[item.id] = item.returnableQty !== '0.0000' ? item.returnableQty : ''
    }
    returnQty.value = qtyMap
  } catch (err) {
    sale.value = null
    error.value = err instanceof ApiError ? err.message : 'Could not load sale'
  } finally {
    busy.value = false
  }
}

async function submit(): Promise<void> {
  if (!sale.value || !canSubmit.value) return

  const items = sale.value.items
    .map((item) => ({
      saleItemId: item.id,
      qty: returnQty.value[item.id] ?? '0',
    }))
    .filter((line) => Number(line.qty) > 0)

  if (items.length === 0) {
    error.value = 'Select at least one line to return'
    return
  }

  busy.value = true
  error.value = null
  try {
    const res = await processReturn(
      {
        uuid: crypto.randomUUID(),
        saleId: sale.value.id,
        sessionId: pos.cashierSession?.id ?? null,
        reason: reason.value,
        items,
        refunds: [
          {
            method: refundMethod.value,
            amount: returnTotal.value,
            provider: 'manual',
          },
        ],
      },
      crypto.randomUUID(),
    )
    result.value = res.data
    await pos.refreshSession()
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Return failed'
  } finally {
    busy.value = false
  }
}

function done(): void {
  pos.closeStub()
}
</script>

<template>
  <div class="px-4 py-4 pb-6">
    <template v-if="result">
      <p class="font-display text-lg font-semibold text-[var(--ink)]">Return complete</p>
      <p class="mt-1 text-sm text-[var(--muted)]">
        Refund {{ result.total }} · Sale #{{ result.saleId }}
      </p>
      <button
        type="button"
        class="mt-6 min-h-11 w-full rounded-[var(--radius)] bg-[var(--primary)] px-4 font-medium text-[var(--primary-fg)]"
        @click="done"
      >
        Done
      </button>
    </template>

    <template v-else>
      <p class="text-sm text-[var(--muted)]">Look up a completed sale by receipt / sale ID.</p>

      <div class="mt-3 flex gap-2">
        <input
          v-model="saleIdInput"
          type="number"
          min="1"
          placeholder="Sale ID"
          class="min-h-11 flex-1 rounded-[var(--radius)] border border-[var(--line)] bg-[var(--surface)] px-3"
          @keydown.enter="lookupSale"
        />
        <button
          type="button"
          class="min-h-11 rounded-[var(--radius)] border border-[var(--line)] px-3 text-sm font-medium"
          :disabled="busy"
          @click="lookupSale"
        >
          Load
        </button>
      </div>

      <template v-if="sale">
        <p class="mt-4 text-sm font-medium text-[var(--ink)]">
          Sale #{{ sale.id }}
          <span v-if="sale.invoice?.invoiceNumber" class="text-[var(--muted)]">
            · {{ sale.invoice.invoiceNumber }}
          </span>
        </p>

        <div class="mt-3 max-h-48 space-y-2 overflow-y-auto">
          <div
            v-for="item in sale.items"
            :key="item.id"
            class="rounded border border-[var(--line)] p-2 text-sm"
          >
            <p class="font-medium">
              {{ item.variant?.name ?? 'Item' }}
              <span class="font-mono text-xs text-[var(--muted)]">{{ item.variant?.sku }}</span>
            </p>
            <p class="text-xs text-[var(--muted)]">
              Sold {{ item.qty }} · Returnable {{ item.returnableQty }}
            </p>
            <label class="mt-1 flex items-center gap-2">
              <span class="text-xs">Return qty</span>
              <input
                v-model="returnQty[item.id]"
                type="number"
                min="0"
                :max="item.returnableQty"
                step="0.0001"
                class="w-24 rounded border border-[var(--line)] px-2 py-1 text-sm"
                :disabled="Number(item.returnableQty) <= 0"
              />
            </label>
          </div>
        </div>

        <label class="mt-4 block text-sm">
          <span class="text-[var(--muted)]">Reason</span>
          <select
            v-model="reason"
            class="mt-1 w-full rounded-[var(--radius)] border border-[var(--line)] bg-[var(--surface)] px-3 py-2"
          >
            <option v-for="r in reasons" :key="r.value" :value="r.value">{{ r.label }}</option>
          </select>
        </label>

        <label class="mt-3 block text-sm">
          <span class="text-[var(--muted)]">Refund method</span>
          <select
            v-model="refundMethod"
            class="mt-1 w-full rounded-[var(--radius)] border border-[var(--line)] bg-[var(--surface)] px-3 py-2"
          >
            <option value="cash">Cash</option>
            <option value="card">Card (original)</option>
            <option value="store_credit">Store credit</option>
          </select>
        </label>

        <p class="mt-3 text-sm">
          Refund total:
          <strong class="text-[var(--ink)]">{{ returnTotal }}</strong>
        </p>

        <p v-if="refundMethod === 'cash' && !pos.hasOpenSession" class="mt-2 text-xs text-[var(--ember)]">
          Open a register session for cash refunds.
        </p>
      </template>

      <p v-if="error" class="mt-3 text-sm text-red-600" role="alert">{{ error }}</p>

      <button
        v-if="sale"
        type="button"
        class="mt-6 min-h-11 w-full rounded-[var(--radius)] bg-[var(--primary)] px-4 font-medium text-[var(--primary-fg)] disabled:opacity-50"
        :disabled="!canSubmit"
        @click="submit"
      >
        {{ busy ? 'Processing…' : 'Process return' }}
      </button>
    </template>
  </div>
</template>
