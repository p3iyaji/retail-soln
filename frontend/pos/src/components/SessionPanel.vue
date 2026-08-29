<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { ApiError } from '@/api/client'
import {
  closeCashierSession,
  recordCashMovement,
  type CashierSessionResource,
} from '@/api/posSessions'
import { usePosStore } from '@/stores/pos'

const pos = usePosStore()

const mode = ref<'open' | 'manage' | 'close' | 'result'>('open')
const openingFloat = ref('0.00')
const closingCash = ref('')
const movementType = ref<'cash_in' | 'cash_out'>('cash_in')
const movementAmount = ref('')
const movementNotes = ref('')
const closeNotes = ref('')
const busy = ref(false)
const error = ref<string | null>(null)
const closedSession = ref<CashierSessionResource | null>(null)

const expectedCash = computed(() => {
  const session = pos.cashierSession
  if (!session) return '0.0000'
  const opening = Number(session.openingFloat)
  const summary = session.summary
  if (!summary) return session.openingFloat
  const total =
    opening +
    Number(summary.cashIn) +
    Number(summary.saleCash) -
    Number(summary.cashOut) -
    Number(summary.refundCash)
  return total.toFixed(4)
})

watch(
  () => pos.activeStub,
  (panel) => {
    if (panel !== 'session') return
    resetPanelState()
  },
)

watch(
  () => pos.hasOpenSession,
  (open) => {
    if (pos.activeStub !== 'session') return
    if (open && mode.value === 'open') {
      mode.value = 'manage'
    }
    if (!open && mode.value === 'manage') {
      mode.value = 'open'
    }
  },
)

function resetPanelState(): void {
  error.value = null
  closedSession.value = null
  if (pos.hasOpenSession) {
    mode.value = 'manage'
    void pos.refreshSession()
  } else {
    mode.value = 'open'
    openingFloat.value = '0.00'
  }
}

onMounted(() => {
  if (pos.activeStub === 'session') {
    resetPanelState()
  }
})

function formatMoney(value: string | number): string {
  const n = Number(value)
  return Number.isFinite(n) ? n.toFixed(2) : String(value)
}

async function submitOpen(): Promise<void> {
  busy.value = true
  error.value = null
  try {
    await pos.openSession(openingFloat.value)
    mode.value = 'manage'
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Could not open session'
  } finally {
    busy.value = false
  }
}

async function submitMovement(): Promise<void> {
  const session = pos.cashierSession
  if (!session || busy.value) return
  const amount = movementAmount.value.trim()
  if (!amount || Number(amount) <= 0) {
    error.value = 'Enter an amount greater than zero'
    return
  }

  busy.value = true
  error.value = null
  try {
    await recordCashMovement(session.id, {
      type: movementType.value,
      amount: Number(amount).toFixed(4),
      notes: movementNotes.value.trim() || null,
    })
    await pos.refreshSession()
    movementAmount.value = ''
    movementNotes.value = ''
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Cash movement failed'
  } finally {
    busy.value = false
  }
}

function startClose(): void {
  closingCash.value = expectedCash.value
  closeNotes.value = ''
  mode.value = 'close'
  error.value = null
}

