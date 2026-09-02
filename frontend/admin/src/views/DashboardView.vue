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

type DashboardSummary = {
  store: { id: number; name: string }
  date: string
  timezone: string
  currency: string | null
  todaySalesTotal: string
  todaySalesCount: number
  pendingOrdersCount: number
  lowStockCount: number | null
  openRegistersCount: number
  needsAttention: {
    pendingOversellApprovals: number | null
    readyForPickupOrders: number
    heldSalesCount: number | null
    openReturnRequests: number | null
  }
}

const auth = useAuthStore()
const stores = ref<Store[]>([])
const storeId = ref<number | ''>('')
const alerts = ref<LowStockAlert[]>([])
const alertsTotal = ref(0)
const summary = ref<DashboardSummary | null>(null)
const summaryDenied = ref(false)
const loading = ref(true)
const error = ref('')
const ready = ref(false)
let loadGeneration = 0

const canViewInventory = computed(
  () => auth.user?.permissions?.includes('inventory.view') ?? false,
)
const canViewReports = computed(() => auth.user?.permissions?.includes('reports.view') ?? false)

const overflowCount = computed(() => Math.max(0, alertsTotal.value - alerts.value.length))

const attentionItems = computed(() => {
  const needs = summary.value?.needsAttention
  if (!needs || !storeId.value) return []

  // Pending-orders KPI already covers the full fulfilment queue; attention only
  // surfaces actionable subsets (ready for pickup + oversells) to avoid double-count.
  return [
    {
      key: 'oversells',
      label: 'Oversell approvals pending',
      count: needs.pendingOversellApprovals,
      to: '/oversell-exceptions',
    },
    {
      key: 'pickup',
      label: 'Orders ready for pickup',
      count: needs.readyForPickupOrders,
      to: '/fulfilment',
    },
    {
      key: 'returns',
      label: 'Open return requests',
      count: needs.openReturnRequests,
      to: '/return-requests',
    },
  ].filter((item) => (item.count ?? 0) > 0)
})

/** Format DECIMAL money without Number() (safe for large totals). */
function formatMoney(amount: string, currency: string | null): string {
  const cleaned = amount.trim()
  const match = /^(-?)(\d+)(?:\.(\d+))?$/.exec(cleaned)
  if (!match) return amount

  const sign = match[1] ?? ''
  const whole = match[2] ?? '0'
  const frac = (match[3] ?? '').padEnd(2, '0').slice(0, 2)
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  const body = `${sign}${grouped}.${frac}`

  if (!currency) return body
  return `${currency} ${body}`
}

const todaySalesDisplay = computed(() =>
  summary.value ? formatMoney(summary.value.todaySalesTotal, summary.value.currency) : '—',
)

const kpiTiles = computed(() => {
  if (!canViewReports.value || summaryDenied.value) return []

  const tiles = [
    { key: 'sales', label: 'Today’s sales', value: todaySalesDisplay.value, mono: true, tone: '' },
    {
      key: 'orders',
      label: 'Orders pending',
      value: summary.value ? String(summary.value.pendingOrdersCount) : '—',
      mono: false,
      tone: '',
    },
    {
      key: 'registers',
      label: 'Open registers',
      value: summary.value ? String(summary.value.openRegistersCount) : '—',
      mono: false,
      tone: '',
    },
  ]

  if (summary.value?.lowStockCount != null) {
    tiles.splice(2, 0, {
      key: 'low-stock',
      label: 'Low stock',
      value: String(summary.value.lowStockCount),
      mono: false,
      tone: summary.value.lowStockCount > 0 ? 'text-[var(--ember)]' : '',
    })
  }

  return tiles
})

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

