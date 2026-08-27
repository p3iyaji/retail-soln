<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

type Store = { id: number; name: string }
type SupplierOption = { id: number; name: string; code: string | null }
type VariantOption = { id: number; label: string }
type PoItem = {
  id?: number
  productVariantId: number | ''
  qty: string
  unitCost: string
  lineTotal?: string
  productVariant?: { id: number; name: string; sku: string } | null
}
type PurchaseOrder = {
  id: number
  number: string
  status: string
  storeId: number
  supplierId: number
  notes: string | null
  totalAmount: string
  createdBy: number
  rejectedBy?: number | null
  rejectionReason: string | null
  supplier?: { id: number; name: string; code: string | null } | null
  store?: { id: number; name: string } | null
  items?: PoItem[]
}

type ListMeta = {
  currentPage: number
  lastPage: number
  total: number
  perPage: number
}

const STATUS_LABELS: Record<string, string> = {
  draft: 'Draft',
  pending_approval: 'Pending approval',
  approved: 'Approved',
  rejected: 'Rejected',
  partial: 'Partial',
  received: 'Received',
  closed: 'Closed',
}

const auth = useAuthStore()
const orders = ref<PurchaseOrder[]>([])
const stores = ref<Store[]>([])
const suppliers = ref<SupplierOption[]>([])
const variants = ref<VariantOption[]>([])
const listMeta = ref<ListMeta>({ currentPage: 1, lastPage: 1, total: 0, perPage: 20 })
const page = ref(1)
const statusFilter = ref('')
const storeFilter = ref('')
const productSearch = ref('')
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const editingId = ref<number | null>(null)
const showForm = ref(false)
const rejectReason = ref('')
const rejectingId = ref<number | null>(null)

const form = reactive({
  storeId: '' as string | number,
  supplierId: '' as string | number,
  notes: '',
  items: [] as Array<{ productVariantId: string | number; qty: string; unitCost: string }>,
})

const canCreate = computed(() => auth.user?.permissions?.includes('purchase.create') === true)
const canApprove = computed(() => auth.user?.permissions?.includes('purchase.approve') === true)
const formTitle = computed(() => (editingId.value ? 'Edit purchase order' : 'New purchase order'))
const editableStatuses = new Set(['draft', 'rejected'])

function statusLabel(status: string): string {
  return STATUS_LABELS[status] ?? status
}

function canEdit(po: PurchaseOrder): boolean {
  return canCreate.value && editableStatuses.has(po.status)
}

function canSubmit(po: PurchaseOrder): boolean {
  return canCreate.value && editableStatuses.has(po.status)
}

function canApprovePo(po: PurchaseOrder): boolean {
  return canApprove.value && po.status === 'pending_approval'
}

function formatError(e: unknown, fallback: string): string {
  if (e instanceof ApiError) {
    if (e.status === 403 && e.message.toLowerCase().includes('dual-control')) {
      return e.message
    }
    return e.message || fallback
  }
  return fallback
}

async function loadStores() {
  const res = await api.get<{ data: Store[] }>('/stores?per_page=50')
  stores.value = res.data
  if (!form.storeId && stores.value[0]) {
    form.storeId = stores.value[0].id
  }
}

async function loadSuppliers() {
  const res = await api.get<{ data: SupplierOption[] }>('/suppliers?lite=1&activeOnly=1&per_page=500')
  suppliers.value = res.data.map((s) => ({ id: s.id, name: s.name, code: s.code }))
  if (!form.supplierId && suppliers.value[0]) {
    form.supplierId = suppliers.value[0].id
  }
}

async function loadVariants(q = '') {
  const qs = new URLSearchParams({ per_page: '200' })
  const trimmed = q.trim()
  if (trimmed) qs.set('q', trimmed)
  const products = await api.get<{
    data: Array<{ name: string; variants?: Array<{ id: number; name: string; sku: string; isActive?: boolean }> }>
    meta?: { lastPage: number }
  }>(`/products?${qs}`)

  let all = [...products.data]
  const lastPage = products.meta?.lastPage ?? 1
  for (let p = 2; p <= Math.min(lastPage, 5); p++) {
    const pageQs = new URLSearchParams({ per_page: '200', page: String(p) })
    if (trimmed) pageQs.set('q', trimmed)
    const pageRes = await api.get<{ data: typeof products.data }>(`/products?${pageQs}`)
    all = all.concat(pageRes.data)
  }

  variants.value = all.flatMap((product) =>
    (product.variants ?? [])
      .filter((v) => v.isActive !== false)
      .map((v) => ({
        id: v.id,
        label: `${product.name} — ${v.name} (${v.sku})`,
      })),
  )
}

