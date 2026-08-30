<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

type Store = { id: number; name: string }

type ShipmentInfo = {
  carrierId: number | null
  carrierName: string | null
  trackingNumber: string | null
  shippedAt: string | null
  deliveredAt: string | null
} | null

type CarrierOption = { id: number; name: string }

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
  collectedAt?: string | null
  createdAt?: string | null
  paidAt?: string | null
  packable: boolean
  shippable?: boolean
  deliverable?: boolean
  readyForPickup?: boolean
  collectable?: boolean
  shipment?: ShipmentInfo
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
  contactPhone?: string | null
  address?: {
    line1: string | null
    line2: string | null
    city: string | null
    region: string | null
    postalCode: string | null
    country: string | null
  } | null
  timeline?: Array<{
    key: string
    label: string
    state: string
    detail: string | null
  }>
}

const PACK_KEY_PREFIX = 'fulfilment-pack-idem:'
const SHIP_KEY_PREFIX = 'fulfilment-ship-idem:'
const DELIVER_KEY_PREFIX = 'fulfilment-deliver-idem:'
const READY_KEY_PREFIX = 'fulfilment-ready-idem:'
const COLLECT_KEY_PREFIX = 'fulfilment-collect-idem:'

const auth = useAuthStore()
const canPick = computed(() => auth.user?.permissions?.includes('fulfilment.pick') === true)
const canShip = computed(() => auth.user?.permissions?.includes('fulfilment.ship') === true)
const canPickup = computed(() => auth.user?.permissions?.includes('fulfilment.pickup') === true)
const canAccess = computed(() => canPick.value || canShip.value || canPickup.value)

const stores = ref<Store[]>([])
const storeId = ref<number | ''>('')
const rows = ref<FulfilmentOrderListItem[]>([])
const selected = ref<FulfilmentOrderDetail | null>(null)
const loading = ref(true)
const detailLoading = ref(false)
const packing = ref(false)
const shipping = ref(false)
const delivering = ref(false)
const markingReady = ref(false)
const collecting = ref(false)
const error = ref('')
const message = ref('')
const carrierName = ref('')
const carrierId = ref<number | ''>('')
const trackingNumber = ref('')
const carriers = ref<CarrierOption[]>([])

function isPickupMode(mode: string): boolean {
  return mode === 'store_pickup' || mode === 'click_collect'
}

function isHomeDelivery(mode: string): boolean {
  return mode === 'home_delivery'
}

function displayStatusLabel(row: { status: string; statusLabel: string; fulfilmentMode: string }): string {
  if (row.status === 'delivered' && isPickupMode(row.fulfilmentMode)) {
    return 'Collected'
  }
  return row.statusLabel
}

function idemStorageId(prefix: string, orderId: number): string {
  return `${prefix}${orderId}`
}

function readIdempotencyKey(prefix: string, orderId: number): string | null {
  try {
    return sessionStorage.getItem(idemStorageId(prefix, orderId))
  } catch {
    return null
  }
}

function writeIdempotencyKey(prefix: string, orderId: number, key: string): void {
  try {
    sessionStorage.setItem(idemStorageId(prefix, orderId), key)
  } catch {
    /* ignore quota / private mode */
  }
}

function clearIdempotencyKey(prefix: string, orderId: number): void {
  try {
    sessionStorage.removeItem(idemStorageId(prefix, orderId))
  } catch {
    /* ignore */
  }
}

function ensureIdempotencyKey(prefix: string, orderId: number): string {
  const existing = readIdempotencyKey(prefix, orderId)
  if (existing) return existing
  const key = crypto.randomUUID().slice(0, 36)
  writeIdempotencyKey(prefix, orderId, key)
  return key
}

function formatAddress(addr: FulfilmentOrderDetail['address']): string {
  if (!addr) return ''
  return [addr.line1, addr.line2, addr.city, addr.region, addr.postalCode, addr.country]
    .filter((p) => p && String(p).trim() !== '')
    .join(', ')
}

function syncShipmentForm(detail: FulfilmentOrderDetail | null) {
  carrierId.value = detail?.shipment?.carrierId ?? ''
  carrierName.value = detail?.shipment?.carrierName ?? ''
  trackingNumber.value = detail?.shipment?.trackingNumber ?? ''
}