async function loadSummary() {
  summaryDenied.value = false
  if (!canViewReports.value || !storeId.value) {
    summary.value = null
    return
  }

  try {
    const res = await api.get<{ data: DashboardSummary }>('/reports/dashboard', {
      'X-Store-Id': String(storeId.value),
    })
    summary.value = res.data
  } catch (e) {
    summary.value = null
    if (e instanceof ApiError && e.status === 403) {
      summaryDenied.value = true
      return
    }
    throw e
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

async function loadStoreScopedData(generation: number) {
  const errors: string[] = []

  await Promise.all([
    loadSummary().catch((e) => {
      if (generation !== loadGeneration) return
      summary.value = null
      if (!(e instanceof ApiError && e.status === 403)) {
        errors.push(e instanceof ApiError ? e.message : 'Failed to load summary')
      }
    }),
    loadAlerts().catch((e) => {
      if (generation !== loadGeneration) return
      alerts.value = []
      alertsTotal.value = 0
      errors.push(e instanceof ApiError ? e.message : 'Failed to load low-stock alerts')
    }),
  ])

  if (generation !== loadGeneration) return
  error.value = errors[0] ?? ''
}

async function refresh() {
  const generation = ++loadGeneration
  loading.value = true
  error.value = ''
  try {
    await loadStores()
    if (generation !== loadGeneration) return
    await loadStoreScopedData(generation)
  } catch (e) {
    if (generation !== loadGeneration) return
    error.value = e instanceof ApiError ? e.message : 'Failed to load dashboard'
  } finally {
    if (generation === loadGeneration) loading.value = false
  }
}

watch(storeId, () => {
  if (!ready.value) return
  const generation = ++loadGeneration
  loading.value = true
  error.value = ''
  void loadStoreScopedData(generation).finally(() => {
    if (generation === loadGeneration) loading.value = false
  })
})

onMounted(async () => {
  await refresh()
  ready.value = true
})
</script>

<template>
  <section class="max-w-5xl space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h2 class="text-xl font-semibold tracking-tight">Store overview</h2>
        <p class="mt-1 text-sm text-[var(--muted)]">
          Signed in as {{ auth.displayName }}.
          <span v-if="summary">
            Today is {{ summary.date }} ({{ summary.timezone }}).
          </span>
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

    <p v-if="error" class="border border-[var(--danger)] bg-white px-4 py-2 text-sm text-[var(--danger)]">
      {{ error }}
    </p>

    <p
      v-if="!canViewReports || summaryDenied"
      class="border border-[var(--line)] bg-[var(--panel)] px-4 py-3 text-sm text-[var(--muted)]"
    >
      You do not have permission to view store performance figures. Ask an administrator for the
      reports permission if you need today's sales, pending orders, or register status.
    </p>

    <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <div
        v-for="kpi in kpiTiles"
        :key="kpi.key"
        class="border border-[var(--line)] bg-[var(--panel)] px-4 py-3"
      >
        <p class="text-xs font-medium text-[var(--muted)]">{{ kpi.label }}</p>
        <p
          class="mt-1.5 text-2xl font-semibold tracking-tight tabular-nums"
          :class="[kpi.mono ? 'font-mono text-xl' : '', kpi.tone]"
        >
          {{ loading && !summary ? '…' : kpi.value }}
        </p>
      </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
      <section v-if="canViewInventory" class="border border-[var(--line)] bg-[var(--panel)]">
        <div class="border-b border-[var(--line)] px-4 py-3">
          <h3 class="text-sm font-semibold tracking-tight">Low stock</h3>
          <p class="mt-0.5 text-xs text-[var(--muted)]">
            Available at or below product reorder level for the selected store.
          </p>
        </div>

        <p v-if="loading" class="px-4 py-3 text-sm text-[var(--muted)]">Loading…</p>
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
                alert.severity === 'critical' ? 'text-[var(--danger)]' : 'text-[var(--ember)]'
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

      <section
        v-if="canViewReports && !summaryDenied"
        class="border border-[var(--line)] bg-[var(--panel)]"
      >
        <div class="border-b border-[var(--line)] px-4 py-3">
          <h3 class="text-sm font-semibold tracking-tight">Needs attention</h3>
          <p class="mt-0.5 text-xs text-[var(--muted)]">
            Open items for this store, from existing sales and fulfilment queues.
          </p>
        </div>

        <p v-if="loading && !summary" class="px-4 py-3 text-sm text-[var(--muted)]">Loading…</p>
        <p v-else-if="!storeId" class="px-4 py-3 text-sm text-[var(--muted)]">
          Select a store to view attention items.
        </p>
        <p v-else-if="attentionItems.length === 0" class="px-4 py-3 text-sm text-[var(--muted)]">
          Nothing needs attention for this store.
        </p>
        <ul v-else class="divide-y divide-[var(--line)] text-sm">
          <li
            v-for="item in attentionItems"
            :key="item.key"
            class="flex items-baseline justify-between gap-3 px-4 py-2"
          >
            <RouterLink
              v-if="item.to"
              :to="item.to"
              class="text-[var(--brand-ink)] underline-offset-2 hover:underline"
            >
              {{ item.label }}
            </RouterLink>
            <span v-else>{{ item.label }}</span>
            <span class="shrink-0 font-mono text-[0.8125rem] tabular-nums text-[var(--ember)]">
              {{ item.count }}
            </span>
          </li>
        </ul>
      </section>
    </div>
  </section>
</template>
