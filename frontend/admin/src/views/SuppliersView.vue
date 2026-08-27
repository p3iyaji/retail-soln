<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '@/api/client'

type Contact = {
  id?: number
  name: string
  email: string | null
  phone: string | null
  title: string | null
  isPrimary: boolean
}

type Address = {
  id?: number
  type: string
  line1: string
  line2: string | null
  city: string | null
  region: string | null
  postalCode: string | null
  country: string | null
  isDefault: boolean
}

type ProductMapping = {
  id?: number
  productId: number | null
  productSku?: string | null
  supplierSku: string | null
  cost: string | null
  product?: { id: number; name: string; sku: string } | null
}

type Supplier = {
  id: number
  name: string
  code: string | null
  isActive: boolean
  contacts?: Contact[]
  addresses?: Address[]
  productMappings?: ProductMapping[]
}

type ProductOption = { id: number; name: string; sku: string }

type ListMeta = {
  currentPage: number
  lastPage: number
  total: number
  perPage: number
}

const suppliers = ref<Supplier[]>([])
const products = ref<ProductOption[]>([])
const listMeta = ref<ListMeta>({ currentPage: 1, lastPage: 1, total: 0, perPage: 50 })
const page = ref(1)
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const importMessage = ref('')
const searchQuery = ref('')
const editingId = ref<number | null>(null)
const showForm = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

const form = reactive({
  name: '',
  code: '',
  isActive: true,
  contacts: [] as Contact[],
  addresses: [] as Address[],
  productMappings: [] as ProductMapping[],
})

const formTitle = computed(() => (editingId.value ? 'Edit supplier' : 'New supplier'))
const showingLabel = computed(() => {
  const total = listMeta.value.total
  const shown = suppliers.value.length
  if (total === 0) return 'Showing 0 of 0'
  const start = (listMeta.value.currentPage - 1) * listMeta.value.perPage + 1
  const end = start + shown - 1
  const overflow = shown < total && listMeta.value.lastPage > 1 ? ' (paginated)' : ''
  return `Showing ${start}–${end} of ${total}${overflow}`
})

async function loadProducts() {
  const res = await api.get<{ data: Array<{ id: number; name: string; sku: string }> }>('/products?per_page=100')
  products.value = res.data.map((p) => ({ id: p.id, name: p.name, sku: p.sku }))
}

async function loadSuppliers() {
  const q = searchQuery.value.trim()
  const qs = new URLSearchParams({
    per_page: '50',
    page: String(page.value),
  })
  if (q) qs.set('q', q)
  const res = await api.get<{ data: Supplier[]; meta: ListMeta }>(`/suppliers?${qs}`)
  suppliers.value = res.data
  listMeta.value = res.meta
}

async function refresh() {
  loading.value = true
  error.value = ''
  try {
    await Promise.all([loadProducts(), loadSuppliers()])
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load suppliers'
  } finally {
    loading.value = false
  }
}

function resetForm() {
  editingId.value = null
  form.name = ''
  form.code = ''
  form.isActive = true
  form.contacts = []
  form.addresses = []
  form.productMappings = []
}

function openCreate() {
  resetForm()
  showForm.value = true
}

function openEdit(s: Supplier) {
  editingId.value = s.id
  form.name = s.name
  form.code = s.code ?? ''
  form.isActive = s.isActive
  form.contacts = (s.contacts ?? []).map((c) => ({
    name: c.name,
    email: c.email,
    phone: c.phone,
    title: c.title,
    isPrimary: c.isPrimary,
  }))
  form.addresses = (s.addresses ?? []).map((a) => ({
    type: a.type,
    line1: a.line1,
    line2: a.line2,
    city: a.city,
    region: a.region,
    postalCode: a.postalCode,
    country: a.country,
    isDefault: a.isDefault,
  }))
  form.productMappings = (s.productMappings ?? []).map((m) => ({
    productId: m.productId,
    supplierSku: m.supplierSku,
    cost: m.cost,
    product: m.product,
  }))
  showForm.value = true
}

function addContact() {
  form.contacts.push({
    name: '',
    email: null,
    phone: null,
    title: null,
    isPrimary: form.contacts.length === 0,
  })
}