async function loadCarriers() {
  if (!canShip.value) {
    carriers.value = []
    return
  }
  try {
    const res = await api.get<{ data: CarrierOption[] }>('/carriers?activeOnly=1&per_page=100')
    carriers.value = res.data.map((c) => ({ id: c.id, name: c.name }))
  } catch {
    carriers.value = []
  }
}

function onCarrierChange() {
  if (carrierId.value) {
    const match = carriers.value.find((c) => c.id === carrierId.value)
    if (match) carrierName.value = match.name
  }
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
  if (!canAccess.value || !storeId.value) {
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
    syncShipmentForm(res.data)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load order'
    selected.value = null
  } finally {
    detailLoading.value = false
  }
}

async function refresh() {
  if (!canAccess.value) {
    loading.value = false
    error.value = 'You do not have permission to view fulfilment orders.'
    return
  }

  loading.value = true
  error.value = ''
  message.value = ''
  try {
    await loadStores()
    await loadCarriers()
    await loadOrders()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load fulfilment orders'
  } finally {
    loading.value = false
  }
}

async function packOrder() {
  if (!selected.value || !storeId.value || packing.value || !selected.value.packable || !canPick.value) {
    return
  }

  const confirmed = window.confirm(
    `Complete pack for ${selected.value.orderNumber}? This consumes reserved stock and cannot be undone from this screen.`,
  )
  if (!confirmed) return

  packing.value = true
  error.value = ''
  message.value = ''
  const orderId = selected.value.id
  const key = ensureIdempotencyKey(PACK_KEY_PREFIX, orderId)
  try {
    const res = await api.post<{ data: FulfilmentOrderDetail }>(
      `/fulfilment/orders/${orderId}/pack`,
      {},
      {
        'X-Store-Id': String(storeId.value),
        'Idempotency-Key': key,
      },
    )
    clearIdempotencyKey(PACK_KEY_PREFIX, orderId)
    selected.value = res.data
    syncShipmentForm(res.data)
    message.value = `Packed order ${res.data.orderNumber}`
    await loadOrders()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Pack failed'
  } finally {
    packing.value = false
  }
}

async function resolveAfterConflict(
  orderId: number,
  advancedStatuses: string[],
): Promise<FulfilmentOrderDetail | null> {
  if (!storeId.value) return null
  try {
    const res = await api.get<{ data: FulfilmentOrderDetail }>(`/fulfilment/orders/${orderId}`, {
      'X-Store-Id': String(storeId.value),
    })
    if (advancedStatuses.includes(res.data.status)) {
      return res.data
    }
  } catch {
    /* ignore refetch failure */
  }
  return null
}

async function shipOrder() {
  if (
    !selected.value ||
    !storeId.value ||
    shipping.value ||
    !selected.value.shippable ||
    !canShip.value ||
    !isHomeDelivery(selected.value.fulfilmentMode)
  ) {
    return
  }

  const confirmed = window.confirm(
    `Ship ${selected.value.orderNumber}? Status will move to Out for Delivery.`,
  )
  if (!confirmed) return

  shipping.value = true
  error.value = ''
  message.value = ''
  const orderId = selected.value.id
  const key = ensureIdempotencyKey(SHIP_KEY_PREFIX, orderId)
  try {
    const res = await api.post<{ data: FulfilmentOrderDetail }>(
      `/fulfilment/orders/${orderId}/ship`,
      {
        carrierId: carrierId.value || null,
        carrierName: carrierId.value ? null : carrierName.value.trim() || null,
        trackingNumber: trackingNumber.value.trim() || null,
      },
      {
        'X-Store-Id': String(storeId.value),
        'Idempotency-Key': key,
      },
    )
    clearIdempotencyKey(SHIP_KEY_PREFIX, orderId)
    selected.value = res.data
    syncShipmentForm(res.data)
    message.value = `Shipped order ${res.data.orderNumber}`
    await loadOrders()
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      const recovered = await resolveAfterConflict(orderId, [
        'out_for_delivery',
        'delivered',
      ])
      if (recovered) {
        clearIdempotencyKey(SHIP_KEY_PREFIX, orderId)
        selected.value = recovered
        syncShipmentForm(recovered)
        message.value = `Shipped order ${recovered.orderNumber}`
        await loadOrders()
        return
      }
    }
    error.value = e instanceof ApiError ? e.message : 'Ship failed'
  } finally {
    shipping.value = false
  }
}

