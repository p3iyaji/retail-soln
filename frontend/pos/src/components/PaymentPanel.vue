<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ApiError } from '@/api/client'
import { completeSale, fetchReceipt, printReceipt, type ReceiptResource } from '@/api/posSales'
import {
  decimalAdd,
  decimalCmp,
  decimalSub,
  formatCurrency as formatMoney,
  moneyToApi,
  parseCurrencyInput,
  roundCurrency,
} from '@/lib/decimal'
import {
  buildEqualInstallments,
  installmentsMatchBalance,
  sumInstallments,
  toInstallmentPlanPayload,
  type InstallmentDraft,
} from '@/lib/installmentSchedule'
import { useAuthStore } from '@/stores/auth'
import { usePosStore } from '@/stores/pos'

const pos = usePosStore()
const auth = useAuthStore()

const cashAmount = ref('')
const cardAmount = ref('')
const useInstallmentPlan = ref(false)
const installmentCount = ref(2)
const installmentRows = ref<InstallmentDraft[]>([])
const busy = ref(false)
const error = ref<string | null>(null)
const receipt = ref<ReceiptResource | null>(null)

const dueTotal = computed(() => pos.cartTotals.total)

const depositTotal = computed(() =>
  decimalAdd(parseCurrencyInput(cashAmount.value), parseCurrencyInput(cardAmount.value)),
)

const financedBalance = computed(() => {
  const balance = decimalSub(dueTotal.value, depositTotal.value)
  return decimalCmp(balance, roundCurrency('0')) < 0 ? roundCurrency('0') : roundCurrency(balance)
})

const canCreateInstallments = computed(
  () =>
    Boolean(auth.user?.permissions?.includes('sales.installments.create')) &&
    pos.selectedCustomer != null &&
    !pos.selectedCustomer.isWalkIn,
)

const remainingTender = computed(() => {
  if (useInstallmentPlan.value) {
    return financedBalance.value
  }
  if (decimalCmp(depositTotal.value, dueTotal.value) >= 0) {
    return roundCurrency('0')
  }
  return roundCurrency(decimalSub(dueTotal.value, depositTotal.value))
})

const scheduleValid = computed(() => {
  if (!useInstallmentPlan.value) return true
  if (decimalCmp(depositTotal.value, dueTotal.value) >= 0) return false
  if (decimalCmp(depositTotal.value, roundCurrency('0')) <= 0) return false
  return installmentsMatchBalance(installmentRows.value, financedBalance.value)
})

const canComplete = computed(() => {
  if (
    pos.cartLines.length === 0 ||
    busy.value ||
    !pos.hasSelectedStore ||
    !pos.hasOpenSession ||
    decimalCmp(depositTotal.value, roundCurrency('0')) <= 0
  ) {
    return false
  }

  if (useInstallmentPlan.value) {
    return scheduleValid.value
  }

  return decimalCmp(depositTotal.value, dueTotal.value) === 0
})

function rebuildSchedule(): void {
  installmentRows.value = buildEqualInstallments(financedBalance.value, installmentCount.value)
}

function resetTenders(): void {
  useInstallmentPlan.value = false
  installmentCount.value = 2
  installmentRows.value = []
  cashAmount.value = formatMoney(dueTotal.value)
  cardAmount.value = ''
  error.value = null
  receipt.value = null
}

function fillCashDeposit(): void {
  if (useInstallmentPlan.value) {
    cashAmount.value = formatMoney(depositTotal.value || dueTotal.value)
    cardAmount.value = ''
    rebuildSchedule()
    return
  }
  cashAmount.value = formatMoney(dueTotal.value)
  cardAmount.value = ''
}

function enableInstallmentPlan(): void {
  useInstallmentPlan.value = true
  cashAmount.value = ''
  cardAmount.value = ''
  rebuildSchedule()
}

watch(
  () => pos.activeStub,
  (panel) => {
    if (panel === 'payment') {
      resetTenders()
      if (canCreateInstallments.value) {
        useInstallmentPlan.value = true
        enableInstallmentPlan()
      }
    }
  },
  { immediate: true },
)

watch([financedBalance, installmentCount], () => {
  if (useInstallmentPlan.value) {
    rebuildSchedule()
  }
})

watch(useInstallmentPlan, (enabled) => {
  if (enabled) {
    enableInstallmentPlan()
  } else {
    cashAmount.value = formatMoney(dueTotal.value)
    cardAmount.value = ''
    installmentRows.value = []
  }
})

function buildPayments(): Array<{ method: 'cash' | 'card'; amount: string; provider: 'manual' }> {
  const due = useInstallmentPlan.value ? depositTotal.value : dueTotal.value
  let cash = parseCurrencyInput(cashAmount.value)
  let card = parseCurrencyInput(cardAmount.value)

  if (decimalCmp(card, roundCurrency('0')) === 0) {
    cash = due
  } else if (decimalCmp(cash, roundCurrency('0')) === 0) {
    card = due
  } else {
    card = roundCurrency(decimalSub(due, cash))
  }

  const payments: Array<{ method: 'cash' | 'card'; amount: string; provider: 'manual' }> = []
  if (decimalCmp(cash, roundCurrency('0')) > 0) {
    payments.push({ method: 'cash', amount: moneyToApi(cash), provider: 'manual' })
  }
  if (decimalCmp(card, roundCurrency('0')) > 0) {
    payments.push({ method: 'card', amount: moneyToApi(card), provider: 'manual' })
  }
  return payments
}

