<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { fetchWalkInCustomer, searchCustomers, type CustomerResource } from '@/api/posCustomers'
import { availableCredit } from '@/lib/installmentSchedule'
import { formatCurrency } from '@/lib/decimal'
import { usePosStore } from '@/stores/pos'

const pos = usePosStore()

const query = ref('')
const results = ref<CustomerResource[]>([])
const busy = ref(false)
const error = ref<string | null>(null)

const selected = computed(() => pos.selectedCustomer)

const creditSummary = computed(() => {
  const customer = selected.value
  if (!customer || customer.isWalkIn) return null
  const available = availableCredit(customer.creditLimit, customer.balance)
  return {
    available,
    ok: true,
  }
})

let searchTimer: ReturnType<typeof setTimeout> | null = null

watch(query, (value) => {
  if (searchTimer) clearTimeout(searchTimer)
  const term = value.trim()
  if (term.length < 2) {
    results.value = []
    return
  }
  searchTimer = setTimeout(() => void runSearch(term), 250)
})

async function runSearch(term: string): Promise<void> {
  busy.value = true
  error.value = null
  try {
    const res = await searchCustomers(term)
    results.value = res.data.filter((c) => !c.isWalkIn)
  } catch {
    error.value = 'Customer search failed'
    results.value = []
  } finally {
    busy.value = false
  }
}

function selectCustomer(customer: CustomerResource): void {
  pos.setSelectedCustomer(customer)
  query.value = ''
  results.value = []
  pos.closeStub()
}

async function useWalkIn(): Promise<void> {
  busy.value = true
  error.value = null
  try {
    const res = await fetchWalkInCustomer()
    pos.setSelectedCustomer(res.data)
    pos.closeStub()
  } catch {
    error.value = 'Could not load walk-in customer'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="px-4 py-4">
    <p v-if="selected" class="mb-4 rounded-[var(--radius)] bg-[var(--surface)] px-3 py-2 text-sm">
      <span class="text-[var(--muted)]">Current:</span>
      <strong class="ml-1 text-[var(--ink)]">{{ selected.name }}</strong>
      <span v-if="!selected.isWalkIn" class="mt-1 block text-xs text-[var(--muted)]">
        {{ selected.accountNumber }} · Credit available {{ formatCurrency(creditSummary?.available ?? '0') }}
      </span>
      <span v-else class="mt-1 block text-xs text-[var(--muted)]">Walk-in · no installment plans</span>
    </p>

    <label class="block text-sm">
      <span class="text-[var(--muted)]">Search name, phone, or account</span>
      <input
        v-model="query"
        type="search"
        class="mt-1 w-full rounded-[var(--radius)] border border-[var(--line)] bg-[var(--surface)] px-3 py-2"
        placeholder="Type to search…"
      />
    </label>

    <p v-if="busy" class="mt-3 text-sm text-[var(--muted)]">Searching…</p>
    <p v-else-if="error" class="mt-3 text-sm text-red-600">{{ error }}</p>

    <ul v-if="results.length" class="mt-3 max-h-56 space-y-1 overflow-y-auto">
      <li v-for="customer in results" :key="customer.id">
        <button
          type="button"
          class="w-full rounded-[var(--radius)] border border-[var(--line)] px-3 py-2 text-left text-sm hover:bg-[var(--surface)]"
          @click="selectCustomer(customer)"
        >
          <span class="font-medium text-[var(--ink)]">{{ customer.name }}</span>
          <span class="mt-0.5 block text-xs text-[var(--muted)]">
            {{ customer.accountNumber }}
            <template v-if="customer.phone"> · {{ customer.phone }}</template>
          </span>
        </button>
      </li>
    </ul>

    <button
      type="button"
      class="mt-4 min-h-11 w-full rounded-[var(--radius)] border border-[var(--line)] bg-[var(--surface)] text-sm"
      :disabled="busy"
      @click="useWalkIn"
    >
      Use walk-in customer
    </button>
  </div>
</template>
