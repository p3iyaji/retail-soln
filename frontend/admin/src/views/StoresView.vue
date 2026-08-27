<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

type TaxConfig = {
  rate?: string
  inclusive?: boolean
  name?: string
} | null

type Store = {
  id: number
  organizationId: number
  name: string
  code: string | null
  addressLine1: string | null
  addressLine2: string | null
  city: string | null
  state: string | null
  postalCode: string | null
  country: string | null
  currency: string
  taxConfig: TaxConfig
  isActive: boolean
  createdAt: string | null
  updatedAt: string | null
}

type ListMeta = {
  currentPage: number
  lastPage: number
  total: number
}

const CURRENCIES = ['USD', 'NGN', 'GBP', 'EUR', 'CAD', 'KES', 'GHS']

const auth = useAuthStore()
const canView = computed(() => auth.user?.permissions?.includes('stores.view') === true)
const canCreate = computed(() => auth.user?.permissions?.includes('stores.create') === true)
const canUpdate = computed(() => auth.user?.permissions?.includes('stores.update') === true)
const canDelete = computed(() => canCreate.value)
const canEditForm = computed(() => (editingId.value ? canUpdate.value : canCreate.value))

const stores = ref<Store[]>([])
const listMeta = ref<ListMeta>({ currentPage: 1, lastPage: 1, total: 0 })
const page = ref(1)
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const message = ref('')
const showForm = ref(false)
const editingId = ref<number | null>(null)
const selectedId = ref<number | null>(null)

const form = reactive({
  name: '',
  code: '',
  addressLine1: '',
  addressLine2: '',
  city: '',
  state: '',
  postalCode: '',
  country: '',
  currency: 'USD',
  taxName: '',
  taxRate: '0.0000',
  taxInclusive: false,
  isActive: true,
})

const formTitle = computed(() => (editingId.value ? 'Edit store' : 'New store'))
const selectedStore = computed(() => stores.value.find((s) => s.id === selectedId.value) ?? null)

function formatError(e: unknown, fallback: string): string {
  if (e instanceof ApiError && e.body.errors) {
    return Object.values(e.body.errors).flat()[0] ?? e.message
  }
  return e instanceof ApiError ? e.message : fallback
}

function resetForm() {
  form.name = ''
  form.code = ''
  form.addressLine1 = ''
  form.addressLine2 = ''
  form.city = ''
  form.state = ''
  form.postalCode = ''
  form.country = ''
  form.currency = 'USD'
  form.taxName = ''
  form.taxRate = '0.0000'
  form.taxInclusive = false
  form.isActive = true
  editingId.value = null
}

function fillForm(store: Store) {
  editingId.value = store.id
  form.name = store.name
  form.code = store.code ?? ''
  form.addressLine1 = store.addressLine1 ?? ''
  form.addressLine2 = store.addressLine2 ?? ''
  form.city = store.city ?? ''
  form.state = store.state ?? ''
  form.postalCode = store.postalCode ?? ''
  form.country = store.country ?? ''
  form.currency = store.currency
  form.taxName = store.taxConfig?.name ?? ''
  form.taxRate = store.taxConfig?.rate ?? '0.0000'
  form.taxInclusive = store.taxConfig?.inclusive === true
  form.isActive = store.isActive
}

function openCreate() {
  resetForm()
  showForm.value = true
  message.value = ''
  error.value = ''
}

function openEdit(store: Store) {
  fillForm(store)
  selectedId.value = store.id
  showForm.value = true
  message.value = ''
  error.value = ''
}

function closeForm() {
  showForm.value = false
  resetForm()
}

function payload() {
  const taxRate = form.taxRate.trim()
  const taxName = form.taxName.trim()
  const taxConfig =
    taxRate !== '' || taxName !== ''
      ? {
          rate: taxRate || '0.0000',
          inclusive: form.taxInclusive,
          name: taxName || undefined,
        }
      : null

  return {
    name: form.name.trim(),
    code: form.code.trim() || null,
    addressLine1: form.addressLine1.trim() || null,
    addressLine2: form.addressLine2.trim() || null,
    city: form.city.trim() || null,
    state: form.state.trim() || null,
    postalCode: form.postalCode.trim() || null,
    country: form.country.trim().toUpperCase() || null,
    currency: form.currency.trim().toUpperCase(),
    taxConfig,
    isActive: form.isActive,
  }
}

async function loadStores() {
  const qs = new URLSearchParams({
    per_page: '20',
    page: String(page.value),
  })
  const res = await api.get<{ data: Store[]; meta: ListMeta }>(`/stores?${qs}`)
  stores.value = res.data
  listMeta.value = res.meta
}

async function refresh() {
  if (!canView.value) {
    loading.value = false
    error.value = 'You do not have permission to view stores.'
    return
  }

  loading.value = true
  error.value = ''
  try {
    await loadStores()
    if (selectedId.value && !stores.value.some((s) => s.id === selectedId.value)) {
      selectedId.value = null
    }
  } catch (e) {
    error.value = formatError(e, 'Failed to load stores')
  } finally {
    loading.value = false
  }
}

