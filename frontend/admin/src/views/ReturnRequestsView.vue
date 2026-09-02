<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

type Store = { id: number; name: string }

type ReturnRequestListItem = {
  id: number
  orderId: number
  orderNumber: string | null
  status: string
  statusLabel: string
  reasonLabel: string
  customerName: string | null
  createdAt: string | null
  actionable: boolean
}

type ReturnRequestDetail = ReturnRequestListItem & {
  reason: string
  notes: string | null
  allowedStatuses: string[]
  refundTotal?: string
  canComplete?: boolean
  orderPaymentProvider?: string | null
  refunds?: Array<{
    id: number
    provider: string
    method: string
    amount: string
    status: string
    statusLabel: string
    reference: string | null
  }>
  customer?: { name?: string | null; email?: string | null }
  items: Array<{
    id: number
    orderItemId: number
    qty: string
    lineTotal?: string
    productName: string | null
    variantName: string | null
    sku: string | null
  }>
}

const STATUS_LABELS: Record<string, string> = {
  under_review: 'Under review',
  approved: 'Approved',
  rejected: 'Rejected',
  completed: 'Completed',
}

const auth = useAuthStore()
const canView = computed(
  () =>
    auth.user?.permissions?.includes('fulfilment.pick') === true ||
    auth.user?.permissions?.includes('fulfilment.ship') === true ||
    auth.user?.permissions?.includes('fulfilment.pickup') === true,
)
const canManage = computed(() => auth.user?.permissions?.includes('fulfilment.pick') === true)

const stores = ref<Store[]>([])
const storeId = ref<number | ''>('')
const rows = ref<ReturnRequestListItem[]>([])
const openCount = ref(0)
const selected = ref<ReturnRequestDetail | null>(null)
const openOnly = ref(true)
const loading = ref(true)
const detailLoading = ref(false)
const saving = ref(false)
const completing = ref(false)
const error = ref('')
const message = ref('')
const refundReference = ref('')
const refundMethod = ref('bank')

function statusActionLabel(status: string): string {
  return STATUS_LABELS[status] ?? status.replace(/_/g, ' ')
}

async function loadStores() {
  const access = auth.user?.storeAccess ?? []
  if (access.length > 0) {
    stores.value = access.map((s) => ({ id: s.id, name: s.name }))
  } else {
    const res = await api.get<{ data: Store[] }>('/stores?per_page=50')
    stores.value = res.data
  }

  if (!storeId.value) {
    const preferred = access.find((s) => s.isDefault)?.id ?? stores.value[0]?.id
    storeId.value = preferred ?? ''
  } else if (!stores.value.some((s) => s.id === storeId.value)) {
    storeId.value = stores.value[0]?.id ?? ''
  }
}

async function loadRequests() {
  if (!canView.value || !storeId.value) {
    rows.value = []
    openCount.value = 0
    return
  }

  const query = openOnly.value ? '?openOnly=1&per_page=50' : '?per_page=50'
  const res = await api.get<{
    data: ReturnRequestListItem[]
    meta: { openCount: number }
  }>(`/fulfilment/return-requests${query}`, { 'X-Store-Id': String(storeId.value) })
  rows.value = res.data
  openCount.value = res.meta.openCount ?? 0
}

async function openRequest(id: number) {
  if (!storeId.value) return
  detailLoading.value = true
  error.value = ''
  try {
    const res = await api.get<{ data: ReturnRequestDetail }>(`/fulfilment/return-requests/${id}`, {
      'X-Store-Id': String(storeId.value),
    })
    selected.value = res.data
    refundReference.value = ''
    refundMethod.value = 'bank'
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load return request'
    selected.value = null
  } finally {
    detailLoading.value = false
  }
}

async function refresh() {
  if (!canView.value) {
    loading.value = false
    error.value = 'You do not have permission to view return requests.'
    return
  }

  loading.value = true
  error.value = ''
  message.value = ''
  try {
    await loadStores()
    await loadRequests()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load return requests'
  } finally {
    loading.value = false
  }
}

async function completeReturn() {
  if (!selected.value || !storeId.value || completing.value || !canManage.value || !selected.value.canComplete) {
    return
  }

  const amount = selected.value.refundTotal ?? '0'
  const provider = selected.value.orderPaymentProvider === 'stripe' || selected.value.orderPaymentProvider === 'paystack'
    ? selected.value.orderPaymentProvider
    : 'manual'

  const confirmed = window.confirm(
    `Complete return #${selected.value.id}? This restocks items and records a ${provider} refund of ${amount}.`,
  )
  if (!confirmed) return

  completing.value = true
  error.value = ''
  message.value = ''
  const idempotencyKey = crypto.randomUUID().slice(0, 36)
  try {
    const refundPayload =
      provider === 'manual'
        ? {
            provider: 'manual',
            method: refundMethod.value,
            amount,
            reference: refundReference.value.trim() || null,
          }
        : { provider, amount }

    const res = await api.post<{ data: ReturnRequestDetail }>(
      `/fulfilment/return-requests/${selected.value.id}/complete`,
      { refunds: [refundPayload] },
      {
        'X-Store-Id': String(storeId.value),
        'Idempotency-Key': idempotencyKey,
      },
    )
    selected.value = res.data
    message.value = `Return completed — refund ${amount} recorded`
    await loadRequests()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Completion failed'
  } finally {
    completing.value = false
  }
}

