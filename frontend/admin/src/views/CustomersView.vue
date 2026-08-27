<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

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

type CustomerGroup = {
  id: number
  name: string
  code: string | null
}

type Customer = {
  id: number
  name: string
  type: string
  phone: string | null
  email: string | null
  accountNumber: string
  isWalkIn: boolean
  creditLimit: string
  balance: string
  customerGroupId: number | null
  notes: string | null
  isActive: boolean
  addresses?: Address[]
  group?: CustomerGroup | null
}

type HistoryPayload = {
  customerId: number
  sales: unknown[]
  invoices: unknown[]
  payments: unknown[]
  returns: unknown[]
  installmentPlans: unknown[]
}

type ListMeta = {
  currentPage: number
  lastPage: number
  total: number
  perPage: number
}

const TYPES = [
  { value: 'registered', label: 'Registered' },
  { value: 'business', label: 'Business' },
  { value: 'corporate', label: 'Corporate' },
]

const WALK_IN_TYPE = { value: 'walk_in', label: 'Walk-in' }

const auth = useAuthStore()
const canView = computed(() => auth.user?.permissions?.includes('customers.view') === true)
const canManage = computed(() => auth.user?.permissions?.includes('customers.manage') === true)

const customers = ref<Customer[]>([])
const groups = ref<CustomerGroup[]>([])
const listMeta = ref<ListMeta>({ currentPage: 1, lastPage: 1, total: 0, perPage: 20 })
const page = ref(1)
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const message = ref('')
const searchQuery = ref('')
const editingId = ref<number | null>(null)
const showForm = ref(false)
const selectedId = ref<number | null>(null)
const history = ref<HistoryPayload | null>(null)
const historyLoading = ref(false)
const addressesDirty = ref(false)

const groupForm = reactive({
  name: '',
  code: '',
})

const form = reactive({
  name: '',
  type: 'registered',
  phone: '',
  email: '',
  accountNumber: '',
  customerGroupId: null as number | null,
  creditLimit: '0.0000',
  notes: '',
  isActive: true,
  addresses: [] as Address[],
})

const formTitle = computed(() => (editingId.value ? 'Edit customer' : 'New customer'))
const selectedCustomer = computed(() => customers.value.find((c) => c.id === selectedId.value) ?? null)
const editingWalkIn = computed(() => {
  if (!editingId.value) return false
  return customers.value.find((c) => c.id === editingId.value)?.isWalkIn === true
})
const typeOptions = computed(() => (editingWalkIn.value ? [WALK_IN_TYPE, ...TYPES] : TYPES))

async function loadGroups() {
  const res = await api.get<{ data: CustomerGroup[] }>('/customer-groups')
  groups.value = res.data
}

async function loadCustomers() {
  const q = searchQuery.value.trim()
  const qs = new URLSearchParams({
    per_page: '20',
    page: String(page.value),
  })
  if (q) qs.set('q', q)
  const res = await api.get<{ data: Customer[]; meta: ListMeta }>(`/customers?${qs}`)
  customers.value = res.data
  listMeta.value = res.meta
}

async function refresh() {
  if (!canView.value) {
    loading.value = false
    error.value = 'You do not have permission to view customers.'
    return
  }

  loading.value = true
  error.value = ''
  try {
    await Promise.all([loadCustomers(), loadGroups()])
    if (selectedId.value && !customers.value.some((c) => c.id === selectedId.value)) {
      selectedId.value = null
      history.value = null
    }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load customers'
  } finally {
    loading.value = false
  }
}

function resetForm() {
  editingId.value = null
  form.name = ''
  form.type = 'registered'
  form.phone = ''
  form.email = ''
  form.accountNumber = ''
  form.customerGroupId = null
  form.creditLimit = '0.0000'
  form.notes = ''
  form.isActive = true
  form.addresses = []
  addressesDirty.value = false
}

function openCreate() {
  resetForm()
  showForm.value = true
  message.value = ''
  error.value = ''
}

function openEdit(c: Customer) {
  editingId.value = c.id
  form.name = c.name
  form.type = c.type
  form.phone = c.phone ?? ''
  form.email = c.email ?? ''
  form.accountNumber = c.accountNumber
  form.customerGroupId = c.customerGroupId
  form.creditLimit = c.creditLimit
  form.notes = c.notes ?? ''
  form.isActive = c.isActive
  form.addresses = (c.addresses ?? []).map((a) => ({
    type: a.type,
    line1: a.line1,
    line2: a.line2,
    city: a.city,
    region: a.region,
    postalCode: a.postalCode,
    country: a.country,
    isDefault: a.isDefault,
  }))
  addressesDirty.value = false
  showForm.value = true
  message.value = ''
  error.value = ''
}

