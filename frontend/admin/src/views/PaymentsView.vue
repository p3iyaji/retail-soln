<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

type SupplierOption = { id: number; name: string; code: string | null; isActive?: boolean }
type InvoiceableGrn = {
  id: number
  purchaseOrderId: number
  purchaseOrderNumber: string | null
  supplierId?: number
  suggestedAmount: string
  createdAt: string | null
}
type Invoice = {
  id: number
  number: string
  status: string
  supplierId: number
  purchaseOrderId: number
  goodsReceiptId: number
  totalAmount: string
  amountPaid: string
  amountRemaining: string
  notes: string | null
  supplier?: { id: number; name: string; code: string | null } | null
  purchaseOrder?: { id: number; number: string; status: string } | null
}
type Payment = {
  id: number
  supplierInvoiceId: number
  amount: string
  method: string | null
  reference: string | null
  paidAt: string | null
  invoice?: { id: number; number: string } | null
}
type ListMeta = {
  currentPage: number
  lastPage: number
  total: number
  perPage: number
  outstanding?: string
  invoiceableGoodsReceipts?: InvoiceableGrn[]
}

const STATUS_LABELS: Record<string, string> = {
  open: 'Open',
  partial: 'Partial',
  paid: 'Paid',
}

const auth = useAuthStore()
const canPay = computed(() => auth.user?.permissions?.includes('purchase.pay') === true)

const suppliers = ref<SupplierOption[]>([])
const invoices = ref<Invoice[]>([])
const payments = ref<Payment[]>([])
const invoiceable = ref<InvoiceableGrn[]>([])
const outstanding = ref('0.0000')
const isSupplierCredit = computed(() => /^-/.test(outstanding.value) && outstanding.value !== '-0.0000')
const listMeta = ref<ListMeta>({ currentPage: 1, lastPage: 1, total: 0, perPage: 20 })
const page = ref(1)
const supplierId = ref<string | number>('')
const statusFilter = ref('')
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const success = ref('')
let loadSeq = 0

const showInvoiceForm = ref(false)
const invoiceIdempotencyKey = ref('')
const paymentIdempotencyKey = ref('')
const invoiceForm = reactive({
  goodsReceiptId: '' as string | number,
  totalAmount: '',
  notes: '',
})

const payingInvoice = ref<Invoice | null>(null)
const paymentForm = reactive({
  amount: '',
  method: '',
  reference: '',
  notes: '',
})

function statusLabel(status: string): string {
  return STATUS_LABELS[status] ?? status
}

function formatError(e: unknown, fallback: string): string {
  if (e instanceof ApiError) return e.message || fallback
  if (e instanceof Error) return e.message
  return fallback
}

function isPositiveDecimal(value: string): boolean {
  return /^\d{1,11}(\.\d{1,4})?$/.test(value) && decimalCmp(value, '0') > 0
}

function decimalCmp(a: string, b: string): number {
  const [ai, af = ''] = a.split('.')
  const [bi, bf = ''] = b.split('.')
  const aPad = af.padEnd(4, '0').slice(0, 4)
  const bPad = bf.padEnd(4, '0').slice(0, 4)
  const an = BigInt(ai + aPad)
  const bn = BigInt(bi + bPad)
  return an === bn ? 0 : an > bn ? 1 : -1
}

async function loadSuppliers() {
  // Include inactive suppliers so historical AP remains selectable.
  const res = await api.get<{ data: SupplierOption[] }>('/suppliers?lite=1&per_page=500')
  suppliers.value = res.data
}

