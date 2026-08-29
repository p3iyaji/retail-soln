<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

type Store = { id: number; name: string }
type Category = { id: number; name: string }

type CashierRow = {
  cashierId: number
  name: string | null
  salesCount: number
  salesTotal: string
}

type TopProduct = {
  productVariantId: number
  sku: string | null
  name: string
  qty: string
  lineTotal: string
}

type SaleRow = {
  id: number
  uuid: string
  createdAt: string | null
  cashier: { id: number; name: string | null }
  total: string
  paymentMethods: string[]
}

type SalesReport = {
  store: { id: number; name: string }
  range: { from: string; to: string; timezone: string }
  currency: string | null
  filters: {
    from: string
    to: string
    productVariantId: number | null
    categoryId: number | null
    paymentMethod: string | null
    cashierId: number | null
  }
  summary: { salesTotal: string; salesCount: number; averageOrderValue: string }
  cashiers: CashierRow[]
  topProducts: TopProduct[]
  rows: SaleRow[]
}

type ReportMeta = { currentPage: number; lastPage: number; perPage: number; total: number }

const PAYMENT_METHODS = ['cash', 'card', 'bank', 'mobile', 'store_credit']
const PER_PAGE = 25

const auth = useAuthStore()
const canViewReports = computed(() => auth.user?.permissions?.includes('reports.view') ?? false)

const stores = ref<Store[]>([])
const categories = ref<Category[]>([])
const storeId = ref<number | ''>('')
const from = ref(isoDaysAgo(6))
const to = ref(isoDaysAgo(0))
const paymentMethod = ref('')
const cashierId = ref<number | ''>('')
const categoryId = ref<number | ''>('')
const productVariantId = ref<number | ''>('')
const productVariantLabel = ref('')
const page = ref(1)

const report = ref<SalesReport | null>(null)
const meta = ref<ReportMeta | null>(null)
const cashierOptions = ref<CashierRow[]>([])
const loading = ref(false)
const exporting = ref(false)
const error = ref('')
const denied = ref(false)
const ready = ref(false)
let loadGeneration = 0

