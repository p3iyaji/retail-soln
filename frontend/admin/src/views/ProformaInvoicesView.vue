<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

type Store = { id: number; name: string }
type CustomerOption = { id: number; name: string; accountNumber: string; isWalkIn: boolean }
type VariantOption = { id: number; label: string; price: string }
type ProformaItem = {
  id?: number
  variantId: number
  qty: string
  unitPrice: string
  discount: string
  lineTotal?: string
  variant?: { id: number; name: string; sku: string } | null
}
type ProformaInvoice = {
  id: number
  storeId: number
  customerId: number
  number: string | null
  status: string
  subtotal: string
  discountTotal: string
  taxTotal: string
  total: string
  validUntil: string | null
  notes: string | null
  convertedSaleId?: number | null
  convertedInvoiceId?: number | null
  customer?: { id: number; name: string; accountNumber: string; email: string | null } | null
  store?: { id: number; name: string } | null
  items?: ProformaItem[]
}
type ListMeta = { currentPage: number; lastPage: number; total: number; perPage: number }

const STATUS_LABELS: Record<string, string> = {
  draft: 'Draft',
  issued: 'Issued',
  accepted: 'Accepted',
  converted: 'Converted',
  expired: 'Expired',
  cancelled: 'Cancelled',
}

const auth = useAuthStore()
const canView = computed(() => auth.user?.permissions?.includes('invoices.proforma.view') === true)
const canCreate = computed(() => auth.user?.permissions?.includes('invoices.proforma.create') === true)
const canUpdate = computed(() => auth.user?.permissions?.includes('invoices.proforma.update') === true)
const canIssue = computed(() => auth.user?.permissions?.includes('invoices.proforma.issue') === true)
const canCancel = computed(() => auth.user?.permissions?.includes('invoices.proforma.cancel') === true)
const canConvert = computed(() => auth.user?.permissions?.includes('invoices.proforma.convert') === true)
const canHonorPrices = computed(() => auth.user?.permissions?.includes('invoices.proforma.honor-prices') === true)

const proformas = ref<ProformaInvoice[]>([])
const stores = ref<Store[]>([])
const customers = ref<CustomerOption[]>([])
const variants = ref<VariantOption[]>([])
const listMeta = ref<ListMeta>({ currentPage: 1, lastPage: 1, total: 0, perPage: 20 })
const page = ref(1)
const statusFilter = ref('')
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const message = ref('')
const showForm = ref(false)
const editingId = ref<number | null>(null)
const selected = ref<ProformaInvoice | null>(null)
const showConvert = ref(false)
const converting = ref<ProformaInvoice | null>(null)

const convertForm = reactive({
  target: 'sale' as 'sale' | 'invoice' | 'both',
  sessionId: '' as string | number,
  paymentAmount: '',
  honorPrices: false,
})

const form = reactive({
  storeId: '' as string | number,
  customerId: '' as string | number,
  notes: '',
  validUntil: '',
  items: [] as Array<{ variantId: string | number; qty: string; unitPrice: string; discount: string }>,
})

function statusLabel(status: string): string {
  return STATUS_LABELS[status] ?? status
}

function isEditable(p: ProformaInvoice): boolean {
  return canUpdate.value && (p.status === 'draft' || p.status === 'issued')
}

function canIssueProforma(p: ProformaInvoice): boolean {
  return canIssue.value && p.status === 'draft'
}

function canCancelProforma(p: ProformaInvoice): boolean {
  return canCancel.value && ['draft', 'issued', 'accepted'].includes(p.status)
}

function canDownloadPdf(p: ProformaInvoice): boolean {
  return ['issued', 'accepted', 'converted'].includes(p.status)
}

function canConvertProforma(p: ProformaInvoice): boolean {
  return canConvert.value && ['issued', 'accepted'].includes(p.status)
}

function resetForm() {
  editingId.value = null
  form.storeId = stores.value[0]?.id ?? ''
  form.customerId = ''
  form.notes = ''
  form.validUntil = ''
  form.items = [{ variantId: '', qty: '1.0000', unitPrice: '0.0000', discount: '0.0000' }]
}

async function loadStores() {
  const res = await api.get<{ data: Store[] }>('/stores')
  stores.value = res.data
}

async function loadCustomers() {
  const res = await api.get<{ data: CustomerOption[] }>('/customers?per_page=100')
  customers.value = res.data.filter((c) => !c.isWalkIn)
}

