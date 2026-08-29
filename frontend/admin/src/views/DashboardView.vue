<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

type Store = { id: number; name: string }
type LowStockAlert = {
  productVariantId: number
  sku: string
  productName: string
  locationType: string
  locationId: number
  available: string
  reorderLevel: string
  severity: 'warning' | 'critical'
}

const auth = useAuthStore()
const stores = ref<Store[]>([])
const storeId = ref<number | ''>('')
const alerts = ref<LowStockAlert[]>([])
const alertsTotal = ref(0)
const loading = ref(true)
const error = ref('')
const ready = ref(false)

const canViewInventory = computed(
  () => auth.user?.permissions?.includes('inventory.view') ?? false,
)

const overflowCount = computed(() => Math.max(0, alertsTotal.value - alerts.value.length))

async function loadStores() {
  const res = await api.get<{ data: Store[] }>('/stores?per_page=50')
  stores.value = res.data
  const preferred =
    auth.user?.storeAccess?.find((s) => s.isDefault)?.id ??
    auth.user?.storeAccess?.[0]?.id ??
    stores.value[0]?.id
  if (!storeId.value && preferred) {
    storeId.value = preferred
  }
}

async function loadAlerts() {
  if (!canViewInventory.value || !storeId.value) {
    alerts.value = []
    alertsTotal.value = 0
    return
  }
  const res = await api.get<{ data: LowStockAlert[]; meta: { total: number } }>(
    `/inventory/alerts/low-stock?storeId=${storeId.value}&per_page=50`,
  )
  alerts.value = res.data
  alertsTotal.value = res.meta?.total ?? res.data.length
}

async function refresh() {
  loading.value = true
  error.value = ''
  try {
    await loadStores()
    await loadAlerts()
  } catch (e) {
    alerts.value = []
    alertsTotal.value = 0
    error.value = e instanceof ApiError ? e.message : 'Failed to load dashboard'
  } finally {
    loading.value = false
  }
}

watch(storeId, () => {
  if (!ready.value) return
  loading.value = true
  error.value = ''
  void loadAlerts()
    .catch((e) => {
      alerts.value = []
      alertsTotal.value = 0
      error.value = e instanceof ApiError ? e.message : 'Failed to load alerts'
    })
    .finally(() => {
      loading.value = false
    })
})

onMounted(async () => {
  await refresh()
  ready.value = true
})
</script>

<template>
  <section class="max-w-3xl space-y-6">
    <div>
      <h2 class="text-xl font-semibold tracking-tight">Welcome</h2>
      <p class="mt-2 text-sm text-[var(--muted)]">
        Signed in as {{ auth.displayName }}. Use the sidebar for catalog, inventory, purchasing, POS
        support screens, and online order fulfilment.
      </p>
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
      <RouterLink
        v-for="item in [
          { to: '/products', label: 'Products' },
          { to: '/inventory', label: 'Inventory' },
          { to: '/fulfilment', label: 'Fulfilment' },
          { to: '/customers', label: 'Customers' },
          { to: '/stores', label: 'Stores' },
          { to: '/settings', label: 'Settings' },
        ]"
        :key="item.to"
        :to="item.to"
        class="border border-[var(--line)] bg-[var(--panel)] px-4 py-3 text-sm hover:border-teal-300"
      >
        {{ item.label }}
      </RouterLink>
    </div>

    <section v-if="canViewInventory" class="border border-[var(--line)] bg-[var(--panel)]">
      <div class="flex flex-wrap items-end justify-between gap-3 border-b border-[var(--line)] px-4 py-3">
        <div>
          <h3 class="text-sm font-semibold tracking-tight">Low-stock alerts</h3>
          <p class="mt-0.5 text-xs text-[var(--muted)]">
            Available at or below product reorder level for the selected store.
          </p>
        </div>
        <label class="text-xs text-[var(--muted)]">
          Store
          <select
            v-model="storeId"
            class="ml-2 rounded-md border border-[var(--line)] bg-white px-2 py-1 text-sm text-[var(--ink)]"
          >
            <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </label>
      </div>

      <p v-if="error" class="px-4 py-2 text-sm text-[var(--danger)]">{{ error }}</p>
      <p v-else-if="loading" class="px-4 py-3 text-sm text-[var(--muted)]">Loading…</p>
      <p v-else-if="stores.length === 0" class="px-4 py-3 text-sm text-[var(--muted)]">
        No stores available.
      </p>
      <p v-else-if="!storeId" class="px-4 py-3 text-sm text-[var(--muted)]">
        Select a store to view alerts.
      </p>
      <p v-else-if="alerts.length === 0" class="px-4 py-3 text-sm text-[var(--muted)]">
        No low-stock items for this store.
      </p>
      <template v-else>
        <ul class="divide-y divide-[var(--line)] text-sm">
          <li
            v-for="alert in alerts"
            :key="`${alert.locationType}-${alert.locationId}-${alert.productVariantId}`"
            class="flex items-baseline justify-between gap-3 px-4 py-2"
            :class="
              alert.severity === 'critical'
                ? 'text-[var(--danger)]'
                : 'text-[var(--ember)]'
            "
          >
            <div class="min-w-0">
              <span class="font-mono text-[0.8125rem]">{{ alert.sku }}</span>
              <span class="ml-2 text-[var(--muted)]">{{ alert.productName }}</span>
            </div>
            <div class="shrink-0 font-mono text-[0.8125rem] tabular-nums">
              {{ alert.available }} / {{ alert.reorderLevel }}
            </div>
          </li>
        </ul>
        <p
          v-if="overflowCount > 0"
          class="border-t border-[var(--line)] px-4 py-2 text-xs text-[var(--muted)]"
        >
          Showing {{ alerts.length }} of {{ alertsTotal }}; {{ overflowCount }} more not shown.
        </p>
      </template>
    </section>
  </section>
</template>