function resolveCustomerId(): number | null {
  const customer = pos.selectedCustomer
  if (!customer || customer.isWalkIn) return null
  return customer.id
}

async function submit(): Promise<void> {
  if (!canComplete.value || busy.value) return
  const session = pos.cashierSession
  if (!session) {
    error.value = 'Open a register session before taking payment'
    return
  }

  if (useInstallmentPlan.value && !canCreateInstallments.value) {
    error.value = 'Select a registered customer to create an installment plan'
    return
  }

  busy.value = true
  error.value = null
  try {
    const idempotencyKey = crypto.randomUUID()
    const saleUuid = crypto.randomUUID()
    const body = {
      uuid: saleUuid,
      customerId: resolveCustomerId(),
      sessionId: session.id,
      terminalId: session.terminalId,
      items: pos.cartLines.map((line) => ({
        variantId: line.variantId,
        qty: line.qty,
        unitPrice: line.unitPrice,
        discount: line.discountAmount,
      })),
      payments: buildPayments(),
      ...(useInstallmentPlan.value
        ? { installmentPlan: toInstallmentPlanPayload(installmentRows.value) }
        : {}),
    }

    const res = await completeSale(body, idempotencyKey)

    const receiptRes = await fetchReceipt(res.data.id)
    receipt.value = receiptRes.data
    try {
      await printReceipt(res.data.id)
    } catch {
      // Print queue failure should not block checkout success.
    }
    const heldId = pos.activeHeldSaleId
    pos.clearCart()
    await pos.refreshSession()
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
        class="mt-6 min-h-11 w-full rounded-[var(--radius)] bg-[var(--primary)] px-4 font-medium text-[var(--primary-fg)]"
        @click="close"
      >
        Done
      </button>
    </template>

    <template v-else>
      <div
        v-if="pos.selectedCustomer && !pos.selectedCustomer.isWalkIn"
        class="mb-4 rounded-[var(--radius)] bg-[var(--surface)] px-3 py-2 text-sm"
      >
        <strong>{{ pos.selectedCustomer.name }}</strong>
        <span class="mt-0.5 block text-xs text-[var(--muted)]">{{ pos.selectedCustomer.accountNumber }}</span>
      </div>

      <p class="text-sm text-[var(--muted)]">{{ useInstallmentPlan ? 'Sale total' : 'Amount due' }}</p>
      <p class="font-display text-2xl font-semibold text-[var(--ink)]">{{ formatMoney(dueTotal) }}</p>

      <div v-if="canCreateInstallments" class="mt-4">
        <label class="flex items-center gap-2 text-sm">
          <input v-model="useInstallmentPlan" type="checkbox" />
          <span>Finance balance with installment plan</span>
        </label>
      </div>

      <div class="mt-4 space-y-3">
        <p class="text-xs font-semibold uppercase tracking-wide text-[var(--muted)]">
          {{ useInstallmentPlan ? 'Deposit now' : 'Payment' }}
        </p>
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
        <template v-if="useInstallmentPlan">
          Deposit: {{ formatMoney(depositTotal) }} · Financed: {{ formatMoney(financedBalance) }}
        </template>
        <template v-else>
          Remaining: {{ formatMoney(remainingTender) }}
        </template>
        <button type="button" class="ml-2 underline" @click="fillCashDeposit">
          {{ useInstallmentPlan ? 'Reset deposit' : 'All cash' }}
        </button>
      </p>

      <div v-if="useInstallmentPlan" class="mt-4 rounded-[var(--radius)] border border-[var(--line)] p-3">
        <div class="mb-3 flex items-center justify-between gap-2">
          <p class="text-sm font-semibold text-[var(--ink)]">Installment schedule</p>
          <label class="flex items-center gap-2 text-xs text-[var(--muted)]">
            Count
            <input
              v-model.number="installmentCount"
              type="number"
              min="1"
              max="24"
              class="w-14 rounded border border-[var(--line)] px-2 py-1"
            />
          </label>
        </div>
        <div class="space-y-2">
          <div
            v-for="row in installmentRows"
            :key="row.sequence"
            class="grid grid-cols-[2rem_1fr_1fr] items-center gap-2 text-sm"
          >
            <span class="text-[var(--muted)]">{{ row.sequence }}</span>
            <input v-model="row.dueAt" type="date" class="rounded border border-[var(--line)] px-2 py-1" />
            <input
              v-model="row.amount"
              type="number"
              min="0"
              step="0.01"
              class="rounded border border-[var(--line)] px-2 py-1 font-mono-pos"
            />
          </div>
        </div>
        <p class="mt-3 flex justify-between text-xs font-medium text-[var(--ink)]">
          <span>Schedule total</span>
          <span>{{ formatMoney(sumInstallments(installmentRows)) }}</span>
        </p>
        <p v-if="!scheduleValid" class="mt-2 text-xs text-red-600">
          Installments must equal the financed balance ({{ formatMoney(financedBalance) }}).
        </p>
      </div>

      <p v-if="error" class="mt-3 text-sm text-red-600" role="alert">{{ error }}</p>

      <button
        type="button"
        class="mt-6 min-h-11 w-full rounded-[var(--radius)] bg-[var(--primary)] px-4 font-medium text-[var(--primary-fg)] disabled:opacity-50"
        :disabled="!canComplete"
        @click="submit"
      >
        {{ busy ? 'Processing…' : useInstallmentPlan ? 'Complete sale with plan' : 'Complete payment' }}
      </button>
    </template>
  </div>
</template>