async function loadVariants() {
  const res = await api.get<{ data: Array<{ id: number; name: string; sku: string; variants?: Array<{ id: number; name: string; sku: string; price: string }> }> }>(
    '/products?per_page=50',
  )
  const options: VariantOption[] = []
  for (const product of res.data) {
    const productVariants = product.variants ?? []
    for (const v of productVariants) {
      options.push({ id: v.id, label: `${product.name} — ${v.name} (${v.sku})`, price: v.price })
    }
  }
  variants.value = options
}

async function loadList() {
  loading.value = true
  error.value = ''
  try {
    const qs = new URLSearchParams({ page: String(page.value), per_page: '20' })
    if (statusFilter.value) qs.set('status', statusFilter.value)
    const res = await api.get<{ data: ProformaInvoice[]; meta: ListMeta }>(`/proforma-invoices?${qs}`)
    proformas.value = res.data
    listMeta.value = res.meta
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load proforma invoices'
  } finally {
    loading.value = false
  }
}

function openCreate() {
  resetForm()
  showForm.value = true
}

function fillForm(p: ProformaInvoice) {
  editingId.value = p.id
  form.storeId = p.storeId
  form.customerId = p.customerId
  form.notes = p.notes ?? ''
  form.validUntil = p.validUntil ?? ''
  form.items = (p.items ?? []).map((item) => ({
    variantId: item.variantId,
    qty: item.qty,
    unitPrice: item.unitPrice,
    discount: item.discount,
  }))
  if (form.items.length === 0) {
    form.items.push({ variantId: '', qty: '1.0000', unitPrice: '0.0000', discount: '0.0000' })
  }
}

async function openEdit(p: ProformaInvoice) {
  const res = await api.get<{ data: ProformaInvoice }>(`/proforma-invoices/${p.id}`)
  fillForm(res.data)
  showForm.value = true
}

async function openDetail(p: ProformaInvoice) {
  const res = await api.get<{ data: ProformaInvoice }>(`/proforma-invoices/${p.id}`)
  selected.value = res.data
}

function addLine() {
  form.items.push({ variantId: '', qty: '1.0000', unitPrice: '0.0000', discount: '0.0000' })
}

function removeLine(index: number) {
  if (form.items.length <= 1) return
  form.items.splice(index, 1)
}

function onVariantChange(index: number) {
  const variantId = form.items[index]?.variantId
  const match = variants.value.find((v) => v.id === Number(variantId))
  if (match && form.items[index]) {
    form.items[index].unitPrice = match.price
  }
}

function payload() {
  return {
    storeId: Number(form.storeId),
    customerId: Number(form.customerId),
    notes: form.notes || null,
    validUntil: form.validUntil || null,
    items: form.items.map((item) => ({
      variantId: Number(item.variantId),
      qty: item.qty,
      unitPrice: item.unitPrice,
      discount: item.discount,
    })),
  }
}

async function saveForm() {
  saving.value = true
  error.value = ''
  message.value = ''
  try {
    if (editingId.value) {
      await api.patch(`/proforma-invoices/${editingId.value}`, payload())
      message.value = 'Proforma updated.'
    } else {
      await api.post('/proforma-invoices', payload())
      message.value = 'Proforma draft created.'
    }
    showForm.value = false
    await loadList()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Save failed'
  } finally {
    saving.value = false
  }
}

async function issueProforma(p: ProformaInvoice) {
  saving.value = true
  error.value = ''
  try {
    await api.post(`/proforma-invoices/${p.id}/issue`)
    message.value = `Proforma ${p.number ?? p.id} issued.`
    selected.value = null
    await loadList()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Issue failed'
  } finally {
    saving.value = false
  }
}

async function cancelProforma(p: ProformaInvoice) {
  if (!confirm('Cancel this proforma invoice?')) return
  saving.value = true
  error.value = ''
  try {
    await api.post(`/proforma-invoices/${p.id}/cancel`)
    message.value = 'Proforma cancelled.'
    selected.value = null
    await loadList()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Cancel failed'
  } finally {
    saving.value = false
  }
}

async function downloadPdf(p: ProformaInvoice) {
  const filename = `${p.number ?? `proforma-${p.id}`}.html`
  try {
    await api.download(`/proforma-invoices/${p.id}/pdf?download=1`, filename)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Download failed'
  }
}