function isoDaysAgo(days: number): string {
  const date = new Date()
  date.setDate(date.getDate() - days)
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${date.getFullYear()}-${month}-${day}`
}

/** Format DECIMAL money without Number() (safe for large totals). */
function formatMoney(amount: string | undefined, currency: string | null | undefined): string {
  if (!amount) return '—'
  const match = /^(-?)(\d+)(?:\.(\d+))?$/.exec(amount.trim())
  if (!match) return amount

  const sign = match[1] ?? ''
  const whole = match[2] ?? '0'
  const frac = (match[3] ?? '').padEnd(2, '0').slice(0, 2)
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  const body = `${sign}${grouped}.${frac}`

  return currency ? `${currency} ${body}` : body
}

function formatTimestamp(value: string | null): string {
  if (!value) return '—'
  return value.slice(0, 16).replace('T', ' ')
}

const summaryTiles = computed(() => {
  const currency = report.value?.currency ?? null
  return [
    {
      key: 'total',
      label: 'Sales total',
      value: formatMoney(report.value?.summary.salesTotal, currency),
      mono: true,
    },
    {
      key: 'count',
      label: 'Sales count',
      value: report.value ? String(report.value.summary.salesCount) : '—',
      mono: false,
    },
    {
      key: 'aov',
      label: 'Average order value',
      value: formatMoney(report.value?.summary.averageOrderValue, currency),
      mono: true,
    },
  ]
})

const rangeInvalid = computed(() => Boolean(from.value && to.value && from.value > to.value))

function reportQuery(): string {
  const params = new URLSearchParams({ from: from.value, to: to.value })
  if (paymentMethod.value) params.set('paymentMethod', paymentMethod.value)
  if (cashierId.value) params.set('cashierId', String(cashierId.value))
  if (categoryId.value) params.set('categoryId', String(categoryId.value))
  if (productVariantId.value) params.set('productVariantId', String(productVariantId.value))
  return params.toString()
}

async function loadStores() {
  const res = await api.get<{ data: Store[] }>('/stores?per_page=50')
  stores.value = res.data
  if (!storeId.value) {
    const preferred =
      auth.user?.storeAccess?.find((s) => s.isDefault)?.id ??
      auth.user?.storeAccess?.[0]?.id ??
      stores.value[0]?.id
    storeId.value = preferred ?? ''
  }
}

/** Category filter is optional chrome: staff without products.view still get the report. */
async function loadCategories() {
  try {
    const res = await api.get<{ data: Category[] }>('/categories')
    categories.value = res.data
  } catch {
    categories.value = []
  }
}

async function loadReport() {
  if (!canViewReports.value || !storeId.value || rangeInvalid.value) {
    report.value = null
    meta.value = null
    return
  }

  const generation = ++loadGeneration
  loading.value = true
  error.value = ''
  denied.value = false

  try {
    const res = await api.get<{ data: SalesReport; meta: ReportMeta }>(
      `/reports/sales?${reportQuery()}&per_page=${PER_PAGE}&page=${page.value}`,
      { 'X-Store-Id': String(storeId.value) },
    )
    if (generation !== loadGeneration) return
    report.value = res.data
    meta.value = res.meta
    if (!cashierId.value) {
      cashierOptions.value = res.data.cashiers
    }
  } catch (e) {
    if (generation !== loadGeneration) return
    report.value = null
    meta.value = null
    if (e instanceof ApiError && e.status === 403) {
      denied.value = true
    } else {
      error.value = e instanceof ApiError ? e.message : 'Failed to load report'
    }
  } finally {
    if (generation === loadGeneration) loading.value = false
  }
}

function applyFilters() {
  page.value = 1
  void loadReport()
}

function filterByVariant(product: TopProduct) {
  productVariantId.value = product.productVariantId
  productVariantLabel.value = product.sku ?? product.name
  applyFilters()
}

function clearVariantFilter() {
  productVariantId.value = ''
  productVariantLabel.value = ''
  applyFilters()
}

function goToPage(next: number) {
  if (!meta.value || next < 1 || next > meta.value.lastPage) return
  page.value = next
  void loadReport()
}

async function onExport() {
  if (!storeId.value || rangeInvalid.value) return
  exporting.value = true
  error.value = ''
  try {
    await api.download(
      `/reports/sales/export?${reportQuery()}`,
      `sales-report-${storeId.value}-${from.value}-to-${to.value}.csv`,
      { 'X-Store-Id': String(storeId.value) },
    )
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Export failed'
  } finally {
    exporting.value = false
  }
}

watch(storeId, () => {
  if (!ready.value) return
  cashierOptions.value = []
  cashierId.value = ''
  denied.value = false
  applyFilters()
})

onMounted(async () => {
  if (!canViewReports.value) return
  loading.value = true
  try {
    await Promise.all([loadStores(), loadCategories()])
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load reports'
  } finally {
    loading.value = false
  }
  await loadReport()
  ready.value = true
})
</script>

<template>
  <section class="space-y-5">
    <div>
      <h2 class="text-base font-semibold">Store performance</h2>
      <p class="text-sm text-[var(--muted)]">
        Completed sales for one store over a date range. Operational read of sales facts — not an
        accounting ledger.
      </p>
    </div>

    <p
      v-if="!canViewReports || denied"
      class="border border-[var(--line)] bg-[var(--panel)] px-4 py-3 text-sm text-[var(--muted)]"
    >
      You do not have permission to view store performance reports. Ask an administrator for the
      reports permission.
    </p>

    <template v-else>
      <form
        class="flex flex-wrap items-end gap-3 border border-[var(--line)] bg-[var(--panel)] px-4 py-3"
        @submit.prevent="applyFilters"
      >
        <label class="text-sm">
          <span class="text-[var(--muted)]">Store</span>
          <select
            v-model="storeId"
            class="mt-1 block min-w-[12rem] rounded-md border border-[var(--line)] px-3 py-2"
          >
            <option disabled value="">Select store</option>
            <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </label>

        <label class="text-sm">
          <span class="text-[var(--muted)]">From</span>
          <input
            v-model="from"
            type="date"
            required
            class="mt-1 block rounded-md border border-[var(--line)] px-3 py-2"
          />
        </label>

        <label class="text-sm">
          <span class="text-[var(--muted)]">To</span>
          <input
            v-model="to"
            type="date"
            required
            class="mt-1 block rounded-md border border-[var(--line)] px-3 py-2"
          />
        </label>

        <label class="text-sm">
          <span class="text-[var(--muted)]">Payment</span>
          <select
            v-model="paymentMethod"
            class="mt-1 block rounded-md border border-[var(--line)] px-3 py-2"
          >
            <option value="">All methods</option>
            <option v-for="method in PAYMENT_METHODS" :key="method" :value="method">
              {{ method.replace('_', ' ') }}
            </option>
          </select>
        </label>

        <label class="text-sm">
          <span class="text-[var(--muted)]">Cashier</span>
          <select
            v-model="cashierId"
            class="mt-1 block min-w-[10rem] rounded-md border border-[var(--line)] px-3 py-2"
          >
            <option value="">All cashiers</option>
            <option v-for="c in cashierOptions" :key="c.cashierId" :value="c.cashierId">
              {{ c.name ?? `#${c.cashierId}` }}
            </option>
          </select>
        </label>

        <label v-if="categories.length > 0" class="text-sm">
          <span class="text-[var(--muted)]">Category</span>
          <select
            v-model="categoryId"
            class="mt-1 block min-w-[10rem] rounded-md border border-[var(--line)] px-3 py-2"
          >
            <option value="">All categories</option>
            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </label>

        <button
          type="submit"
          class="rounded-md border border-[var(--brand)] bg-teal-50 px-3 py-2 text-sm font-medium text-[var(--brand-ink)] hover:bg-teal-100 disabled:opacity-60"
          :disabled="loading || !storeId || rangeInvalid"
        >
          {{ loading ? 'Running…' : 'Run report' }}
        </button>

        <button
          type="button"
          class="rounded-md border border-[var(--line)] px-3 py-2 text-sm hover:bg-stone-50 disabled:opacity-60"
          :disabled="exporting || loading || !storeId || rangeInvalid"
          @click="onExport"
        >
          {{ exporting ? 'Exporting…' : 'Export CSV' }}
        </button>
      </form>

      <p v-if="rangeInvalid" class="text-sm text-[var(--danger)]">
        The start date must be on or before the end date.
      </p>

      <p
        v-if="error"
        class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"
      >
        {{ error }}
      </p>

      <p v-if="productVariantId" class="text-sm text-[var(--muted)]">
        Filtered to product
        <span class="font-mono text-[var(--ink)]">{{ productVariantLabel }}</span>
        <button
          type="button"
          class="ml-2 underline underline-offset-2 hover:text-[var(--ink)]"
          @click="clearVariantFilter"
        >
          clear
        </button>
      </p>

      <div class="grid gap-3 sm:grid-cols-3">
        <div
          v-for="tile in summaryTiles"
          :key="tile.key"
          class="border border-[var(--line)] bg-[var(--panel)] px-4 py-3"
        >
          <p class="text-xs font-medium text-[var(--muted)]">{{ tile.label }}</p>
          <p
            class="mt-1.5 text-2xl font-semibold tracking-tight tabular-nums"
            :class="tile.mono ? 'font-mono text-xl' : ''"
          >
            {{ loading && !report ? '…' : tile.value }}
          </p>
        </div>
      </div>

      <div class="overflow-x-auto rounded-md border border-[var(--line)] bg-[var(--panel)]">
        <table class="min-w-full text-left text-sm">
          <thead
            class="border-b border-[var(--line)] bg-stone-50 text-xs tracking-wide text-[var(--muted)] uppercase"
          >
            <tr>
              <th class="px-3 py-2 font-medium">Sale</th>
              <th class="px-3 py-2 font-medium">Sold at</th>
              <th class="px-3 py-2 font-medium">Cashier</th>
              <th class="px-3 py-2 font-medium">Payment</th>
              <th class="px-3 py-2 text-right font-medium">Total</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading && !report">
              <td colspan="5" class="px-3 py-4 text-[var(--muted)]">Loading…</td>
            </tr>
            <tr v-else-if="!report || report.rows.length === 0">
              <td colspan="5" class="px-3 py-4 text-[var(--muted)]">
                No completed sales for this store, range, and filters.
              </td>
            </tr>
            <tr
              v-for="row in report?.rows ?? []"
              :key="row.id"
              class="border-b border-[var(--line)] last:border-b-0"
            >
              <td class="px-3 py-2">
                #{{ row.id }}
                <span class="block font-mono text-xs text-[var(--muted)]">{{ row.uuid }}</span>
              </td>
              <td class="px-3 py-2 text-[var(--muted)]">{{ formatTimestamp(row.createdAt) }}</td>
              <td class="px-3 py-2">{{ row.cashier.name ?? `#${row.cashier.id}` }}</td>
              <td class="px-3 py-2 text-[var(--muted)]">
                {{ row.paymentMethods.join(', ') || '—' }}
              </td>
              <td class="px-3 py-2 text-right font-mono tabular-nums">
                {{ formatMoney(row.total, report?.currency ?? null) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="meta && meta.lastPage > 1" class="flex items-center gap-3 text-sm">
        <button
          type="button"
          class="rounded-md border border-[var(--line)] px-3 py-1.5 hover:bg-stone-50 disabled:opacity-60"
          :disabled="meta.currentPage <= 1 || loading"
          @click="goToPage(meta.currentPage - 1)"
        >
          Previous
        </button>
        <span class="text-[var(--muted)]">
          Page {{ meta.currentPage }} of {{ meta.lastPage }} — {{ meta.total }} sales
        </span>
        <button
          type="button"
          class="rounded-md border border-[var(--line)] px-3 py-1.5 hover:bg-stone-50 disabled:opacity-60"
          :disabled="meta.currentPage >= meta.lastPage || loading"
          @click="goToPage(meta.currentPage + 1)"
        >
          Next
        </button>
      </div>

      <div class="grid gap-4 lg:grid-cols-2">
        <section class="border border-[var(--line)] bg-[var(--panel)]">
          <div class="border-b border-[var(--line)] px-4 py-3">
            <h3 class="text-sm font-semibold tracking-tight">Cashier performance</h3>
          </div>
          <p
            v-if="!report || report.cashiers.length === 0"
            class="px-4 py-3 text-sm text-[var(--muted)]"
          >
            No cashier activity in this range.
          </p>
          <ul v-else class="divide-y divide-[var(--line)] text-sm">
            <li
              v-for="c in report.cashiers"
              :key="c.cashierId"
              class="flex items-baseline justify-between gap-3 px-4 py-2"
            >
              <span>
                {{ c.name ?? `#${c.cashierId}` }}
                <span class="ml-2 text-xs text-[var(--muted)]">{{ c.salesCount }} sales</span>
              </span>
              <span class="shrink-0 font-mono text-[0.8125rem] tabular-nums">
                {{ formatMoney(c.salesTotal, report?.currency ?? null) }}
              </span>
            </li>
          </ul>
        </section>

        <section class="border border-[var(--line)] bg-[var(--panel)]">
          <div class="border-b border-[var(--line)] px-4 py-3">
            <h3 class="text-sm font-semibold tracking-tight">Top products</h3>
            <p class="mt-0.5 text-xs text-[var(--muted)]">
              By line total. Select one to filter the report.
            </p>
          </div>
          <p
            v-if="!report || report.topProducts.length === 0"
            class="px-4 py-3 text-sm text-[var(--muted)]"
          >
            No products sold in this range.
          </p>
          <ul v-else class="divide-y divide-[var(--line)] text-sm">
            <li
              v-for="p in report.topProducts"
              :key="p.productVariantId"
              class="flex items-baseline justify-between gap-3 px-4 py-2"
            >
              <button
                type="button"
                class="min-w-0 text-left text-[var(--brand-ink)] underline-offset-2 hover:underline"
                @click="filterByVariant(p)"
              >
                <span class="font-mono text-[0.8125rem]">{{ p.sku ?? `#${p.productVariantId}` }}</span>
                <span class="ml-2 text-[var(--muted)]">{{ p.name }}</span>
              </button>
              <span class="shrink-0 font-mono text-[0.8125rem] tabular-nums">
                {{ p.qty }} · {{ formatMoney(p.lineTotal, report?.currency ?? null) }}
              </span>
            </li>
          </ul>
        </section>
      </div>
    </template>
  </section>
</template>