async function loadOrders() {
  const qs = new URLSearchParams({
    per_page: '20',
    page: String(page.value),
  })
  if (statusFilter.value) qs.set('status', statusFilter.value)
  if (storeFilter.value) qs.set('storeId', String(storeFilter.value))
  const res = await api.get<{ data: PurchaseOrder[]; meta: ListMeta }>(`/purchase-orders?${qs}`)
  orders.value = res.data
  listMeta.value = res.meta
}

async function refresh() {
  loading.value = true
  error.value = ''
  try {
    await Promise.all([loadStores(), loadSuppliers(), loadVariants(), loadOrders()])
  } catch (e) {
    error.value = formatError(e, 'Failed to load purchase orders')
  } finally {
    loading.value = false
  }
}

let searchTimer: ReturnType<typeof setTimeout> | null = null
watch(productSearch, (q) => {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    loadVariants(q).catch((e) => {
      error.value = formatError(e, 'Failed to search products')
    })
  }, 300)
})

function resetForm() {
  editingId.value = null
  form.storeId = stores.value[0]?.id ?? ''
  form.supplierId = suppliers.value[0]?.id ?? ''
  form.notes = ''
  form.items = [{ productVariantId: '', qty: '1.0000', unitCost: '0.0000' }]
}

function openCreate() {
  resetForm()
  showForm.value = true
}

function openEdit(po: PurchaseOrder) {
  editingId.value = po.id
  form.storeId = po.storeId
  form.supplierId = po.supplierId
  form.notes = po.notes ?? ''
  form.items = (po.items ?? []).map((i) => ({
    productVariantId: i.productVariantId,
    qty: i.qty,
    unitCost: i.unitCost,
  }))
  if (form.items.length === 0) {
    form.items = [{ productVariantId: '', qty: '1.0000', unitCost: '0.0000' }]
  }
  showForm.value = true
}

function addLine() {
  form.items.push({ productVariantId: '', qty: '1.0000', unitCost: '0.0000' })
}

function removeLine(index: number) {
  form.items.splice(index, 1)
  if (form.items.length === 0) {
    addLine()
  }
}

function payload() {
  return {
    storeId: Number(form.storeId),
    supplierId: Number(form.supplierId),
    notes: form.notes || null,
    items: form.items
      .filter((i) => i.productVariantId !== '' && i.productVariantId != null)
      .map((i) => ({
        productVariantId: Number(i.productVariantId),
        qty: i.qty,
        unitCost: i.unitCost,
      })),
  }
}

async function onSubmit() {
  saving.value = true
  error.value = ''
  try {
    const body = payload()
    if (body.items.length === 0) {
      error.value = 'Add at least one line item.'
      return
    }
    if (editingId.value) {
      await api.patch(`/purchase-orders/${editingId.value}`, body)
    } else {
      await api.post('/purchase-orders', body)
    }
    showForm.value = false
    resetForm()
    await loadOrders()
  } catch (e) {
    error.value = formatError(e, 'Save failed')
  } finally {
    saving.value = false
  }
}

async function onSubmitForApproval(po: PurchaseOrder) {
  saving.value = true
  error.value = ''
  try {
    await api.post(`/purchase-orders/${po.id}/submit`)
    await loadOrders()
  } catch (e) {
    error.value = formatError(e, 'Submit failed')
  } finally {
    saving.value = false
  }
}

async function onApprove(po: PurchaseOrder) {
  if (!confirm(`Approve ${po.number}?`)) return
  saving.value = true
  error.value = ''
  try {
    await api.post(`/purchase-orders/${po.id}/approve`)
    await loadOrders()
  } catch (e) {
    error.value = formatError(e, 'Approve failed')
  } finally {
    saving.value = false
  }
}

function startReject(po: PurchaseOrder) {
  rejectingId.value = po.id
  rejectReason.value = ''
}

async function onReject() {
  if (rejectingId.value == null) return
  saving.value = true
  error.value = ''
  try {
    await api.post(`/purchase-orders/${rejectingId.value}/reject`, {
      reason: rejectReason.value || null,
    })
    rejectingId.value = null
    rejectReason.value = ''
    await loadOrders()
  } catch (e) {
    error.value = formatError(e, 'Reject failed')
  } finally {
    saving.value = false
  }
}

