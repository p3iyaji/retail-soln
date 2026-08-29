<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ApiError } from '@/api/client'
import {
  collectInstallmentPayment,
  listInstallmentPlans,
  nextCollectibleInstallment,
  type InstallmentPaymentResource,
  type InstallmentPlanResource,
} from '@/api/posInstallments'
import { formatCurrency as formatMoney, moneyToApi, parseCurrencyInput } from '@/lib/decimal'
import { useAuthStore } from '@/stores/auth'
import { usePosStore } from '@/stores/pos'

const pos = usePosStore()
const auth = useAuthStore()

const searchQuery = ref('')
const plans = ref<InstallmentPlanResource[]>([])
const selectedPlan = ref<InstallmentPlanResource | null>(null)
const selectedInstallmentId = ref<number | null>(null)
const amount = ref('')
const method = ref<'cash' | 'card'>('cash')
const busy = ref(false)
const error = ref<string | null>(null)
const result = ref<InstallmentPaymentResource | null>(null)

const canCollect = computed(() => auth.user?.permissions?.includes('sales.installments.collect'))
const allowPartial = computed(() => auth.user?.permissions?.includes('sales.installments.partial'))

const selectedInstallment = computed(() =>
  selectedPlan.value?.installments.find((line) => line.id === selectedInstallmentId.value) ?? null,
)

const canSubmit = computed(
  () =>
    canCollect.value &&
    !busy.value &&
    pos.hasOpenSession &&
    selectedPlan.value != null &&
    selectedInstallment.value != null &&
    parseCurrencyInput(amount.value) !== '0.0000',
)

watch(
  () => pos.activeStub,
  (panel) => {
    if (panel !== 'installment') return
    error.value = null
    result.value = null
    selectedPlan.value = null
    selectedInstallmentId.value = null
    amount.value = ''
    searchQuery.value = pos.selectedCustomer && !pos.selectedCustomer.isWalkIn ? pos.selectedCustomer.name : ''
    void runSearch()
  },
)

watch(selectedInstallment, (line) => {
  if (line) {
    amount.value = formatMoney(line.balanceDue)
  }
})

async function runSearch(): Promise<void> {
  if (!canCollect.value) return
  busy.value = true
  error.value = null
  try {
    const res = await listInstallmentPlans({
      q: searchQuery.value.trim() || undefined,
      customerId:
        pos.selectedCustomer && !pos.selectedCustomer.isWalkIn ? pos.selectedCustomer.id : undefined,
    })
    plans.value = res.data
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Could not load plans'
    plans.value = []
  } finally {
    busy.value = false
  }
}

function selectPlan(plan: InstallmentPlanResource): void {
  selectedPlan.value = plan
  const next = nextCollectibleInstallment(plan)
  selectedInstallmentId.value = next?.id ?? plan.installments[0]?.id ?? null
}