function addAddress() {
  form.addresses.push({
    type: 'billing',
    line1: '',
    line2: null,
    city: null,
    region: null,
    postalCode: null,
    country: null,
    isDefault: form.addresses.length === 0,
  })
}

function addMapping() {
  form.productMappings.push({
    productId: null,
    supplierSku: null,
    cost: null,
  })
}

function payload() {
  return {
    name: form.name,
    code: form.code || null,
    isActive: form.isActive,
    contacts: form.contacts
      .filter((c) => c.name.trim())
      .map((c) => ({
        name: c.name,
        email: c.email || null,
        phone: c.phone || null,
        title: c.title || null,
        isPrimary: c.isPrimary,
      })),
    addresses: form.addresses
      .filter((a) => a.line1.trim())
      .map((a) => ({
        type: a.type || 'other',
        line1: a.line1,
        line2: a.line2 || null,
        city: a.city || null,
        region: a.region || null,
        postalCode: a.postalCode || null,
        country: a.country || null,
        isDefault: a.isDefault,
      })),
    productMappings: form.productMappings
      .filter((m) => m.productId)
      .map((m) => ({
        productId: Number(m.productId),
        supplierSku: m.supplierSku || null,
        cost: m.cost || null,
      })),
  }
}

async function onSubmit() {
  saving.value = true
  error.value = ''
  try {
    const body = payload()
    if (editingId.value) {
      await api.patch(`/suppliers/${editingId.value}`, body)
    } else {
      await api.post('/suppliers', body)
    }
    showForm.value = false
    resetForm()
    await loadSuppliers()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Save failed'
  } finally {
    saving.value = false
  }
}

async function onDelete(s: Supplier) {
  if (!confirm(`Remove or deactivate “${s.name}”?`)) return
  error.value = ''
  importMessage.value = ''
  try {
    const res = await api.delete<{ data?: Supplier; meta?: { deactivated?: boolean } } | undefined>(
      `/suppliers/${s.id}`,
    )
    if (res && typeof res === 'object' && res.meta?.deactivated) {
      importMessage.value = `“${s.name}” deactivated (still referenced by products or mappings).`
    } else {
      importMessage.value = `“${s.name}” removed.`
    }
    await loadSuppliers()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Delete failed'
  }
}

async function onSearch() {
  error.value = ''
  page.value = 1
  try {
    await loadSuppliers()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Search failed'
  }
}

async function goPage(next: number) {
  if (next < 1 || next > listMeta.value.lastPage) return
  page.value = next
  error.value = ''
  try {
    await loadSuppliers()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load page'
  }
}

async function onExport() {
  error.value = ''
  try {
    await api.download('/suppliers/export', 'suppliers-export.csv')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Export failed'
  }
}

async function pollImportStatus(importId: number) {
  for (let i = 0; i < 3; i++) {
    await new Promise((r) => setTimeout(r, 800))
    try {
      const status = await api.get<{
        data: { id: number; status: string; successCount: number; errorCount: number }
      }>(`/suppliers/imports/${importId}`)
      if (status.data.status === 'completed' || status.data.status === 'failed') {
        importMessage.value =
          status.data.status === 'completed'
            ? `Import #${importId} finished: ${status.data.successCount} row(s); ${status.data.errorCount} error(s).`
            : `Import #${importId} failed.`
        await loadSuppliers()
        return
      }
      importMessage.value = `Import #${importId} still ${status.data.status}…`
    } catch {
      return
    }
  }
}

async function onImportFile(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return

  error.value = ''
  importMessage.value = ''
  try {
    const formData = new FormData()
    formData.append('file', file)
    const preview = await api.upload<{
      data: {
        totalRows: number
        validCount: number
        errorCount: number
        errors: Array<{ row: number; message: string }>
      }
    }>('/suppliers/import/preview', formData)

    if (preview.data.errorCount > 0 && preview.data.validCount === 0) {
      error.value = `Import preview failed: ${preview.data.errors[0]?.message ?? 'validation errors'}`
      return
    }

    const importForm = new FormData()
    importForm.append('file', file)
    const result = await api.upload<{
      data: { id: number; status: string; successCount: number; errorCount: number; async: boolean }
    }>('/suppliers/import', importForm)

    if (result.data.async) {
      importMessage.value = `Import #${result.data.id} queued (${result.data.status}).`
      void pollImportStatus(result.data.id)
    } else {
      importMessage.value = `Imported ${result.data.successCount} row(s); ${result.data.errorCount} error(s).`
      await loadSuppliers()
    }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Import failed'
  } finally {
    input.value = ''
  }
}