async function openConvert(p: ProformaInvoice) {
  const res = await api.get<{ data: ProformaInvoice }>(`/proforma-invoices/${p.id}`)
  converting.value = res.data
  convertForm.target = 'sale'
  convertForm.paymentAmount = res.data.total
  convertForm.honorPrices = false
  convertForm.sessionId = ''
  showConvert.value = true

  try {
    const sessionRes = await api.get<{ data: { id: number } | null }>(
      '/pos/sessions/current?terminalName=ADMIN',
      { 'X-Store-Id': String(res.data.storeId) },
    )
    if (sessionRes.data?.id) {
      convertForm.sessionId = sessionRes.data.id
    }
  } catch {
    // Session lookup is best-effort.
  }
}

async function submitConvert() {
  if (!converting.value) return
  saving.value = true
  error.value = ''
  message.value = ''
  try {
    const body: Record<string, unknown> = { target: convertForm.target }
    if (convertForm.target !== 'invoice') {
      body.uuid = crypto.randomUUID()
      body.sessionId = Number(convertForm.sessionId)
      body.payments = [{
        method: 'cash',
        amount: convertForm.paymentAmount,
        provider: 'manual',
      }]
      if (convertForm.honorPrices && canHonorPrices.value) {
        body.honorPrices = true
      }
    }
    await api.post(`/proforma-invoices/${converting.value.id}/convert`, body, {
      'Idempotency-Key': `admin-pf-${converting.value.id}-${Date.now()}`,
      'X-Store-Id': String(converting.value.storeId),
    })
    message.value = 'Proforma converted successfully.'
    showConvert.value = false
    converting.value = null
    selected.value = null
    await loadList()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Conversion failed'
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  if (!canView.value) {
    loading.value = false
    return
  }
  await Promise.all([loadStores(), loadCustomers(), loadVariants(), loadList()])
})
</script>