function addAddress() {
  addressesDirty.value = true
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

function markAddressesDirty() {
  addressesDirty.value = true
}

function payload() {
  const body: Record<string, unknown> = {
    phone: form.phone || null,
    email: form.email || null,
    customerGroupId: form.customerGroupId,
    creditLimit: form.creditLimit || '0.0000',
    notes: form.notes || null,
  }

  if (!editingWalkIn.value) {
    body.name = form.name
    body.type = form.type
    body.isActive = form.isActive
    if (form.accountNumber.trim()) {
      body.accountNumber = form.accountNumber.trim()
    }
  }

  const addresses = form.addresses
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
    }))

  if (!editingId.value) {
    body.addresses = addresses
  } else if (addressesDirty.value || addresses.length > 0) {
    body.addresses = addresses
  }

  return body
}

async function onSubmit() {
  saving.value = true
  error.value = ''
  try {
    const body = payload()
    if (editingId.value) {
      await api.patch(`/customers/${editingId.value}`, body)
      message.value = 'Customer updated.'
    } else {
      await api.post('/customers', body)
      message.value = 'Customer created.'
    }
    showForm.value = false
    resetForm()
    await loadCustomers()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Save failed'
  } finally {
    saving.value = false
  }
}

async function onDelete(c: Customer) {
  if (c.isWalkIn) {
    error.value = 'Walk-in customer cannot be deleted.'
    return
  }
  if (!confirm(`Delete “${c.name}”?`)) return
  error.value = ''
  try {
    await api.delete(`/customers/${c.id}`)
    message.value = `“${c.name}” deleted.`
    if (selectedId.value === c.id) {
      selectedId.value = null
      history.value = null
    }
    await loadCustomers()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Delete failed'
  }
}

async function onSearch() {
  error.value = ''
  page.value = 1
  try {
    await loadCustomers()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Search failed'
  }
}

async function goPage(next: number) {
  if (next < 1 || next > listMeta.value.lastPage) return
  page.value = next
  try {
    await loadCustomers()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load page'
  }
}

async function selectCustomer(c: Customer) {
  selectedId.value = c.id
  historyLoading.value = true
  history.value = null
  error.value = ''
  try {
    const detail = await api.get<{ data: Customer }>(`/customers/${c.id}`)
    const idx = customers.value.findIndex((row) => row.id === detail.data.id)
    if (idx >= 0) customers.value[idx] = detail.data

    const hist = await api.get<{ data: HistoryPayload }>(`/customers/${c.id}/history`)
    history.value = hist.data
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load customer detail'
  } finally {
    historyLoading.value = false
  }
}

async function createGroup() {
  if (!groupForm.name.trim()) return
  error.value = ''
  try {
    await api.post('/customer-groups', {
      name: groupForm.name.trim(),
      code: groupForm.code.trim() || null,
    })
    groupForm.name = ''
    groupForm.code = ''
    message.value = 'Customer group created.'
    await loadGroups()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to create group'
  }
}

onMounted(refresh)
</script>