async function onSubmit() {
  if (!canEditForm.value) return

  saving.value = true
  error.value = ''
  message.value = ''
  try {
    const body = payload()
    if (editingId.value) {
      const res = await api.patch<{ data: Store }>(`/stores/${editingId.value}`, body)
      const idx = stores.value.findIndex((s) => s.id === res.data.id)
      if (idx >= 0) stores.value[idx] = res.data
      selectedId.value = res.data.id
      message.value = 'Store updated'
    } else {
      const res = await api.post<{ data: Store }>('/stores', body)
      stores.value = [res.data, ...stores.value]
      listMeta.value.total += 1
      selectedId.value = res.data.id
      message.value = 'Store created'
    }
    showForm.value = false
    resetForm()
    await loadStores()
  } catch (e) {
    error.value = formatError(e, 'Save failed')
  } finally {
    saving.value = false
  }
}

async function onDelete(store: Store) {
  if (!canDelete.value) return
  if (!confirm(`Delete “${store.name}”?`)) return

  error.value = ''
  message.value = ''
  try {
    await api.delete(`/stores/${store.id}`)
    message.value = `“${store.name}” deleted`
    if (selectedId.value === store.id) selectedId.value = null
    if (editingId.value === store.id) closeForm()
    await loadStores()
  } catch (e) {
    error.value = formatError(e, 'Delete failed')
  }
}

async function goPage(next: number) {
  if (next < 1 || next > listMeta.value.lastPage) return
  page.value = next
  try {
    await loadStores()
  } catch (e) {
    error.value = formatError(e, 'Failed to load page')
  }
}

function selectStore(store: Store) {
  selectedId.value = store.id
}

onMounted(refresh)
</script>