<template>
  <div class="space-y-6">
    <div v-if="!canView" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
      You do not have permission to view proforma invoices.
    </div>

    <template v-else>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="text-xl font-semibold">Proforma invoices</h2>
          <p class="text-sm text-[var(--muted)]">Non-fiscal quotes — no stock or AR until converted.</p>
        </div>
        <button
          v-if="canCreate"
          type="button"
          class="rounded-md bg-[var(--brand)] px-4 py-2 text-sm font-medium text-white hover:opacity-90"
          @click="openCreate"
        >
          New proforma
        </button>
      </div>

      <p v-if="message" class="rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800">{{ message }}</p>
      <p v-if="error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{{ error }}</p>

      <div class="flex flex-wrap items-center gap-3">
        <label class="text-sm text-[var(--muted)]">
          Status
          <select v-model="statusFilter" class="ml-2 rounded border border-[var(--line)] px-2 py-1 text-sm" @change="page = 1; loadList()">
            <option value="">All</option>
            <option v-for="(label, key) in STATUS_LABELS" :key="key" :value="key">{{ label }}</option>
          </select>
        </label>
      </div>

      <div v-if="loading" class="text-sm text-[var(--muted)]">Loading…</div>

      <div v-else class="overflow-x-auto rounded-lg border border-[var(--line)]">
        <table class="min-w-full text-sm">
          <thead class="bg-stone-50 text-left text-[var(--muted)]">
            <tr>
              <th class="px-3 py-2">Number</th>
              <th class="px-3 py-2">Customer</th>
              <th class="px-3 py-2">Store</th>
              <th class="px-3 py-2">Status</th>
              <th class="px-3 py-2 text-right">Total</th>
              <th class="px-3 py-2">Valid until</th>
              <th class="px-3 py-2">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in proformas" :key="p.id" class="border-t border-[var(--line)]">
              <td class="px-3 py-2 font-mono text-xs">{{ p.number ?? `Draft #${p.id}` }}</td>
              <td class="px-3 py-2">{{ p.customer?.name ?? p.customerId }}</td>
              <td class="px-3 py-2">{{ p.store?.name ?? p.storeId }}</td>
              <td class="px-3 py-2">{{ statusLabel(p.status) }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ p.total }}</td>
              <td class="px-3 py-2">{{ p.validUntil ?? '—' }}</td>
              <td class="px-3 py-2">
                <div class="flex flex-wrap gap-2">
                  <button type="button" class="text-[var(--brand)] hover:underline" @click="openDetail(p)">View</button>
                  <button v-if="isEditable(p)" type="button" class="text-[var(--brand)] hover:underline" @click="openEdit(p)">Edit</button>
                  <button v-if="canIssueProforma(p)" type="button" class="text-[var(--brand)] hover:underline" @click="issueProforma(p)">Issue</button>
                  <button v-if="canConvertProforma(p)" type="button" class="text-[var(--brand)] hover:underline" @click="openConvert(p)">Convert</button>
                  <button v-if="canDownloadPdf(p)" type="button" class="text-[var(--brand)] hover:underline" @click="downloadPdf(p)">Download</button>
                  <button v-if="canCancelProforma(p)" type="button" class="text-red-700 hover:underline" @click="cancelProforma(p)">Cancel</button>
                </div>
              </td>
            </tr>
            <tr v-if="proformas.length === 0">
              <td colspan="7" class="px-3 py-6 text-center text-[var(--muted)]">No proforma invoices yet.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="listMeta.lastPage > 1" class="flex items-center gap-2 text-sm">
        <button type="button" class="rounded border px-2 py-1 disabled:opacity-50" :disabled="page <= 1" @click="page--; loadList()">Prev</button>
        <span>Page {{ listMeta.currentPage }} / {{ listMeta.lastPage }}</span>
        <button type="button" class="rounded border px-2 py-1 disabled:opacity-50" :disabled="page >= listMeta.lastPage" @click="page++; loadList()">Next</button>
      </div>

      <div v-if="showForm" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4">
        <div class="mt-8 w-full max-w-3xl rounded-lg bg-white p-6 shadow-lg">
          <h3 class="text-lg font-semibold">{{ editingId ? 'Edit proforma' : 'New proforma' }}</h3>
          <form class="mt-4 space-y-4" @submit.prevent="saveForm">
            <div class="grid gap-4 sm:grid-cols-2">
              <label class="block text-sm">
                Store
                <select v-model="form.storeId" required class="mt-1 w-full rounded border px-2 py-1.5" :disabled="Boolean(editingId)">
                  <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
              </label>
              <label class="block text-sm">
                Customer
                <select v-model="form.customerId" required class="mt-1 w-full rounded border px-2 py-1.5" :disabled="Boolean(editingId)">
                  <option value="">Select customer</option>
                  <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }} ({{ c.accountNumber }})</option>
                </select>
              </label>
              <label class="block text-sm">
                Valid until
                <input v-model="form.validUntil" type="date" class="mt-1 w-full rounded border px-2 py-1.5" />
              </label>
            </div>
            <label class="block text-sm">
              Notes
              <textarea v-model="form.notes" rows="2" class="mt-1 w-full rounded border px-2 py-1.5" />
            </label>

            <div>
              <div class="mb-2 flex items-center justify-between">
                <h4 class="text-sm font-medium">Line items</h4>
                <button type="button" class="text-sm text-[var(--brand)]" @click="addLine">Add line</button>
              </div>
              <div v-for="(line, index) in form.items" :key="index" class="mb-2 grid gap-2 rounded border p-2 sm:grid-cols-5">
                <select v-model="line.variantId" required class="rounded border px-2 py-1 text-sm sm:col-span-2" @change="onVariantChange(index)">
                  <option value="">Variant</option>
                  <option v-for="v in variants" :key="v.id" :value="v.id">{{ v.label }}</option>
                </select>
                <input v-model="line.qty" required placeholder="Qty" class="rounded border px-2 py-1 text-sm font-mono" />
                <input v-model="line.unitPrice" required placeholder="Unit price" class="rounded border px-2 py-1 text-sm font-mono" />
                <div class="flex gap-1">
                  <input v-model="line.discount" required placeholder="Discount" class="w-full rounded border px-2 py-1 text-sm font-mono" />
                  <button type="button" class="text-red-600" @click="removeLine(index)">×</button>
                </div>
              </div>
            </div>

            <div class="flex justify-end gap-2">
              <button type="button" class="rounded border px-4 py-2 text-sm" @click="showForm = false">Close</button>
              <button type="submit" class="rounded bg-[var(--brand)] px-4 py-2 text-sm text-white disabled:opacity-60" :disabled="saving">
                {{ saving ? 'Saving…' : 'Save draft' }}
              </button>
            </div>
          </form>
        </div>
      </div>

      <div v-if="selected" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4">
        <div class="mt-8 w-full max-w-2xl rounded-lg bg-white p-6 shadow-lg">
          <div class="flex items-start justify-between gap-4">
            <div>
              <h3 class="text-lg font-semibold">{{ selected.number ?? `Draft #${selected.id}` }}</h3>
              <p class="text-sm text-[var(--muted)]">{{ statusLabel(selected.status) }} · {{ selected.customer?.name }}</p>
            </div>
            <button type="button" class="text-sm" @click="selected = null">Close</button>
          </div>
          <p class="mt-2 rounded border border-orange-200 bg-orange-50 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-orange-800">
            Proforma — Not a tax invoice
          </p>
          <table class="mt-4 w-full text-sm">
            <thead>
              <tr class="border-b text-left text-[var(--muted)]">
                <th class="py-1">SKU</th>
                <th class="py-1">Item</th>
                <th class="py-1 text-right">Qty</th>
                <th class="py-1 text-right">Total</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in selected.items ?? []" :key="item.id ?? item.variantId" class="border-b">
                <td class="py-1 font-mono text-xs">{{ item.variant?.sku }}</td>
                <td class="py-1">{{ item.variant?.name }}</td>
                <td class="py-1 text-right font-mono">{{ item.qty }}</td>
                <td class="py-1 text-right font-mono">{{ item.lineTotal }}</td>
              </tr>
            </tbody>
          </table>
          <div class="mt-4 space-y-1 text-sm">
            <div class="flex justify-between"><span>Subtotal</span><span class="font-mono">{{ selected.subtotal }}</span></div>
            <div class="flex justify-between"><span>Tax</span><span class="font-mono">{{ selected.taxTotal }}</span></div>
            <div class="flex justify-between font-semibold"><span>Total</span><span class="font-mono">{{ selected.total }}</span></div>
          </div>
          <div v-if="selected.convertedSaleId || selected.convertedInvoiceId" class="mt-4 rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-900">
            <p v-if="selected.convertedSaleId">Converted sale #{{ selected.convertedSaleId }}</p>
            <p v-if="selected.convertedInvoiceId">Converted invoice #{{ selected.convertedInvoiceId }}</p>
          </div>
          <div class="mt-4 flex flex-wrap gap-2">
            <button v-if="canIssueProforma(selected)" type="button" class="rounded bg-[var(--brand)] px-3 py-1.5 text-sm text-white" @click="issueProforma(selected)">Issue</button>
            <button v-if="canConvertProforma(selected)" type="button" class="rounded bg-[var(--brand)] px-3 py-1.5 text-sm text-white" @click="openConvert(selected)">Convert</button>
            <button v-if="canDownloadPdf(selected)" type="button" class="rounded border px-3 py-1.5 text-sm" @click="downloadPdf(selected)">Download</button>
            <button v-if="canCancelProforma(selected)" type="button" class="rounded border border-red-300 px-3 py-1.5 text-sm text-red-700" @click="cancelProforma(selected)">Cancel</button>
          </div>
        </div>
      </div>

      <div v-if="showConvert && converting" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4">
        <div class="mt-8 w-full max-w-lg rounded-lg bg-white p-6 shadow-lg">
          <h3 class="text-lg font-semibold">Convert proforma</h3>
          <p class="mt-1 text-sm text-[var(--muted)]">{{ converting.number ?? `Draft #${converting.id}` }} · Total {{ converting.total }}</p>
          <form class="mt-4 space-y-4" @submit.prevent="submitConvert">
            <label class="block text-sm">
              Target
              <select v-model="convertForm.target" class="mt-1 w-full rounded border px-2 py-1.5">
                <option value="sale">Sale (stock + payment)</option>
                <option value="invoice">Tax invoice only</option>
                <option value="both">Sale + tax invoice</option>
              </select>
            </label>
            <template v-if="convertForm.target !== 'invoice'">
              <label class="block text-sm">
                Cashier session ID
                <input v-model="convertForm.sessionId" required type="number" class="mt-1 w-full rounded border px-2 py-1.5 font-mono" />
              </label>
              <label class="block text-sm">
                Payment amount
                <input v-model="convertForm.paymentAmount" required class="mt-1 w-full rounded border px-2 py-1.5 font-mono" />
              </label>
              <label v-if="canHonorPrices" class="flex items-center gap-2 text-sm">
                <input v-model="convertForm.honorPrices" type="checkbox" class="rounded border" />
                Honor proforma prices
              </label>
              <p class="text-xs text-[var(--muted)]">Requires an open cashier session for the proforma store. Current prices apply unless you have honor-prices permission.</p>
            </template>
            <div class="flex justify-end gap-2">
              <button type="button" class="rounded border px-4 py-2 text-sm" @click="showConvert = false">Close</button>
              <button type="submit" class="rounded bg-[var(--brand)] px-4 py-2 text-sm text-white disabled:opacity-60" :disabled="saving">
                {{ saving ? 'Converting…' : 'Convert' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </template>
  </div>
</template>