async function loadInvoices() {
  if (!canPay.value || !supplierId.value) {
    invoices.value = []
    payments.value = []
    invoiceable.value = []
    outstanding.value = '0.0000'
    listMeta.value = { currentPage: 1, lastPage: 1, total: 0, perPage: 20 }
    return
  }

  const seq = ++loadSeq
  const requestedSupplier = String(supplierId.value)
  const qs = new URLSearchParams({
    per_page: '20',
    page: String(page.value),
    supplierId: requestedSupplier,
  })
  if (statusFilter.value) qs.set('status', statusFilter.value)

  const [invoiceRes, paymentRes] = await Promise.all([
    api.get<{ data: Invoice[]; meta: ListMeta }>(`/supplier-invoices?${qs}`),
    api.get<{ data: Payment[] }>(
      `/supplier-payments?supplierId=${requestedSupplier}&per_page=50`,
    ),
  ])

  if (seq !== loadSeq || String(supplierId.value) !== requestedSupplier) {
    return
  }

  invoices.value = invoiceRes.data
  listMeta.value = invoiceRes.meta
  outstanding.value = invoiceRes.meta.outstanding ?? '0.0000'
  invoiceable.value = invoiceRes.meta.invoiceableGoodsReceipts ?? []
  payments.value = paymentRes.data
}

async function refresh() {
  if (!canPay.value) {
    loading.value = false
    return
  }
  loading.value = true
  error.value = ''
  try {
    await loadInvoices()
  } catch (e) {
    error.value = formatError(e, 'Failed to load invoices')
  } finally {
    loading.value = false
  }
}

watch([supplierId, statusFilter], () => {
  page.value = 1
  showInvoiceForm.value = false
  payingInvoice.value = null
  void refresh()
})

function openInvoiceForm() {
  if (!canPay.value) return
  showInvoiceForm.value = true
  invoiceIdempotencyKey.value = crypto.randomUUID()
  invoiceForm.goodsReceiptId = invoiceable.value[0]?.id ?? ''
  invoiceForm.totalAmount = invoiceable.value[0]?.suggestedAmount ?? ''
  invoiceForm.notes = ''
}

watch(
  () => invoiceForm.goodsReceiptId,
  (id) => {
    const grn = invoiceable.value.find((g) => g.id === Number(id))
    if (grn) invoiceForm.totalAmount = grn.suggestedAmount
  },
)

async function onCreateInvoice() {
  if (saving.value || !canPay.value) return
  saving.value = true
  error.value = ''
  success.value = ''
  try {
    if (!invoiceForm.goodsReceiptId) {
      error.value = 'Select a goods receipt.'
      return
    }
    if (!invoiceForm.totalAmount || !isPositiveDecimal(invoiceForm.totalAmount)) {
      error.value = 'Total amount must be greater than zero (up to 11+4 decimal digits).'
      return
    }
    if (!invoiceIdempotencyKey.value) {
      invoiceIdempotencyKey.value = crypto.randomUUID()
    }

    await api.post(
      '/supplier-invoices',
      {
        goodsReceiptId: Number(invoiceForm.goodsReceiptId),
        totalAmount: invoiceForm.totalAmount,
        notes: invoiceForm.notes || null,
      },
      { 'Idempotency-Key': invoiceIdempotencyKey.value },
    )
    showInvoiceForm.value = false
    invoiceIdempotencyKey.value = ''
    success.value = 'Supplier invoice created.'
    await loadInvoices()
  } catch (e) {
    error.value = formatError(e, 'Failed to create invoice')
  } finally {
    saving.value = false
  }
}

function openPay(invoice: Invoice) {
  if (!canPay.value) return
  if (invoice.status === 'paid' || decimalCmp(invoice.amountRemaining, '0') <= 0) return
  payingInvoice.value = invoice
  paymentIdempotencyKey.value = crypto.randomUUID()
  paymentForm.amount = invoice.amountRemaining
  paymentForm.method = ''
  paymentForm.reference = ''
  paymentForm.notes = ''
}

function closePay() {
  payingInvoice.value = null
  paymentIdempotencyKey.value = ''
}