async function deliverOrder() {
  if (
    !selected.value ||
    !storeId.value ||
    delivering.value ||
    !selected.value.deliverable ||
    !canShip.value ||
    !isHomeDelivery(selected.value.fulfilmentMode)
  ) {
    return
  }

  const confirmed = window.confirm(
    `Mark ${selected.value.orderNumber} as delivered?`,
  )
  if (!confirmed) return

  delivering.value = true
  error.value = ''
  message.value = ''
  const orderId = selected.value.id
  const key = ensureIdempotencyKey(DELIVER_KEY_PREFIX, orderId)
  try {
    const res = await api.post<{ data: FulfilmentOrderDetail }>(
      `/fulfilment/orders/${orderId}/deliver`,
      {},
      {
        'X-Store-Id': String(storeId.value),
        'Idempotency-Key': key,
      },
    )
    clearIdempotencyKey(DELIVER_KEY_PREFIX, orderId)
    selected.value = res.data
    syncShipmentForm(res.data)
    message.value = `Delivered order ${res.data.orderNumber}`
    await loadOrders()
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      const recovered = await resolveAfterConflict(orderId, ['delivered'])
      if (recovered) {
        clearIdempotencyKey(DELIVER_KEY_PREFIX, orderId)
        selected.value = recovered
        syncShipmentForm(recovered)
        message.value = `Delivered order ${recovered.orderNumber}`
        await loadOrders()
        return
      }
    }
    error.value = e instanceof ApiError ? e.message : 'Deliver failed'
  } finally {
    delivering.value = false
  }
}

async function readyForPickup() {
  if (
    !selected.value ||
    !storeId.value ||
    markingReady.value ||
    !selected.value.readyForPickup ||
    !canPickup.value ||
    !isPickupMode(selected.value.fulfilmentMode)
  ) {
    return
  }

  const confirmed = window.confirm(
    `Mark ${selected.value.orderNumber} ready for pickup? Customer can collect at the counter.`,
  )
  if (!confirmed) return

  markingReady.value = true
  error.value = ''
  message.value = ''
  const orderId = selected.value.id
  const key = ensureIdempotencyKey(READY_KEY_PREFIX, orderId)
  try {
    const res = await api.post<{ data: FulfilmentOrderDetail }>(
      `/fulfilment/orders/${orderId}/ready`,
      {},
      {
        'X-Store-Id': String(storeId.value),
        'Idempotency-Key': key,
      },
    )
    clearIdempotencyKey(READY_KEY_PREFIX, orderId)
    selected.value = res.data
    syncShipmentForm(res.data)
    message.value = `Ready for pickup: ${res.data.orderNumber}`
    await loadOrders()
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      const recovered = await resolveAfterConflict(orderId, ['ready_for_pickup'])
      if (recovered) {
        clearIdempotencyKey(READY_KEY_PREFIX, orderId)
        selected.value = recovered
        syncShipmentForm(recovered)
        message.value = `Ready for pickup: ${recovered.orderNumber}`
        await loadOrders()
        return
      }
    }
    error.value = e instanceof ApiError ? e.message : 'Ready for pickup failed'
  } finally {
    markingReady.value = false
  }
}