async function submit(): Promise<void> {
  if (!canSubmit.value || !selectedPlan.value || !selectedInstallment.value) return
  const session = pos.cashierSession
  if (!session) {
    error.value = 'Open a register session before collecting'
    return
  }

  busy.value = true
  error.value = null
  try {
    const res = await collectInstallmentPayment(
      selectedPlan.value.id,
      {
        installmentId: selectedInstallment.value.id,
        sessionId: session.id,
        payment: {
          method: method.value,
          amount: moneyToApi(parseCurrencyInput(amount.value)),
          provider: 'manual',
        },
      },
      crypto.randomUUID(),
    )
    result.value = res.data
    await runSearch()
    if (selectedPlan.value) {
      const refreshed = plans.value.find((plan) => plan.id === selectedPlan.value?.id)
      if (refreshed) {
        selectPlan(refreshed)
      } else {
        selectedPlan.value = null
        selectedInstallmentId.value = null
      }
    }
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Collection failed'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="px-4 py-4">
    <template v-if="!canCollect">
      <p class="text-sm text-[var(--muted)]">You do not have permission to collect installments.</p>
    </template>

    <template v-else-if="result">
      <p class="font-display text-lg font-semibold text-[var(--ink)]">Payment collected</p>
      <dl class="mt-4 space-y-2 text-sm">
        <div class="flex justify-between">
          <dt>Amount</dt>
          <dd class="font-medium">{{ formatMoney(result.amount) }}</dd>
        </div>
        <div class="flex justify-between text-[var(--muted)]">
          <dt>Plan outstanding</dt>
          <dd>{{ formatMoney(result.statement?.planOutstanding ?? '0') }}</dd>
        </div>
        <div class="flex justify-between text-[var(--muted)]">
          <dt>Plan status</dt>
          <dd>{{ result.statement?.planStatus }}</dd>
        </div>
      </dl>
      <button
        type="button"
        class="mt-6 min-h-11 w-full rounded-[var(--radius)] bg-[var(--primary)] px-4 font-medium text-[var(--primary-fg)]"
        @click="result = null"
      >
        Collect another
      </button>
    </template>

    <template v-else>
      <label class="block text-sm">
        <span class="text-[var(--muted)]">Search plan, sale, or customer</span>
        <div class="mt-1 flex gap-2">
          <input
            v-model="searchQuery"
            type="search"
            class="min-w-0 flex-1 rounded-[var(--radius)] border border-[var(--line)] bg-[var(--surface)] px-3 py-2"
            @keydown.enter.prevent="runSearch"
          />
          <button
            type="button"
            class="rounded-[var(--radius)] border border-[var(--line)] px-3 text-sm"
            :disabled="busy"
            @click="runSearch"
          >
            Search
          </button>
        </div>
      </label>

      <ul v-if="plans.length" class="mt-4 max-h-40 space-y-2 overflow-y-auto">
        <li v-for="plan in plans" :key="plan.id">
          <button
            type="button"
            class="w-full rounded-[var(--radius)] border px-3 py-2 text-left text-sm"
            :class="
              selectedPlan?.id === plan.id
                ? 'border-[var(--primary)] bg-[var(--surface)]'
                : 'border-[var(--line)]'
            "
            @click="selectPlan(plan)"
          >
            <span class="font-medium text-[var(--ink)]">
              Plan #{{ plan.id }}
              <span v-if="plan.customer"> · {{ plan.customer.name }}</span>
            </span>
            <span class="mt-0.5 block text-xs text-[var(--muted)]">
              Sale #{{ plan.saleId }} · Outstanding {{ formatMoney(plan.outstandingBalance) }}
            </span>
          </button>
        </li>
      </ul>

      <div v-if="selectedPlan" class="mt-4 rounded-[var(--radius)] border border-[var(--line)] p-3">
        <p class="text-sm font-semibold text-[var(--ink)]">Installments</p>
        <ul class="mt-2 space-y-2">
          <li
            v-for="line in selectedPlan.installments"
            :key="line.id"
            class="flex items-center justify-between gap-2 text-sm"
          >
            <label class="flex flex-1 items-center gap-2">
              <input v-model="selectedInstallmentId" type="radio" :value="line.id" />
              <span>
                #{{ line.sequence }}
                <span
                  v-if="line.isOverdue || line.status === 'overdue'"
                  class="ml-1 text-xs font-semibold text-[var(--ember)]"
                >
                  Overdue
                </span>
              </span>
            </label>
            <span class="font-mono-pos text-xs">
              {{ formatMoney(line.balanceDue) }} due {{ line.dueAt }}
            </span>
          </li>
        </ul>

        <div class="mt-4 space-y-3">
          <label class="block text-sm">
            <span class="text-[var(--muted)]">Amount</span>
            <input
              v-model="amount"
              type="number"
              min="0"
              step="0.01"
              class="mt-1 w-full rounded-[var(--radius)] border border-[var(--line)] bg-[var(--surface)] px-3 py-2"
              :readonly="!allowPartial"
            />
          </label>
          <label class="block text-sm">
            <span class="text-[var(--muted)]">Method</span>
            <select
              v-model="method"
              class="mt-1 w-full rounded-[var(--radius)] border border-[var(--line)] bg-[var(--surface)] px-3 py-2"
            >
              <option value="cash">Cash</option>
              <option value="card">Card</option>
            </select>
          </label>
        </div>
      </div>

      <p v-if="error" class="mt-3 text-sm text-red-600" role="alert">{{ error }}</p>

      <button
        type="button"
        class="mt-6 min-h-11 w-full rounded-[var(--radius)] bg-[var(--primary)] px-4 font-medium text-[var(--primary-fg)] disabled:opacity-50"
        :disabled="!canSubmit"
        @click="submit"
      >
        {{ busy ? 'Processing…' : 'Collect payment' }}
      </button>
    </template>
  </div>
</template>