onMounted(refresh)
</script>

<template>
  <section class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <p class="text-sm text-[var(--muted)]">Manage supplier contacts, addresses, and product mappings.</p>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="rounded-md border border-[var(--line)] px-3 py-2 text-sm" @click="onExport">
          Export CSV
        </button>
        <button type="button" class="rounded-md border border-[var(--line)] px-3 py-2 text-sm" @click="fileInput?.click()">
          Import CSV
        </button>
        <input ref="fileInput" type="file" accept=".csv,text/csv" class="hidden" @change="onImportFile" />
        <button
          type="button"
          class="rounded-md bg-[var(--brand)] px-3 py-2 text-sm font-medium text-white hover:opacity-90"
          @click="openCreate"
        >
          New supplier
        </button>
      </div>
    </div>

    <form class="flex flex-wrap gap-2" @submit.prevent="onSearch">
      <input
        v-model="searchQuery"
        type="search"
        placeholder="Search name or code…"
        class="min-w-[240px] flex-1 rounded-md border border-[var(--line)] px-3 py-2 text-sm"
      />
      <button type="submit" class="rounded-md border border-[var(--line)] px-3 py-2 text-sm">Search</button>
    </form>

    <p v-if="importMessage" class="rounded-md border border-teal-200 bg-teal-50 px-3 py-2 text-sm text-teal-900">
      {{ importMessage }}
    </p>
    <p v-if="error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
      {{ error }}
    </p>
    <p v-if="loading" class="text-sm text-[var(--muted)]">Loading suppliers…</p>

    <div v-if="showForm" class="rounded-lg border border-[var(--line)] bg-[var(--panel)] p-4">
      <h3 class="text-base font-semibold">{{ formTitle }}</h3>
      <form class="mt-4 grid gap-3 sm:grid-cols-2" @submit.prevent="onSubmit">
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Name</span>
          <input v-model="form.name" required class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Code</span>
          <input v-model="form.code" class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>
        <label class="flex items-center gap-2 text-sm sm:col-span-2">
          <input v-model="form.isActive" type="checkbox" />
          Active
        </label>

        <div class="sm:col-span-2">
          <div class="flex items-center justify-between">
            <h4 class="text-sm font-semibold">Contacts</h4>
            <button type="button" class="text-xs text-[var(--brand)]" @click="addContact">+ add</button>
          </div>
          <div v-for="(c, i) in form.contacts" :key="i" class="mt-2 grid gap-2 sm:grid-cols-4">
            <input v-model="c.name" placeholder="Name" required class="rounded-md border border-[var(--line)] px-2 py-1.5 text-sm" />
            <input v-model="c.email" placeholder="Email" class="rounded-md border border-[var(--line)] px-2 py-1.5 text-sm" />
            <input v-model="c.phone" placeholder="Phone" class="rounded-md border border-[var(--line)] px-2 py-1.5 text-sm" />
            <label class="flex items-center gap-2 text-sm">
              <input v-model="c.isPrimary" type="checkbox" />
              Primary
            </label>
          </div>
        </div>

        <div class="sm:col-span-2">
          <div class="flex items-center justify-between">
            <h4 class="text-sm font-semibold">Addresses</h4>
            <button type="button" class="text-xs text-[var(--brand)]" @click="addAddress">+ add</button>
          </div>
          <div v-for="(a, i) in form.addresses" :key="i" class="mt-2 grid gap-2 sm:grid-cols-3">
            <select v-model="a.type" class="rounded-md border border-[var(--line)] px-2 py-1.5 text-sm">
              <option value="billing">Billing</option>
              <option value="shipping">Shipping</option>
              <option value="other">Other</option>
            </select>
            <input v-model="a.line1" placeholder="Line 1" required class="rounded-md border border-[var(--line)] px-2 py-1.5 text-sm sm:col-span-2" />
            <input v-model="a.city" placeholder="City" class="rounded-md border border-[var(--line)] px-2 py-1.5 text-sm" />
            <input v-model="a.country" placeholder="Country" class="rounded-md border border-[var(--line)] px-2 py-1.5 text-sm" />
            <label class="flex items-center gap-2 text-sm">
              <input v-model="a.isDefault" type="checkbox" />
              Default
            </label>
          </div>
        </div>

        <div class="sm:col-span-2">
          <div class="flex items-center justify-between">
            <h4 class="text-sm font-semibold">Product mappings</h4>
            <button type="button" class="text-xs text-[var(--brand)]" @click="addMapping">+ add</button>
          </div>
          <div v-for="(m, i) in form.productMappings" :key="i" class="mt-2 grid gap-2 sm:grid-cols-3">
            <select v-model="m.productId" class="rounded-md border border-[var(--line)] px-2 py-1.5 text-sm">
              <option :value="null">— product —</option>
              <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }} ({{ p.sku }})</option>
            </select>
            <input v-model="m.supplierSku" placeholder="Supplier SKU" class="rounded-md border border-[var(--line)] px-2 py-1.5 text-sm" />
            <input v-model="m.cost" placeholder="Cost" class="rounded-md border border-[var(--line)] px-2 py-1.5 text-sm" />
          </div>
        </div>

        <div class="flex gap-2 sm:col-span-2">
          <button
            type="submit"
            class="rounded-md bg-[var(--brand)] px-3 py-2 text-sm font-medium text-white disabled:opacity-60"
            :disabled="saving"
          >
            {{ saving ? 'Saving…' : 'Save' }}
          </button>
          <button type="button" class="rounded-md border border-[var(--line)] px-3 py-2 text-sm" @click="showForm = false">
            Cancel
          </button>
        </div>
      </form>
    </div>

    <div v-if="!loading" class="flex flex-wrap items-center justify-between gap-2 text-sm text-[var(--muted)]">
      <p>{{ showingLabel }}</p>
      <div v-if="listMeta.lastPage > 1" class="flex gap-2">
        <button
          type="button"
          class="rounded-md border border-[var(--line)] px-2 py-1 disabled:opacity-50"
          :disabled="page <= 1"
          @click="goPage(page - 1)"
        >
          Prev
        </button>
        <span>Page {{ listMeta.currentPage }} / {{ listMeta.lastPage }}</span>
        <button
          type="button"
          class="rounded-md border border-[var(--line)] px-2 py-1 disabled:opacity-50"
          :disabled="page >= listMeta.lastPage"
          @click="goPage(page + 1)"
        >
          Next
        </button>
      </div>
    </div>

    <div v-if="!loading" class="overflow-x-auto rounded-lg border border-[var(--line)]">
      <table class="min-w-full text-left text-sm">
        <thead class="border-b border-[var(--line)] bg-stone-50 text-[var(--muted)]">
          <tr>
            <th class="px-3 py-2 font-medium">Name</th>
            <th class="px-3 py-2 font-medium">Code</th>
            <th class="px-3 py-2 font-medium">Contacts</th>
            <th class="px-3 py-2 font-medium">Mappings</th>
            <th class="px-3 py-2 font-medium">Active</th>
            <th class="px-3 py-2 font-medium" />
          </tr>
        </thead>
        <tbody>
          <tr v-if="suppliers.length === 0">
            <td colspan="6" class="px-3 py-6 text-[var(--muted)]">No suppliers yet.</td>
          </tr>
          <tr v-for="s in suppliers" :key="s.id" class="border-b border-[var(--line)] last:border-0">
            <td class="px-3 py-2">{{ s.name }}</td>
            <td class="px-3 py-2 font-mono text-xs">{{ s.code ?? '—' }}</td>
            <td class="px-3 py-2">{{ s.contacts?.length ?? 0 }}</td>
            <td class="px-3 py-2">{{ s.productMappings?.length ?? 0 }}</td>
            <td class="px-3 py-2">{{ s.isActive ? 'Yes' : 'No' }}</td>
            <td class="px-3 py-2 text-right">
              <button type="button" class="mr-2 text-[var(--brand)]" @click="openEdit(s)">Edit</button>
              <button type="button" class="text-red-700" @click="onDelete(s)">Delete</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