async function collectOrder() {
  if (
    !selected.value ||
    !storeId.value ||
    collecting.value ||
    !selected.value.collectable ||
    !canPickup.value ||
    !isPickupMode(selected.value.fulfilmentMode)
  ) {
    return
  }

  const confirmed = window.confirm(
    `Mark ${selected.value.orderNumber} as collected? Confirm the customer has this order number.`,
  )
  if (!confirmed) return

  collecting.value = true
  error.value = ''
  message.value = ''
  const orderId = selected.value.id
  const key = ensureIdempotencyKey(COLLECT_KEY_PREFIX, orderId)
  try {
    const res = await api.post<{ data: FulfilmentOrderDetail }>(
      `/fulfilment/orders/${orderId}/collect`,
      {},
      {
        'X-Store-Id': String(storeId.value),
        'Idempotency-Key': key,
      },
    )
    clearIdempotencyKey(COLLECT_KEY_PREFIX, orderId)
    selected.value = res.data
    syncShipmentForm(res.data)
    message.value = `Collected order ${res.data.orderNumber}`
    await loadOrders()
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      const recovered = await resolveAfterConflict(orderId, ['delivered'])
      if (recovered) {
        clearIdempotencyKey(COLLECT_KEY_PREFIX, orderId)
        selected.value = recovered
        syncShipmentForm(recovered)
        message.value = `Collected order ${recovered.orderNumber}`
        await loadOrders()
        return
      }
    }
    error.value = e instanceof ApiError ? e.message : 'Collect failed'
  } finally {
    collecting.value = false
  }
}