async function submitClose(): Promise<void> {
  const session = pos.cashierSession
  if (!session || busy.value) return

  busy.value = true
  error.value = null
  try {
    const res = await closeCashierSession(session.id, {
      closingCash: Number(closingCash.value || 0).toFixed(4),
      notes: closeNotes.value.trim() || null,
    })
    closedSession.value = res.data
    pos.clearSession()
    mode.value = 'result'
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Could not close session'
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
    <template v-if="mode === 'open'">
      <p class="font-display text-lg font-semibold text-[var(--ink)]">Open register session</p>
      <p class="mt-1 text-sm text-[var(--muted)]">
        Terminal {{ pos.terminalLabel }} · count the opening float in the drawer.
      </p>

      <label class="mt-4 block text-sm">
        <span class="text-[var(--muted)]">Opening float</span>
        <input
          v-model="openingFloat"
          type="number"
          min="0"
          step="0.01"
          class="mt-1 w-full rounded-[var(--radius)] border border-[var(--line)] bg-[var(--surface)] px-3 py-2"
        />
      </label>

      <p v-if="error" class="mt-3 text-sm text-red-600" role="alert">{{ error }}</p>

      <button
        type="button"
        class="mt-6 min-h-11 w-full rounded-[var(--radius)] bg-[var(--primary)] px-4 font-medium text-[var(--primary-fg)] disabled:opacity-50"
        :disabled="busy"
        @click="submitOpen"
      >
        {{ busy ? 'Opening…' : 'Open session' }}
      </button>
    </template>

    <template v-else-if="mode === 'manage'">
      <p v-if="pos.sessionBusy" class="text-sm text-[var(--muted)]">Loading session…</p>
      <template v-else-if="pos.cashierSession">
      <p class="font-display text-lg font-semibold text-[var(--ink)]">Session open</p>
      <p class="mt-1 text-sm text-[var(--muted)]">
        Opened {{ pos.cashierSession.openedAt ? new Date(pos.cashierSession.openedAt).toLocaleTimeString() : '—' }}
      </p>

      <dl class="mt-4 space-y-2 text-sm">
        <div class="flex justify-between">
          <dt>Opening float</dt>
          <dd>{{ formatMoney(pos.cashierSession.openingFloat) }}</dd>
        </div>
        <div class="flex justify-between font-medium text-[var(--ink)]">
          <dt>Expected cash</dt>
          <dd>{{ formatMoney(expectedCash) }}</dd>
        </div>
      </dl>

      <div class="mt-4 space-y-2 rounded border border-[var(--line)] p-3">
        <p class="text-xs font-semibold uppercase tracking-wide text-[var(--muted)]">Cash in / out</p>
        <div class="flex gap-2">
          <select
            v-model="movementType"
            class="min-h-10 flex-1 rounded border border-[var(--line)] bg-[var(--surface)] px-2 text-sm"
          >
            <option value="cash_in">Cash in</option>
            <option value="cash_out">Cash out</option>
          </select>
          <input
            v-model="movementAmount"
            type="number"
            min="0"
            step="0.01"
            placeholder="Amount"
            class="min-h-10 w-28 rounded border border-[var(--line)] bg-[var(--surface)] px-2 text-sm"
          />
        </div>
        <input
          v-model="movementNotes"
          type="text"
          placeholder="Notes (optional)"
          class="w-full rounded border border-[var(--line)] bg-[var(--surface)] px-2 py-2 text-sm"
        />
        <button
          type="button"
          class="min-h-10 w-full rounded border border-[var(--line)] bg-[var(--surface)] text-sm font-medium hover:bg-[var(--panel)]"
          :disabled="busy"
          @click="submitMovement"
        >
          Record movement
        </button>
      </div>

      <p v-if="error" class="mt-3 text-sm text-red-600" role="alert">{{ error }}</p>

      <button
        type="button"
        class="mt-6 min-h-11 w-full rounded-[var(--radius)] bg-[var(--primary)] px-4 font-medium text-[var(--primary-fg)]"
        @click="startClose"
      >
        Close session
      </button>
      </template>
      <template v-else>
        <p class="text-sm text-[var(--muted)]">No open session on this terminal.</p>
        <button
          type="button"
          class="mt-4 min-h-11 w-full rounded-[var(--radius)] bg-[var(--primary)] px-4 font-medium text-[var(--primary-fg)]"
          @click="mode = 'open'"
        >
          Open session
        </button>
      </template>
    </template>

    <template v-else-if="mode === 'close'">
      <p class="font-display text-lg font-semibold text-[var(--ink)]">Close session</p>
      <p class="mt-1 text-sm text-[var(--muted)]">Expected cash: {{ formatMoney(expectedCash) }}</p>

      <label class="mt-4 block text-sm">
        <span class="text-[var(--muted)]">Actual cash counted</span>
        <input
          v-model="closingCash"
          type="number"
          min="0"
          step="0.01"
          class="mt-1 w-full rounded-[var(--radius)] border border-[var(--line)] bg-[var(--surface)] px-3 py-2"
        />
      </label>

      <label class="mt-3 block text-sm">
        <span class="text-[var(--muted)]">Notes</span>
        <textarea
          v-model="closeNotes"
          rows="2"
          class="mt-1 w-full rounded-[var(--radius)] border border-[var(--line)] bg-[var(--surface)] px-3 py-2"
        />
      </label>

      <p v-if="error" class="mt-3 text-sm text-red-600" role="alert">{{ error }}</p>

      <div class="mt-6 flex gap-2">
        <button
          type="button"
          class="min-h-11 flex-1 rounded border border-[var(--line)] bg-[var(--surface)] font-medium hover:bg-[var(--panel)]"
          @click="mode = 'manage'"
        >
          Back
        </button>
        <button
          type="button"
          class="min-h-11 flex-1 rounded-[var(--radius)] bg-[var(--primary)] font-medium text-[var(--primary-fg)] disabled:opacity-50"
          :disabled="busy"
          @click="submitClose"
        >
          {{ busy ? 'Closing…' : 'Confirm close' }}
        </button>
      </div>
    </template>

    <template v-else-if="mode === 'result' && closedSession">
      <p class="font-display text-lg font-semibold text-[var(--ink)]">Session closed</p>
      <dl class="mt-4 space-y-2 text-sm">
        <div class="flex justify-between">
          <dt>Expected</dt>
          <dd>{{ formatMoney(closedSession.expectedCash ?? '0') }}</dd>
        </div>
        <div class="flex justify-between">
          <dt>Counted</dt>
          <dd>{{ formatMoney(closedSession.closingCash ?? '0') }}</dd>
        </div>
        <div class="flex justify-between font-medium" :class="Number(closedSession.variance) === 0 ? 'text-green-700' : 'text-[var(--ember)]'">
          <dt>Variance</dt>
          <dd>{{ formatMoney(closedSession.variance ?? '0') }}</dd>
        </div>
      </dl>
      <button
        type="button"
        class="mt-6 min-h-11 w-full rounded-[var(--radius)] bg-[var(--primary)] px-4 font-medium text-[var(--primary-fg)]"
        @click="done"
      >
        Done
      </button>
    </template>
  </div>
</template>
