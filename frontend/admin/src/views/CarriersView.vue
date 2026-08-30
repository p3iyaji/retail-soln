<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

type Carrier = {
  id: number
  name: string
  code: string | null
  trackingUrlTemplate: string | null
  isActive: boolean
}

type ListMeta = {
  currentPage: number
  lastPage: number
  total: number
  perPage: number
}

const auth = useAuthStore()
const canView = computed(() => auth.user?.permissions?.includes('carriers.view') === true)
const canManage = computed(() => auth.user?.permissions?.includes('carriers.manage') === true)

const carriers = ref<Carrier[]>([])
const listMeta = ref<ListMeta>({ currentPage: 1, lastPage: 1, total: 0, perPage: 50 })
const page = ref(1)
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const message = ref('')
const searchQuery = ref('')
const showForm = ref(false)
const editingId = ref<number | null>(null)

const form = reactive({
  name: '',
  code: '',
  trackingUrlTemplate: '',
  isActive: true,
})

const formTitle = computed(() => (editingId.value ? 'Edit carrier' : 'New carrier'))

function formatError(e: unknown, fallback: string): string {
  if (e instanceof ApiError && e.body.errors) {
    return Object.values(e.body.errors).flat()[0] ?? e.message
  }
  return e instanceof ApiError ? e.message : fallback
}

function resetForm() {
  editingId.value = null
  form.name = ''
  form.code = ''
  form.trackingUrlTemplate = ''
  form.isActive = true
}

function fillForm(carrier: Carrier) {
  editingId.value = carrier.id
  form.name = carrier.name
  form.code = carrier.code ?? ''
  form.trackingUrlTemplate = carrier.trackingUrlTemplate ?? ''
  form.isActive = carrier.isActive
}

function openCreate() {
  resetForm()
  showForm.value = true
  message.value = ''
  error.value = ''
}

function openEdit(carrier: Carrier) {
  fillForm(carrier)
  showForm.value = true
  message.value = ''
  error.value = ''
}

function closeForm() {
  showForm.value = false
  resetForm()
}

function payload() {
  return {
    name: form.name.trim(),
    code: form.code.trim() || null,
    trackingUrlTemplate: form.trackingUrlTemplate.trim() || null,
    isActive: form.isActive,
  }
}

async function loadCarriers() {
  const q = searchQuery.value.trim()
  const qs = new URLSearchParams({
    per_page: '50',
    page: String(page.value),
  })
  if (q) qs.set('q', q)
  const res = await api.get<{ data: Carrier[]; meta: ListMeta }>(`/carriers?${qs}`)
  carriers.value = res.data
  listMeta.value = res.meta
}

async function refresh() {
  if (!canView.value) {
    loading.value = false
    error.value = 'You do not have permission to view carriers.'
    return
  }

  loading.value = true
  error.value = ''
  try {
    await loadCarriers()
  } catch (e) {
    error.value = formatError(e, 'Failed to load carriers')
  } finally {
    loading.value = false
  }
}

async function onSubmit() {
  if (!canManage.value) return

  saving.value = true
  error.value = ''
  message.value = ''
  try {
    if (editingId.value) {
      const res = await api.patch<{ data: Carrier }>(`/carriers/${editingId.value}`, payload())
      message.value = `Updated ${res.data.name}`
    } else {
      const res = await api.post<{ data: Carrier }>('/carriers', payload())
      message.value = `Created ${res.data.name}`
    }
    closeForm()
    await loadCarriers()
  } catch (e) {
    error.value = formatError(e, 'Failed to save carrier')
  } finally {
    saving.value = false
  }
}

async function removeCarrier(carrier: Carrier) {
  if (!canManage.value) return
  if (!window.confirm(`Delete carrier "${carrier.name}"?`)) return

  error.value = ''
  message.value = ''
  try {
    const res = await api.delete<{ data?: Carrier; meta?: { deactivated?: boolean } }>(
      `/carriers/${carrier.id}`,
    )
    if (res.meta?.deactivated) {
      message.value = `${carrier.name} was deactivated (used on past shipments).`
    } else {
      message.value = `Deleted ${carrier.name}`
    }
    await loadCarriers()
  } catch (e) {
    error.value = formatError(e, 'Failed to delete carrier')
  }
}