watch(storeId, () => {
  selected.value = null
  if (canAccess.value) {
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
        <h2 class="text-base font-semibold">Fulfilment</h2>
        <p class="text-sm text-[var(--muted)]">
          Pack consumes reserved stock. Home delivery: ship then mark delivered. Pickup / click &amp; collect:
          ready for pickup then mark collected (no inventory change).
        </p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <label class="text-sm">
          <span class="text-[var(--muted)]">Store</span>
          <select
            v-model="storeId"
            class="mt-1 block min-w-[12rem] rounded-md border border-[var(--line)] bg-white px-3 py-1.5"
            :disabled="!canAccess"
          >
            <option disabled value="">Select store</option>
            <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </label>
        <button
          type="button"
          class="rounded-md border border-[var(--line)] px-3 py-1.5 text-sm hover:bg-stone-50 disabled:opacity-60"
          :disabled="loading || !canAccess"
          @click="refresh"
        >
          Refresh
        </button>
      </div>
    </div>

    <p v-if="error" class="text-sm text-red-700">{{ error }}</p>
    <p v-if="message" class="text-sm text-teal-800">{{ message }}</p>

    <div v-if="loading" class="text-sm text-[var(--muted)]">Loading…</div>
    <div v-else-if="!canAccess" class="text-sm text-[var(--muted)]">
      Fulfilment requires <code class="text-xs">fulfilment.pick</code>,
      <code class="text-xs">fulfilment.ship</code>, or
      <code class="text-xs">fulfilment.pickup</code>.
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
              <td colspan="4" class="px-3 py-6 text-[var(--muted)]">No fulfilment orders for this store.</td>
            </tr>
            <tr
              v-for="row in rows"
              :key="row.id"
              class="cursor-pointer border-b border-[var(--line)] last:border-0 hover:bg-stone-50"
              :class="selected?.id === row.id ? 'bg-teal-50/60' : ''"
              @click="openOrder(row.id)"
            >
              <td class="px-3 py-2 font-mono text-sm font-semibold tracking-tight">{{ row.orderNumber }}</td>
              <td class="px-3 py-2">{{ displayStatusLabel(row) }}</td>
              <td class="px-3 py-2 capitalize">{{ row.fulfilmentMode.replace(/_/g, ' ') }}</td>
              <td class="px-3 py-2 tabular-nums">{{ row.totals.grandTotal }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="rounded-md border border-[var(--line)] p-4">
        <div v-if="detailLoading" class="text-sm text-[var(--muted)]">Loading order…</div>
        <div v-else-if="!selected" class="text-sm text-[var(--muted)]">Select an order to view lines and actions.</div>
        <div v-else class="space-y-4">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p
                v-if="isPickupMode(selected.fulfilmentMode)"
                class="text-xs font-medium tracking-wide text-[var(--muted)] uppercase"
              >
                Order number
              </p>
              <h3
                class="font-semibold"
                :class="isPickupMode(selected.fulfilmentMode) ? 'text-2xl tracking-tight' : 'text-base'"
              >
                {{ selected.orderNumber }}
              </h3>
              <p class="text-sm text-[var(--muted)]">
                {{ displayStatusLabel(selected) }} · {{ selected.fulfilmentMode }}
                <span v-if="selected.customer?.name"> · {{ selected.customer.name }}</span>
                <span v-if="selected.contactPhone"> · {{ selected.contactPhone }}</span>
              </p>
            </div>
            <div class="flex flex-wrap gap-2">
              <button
                v-if="selected.packable && canPick"
                type="button"
                class="rounded-md bg-[var(--brand)] px-3 py-1.5 text-sm font-medium text-white hover:opacity-90 disabled:opacity-60"
                :disabled="packing"
                @click="packOrder"
              >
                {{ packing ? 'Packing…' : 'Complete pack' }}
              </button>
              <button
                v-if="selected.shippable && canShip && isHomeDelivery(selected.fulfilmentMode)"
                type="button"
                class="rounded-md bg-[var(--brand)] px-3 py-1.5 text-sm font-medium text-white hover:opacity-90 disabled:opacity-60"
                :disabled="shipping"
                @click="shipOrder"
              >
                {{ shipping ? 'Shipping…' : 'Ship' }}
              </button>
              <button
                v-if="selected.deliverable && canShip && isHomeDelivery(selected.fulfilmentMode)"
                type="button"
                class="rounded-md bg-[var(--brand)] px-3 py-1.5 text-sm font-medium text-white hover:opacity-90 disabled:opacity-60"
                :disabled="delivering"
                @click="deliverOrder"
              >
                {{ delivering ? 'Updating…' : 'Mark delivered' }}
              </button>
              <button
                v-if="selected.readyForPickup && canPickup && isPickupMode(selected.fulfilmentMode)"
                type="button"
                class="rounded-md bg-[var(--brand)] px-3 py-1.5 text-sm font-medium text-white hover:opacity-90 disabled:opacity-60"
                :disabled="markingReady"
                @click="readyForPickup"
              >
                {{ markingReady ? 'Updating…' : 'Ready for pickup' }}
              </button>
              <button
                v-if="selected.collectable && canPickup && isPickupMode(selected.fulfilmentMode)"
                type="button"
                class="rounded-md bg-[var(--brand)] px-3 py-1.5 text-sm font-medium text-white hover:opacity-90 disabled:opacity-60"
                :disabled="collecting"
                @click="collectOrder"
              >
                {{ collecting ? 'Updating…' : 'Mark collected' }}
              </button>
            </div>
          </div>

          <p
            v-if="selected.fulfilmentMode === 'home_delivery' && formatAddress(selected.address)"
            class="text-sm text-[var(--muted)]"
          >
            Deliver to: {{ formatAddress(selected.address) }}
          </p>

          <div
            v-if="
              canShip &&
              selected.fulfilmentMode === 'home_delivery' &&
              (selected.shippable || selected.shipment)
            "
            class="grid gap-2 sm:grid-cols-2"
          >
            <label class="text-sm">
              <span class="text-[var(--muted)]">Carrier</span>
              <select
                v-if="carriers.length > 0"
                v-model="carrierId"
                class="mt-1 block w-full rounded-md border border-[var(--line)] bg-white px-3 py-1.5"
                :disabled="!selected.shippable"
                @change="onCarrierChange"
              >
                <option value="">Custom / other</option>
                <option v-for="c in carriers" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
              <input
                v-if="carriers.length === 0 || !carrierId"
                v-model="carrierName"
                type="text"
                maxlength="120"
                class="mt-1 block w-full rounded-md border border-[var(--line)] bg-white px-3 py-1.5"
                :disabled="!selected.shippable"
                placeholder="Carrier name (optional)"
              />
            </label>
            <label class="text-sm">
              <span class="text-[var(--muted)]">Tracking number</span>
              <input
                v-model="trackingNumber"
                type="text"
                maxlength="120"
                class="mt-1 block w-full rounded-md border border-[var(--line)] bg-white px-3 py-1.5"
                :disabled="!selected.shippable"
                placeholder="Optional"
              />
            </label>
          </div>

          <p
            v-if="canShip && selected.shipment?.shippedAt"
            class="text-sm text-[var(--muted)]"
          >
            Shipped
            <span v-if="selected.shipment.carrierName"> via {{ selected.shipment.carrierName }}</span>
            <span v-if="selected.shipment.trackingNumber"> · {{ selected.shipment.trackingNumber }}</span>
            <span v-if="selected.shipment.deliveredAt"> · Delivered</span>
          </p>

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
