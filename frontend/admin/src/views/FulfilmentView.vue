<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

type Store = { id: number; name: string }

type FulfilmentOrderListItem = {
  id: number
  orderNumber: string
  status: string
  statusLabel: string
  paymentStatus: string
  fulfilmentMode: string
  store: { id: number; name: string | null }
  totals: { grandTotal: string }
  itemCount: number
  packedAt?: string | null
  createdAt?: string | null
  paidAt?: string | null
  packable: boolean
}

type FulfilmentOrderDetail = FulfilmentOrderListItem & {
  items: Array<{
    id: number
    productName: string
    variantName: string
    sku: string
    qty: string
    lineTotal: string
  }>
  customer?: { name?: string | null; email?: string | null }
  timeline?: Array<{
    key: string
    label: string
    state: string
    detail: string | null
  }>
}

const PACK_KEY_PREFIX = 'fulfilment-pack-idem:'

const auth = useAuthStore()
const canPick = computed(() => auth.user?.permissions?.includes('fulfilment.pick') === true)

const stores = ref<Store[]>([])
const storeId = ref<number | ''>('')
const rows = ref<FulfilmentOrderListItem[]>([])
const selected = ref<FulfilmentOrderDetail | null>(null)
const loading = ref(true)
const detailLoading = ref(false)
const packing = ref(false)
const error = ref('')
const message = ref('')

function packKeyStorageId(orderId: number): string {
  return `${PACK_KEY_PREFIX}${orderId}`
}

function readPackIdempotencyKey(orderId: number): string | null {
  try {
    return sessionStorage.getItem(packKeyStorageId(orderId))
  } catch {
    return null
  }
}

function writePackIdempotencyKey(orderId: number, key: string): void {
  try {
    sessionStorage.setItem(packKeyStorageId(orderId), key)
  } catch {
    /* ignore quota / private mode */
  }
}

function clearPackIdempotencyKey(orderId: number): void {
  try {
    sessionStorage.removeItem(packKeyStorageId(orderId))
  } catch {
    /* ignore */
  }
}