onMounted(refresh)
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-base font-semibold">Carriers</h2>
        <p class="text-sm text-[var(--muted)]">
          Shipping carriers for home-delivery fulfilment. Use tracking URL templates with
          <code class="text-xs">{tracking}</code> as a placeholder.
        </p>
      </div>
      <button
        v-if="canManage"
        type="button"
        class="rounded-md bg-[var(--brand)] px-3 py-1.5 text-sm font-medium text-white hover:opacity-90"
        @click="openCreate"
      >
        New carrier
      </button>
    </div>

    <p v-if="error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
      {{ error }}
    </p>
    <p
      v-if="message"
      class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
    >
      {{ message }}
    </p>

    <div v-if="canView" class="flex flex-wrap items-center gap-2">
      <input
        v-model="searchQuery"
        type="search"
        placeholder="Search carriers…"
        class="rounded-md border border-[var(--line)] bg-white px-3 py-1.5 text-sm"
        @keyup.enter="page = 1; refresh()"
      />
      <button
        type="button"
        class="rounded-md border border-[var(--line)] px-3 py-1.5 text-sm hover:bg-[var(--surface)]"
        @click="page = 1; refresh()"
      >
        Search
      </button>
    </div>

    <div
      v-if="showForm && canManage"
      class="rounded-lg border border-[var(--line)] bg-white p-4 shadow-sm"
    >
      <h3 class="mb-3 text-sm font-semibold">{{ formTitle }}</h3>
      <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="onSubmit">
        <label class="text-sm sm:col-span-2">
          <span class="text-[var(--muted)]">Name</span>
          <input
            v-model="form.name"
            required
            maxlength="120"
            class="mt-1 block w-full rounded-md border border-[var(--line)] px-3 py-1.5"
          />
        </label>
        <label class="text-sm">
          <span class="text-[var(--muted)]">Code</span>
          <input
            v-model="form.code"
            maxlength="50"
            class="mt-1 block w-full rounded-md border border-[var(--line)] px-3 py-1.5"
            placeholder="Optional"
          />
        </label>
        <label class="text-sm flex items-end gap-2 pb-1">
          <input v-model="form.isActive" type="checkbox" class="rounded" />
          <span>Active</span>
        </label>
        <label class="text-sm sm:col-span-2">
          <span class="text-[var(--muted)]">Tracking URL template</span>
          <input
            v-model="form.trackingUrlTemplate"
            maxlength="500"
            class="mt-1 block w-full rounded-md border border-[var(--line)] px-3 py-1.5"
            placeholder="https://carrier.example/track/{tracking}"
          />
        </label>
        <div class="flex gap-2 sm:col-span-2">
          <button
            type="submit"
            class="rounded-md bg-[var(--brand)] px-3 py-1.5 text-sm font-medium text-white hover:opacity-90 disabled:opacity-60"
            :disabled="saving"
          >
            {{ saving ? 'Saving…' : 'Save' }}
          </button>
          <button
            type="button"
            class="rounded-md border border-[var(--line)] px-3 py-1.5 text-sm"
            @click="closeForm"
          >
            Cancel
          </button>
        </div>
      </form>
    </div>

    <div v-if="loading" class="text-sm text-[var(--muted)]">Loading carriers…</div>

    <div v-else-if="canView" class="overflow-hidden rounded-lg border border-[var(--line)] bg-white">
      <table class="min-w-full text-sm">
        <thead class="border-b border-[var(--line)] bg-[var(--surface)] text-left text-[var(--muted)]">
          <tr>
            <th class="px-3 py-2 font-medium">Name</th>
            <th class="px-3 py-2 font-medium">Code</th>
            <th class="px-3 py-2 font-medium">Status</th>
            <th v-if="canManage" class="px-3 py-2 font-medium">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="carriers.length === 0">
            <td colspan="4" class="px-3 py-6 text-[var(--muted)]">No carriers yet.</td>
          </tr>
          <tr
            v-for="carrier in carriers"
            :key="carrier.id"
            class="border-t border-[var(--line)]"
          >
            <td class="px-3 py-2">{{ carrier.name }}</td>
            <td class="px-3 py-2 text-[var(--muted)]">{{ carrier.code || '—' }}</td>
            <td class="px-3 py-2">
              <span
                class="rounded-full px-2 py-0.5 text-xs"
                :class="carrier.isActive ? 'bg-emerald-50 text-emerald-800' : 'bg-gray-100 text-gray-600'"
              >
                {{ carrier.isActive ? 'Active' : 'Inactive' }}
              </span>
            </td>
            <td v-if="canManage" class="px-3 py-2">
              <div class="flex gap-2">
                <button
                  type="button"
                  class="text-[var(--brand)] hover:underline"
                  @click="openEdit(carrier)"
                >
                  Edit
                </button>
                <button
                  type="button"
                  class="text-red-700 hover:underline"
                  @click="removeCarrier(carrier)"
                >
                  Delete
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