<template>
  <section class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="text-xl font-semibold tracking-tight">Stores</h2>
        <p class="mt-1 text-sm text-[var(--muted)]">
          Manage store locations, currency, and tax configuration.
        </p>
      </div>
      <button
        v-if="canCreate"
        type="button"
        class="rounded-md bg-[var(--brand)] px-3 py-1.5 text-sm text-white hover:opacity-90"
        @click="openCreate"
      >
        New store
      </button>
    </div>

    <p v-if="loading" class="text-sm text-[var(--muted)]">Loading…</p>
    <p
      v-if="error"
      class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"
    >
      {{ error }}
    </p>
    <p
      v-if="message"
      class="rounded-md border border-teal-200 bg-teal-50 px-3 py-2 text-sm text-teal-900"
    >
      {{ message }}
    </p>

    <div
      v-if="showForm && canEditForm"
      class="space-y-4 rounded-md border border-[var(--line)] bg-[var(--panel)] p-4"
    >
      <div class="flex items-center justify-between gap-3">
        <h3 class="text-sm font-semibold">{{ formTitle }}</h3>
        <button type="button" class="text-sm text-[var(--muted)] hover:text-[var(--ink)]" @click="closeForm">
          Cancel
        </button>
      </div>
      <form class="grid gap-3 md:grid-cols-2" @submit.prevent="onSubmit">
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Name</span>
          <input
            v-model="form.name"
            required
            maxlength="255"
            class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2"
          />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Code</span>
          <input
            v-model="form.code"
            maxlength="50"
            class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2"
          />
        </label>
        <label class="block text-sm md:col-span-2">
          <span class="text-[var(--muted)]">Address line 1</span>
          <input
            v-model="form.addressLine1"
            maxlength="255"
            class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2"
          />
        </label>
        <label class="block text-sm md:col-span-2">
          <span class="text-[var(--muted)]">Address line 2</span>
          <input
            v-model="form.addressLine2"
            maxlength="255"
            class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2"
          />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">City</span>
          <input v-model="form.city" maxlength="100" class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">State / region</span>
          <input v-model="form.state" maxlength="100" class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Postal code</span>
          <input
            v-model="form.postalCode"
            maxlength="30"
            class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2"
          />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Country (ISO-2)</span>
          <input
            v-model="form.country"
            maxlength="2"
            class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2 uppercase"
            placeholder="NG"
          />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Currency</span>
          <input
            v-model="form.currency"
            list="store-currencies"
            required
            maxlength="3"
            class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2 uppercase"
          />
          <datalist id="store-currencies">
            <option v-for="code in CURRENCIES" :key="code" :value="code" />
          </datalist>
        </label>
        <label class="flex items-center gap-2 self-end pb-2 text-sm">
          <input v-model="form.isActive" type="checkbox" class="rounded border-[var(--line)]" />
          Active
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Tax name</span>
          <input
            v-model="form.taxName"
            maxlength="100"
            class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2"
            placeholder="VAT"
          />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Tax rate</span>
          <input
            v-model="form.taxRate"
            class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2"
            placeholder="0.0750"
          />
        </label>
        <label class="flex items-center gap-2 text-sm md:col-span-2">
          <input v-model="form.taxInclusive" type="checkbox" class="rounded border-[var(--line)]" />
          Tax inclusive pricing
        </label>
        <div class="md:col-span-2">
          <button
            type="submit"
            class="rounded-md bg-[var(--brand)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-60"
            :disabled="saving"
          >
            {{ saving ? 'Saving…' : 'Save' }}
          </button>
        </div>
      </form>
    </div>

    <div v-if="!loading && canView" class="overflow-x-auto rounded-md border border-[var(--line)]">
      <table class="min-w-full text-left text-sm">
        <thead class="border-b border-[var(--line)] bg-stone-50 text-[var(--muted)]">
          <tr>
            <th class="px-3 py-2 font-medium">Name</th>
            <th class="px-3 py-2 font-medium">Code</th>
            <th class="px-3 py-2 font-medium">City</th>
            <th class="px-3 py-2 font-medium">Currency</th>
            <th class="px-3 py-2 font-medium">Status</th>
            <th class="px-3 py-2 font-medium"></th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="store in stores"
            :key="store.id"
            class="cursor-pointer border-b border-[var(--line)] last:border-b-0 hover:bg-stone-50"
            :class="selectedId === store.id ? 'bg-teal-50/60' : ''"
            @click="selectStore(store)"
          >
            <td class="px-3 py-2 font-medium">{{ store.name }}</td>
            <td class="px-3 py-2 text-[var(--muted)]">{{ store.code || '—' }}</td>
            <td class="px-3 py-2 text-[var(--muted)]">{{ store.city || '—' }}</td>
            <td class="px-3 py-2">{{ store.currency }}</td>
            <td class="px-3 py-2">
              <span :class="store.isActive ? 'text-teal-800' : 'text-[var(--muted)]'">
                {{ store.isActive ? 'Active' : 'Inactive' }}
              </span>
            </td>
            <td class="px-3 py-2 text-right" @click.stop>
              <button
                v-if="canUpdate"
                type="button"
                class="mr-2 text-[var(--brand-ink)] hover:underline"
                @click="openEdit(store)"
              >
                Edit
              </button>
              <button
                v-if="canDelete"
                type="button"
                class="text-red-700 hover:underline"
                @click="onDelete(store)"
              >
                Delete
              </button>
            </td>
          </tr>
          <tr v-if="stores.length === 0">
            <td colspan="6" class="px-3 py-6 text-center text-[var(--muted)]">No stores yet.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div
      v-if="canView && listMeta.lastPage > 1"
      class="flex items-center justify-between gap-3 text-sm"
    >
      <p class="text-[var(--muted)]">
        Page {{ listMeta.currentPage }} of {{ listMeta.lastPage }} ({{ listMeta.total }} total)
      </p>
      <div class="flex gap-2">
        <button
          type="button"
          class="rounded-md border border-[var(--line)] px-3 py-1.5 disabled:opacity-50"
          :disabled="page <= 1"
          @click="goPage(page - 1)"
        >
          Previous
        </button>
        <button
          type="button"
          class="rounded-md border border-[var(--line)] px-3 py-1.5 disabled:opacity-50"
          :disabled="page >= listMeta.lastPage"
          @click="goPage(page + 1)"
        >
          Next
        </button>
      </div>
    </div>

    <aside
      v-if="selectedStore"
      class="rounded-md border border-[var(--line)] bg-[var(--panel)] p-4 text-sm"
    >
      <h3 class="font-semibold">{{ selectedStore.name }}</h3>
      <dl class="mt-3 grid gap-2 sm:grid-cols-2">
        <div>
          <dt class="text-[var(--muted)]">Code</dt>
          <dd>{{ selectedStore.code || '—' }}</dd>
        </div>
        <div>
          <dt class="text-[var(--muted)]">Currency</dt>
          <dd>{{ selectedStore.currency }}</dd>
        </div>
        <div class="sm:col-span-2">
          <dt class="text-[var(--muted)]">Address</dt>
          <dd>
            <template v-if="selectedStore.addressLine1 || selectedStore.city">
              <span v-if="selectedStore.addressLine1">{{ selectedStore.addressLine1 }}</span>
              <span v-if="selectedStore.addressLine2"><br />{{ selectedStore.addressLine2 }}</span>
              <br />
              {{
                [selectedStore.city, selectedStore.state, selectedStore.postalCode]
                  .filter(Boolean)
                  .join(', ')
              }}
              <span v-if="selectedStore.country"> {{ selectedStore.country }}</span>
            </template>
            <template v-else>—</template>
          </dd>
        </div>
        <div>
          <dt class="text-[var(--muted)]">Tax</dt>
          <dd>
            <template v-if="selectedStore.taxConfig">
              {{ selectedStore.taxConfig.name || 'Tax' }}
              {{ selectedStore.taxConfig.rate ?? '—' }}
              ({{ selectedStore.taxConfig.inclusive ? 'inclusive' : 'exclusive' }})
            </template>
            <template v-else>—</template>
          </dd>
        </div>
        <div>
          <dt class="text-[var(--muted)]">Status</dt>
          <dd>{{ selectedStore.isActive ? 'Active' : 'Inactive' }}</dd>
        </div>
      </dl>
    </aside>
  </section>
</template>