function ensurePackIdempotencyKey(orderId: number): string {
  const existing = readPackIdempotencyKey(orderId)
  if (existing) return existing
  const key = crypto.randomUUID().slice(0, 36)
  writePackIdempotencyKey(orderId, key)
  return key
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

async function loadOrders() {
  if (!canPick.value || !storeId.value) {
    rows.value = []
    return
  }

  const res = await api.get<{ data: FulfilmentOrderListItem[] }>(
    '/fulfilment/orders?per_page=50',
    { 'X-Store-Id': String(storeId.value) },
  )
  rows.value = res.data
}

async function openOrder(id: number) {
  if (!storeId.value) return
  detailLoading.value = true
  error.value = ''
  try {
    const res = await api.get<{ data: FulfilmentOrderDetail }>(`/fulfilment/orders/${id}`, {
      'X-Store-Id': String(storeId.value),
    })
    selected.value = res.data
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load order'
    selected.value = null
  } finally {
    detailLoading.value = false
  }
}

async function refresh() {
  if (!canPick.value) {
    loading.value = false
    error.value = 'You do not have permission to pick and pack fulfilment orders.'
    return
  }

  loading.value = true
  error.value = ''
  message.value = ''
  try {
    await loadStores()
    await loadOrders()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load fulfilment orders'
  } finally {
    loading.value = false
  }
}

async function packOrder() {
  if (!selected.value || !storeId.value || packing.value || !selected.value.packable) return

  const confirmed = window.confirm(
    `Complete pack for ${selected.value.orderNumber}? This consumes reserved stock and cannot be undone from this screen.`,
  )
  if (!confirmed) return

  packing.value = true
  error.value = ''
  message.value = ''
  const orderId = selected.value.id
  const key = ensurePackIdempotencyKey(orderId)
  try {
    const res = await api.post<{ data: FulfilmentOrderDetail }>(
      `/fulfilment/orders/${orderId}/pack`,
      {},
      {
        'X-Store-Id': String(storeId.value),
        'Idempotency-Key': key,
      },
    )
    clearPackIdempotencyKey(orderId)
    selected.value = res.data
    message.value = `Packed order ${res.data.orderNumber}`
    await loadOrders()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Pack failed'
  } finally {
    packing.value = false
  }
}

watch(storeId, () => {
  selected.value = null
  if (canPick.value) {
    void loadOrders().catch((e) => {
      error.value = e instanceof ApiError ? e.message : 'Failed to load fulfilment orders'
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
        <h2 class="text-base font-semibold">Fulfilment — pick &amp; pack</h2>
        <p class="text-sm text-[var(--muted)]">
          Paid portal orders for this store. Pack consumes reserved stock and moves status to Processing.
        </p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <label class="text-sm">
          <span class="text-[var(--muted)]">Store</span>
          <select
            v-model="storeId"
            class="mt-1 block min-w-[12rem] rounded-md border border-[var(--line)] bg-white px-3 py-1.5"
            :disabled="!canPick"
          >
            <option disabled value="">Select store</option>
            <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </label>
        <button
          type="button"
          class="rounded-md border border-[var(--line)] px-3 py-1.5 text-sm hover:bg-stone-50 disabled:opacity-60"
          :disabled="loading || !canPick"
          @click="refresh"
        >
          Refresh
        </button>
      </div>
    </div>

    <p v-if="error" class="text-sm text-red-700">{{ error }}</p>
    <p v-if="message" class="text-sm text-teal-800">{{ message }}</p>

    <div v-if="loading" class="text-sm text-[var(--muted)]">Loading…</div>
    <div v-else-if="!canPick" class="text-sm text-[var(--muted)]">
      Fulfilment UI requires the <code class="text-xs">fulfilment.pick</code> permission.
    </div>
    <div v-else class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
      <div class="overflow-x-auto rounded-md border border-[var(--line)]">
        <table class="min-w-full text-left text-sm">
          <thead class="border-b border-[var(--line)] bg-stone-50 text-xs text-[var(--muted)] uppercase">
            <tr>
              <th class="px-3 py-2 font-medium">Order</th>
              <th class="px-3 py-2 font-medium">Status</th>
              <th class="px-3 py-2 font-medium">Mode</th>
              <th class="px-3 py-2 font-medium">Total</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="rows.length === 0">
              <td colspan="4" class="px-3 py-6 text-[var(--muted)]">No packable orders for this store.</td>
            </tr>
            <tr
              v-for="row in rows"
              :key="row.id"
              class="cursor-pointer border-b border-[var(--line)] last:border-0 hover:bg-stone-50"
              :class="selected?.id === row.id ? 'bg-teal-50/60' : ''"
              @click="openOrder(row.id)"
            >
              <td class="px-3 py-2 font-medium">{{ row.orderNumber }}</td>
              <td class="px-3 py-2">{{ row.statusLabel }}</td>
              <td class="px-3 py-2">{{ row.fulfilmentMode }}</td>
              <td class="px-3 py-2 tabular-nums">{{ row.totals.grandTotal }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="rounded-md border border-[var(--line)] p-4">
        <div v-if="detailLoading" class="text-sm text-[var(--muted)]">Loading order…</div>
        <div v-else-if="!selected" class="text-sm text-[var(--muted)]">Select an order to view lines and pack.</div>
        <div v-else class="space-y-4">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h3 class="text-base font-semibold">{{ selected.orderNumber }}</h3>
              <p class="text-sm text-[var(--muted)]">
                {{ selected.statusLabel }} · {{ selected.fulfilmentMode }}
                <span v-if="selected.customer?.name"> · {{ selected.customer.name }}</span>
              </p>
            </div>
            <button
              type="button"
              class="rounded-md bg-[var(--brand)] px-3 py-1.5 text-sm font-medium text-white hover:opacity-90 disabled:opacity-60"
              :disabled="!selected.packable || packing"
              @click="packOrder"
            >
              {{ packing ? 'Packing…' : selected.packable ? 'Complete pack' : 'Already packed' }}
            </button>
          </div>

          <ul class="divide-y divide-[var(--line)] text-sm">
            <li v-for="item in selected.items" :key="item.id" class="flex justify-between gap-3 py-2">
              <span>
                {{ item.productName }}
                <span class="text-[var(--muted)]"> · {{ item.sku }}</span>
              </span>
              <span class="tabular-nums">× {{ item.qty }}</span>
            </li>
          </ul>

          <div v-if="selected.timeline?.length" class="space-y-1 text-sm">
            <p class="text-xs font-medium tracking-wide text-[var(--muted)] uppercase">Timeline</p>
            <p
              v-for="step in selected.timeline"
              :key="step.key"
              class="flex gap-2"
              :class="step.state === 'current' ? 'font-medium text-[var(--brand-ink)]' : 'text-[var(--muted)]'"
            >
              <span>{{ step.label }}</span>
              <span v-if="step.detail">— {{ step.detail }}</span>
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