async function updateStatus(status: string) {
  if (!selected.value || !storeId.value || saving.value || !canManage.value) return

  const label = statusActionLabel(status)
  const confirmed = window.confirm(`Mark return request #${selected.value.id} as ${label}?`)
  if (!confirmed) return

  saving.value = true
  error.value = ''
  message.value = ''
  try {
    const res = await api.patch<{ data: ReturnRequestDetail }>(
      `/fulfilment/return-requests/${selected.value.id}`,
      { status },
      { 'X-Store-Id': String(storeId.value) },
    )
    selected.value = res.data
    message.value = `Return request updated to ${res.data.statusLabel}`
    await loadRequests()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Status update failed'
  } finally {
    saving.value = false
  }
}

watch(storeId, () => {
  selected.value = null
  if (canView.value) {
    void loadRequests().catch((e) => {
      error.value = e instanceof ApiError ? e.message : 'Failed to load return requests'
    })
  }
})

watch(openOnly, () => {
  selected.value = null
  if (canView.value && storeId.value) {
    void loadRequests().catch((e) => {
      error.value = e instanceof ApiError ? e.message : 'Failed to load return requests'
    })
  }
})

onMounted(() => {
  void refresh()
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h2 class="text-base font-semibold">Return requests</h2>
        <p class="text-sm text-[var(--muted)]">
          Review customer portal return requests. Approve or reject after inspection, then complete to
          restock and record refunds.
        </p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <label class="text-sm">
          <span class="text-[var(--muted)]">Store</span>
          <select
            v-model="storeId"
            class="mt-1 block min-w-[12rem] rounded-md border border-[var(--line)] bg-white px-3 py-1.5"
            :disabled="!canView"
          >
            <option disabled value="">Select store</option>
            <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </label>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="openOnly" type="checkbox" class="rounded border-[var(--line)]" :disabled="!canView" />
          Open only
          <span v-if="openCount > 0" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-900">
            {{ openCount }} open
          </span>
        </label>
        <button
          type="button"
          class="rounded-md border border-[var(--line)] px-3 py-1.5 text-sm hover:bg-stone-50 disabled:opacity-60"
          :disabled="loading || !canView"
          @click="refresh"
        >
          Refresh
        </button>
      </div>
    </div>

    <p v-if="error" class="text-sm text-red-700">{{ error }}</p>
    <p v-if="message" class="text-sm text-teal-800">{{ message }}</p>

    <div v-if="loading" class="text-sm text-[var(--muted)]">Loading…</div>
    <div v-else-if="!canView" class="text-sm text-[var(--muted)]">
      Return requests require <code class="text-xs">fulfilment.pick</code>,
      <code class="text-xs">fulfilment.ship</code>, or
      <code class="text-xs">fulfilment.pickup</code>.
    </div>
    <div v-else class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
      <div class="overflow-x-auto rounded-md border border-[var(--line)]">
        <table class="min-w-full text-left text-sm">
          <thead class="border-b border-[var(--line)] bg-stone-50 text-xs text-[var(--muted)] uppercase">
            <tr>
              <th class="px-3 py-2 font-medium">Request</th>
              <th class="px-3 py-2 font-medium">Order</th>
              <th class="px-3 py-2 font-medium">Status</th>
              <th class="px-3 py-2 font-medium">Customer</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="rows.length === 0">
              <td colspan="4" class="px-3 py-6 text-[var(--muted)]">
                No return requests for this store{{ openOnly ? ' (open)' : '' }}.
              </td>
            </tr>
            <tr
              v-for="row in rows"
              :key="row.id"
              class="cursor-pointer border-b border-[var(--line)] last:border-0 hover:bg-stone-50"
              :class="selected?.id === row.id ? 'bg-teal-50/60' : ''"
              @click="openRequest(row.id)"
            >
              <td class="px-3 py-2 font-medium">#{{ row.id }}</td>
              <td class="px-3 py-2 font-mono text-sm">{{ row.orderNumber ?? row.orderId }}</td>
              <td class="px-3 py-2">{{ row.statusLabel }}</td>
              <td class="px-3 py-2">{{ row.customerName ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="rounded-md border border-[var(--line)] p-4">
        <div v-if="detailLoading" class="text-sm text-[var(--muted)]">Loading request…</div>
        <div v-else-if="!selected" class="text-sm text-[var(--muted)]">
          Select a return request to review lines and update status.
        </div>
        <div v-else class="space-y-4">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h3 class="text-base font-semibold">Return #{{ selected.id }}</h3>
              <p class="text-sm text-[var(--muted)]">
                {{ selected.statusLabel }} · Order {{ selected.orderNumber }}
                <span v-if="selected.customer?.name"> · {{ selected.customer.name }}</span>
                <span v-if="selected.customer?.email"> · {{ selected.customer.email }}</span>
              </p>
              <p class="text-sm text-[var(--muted)]">
                Reason: {{ selected.reasonLabel }}
                <span v-if="selected.notes"> — {{ selected.notes }}</span>
              </p>
            </div>
            <div v-if="canManage && selected.allowedStatuses.length > 0" class="flex flex-wrap gap-2">
              <button
                v-for="status in selected.allowedStatuses"
                :key="status"
                type="button"
                class="rounded-md border border-[var(--line)] px-3 py-1.5 text-sm hover:bg-stone-50 disabled:opacity-60"
                :class="
                  status === 'rejected'
                    ? 'border-red-200 text-red-800 hover:bg-red-50'
                    : status === 'approved'
                      ? 'border-teal-200 bg-teal-50 text-teal-900 hover:bg-teal-100'
                      : ''
                "
                :disabled="saving || completing"
                @click="updateStatus(status)"
              >
                {{ saving ? 'Saving…' : statusActionLabel(status) }}
              </button>
            </div>
          </div>

          <div
            v-if="canManage && selected.canComplete"
            class="rounded-md border border-teal-200 bg-teal-50/50 p-3 space-y-3"
          >
            <p class="text-sm font-medium text-teal-950">
              Complete return — refund total {{ selected.refundTotal }}
              <span v-if="selected.orderPaymentProvider">
                (order paid via {{ selected.orderPaymentProvider }})
              </span>
            </p>
            <div
              v-if="!selected.orderPaymentProvider || selected.orderPaymentProvider === 'manual'"
              class="grid gap-2 sm:grid-cols-2"
            >
              <label class="text-sm">
                <span class="text-[var(--muted)]">Refund method</span>
                <select
                  v-model="refundMethod"
                  class="mt-1 block w-full rounded-md border border-[var(--line)] bg-white px-3 py-1.5"
                >
                  <option value="bank">Bank transfer</option>
                  <option value="cash">Cash</option>
                  <option value="card">Card reversal</option>
                  <option value="mobile">Mobile money</option>
                </select>
              </label>
              <label class="text-sm">
                <span class="text-[var(--muted)]">Reference (optional)</span>
                <input
                  v-model="refundReference"
                  type="text"
                  maxlength="128"
                  class="mt-1 block w-full rounded-md border border-[var(--line)] bg-white px-3 py-1.5"
                  placeholder="Bank ref, receipt #, etc."
                />
              </label>
            </div>
            <button
              type="button"
              class="rounded-md bg-[var(--brand)] px-3 py-1.5 text-sm font-medium text-white hover:opacity-90 disabled:opacity-60"
              :disabled="completing"
              @click="completeReturn"
            >
              {{ completing ? 'Completing…' : 'Complete return & record refund' }}
            </button>
          </div>

          <ul class="divide-y divide-[var(--line)] text-sm">
            <li v-for="item in selected.items" :key="item.id" class="flex justify-between gap-3 py-2">
              <span>
                {{ item.productName }}
                <span v-if="item.variantName" class="text-[var(--muted)]"> · {{ item.variantName }}</span>
                <span class="text-[var(--muted)]"> · {{ item.sku }}</span>
              </span>
              <span class="tabular-nums text-right">
                × {{ item.qty }}
                <span v-if="item.lineTotal" class="block text-xs text-[var(--muted)]">{{ item.lineTotal }}</span>
              </span>
            </li>
          </ul>

          <div v-if="selected.refunds && selected.refunds.length > 0" class="space-y-1 text-sm">
            <p class="text-xs font-medium tracking-wide text-[var(--muted)] uppercase">Refunds</p>
            <p v-for="refund in selected.refunds" :key="refund.id" class="flex justify-between gap-3">
              <span>
                {{ refund.statusLabel }} · {{ refund.provider }}
                <span v-if="refund.reference" class="text-[var(--muted)]"> · {{ refund.reference }}</span>
              </span>
              <span class="tabular-nums">{{ refund.amount }}</span>
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