<template>
  <section class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-base font-semibold tracking-tight">Customers</h2>
        <p class="mt-1 text-sm text-[var(--muted)]">
          Manage registered customers, groups, and addresses. Walk-in is always available for POS.
        </p>
      </div>
      <button
        v-if="canManage"
        type="button"
        class="rounded-md bg-[var(--brand)] px-3 py-2 text-sm font-medium text-white hover:opacity-90"
        @click="openCreate"
      >
        New customer
      </button>
    </div>

    <form class="flex flex-wrap gap-2" @submit.prevent="onSearch">
      <input
        v-model="searchQuery"
        type="search"
        placeholder="Search name, phone, email, or account…"
        class="min-w-[240px] flex-1 rounded-md border border-[var(--line)] px-3 py-2 text-sm"
      />
      <button type="submit" class="rounded-md border border-[var(--line)] px-3 py-2 text-sm">Search</button>
    </form>

    <p v-if="message" class="rounded-md border border-teal-200 bg-teal-50 px-3 py-2 text-sm text-teal-900">
      {{ message }}
    </p>
    <p v-if="error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
      {{ error }}
    </p>
    <p v-if="loading" class="text-sm text-[var(--muted)]">Loading customers…</p>

    <div v-if="canManage" class="rounded-lg border border-[var(--line)] bg-[var(--panel)] p-4">
      <h3 class="text-sm font-semibold">Customer groups</h3>
      <form class="mt-3 flex flex-wrap gap-2" @submit.prevent="createGroup">
        <input
          v-model="groupForm.name"
          required
          placeholder="Group name"
          class="rounded-md border border-[var(--line)] px-3 py-2 text-sm"
        />
        <input
          v-model="groupForm.code"
          placeholder="Code (optional)"
          class="rounded-md border border-[var(--line)] px-3 py-2 text-sm"
        />
        <button type="submit" class="rounded-md border border-[var(--line)] px-3 py-2 text-sm">Add group</button>
      </form>
      <ul v-if="groups.length" class="mt-3 flex flex-wrap gap-2 text-sm text-[var(--muted)]">
        <li v-for="g in groups" :key="g.id" class="rounded border border-[var(--line)] px-2 py-1">
          {{ g.name }}<span v-if="g.code"> ({{ g.code }})</span>
        </li>
      </ul>
      <p v-else class="mt-2 text-sm text-[var(--muted)]">No groups yet.</p>
    </div>

    <div v-if="showForm" class="rounded-lg border border-[var(--line)] bg-[var(--panel)] p-4">
      <h3 class="text-base font-semibold">{{ formTitle }}</h3>
      <p v-if="editingWalkIn" class="mt-1 text-sm text-[var(--muted)]">
        Walk-in name and type are locked; other fields may be updated.
      </p>
      <form class="mt-4 grid gap-3 sm:grid-cols-2" @submit.prevent="onSubmit">
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Name</span>
          <input
            v-model="form.name"
            required
            :disabled="editingWalkIn"
            class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2 disabled:bg-stone-50"
          />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Type</span>
          <select
            v-model="form.type"
            :disabled="editingWalkIn"
            class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2 disabled:bg-stone-50"
          >
            <option v-for="t in typeOptions" :key="t.value" :value="t.value">{{ t.label }}</option>
          </select>
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Phone</span>
          <input v-model="form.phone" class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Email</span>
          <input v-model="form.email" type="email" class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Account number</span>
          <input
            v-model="form.accountNumber"
            :placeholder="editingId ? undefined : 'Auto if blank'"
            :disabled="editingWalkIn"
            class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2 disabled:bg-stone-50"
          />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Group</span>
          <select v-model="form.customerGroupId" class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2">
            <option :value="null">— none —</option>
            <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
          </select>
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Credit limit</span>
          <input v-model="form.creditLimit" class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2 font-mono" />
        </label>
        <label class="flex items-center gap-2 text-sm sm:mt-6">
          <input v-model="form.isActive" type="checkbox" :disabled="editingWalkIn" />
          Active
        </label>
        <label class="block text-sm sm:col-span-2">
          <span class="text-[var(--muted)]">Notes</span>
          <textarea v-model="form.notes" rows="2" class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>

        <div class="sm:col-span-2">
          <div class="flex items-center justify-between">
            <h4 class="text-sm font-semibold">Addresses</h4>
            <button type="button" class="text-xs text-[var(--brand)]" @click="addAddress">+ add</button>
          </div>
          <div v-for="(a, i) in form.addresses" :key="i" class="mt-2 grid gap-2 sm:grid-cols-3">
            <select
              v-model="a.type"
              class="rounded-md border border-[var(--line)] px-2 py-1.5 text-sm"
              @change="markAddressesDirty"
            >
              <option value="billing">Billing</option>
              <option value="shipping">Shipping</option>
              <option value="other">Other</option>
            </select>
            <input
              v-model="a.line1"
              placeholder="Line 1"
              required
              class="rounded-md border border-[var(--line)] px-2 py-1.5 text-sm sm:col-span-2"
              @input="markAddressesDirty"
            />
            <input
              v-model="a.line2"
              placeholder="Line 2"
              class="rounded-md border border-[var(--line)] px-2 py-1.5 text-sm sm:col-span-3"
              @input="markAddressesDirty"
            />
            <input
              v-model="a.city"
              placeholder="City"
              class="rounded-md border border-[var(--line)] px-2 py-1.5 text-sm"
              @input="markAddressesDirty"
            />
            <input
              v-model="a.region"
              placeholder="Region"
              class="rounded-md border border-[var(--line)] px-2 py-1.5 text-sm"
              @input="markAddressesDirty"
            />
            <input
              v-model="a.postalCode"
              placeholder="Postal code"
              class="rounded-md border border-[var(--line)] px-2 py-1.5 text-sm"
              @input="markAddressesDirty"
            />
            <input
              v-model="a.country"
              placeholder="Country"
              class="rounded-md border border-[var(--line)] px-2 py-1.5 text-sm"
              @input="markAddressesDirty"
            />
            <label class="flex items-center gap-2 text-sm">
              <input v-model="a.isDefault" type="checkbox" @change="markAddressesDirty" />
              Default
            </label>
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
          <button
            type="button"
            class="rounded-md border border-[var(--line)] px-3 py-2 text-sm"
            @click="showForm = false; resetForm()"
          >
            Cancel
          </button>
        </div>
      </form>
    </div>

    <div class="overflow-x-auto rounded-lg border border-[var(--line)]">
      <table class="min-w-full text-left text-sm">
        <thead class="border-b border-[var(--line)] bg-stone-50 text-[var(--muted)]">
          <tr>
            <th class="px-3 py-2 font-medium">Name</th>
            <th class="px-3 py-2 font-medium">Account</th>
            <th class="px-3 py-2 font-medium">Phone</th>
            <th class="px-3 py-2 font-medium">Type</th>
            <th class="px-3 py-2 font-medium">Group</th>
            <th class="px-3 py-2 font-medium"></th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="c in customers"
            :key="c.id"
            class="cursor-pointer border-b border-[var(--line)] last:border-0 hover:bg-stone-50"
            :class="selectedId === c.id ? 'bg-teal-50/60' : ''"
            @click="selectCustomer(c)"
          >
            <td class="px-3 py-2">
              {{ c.name }}
              <span
                v-if="c.isWalkIn"
                class="ml-2 rounded border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-xs text-amber-900"
              >
                Walk-in
              </span>
            </td>
            <td class="px-3 py-2 font-mono text-xs">{{ c.accountNumber }}</td>
            <td class="px-3 py-2">{{ c.phone || '—' }}</td>
            <td class="px-3 py-2">{{ c.type }}</td>
            <td class="px-3 py-2">{{ c.group?.name ?? '—' }}</td>
            <td class="px-3 py-2 text-right" @click.stop>
              <button
                v-if="canManage"
                type="button"
                class="mr-2 text-[var(--brand)] hover:underline"
                @click="openEdit(c)"
              >
                Edit
              </button>
              <button
                v-if="canManage && !c.isWalkIn"
                type="button"
                class="text-red-700 hover:underline"
                @click="onDelete(c)"
              >
                Delete
              </button>
            </td>
          </tr>
          <tr v-if="!loading && customers.length === 0">
            <td colspan="6" class="px-3 py-6 text-center text-[var(--muted)]">No customers found.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="listMeta.lastPage > 1" class="flex items-center gap-2 text-sm">
      <button
        type="button"
        class="rounded-md border border-[var(--line)] px-3 py-1.5 disabled:opacity-50"
        :disabled="page <= 1"
        @click="goPage(page - 1)"
      >
        Previous
      </button>
      <span class="text-[var(--muted)]">Page {{ listMeta.currentPage }} of {{ listMeta.lastPage }}</span>
      <button
        type="button"
        class="rounded-md border border-[var(--line)] px-3 py-1.5 disabled:opacity-50"
        :disabled="page >= listMeta.lastPage"
        @click="goPage(page + 1)"
      >
        Next
      </button>
    </div>

    <div v-if="selectedCustomer" class="rounded-lg border border-[var(--line)] bg-[var(--panel)] p-4">
      <h3 class="text-base font-semibold">{{ selectedCustomer.name }}</h3>
      <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
        <div>
          <dt class="text-[var(--muted)]">Account</dt>
          <dd class="font-mono">{{ selectedCustomer.accountNumber }}</dd>
        </div>
        <div>
          <dt class="text-[var(--muted)]">Credit / balance</dt>
          <dd class="font-mono">{{ selectedCustomer.creditLimit }} / {{ selectedCustomer.balance }}</dd>
        </div>
        <div>
          <dt class="text-[var(--muted)]">Email</dt>
          <dd>{{ selectedCustomer.email || '—' }}</dd>
        </div>
        <div>
          <dt class="text-[var(--muted)]">Phone</dt>
          <dd>{{ selectedCustomer.phone || '—' }}</dd>
        </div>
      </dl>

      <h4 class="mt-4 text-sm font-semibold">History</h4>
      <p v-if="historyLoading" class="mt-2 text-sm text-[var(--muted)]">Loading history…</p>
      <ul v-else-if="history" class="mt-2 grid gap-1 text-sm text-[var(--muted)] sm:grid-cols-2">
        <li>Sales: {{ history.sales.length }}</li>
        <li>Invoices: {{ history.invoices.length }}</li>
        <li>Payments: {{ history.payments.length }}</li>
        <li>Returns: {{ history.returns.length }}</li>
        <li>Installment plans: {{ history.installmentPlans.length }}</li>
      </ul>
      <p v-else class="mt-2 text-sm text-[var(--muted)]">Select a customer to view history stubs.</p>
    </div>
  </section>
</template>