async function onDelete(po: PurchaseOrder) {
  if (!confirm(`Delete draft ${po.number}?`)) return
  error.value = ''
  try {
    await api.delete(`/purchase-orders/${po.id}`)
    await loadOrders()
  } catch (e) {
    error.value = formatError(e, 'Delete failed')
  }
}

async function onFilter() {
  page.value = 1
  error.value = ''
  try {
    await loadOrders()
  } catch (e) {
    error.value = formatError(e, 'Filter failed')
  }
}

async function goPage(next: number) {
  if (next < 1 || next > listMeta.value.lastPage) return
  page.value = next
  error.value = ''
  try {
    await loadOrders()
  } catch (e) {
    error.value = formatError(e, 'Failed to load page')
  }
}

onMounted(refresh)
</script>

<template>
  <section class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="text-xl font-semibold tracking-tight">Purchase orders</h2>
        <p class="mt-1 text-sm text-[var(--muted)]">
          Create drafts, submit for approval, and approve or reject pending orders. Receiving is not available until a PO is approved.
        </p>
      </div>
      <button
        v-if="canCreate"
        type="button"
        class="rounded-md bg-[var(--brand)] px-3 py-2 text-sm text-white hover:opacity-90"
        @click="openCreate"
      >
        New purchase order
      </button>
    </div>

    <p v-if="error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{{ error }}</p>
    <p v-if="loading" class="text-sm text-[var(--muted)]">Loading…</p>

    <div v-if="!loading" class="flex flex-wrap items-end gap-3">
      <label class="block text-sm">
        <span class="text-[var(--muted)]">Status</span>
        <select v-model="statusFilter" class="mt-1 block rounded-md border border-[var(--line)] px-3 py-2" @change="onFilter">
          <option value="">All</option>
          <option value="draft">Draft</option>
          <option value="pending_approval">Pending approval</option>
          <option value="approved">Approved</option>
          <option value="rejected">Rejected</option>
        </select>
      </label>
      <label class="block text-sm">
        <span class="text-[var(--muted)]">Store</span>
        <select v-model="storeFilter" class="mt-1 block rounded-md border border-[var(--line)] px-3 py-2" @change="onFilter">
          <option value="">All stores</option>
          <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>
      </label>
      <p class="text-sm text-[var(--muted)]">
        Showing page {{ listMeta.currentPage }} of {{ listMeta.lastPage }} ({{ listMeta.total }} total)
      </p>
    </div>

    <div v-if="showForm" class="rounded-lg border border-[var(--line)] bg-[var(--panel)] p-4 space-y-3">
      <h3 class="font-semibold">{{ formTitle }}</h3>
      <div class="grid gap-3 md:grid-cols-2">
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Store</span>
          <select v-model="form.storeId" required class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2">
            <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Supplier</span>
          <select v-model="form.supplierId" required class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2">
            <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}{{ s.code ? ` (${s.code})` : '' }}</option>
          </select>
        </label>
      </div>
      <label class="block text-sm">
        <span class="text-[var(--muted)]">Notes</span>
        <input v-model="form.notes" class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2" />
      </label>

      <div class="space-y-2">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h4 class="text-sm font-medium">Line items</h4>
          <div class="flex flex-wrap items-center gap-2">
            <input
              v-model="productSearch"
              type="search"
              placeholder="Search products…"
              class="rounded-md border border-[var(--line)] px-3 py-1.5 text-sm"
            />
            <button type="button" class="text-sm text-[var(--brand)]" @click="addLine">Add line</button>
          </div>
        </div>
        <div v-for="(line, index) in form.items" :key="index" class="grid gap-2 md:grid-cols-[1fr_120px_120px_auto]">
          <select v-model="line.productVariantId" required class="rounded-md border border-[var(--line)] px-3 py-2 text-sm">
            <option value="">Variant…</option>
            <option v-for="v in variants" :key="v.id" :value="v.id">{{ v.label }}</option>
          </select>
          <input v-model="line.qty" required placeholder="Qty" class="rounded-md border border-[var(--line)] px-3 py-2 text-sm" />
          <input v-model="line.unitCost" required placeholder="Unit cost" class="rounded-md border border-[var(--line)] px-3 py-2 text-sm" />
          <button type="button" class="text-sm text-red-700" @click="removeLine(index)">Remove</button>
        </div>
      </div>

      <div class="flex gap-2">
        <button
          type="button"
          class="rounded-md bg-[var(--brand)] px-3 py-2 text-sm text-white disabled:opacity-60"
          :disabled="saving"
          @click="onSubmit"
        >
          Save draft
        </button>
        <button
          type="button"
          class="rounded-md border border-[var(--line)] px-3 py-2 text-sm"
          :disabled="saving"
          @click="showForm = false"
        >
          Cancel
        </button>
      </div>
    </div>

    <div
      v-if="rejectingId != null"
      class="rounded-lg border border-[var(--line)] bg-[var(--panel)] p-4 space-y-3"
    >
      <h3 class="font-semibold">Reject purchase order</h3>
      <label class="block text-sm">
        <span class="text-[var(--muted)]">Reason (optional)</span>
        <input v-model="rejectReason" class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2" />
      </label>
      <div class="flex gap-2">
        <button
          type="button"
          class="rounded-md bg-red-700 px-3 py-2 text-sm text-white disabled:opacity-60"
          :disabled="saving"
          @click="onReject"
        >
          Confirm reject
        </button>
        <button type="button" class="rounded-md border border-[var(--line)] px-3 py-2 text-sm" @click="rejectingId = null">
          Cancel
        </button>
      </div>
    </div>

    <div v-if="!loading" class="overflow-x-auto rounded-lg border border-[var(--line)]">
      <table class="min-w-full text-left text-sm">
        <thead class="border-b border-[var(--line)] bg-stone-50 text-[var(--muted)]">
          <tr>
            <th class="px-3 py-2 font-medium">Number</th>
            <th class="px-3 py-2 font-medium">Supplier</th>
            <th class="px-3 py-2 font-medium">Store</th>
            <th class="px-3 py-2 font-medium">Status</th>
            <th class="px-3 py-2 font-medium">Total</th>
            <th class="px-3 py-2 font-medium" />
          </tr>
        </thead>
        <tbody>
          <tr v-if="orders.length === 0">
            <td colspan="6" class="px-3 py-4 text-[var(--muted)]">No purchase orders yet.</td>
          </tr>
          <tr v-for="po in orders" :key="po.id" class="border-b border-[var(--line)]">
            <td class="px-3 py-2 font-mono text-xs">{{ po.number }}</td>
            <td class="px-3 py-2">{{ po.supplier?.name ?? `#${po.supplierId}` }}</td>
            <td class="px-3 py-2">{{ po.store?.name ?? `#${po.storeId}` }}</td>
            <td class="px-3 py-2">
              <span>{{ statusLabel(po.status) }}</span>
              <span v-if="po.status === 'rejected' && po.rejectionReason" class="mt-0.5 block text-xs text-[var(--muted)]">
                {{ po.rejectionReason }}
              </span>
            </td>
            <td class="px-3 py-2 font-mono text-xs">{{ po.totalAmount }}</td>
            <td class="px-3 py-2 text-right space-x-2 whitespace-nowrap">
              <button
                v-if="canEdit(po)"
                type="button"
                class="text-[var(--brand)] disabled:opacity-60"
                :disabled="saving"
                @click="openEdit(po)"
              >
                Edit
              </button>
              <button
                v-if="canSubmit(po)"
                type="button"
                class="text-[var(--brand)] disabled:opacity-60"
                :disabled="saving"
                @click="onSubmitForApproval(po)"
              >
                Submit
              </button>
              <button
                v-if="canApprovePo(po)"
                type="button"
                class="text-emerald-700 disabled:opacity-60"
                :disabled="saving"
                @click="onApprove(po)"
              >
                Approve
              </button>
              <button
                v-if="canApprovePo(po)"
                type="button"
                class="text-red-700 disabled:opacity-60"
                :disabled="saving"
                @click="startReject(po)"
              >
                Reject
              </button>
              <button
                v-if="canCreate && po.status === 'draft'"
                type="button"
                class="text-red-700 disabled:opacity-60"
                :disabled="saving"
                @click="onDelete(po)"
              >
                Delete
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="!loading && listMeta.lastPage > 1" class="flex gap-2">
      <button
        type="button"
        class="rounded-md border border-[var(--line)] px-3 py-1.5 text-sm disabled:opacity-50"
        :disabled="page <= 1"
        @click="goPage(page - 1)"
      >
        Previous
      </button>
      <button
        type="button"
        class="rounded-md border border-[var(--line)] px-3 py-1.5 text-sm disabled:opacity-50"
        :disabled="page >= listMeta.lastPage"
        @click="goPage(page + 1)"
      >
        Next
      </button>
    </div>
  </section>
</template>