async function onPay() {
  if (saving.value || payingInvoice.value == null || !canPay.value) return
  saving.value = true
  error.value = ''
  success.value = ''
  try {
    if (!isPositiveDecimal(paymentForm.amount)) {
      error.value = 'Payment amount must be greater than zero.'
      return
    }
    if (decimalCmp(paymentForm.amount, payingInvoice.value.amountRemaining) > 0) {
      error.value = `Amount exceeds remaining (${payingInvoice.value.amountRemaining}).`
      return
    }
    if (!paymentIdempotencyKey.value) {
      paymentIdempotencyKey.value = crypto.randomUUID()
    }
    await api.post(
      '/supplier-payments',
      {
        supplierInvoiceId: payingInvoice.value.id,
        amount: paymentForm.amount,
        method: paymentForm.method || null,
        reference: paymentForm.reference || null,
        notes: paymentForm.notes || null,
      },
      { 'Idempotency-Key': paymentIdempotencyKey.value },
    )
    closePay()
    success.value = 'Payment recorded.'
    await loadInvoices()
  } catch (e) {
    error.value = formatError(e, 'Failed to record payment')
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  loading.value = true
  try {
    if (canPay.value) {
      await loadSuppliers()
    }
  } catch (e) {
    error.value = formatError(e, 'Failed to load suppliers')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <p class="text-sm text-[var(--muted)]">
          Match goods receipts to supplier invoices and record payments. Outstanding is computed from invoices minus payments.
        </p>
      </div>
      <button
        v-if="canPay && supplierId && invoiceable.length > 0"
        type="button"
        class="rounded-md bg-[var(--brand)] px-3 py-2 text-sm font-medium text-white hover:bg-[var(--brand-ink)] disabled:opacity-60"
        :disabled="saving"
        @click="openInvoiceForm"
      >
        New invoice
      </button>
    </div>

    <p v-if="!canPay" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
      You need the <code class="text-xs">purchase.pay</code> permission to manage invoices and payments.
    </p>

    <div v-if="error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{{ error }}</div>
    <div v-if="success" class="rounded-md border border-teal-200 bg-teal-50 px-3 py-2 text-sm text-teal-900">{{ success }}</div>

    <div class="flex flex-wrap items-end gap-3">
      <label class="block text-sm">
        <span class="mb-1 block text-[var(--muted)]">Supplier</span>
        <select
          v-model="supplierId"
          class="min-w-[240px] rounded-md border border-[var(--line)] bg-white px-3 py-2 disabled:opacity-60"
          :disabled="!canPay"
        >
          <option value="">Select supplier…</option>
          <option v-for="s in suppliers" :key="s.id" :value="s.id">
            {{ s.name }}{{ s.code ? ` (${s.code})` : '' }}{{ s.isActive === false ? ' — inactive' : '' }}
          </option>
        </select>
      </label>
      <label class="block text-sm">
        <span class="mb-1 block text-[var(--muted)]">Status</span>
        <select
          v-model="statusFilter"
          class="rounded-md border border-[var(--line)] bg-white px-3 py-2 disabled:opacity-60"
          :disabled="!canPay || !supplierId"
        >
          <option value="">All</option>
          <option value="open">Open</option>
          <option value="partial">Partial</option>
          <option value="paid">Paid</option>
        </select>
      </label>
      <div v-if="canPay && supplierId" class="rounded-md border border-[var(--line)] bg-[var(--panel)] px-4 py-2">
        <p class="text-xs tracking-wide text-[var(--muted)] uppercase">
          {{ isSupplierCredit ? 'Supplier credit' : 'Outstanding' }}
        </p>
        <p class="text-lg font-semibold tabular-nums">{{ outstanding }}</p>
        <p class="text-xs text-[var(--muted)]">
          {{
            isSupplierCredit
              ? 'Negative outstanding — return credits exceed net AP (invoices − payments − credits).'
              : 'Invoices − payments − return credits'
          }}
        </p>
      </div>
    </div>

    <div v-if="canPay && showInvoiceForm" class="rounded-md border border-[var(--line)] bg-[var(--panel)] p-4">
      <h2 class="mb-3 text-base font-semibold">Create invoice from goods receipt</h2>
      <div class="grid gap-3 sm:grid-cols-2">
        <label class="block text-sm sm:col-span-2">
          <span class="mb-1 block text-[var(--muted)]">Goods receipt</span>
          <select v-model="invoiceForm.goodsReceiptId" class="w-full rounded-md border border-[var(--line)] bg-white px-3 py-2">
            <option disabled value="">Select GRN…</option>
            <option v-for="g in invoiceable" :key="g.id" :value="g.id">
              GRN #{{ g.id }} · PO {{ g.purchaseOrderNumber ?? g.purchaseOrderId }} · suggested {{ g.suggestedAmount }}
            </option>
          </select>
        </label>
        <label class="block text-sm">
          <span class="mb-1 block text-[var(--muted)]">Total amount</span>
          <input v-model="invoiceForm.totalAmount" type="text" class="w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>
        <label class="block text-sm">
          <span class="mb-1 block text-[var(--muted)]">Notes</span>
          <input v-model="invoiceForm.notes" type="text" class="w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>
      </div>
      <div class="mt-3 flex gap-2">
        <button
          type="button"
          class="rounded-md bg-[var(--brand)] px-3 py-2 text-sm font-medium text-white hover:bg-[var(--brand-ink)] disabled:opacity-60"
          :disabled="saving"
          @click="onCreateInvoice"
        >
          Create invoice
        </button>
        <button type="button" class="rounded-md border border-[var(--line)] px-3 py-2 text-sm" @click="showInvoiceForm = false">
          Cancel
        </button>
      </div>
    </div>

    <div v-if="canPay && payingInvoice" class="rounded-md border border-[var(--line)] bg-[var(--panel)] p-4">
      <h2 class="mb-1 text-base font-semibold">Record payment · {{ payingInvoice.number }}</h2>
      <p class="mb-3 text-sm text-[var(--muted)]">
        Remaining {{ payingInvoice.amountRemaining }} of {{ payingInvoice.totalAmount }}
      </p>
      <div class="grid gap-3 sm:grid-cols-2">
        <label class="block text-sm">
          <span class="mb-1 block text-[var(--muted)]">Amount</span>
          <input v-model="paymentForm.amount" type="text" class="w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>
        <label class="block text-sm">
          <span class="mb-1 block text-[var(--muted)]">Method</span>
          <input v-model="paymentForm.method" type="text" placeholder="transfer, cash…" class="w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>
        <label class="block text-sm">
          <span class="mb-1 block text-[var(--muted)]">Reference</span>
          <input v-model="paymentForm.reference" type="text" class="w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>
        <label class="block text-sm">
          <span class="mb-1 block text-[var(--muted)]">Notes</span>
          <input v-model="paymentForm.notes" type="text" class="w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>
      </div>
      <div class="mt-3 flex gap-2">
        <button
          type="button"
          class="rounded-md bg-[var(--brand)] px-3 py-2 text-sm font-medium text-white hover:bg-[var(--brand-ink)] disabled:opacity-60"
          :disabled="saving"
          @click="onPay"
        >
          Record payment
        </button>
        <button type="button" class="rounded-md border border-[var(--line)] px-3 py-2 text-sm" @click="closePay">Cancel</button>
      </div>
    </div>

    <div v-if="loading" class="text-sm text-[var(--muted)]">Loading…</div>

    <div v-else-if="!canPay" class="text-sm text-[var(--muted)]">Payments UI is locked without permission.</div>

    <div v-else-if="!supplierId" class="text-sm text-[var(--muted)]">Select a supplier to view invoices and outstanding balance.</div>

    <template v-else>
      <div class="overflow-x-auto rounded-md border border-[var(--line)] bg-[var(--panel)]">
        <table class="min-w-full text-left text-sm">
          <thead class="sticky top-0 border-b border-[var(--line)] bg-stone-50 text-xs tracking-wide text-[var(--muted)] uppercase">
            <tr>
              <th class="px-3 py-2 font-medium">Invoice</th>
              <th class="px-3 py-2 font-medium">PO / GRN</th>
              <th class="px-3 py-2 font-medium">Status</th>
              <th class="px-3 py-2 font-medium text-right">Total</th>
              <th class="px-3 py-2 font-medium text-right">Paid</th>
              <th class="px-3 py-2 font-medium text-right">Remaining</th>
              <th class="px-3 py-2 font-medium"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="invoices.length === 0">
              <td colspan="7" class="px-3 py-6 text-center text-[var(--muted)]">No invoices for this supplier yet.</td>
            </tr>
            <tr v-for="inv in invoices" :key="inv.id" class="border-t border-[var(--line)]">
              <td class="px-3 py-2 font-medium">{{ inv.number }}</td>
              <td class="px-3 py-2 text-[var(--muted)]">
                {{ inv.purchaseOrder?.number ?? inv.purchaseOrderId }} / GRN #{{ inv.goodsReceiptId }}
              </td>
              <td class="px-3 py-2">{{ statusLabel(inv.status) }}</td>
              <td class="px-3 py-2 text-right tabular-nums">{{ inv.totalAmount }}</td>
              <td class="px-3 py-2 text-right tabular-nums">{{ inv.amountPaid }}</td>
              <td class="px-3 py-2 text-right tabular-nums">{{ inv.amountRemaining }}</td>
              <td class="px-3 py-2 text-right">
                <button
                  v-if="inv.status !== 'paid'"
                  type="button"
                  class="rounded-md border border-[var(--line)] px-2 py-1 text-xs hover:bg-stone-50"
                  @click="openPay(inv)"
                >
                  Pay
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="listMeta.lastPage > 1" class="flex items-center gap-2 text-sm">
        <button
          type="button"
          class="rounded-md border border-[var(--line)] px-2 py-1 disabled:opacity-50"
          :disabled="page <= 1"
          @click="page -= 1; refresh()"
        >
          Prev
        </button>
        <span class="text-[var(--muted)]">Page {{ listMeta.currentPage }} of {{ listMeta.lastPage }}</span>
        <button
          type="button"
          class="rounded-md border border-[var(--line)] px-2 py-1 disabled:opacity-50"
          :disabled="page >= listMeta.lastPage"
          @click="page += 1; refresh()"
        >
          Next
        </button>
      </div>

      <div>
        <h2 class="mb-2 text-base font-semibold">Payment history</h2>
        <div class="overflow-x-auto rounded-md border border-[var(--line)] bg-[var(--panel)]">
          <table class="min-w-full text-left text-sm">
            <thead class="border-b border-[var(--line)] bg-stone-50 text-xs tracking-wide text-[var(--muted)] uppercase">
              <tr>
                <th class="px-3 py-2 font-medium">Paid at</th>
                <th class="px-3 py-2 font-medium">Invoice</th>
                <th class="px-3 py-2 font-medium">Method</th>
                <th class="px-3 py-2 font-medium">Reference</th>
                <th class="px-3 py-2 font-medium text-right">Amount</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="payments.length === 0">
                <td colspan="5" class="px-3 py-6 text-center text-[var(--muted)]">No payments recorded.</td>
              </tr>
              <tr v-for="p in payments" :key="p.id" class="border-t border-[var(--line)]">
                <td class="px-3 py-2 text-[var(--muted)]">{{ p.paidAt ? new Date(p.paidAt).toLocaleString() : '—' }}</td>
                <td class="px-3 py-2">{{ p.invoice?.number ?? `#${p.supplierInvoiceId}` }}</td>
                <td class="px-3 py-2">{{ p.method || '—' }}</td>
                <td class="px-3 py-2 text-[var(--muted)]">{{ p.reference || '—' }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ p.amount }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>
