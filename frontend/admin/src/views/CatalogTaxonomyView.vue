<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { userHasPermission } from '@/lib/rbac'

type Tab = 'categories' | 'brands' | 'units'

type Category = {
  id: number
  name: string
  slug: string | null
  parentId: number | null
  isActive: boolean
}

type Brand = {
  id: number
  name: string
  slug: string | null
  isActive: boolean
}

type Unit = {
  id: number
  name: string
  abbreviation: string
  isActive: boolean
}

const auth = useAuthStore()
const canView = computed(
  () =>
    userHasPermission(auth.user, 'catalog.view') ||
    userHasPermission(auth.user, 'products.view'),
)
const canManage = computed(() => userHasPermission(auth.user, 'products.manage'))

const tab = ref<Tab>('categories')
const categories = ref<Category[]>([])
const brands = ref<Brand[]>([])
const units = ref<Unit[]>([])
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const message = ref('')
const searchQuery = ref('')
const showForm = ref(false)
const editingId = ref<number | null>(null)

const form = reactive({
  name: '',
  slug: '',
  parentId: '' as string | number,
  abbreviation: '',
  isActive: true,
})

const formTitle = computed(() => {
  const verb = editingId.value ? 'Edit' : 'New'
  if (tab.value === 'categories') return `${verb} category`
  if (tab.value === 'brands') return `${verb} brand`
  return `${verb} unit`
})

const parentOptions = computed(() =>
  categories.value.filter((c) => c.id !== editingId.value),
)

function formatError(e: unknown, fallback: string): string {
  if (e instanceof ApiError && e.body.errors) {
    return Object.values(e.body.errors).flat()[0] ?? e.message
  }
  return e instanceof ApiError ? e.message : fallback
}

function resetForm() {
  editingId.value = null
  form.name = ''
  form.slug = ''
  form.parentId = ''
  form.abbreviation = ''
  form.isActive = true
}

function openCreate() {
  resetForm()
  showForm.value = true
  message.value = ''
  error.value = ''
}

function openEditCategory(item: Category) {
  editingId.value = item.id
  form.name = item.name
  form.slug = item.slug ?? ''
  form.parentId = item.parentId ?? ''
  form.isActive = item.isActive
  showForm.value = true
  message.value = ''
  error.value = ''
}

function openEditBrand(item: Brand) {
  editingId.value = item.id
  form.name = item.name
  form.slug = item.slug ?? ''
  form.isActive = item.isActive
  showForm.value = true
  message.value = ''
  error.value = ''
}

function openEditUnit(item: Unit) {
  editingId.value = item.id
  form.name = item.name
  form.abbreviation = item.abbreviation
  form.isActive = item.isActive
  showForm.value = true
  message.value = ''
  error.value = ''
}

function closeForm() {
  showForm.value = false
  resetForm()
}

function categoryPayload() {
  return {
    name: form.name.trim(),
    slug: form.slug.trim() || null,
    parentId: form.parentId === '' ? null : Number(form.parentId),
    isActive: form.isActive,
  }
}

function brandPayload() {
  return {
    name: form.name.trim(),
    slug: form.slug.trim() || null,
    isActive: form.isActive,
  }
}

function unitPayload() {
  return {
    name: form.name.trim(),
    abbreviation: form.abbreviation.trim(),
    isActive: form.isActive,
  }
}

async function loadAll() {
  const q = searchQuery.value.trim()
  const qs = q ? `?q=${encodeURIComponent(q)}` : ''
  const [cats, brs, uns] = await Promise.all([
    api.get<{ data: Category[] }>(`/categories${qs}`),
    api.get<{ data: Brand[] }>(`/brands${qs}`),
    api.get<{ data: Unit[] }>(`/units${qs}`),
  ])
  categories.value = cats.data
  brands.value = brs.data
  units.value = uns.data
}

async function refresh() {
  if (!canView.value) {
    loading.value = false
    error.value = 'You do not have permission to view catalog taxonomy.'
    return
  }

  loading.value = true
  error.value = ''
  try {
    await loadAll()
  } catch (e) {
    error.value = formatError(e, 'Failed to load catalog')
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
    if (tab.value === 'categories') {
      if (editingId.value) {
        const res = await api.patch<{ data: Category }>(
          `/categories/${editingId.value}`,
          categoryPayload(),
        )
        message.value = `Updated ${res.data.name}`
      } else {
        const res = await api.post<{ data: Category }>('/categories', categoryPayload())
        message.value = `Created ${res.data.name}`
      }
    } else if (tab.value === 'brands') {
      if (editingId.value) {
        const res = await api.patch<{ data: Brand }>(`/brands/${editingId.value}`, brandPayload())
        message.value = `Updated ${res.data.name}`
      } else {
        const res = await api.post<{ data: Brand }>('/brands', brandPayload())
        message.value = `Created ${res.data.name}`
      }
    } else if (editingId.value) {
      const res = await api.patch<{ data: Unit }>(`/units/${editingId.value}`, unitPayload())
      message.value = `Updated ${res.data.name}`
    } else {
      const res = await api.post<{ data: Unit }>('/units', unitPayload())
      message.value = `Created ${res.data.name}`
    }
    closeForm()
    await loadAll()
  } catch (e) {
    error.value = formatError(e, 'Failed to save')
  } finally {
    saving.value = false
  }
}

async function removeItem(kind: Tab, id: number, name: string) {
  if (!canManage.value) return
  if (!window.confirm(`Delete ${name}?`)) return

  error.value = ''
  message.value = ''
  try {
    const path =
      kind === 'categories' ? `/categories/${id}` : kind === 'brands' ? `/brands/${id}` : `/units/${id}`
    const res = await api.delete<{ data?: unknown; meta?: { deactivated?: boolean } }>(path)
    if (res?.meta?.deactivated) {
      message.value = `${name} was deactivated (still linked to products).`
    } else {
      message.value = `Deleted ${name}`
    }
    await loadAll()
  } catch (e) {
    error.value = formatError(e, 'Failed to delete')
  }
}

function parentName(parentId: number | null): string {
  if (!parentId) return '—'
  return categories.value.find((c) => c.id === parentId)?.name ?? `#${parentId}`
}

watch(tab, () => {
  closeForm()
  message.value = ''
  error.value = ''
})

onMounted(refresh)
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-base font-semibold">Categories, brands & units</h2>
        <p class="text-sm text-[var(--muted)]">
          Manage catalog taxonomy used when creating products.
        </p>
      </div>
      <button
        v-if="canManage"
        type="button"
        class="rounded-md bg-[var(--brand)] px-3 py-1.5 text-sm font-medium text-white hover:opacity-90"
        @click="openCreate"
      >
        New {{ tab === 'categories' ? 'category' : tab === 'brands' ? 'brand' : 'unit' }}
      </button>
    </div>

    <div class="flex flex-wrap gap-2">
      <button
        v-for="t in (['categories', 'brands', 'units'] as Tab[])"
        :key="t"
        type="button"
        class="rounded-md px-3 py-1.5 text-sm capitalize"
        :class="
          tab === t
            ? 'bg-[var(--brand)] font-medium text-white'
            : 'border border-[var(--line)] hover:bg-[var(--surface)]'
        "
        @click="tab = t"
      >
        {{ t }}
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
        placeholder="Search…"
        class="rounded-md border border-[var(--line)] bg-white px-3 py-1.5 text-sm"
        @keyup.enter="refresh()"
      />
      <button
        type="button"
        class="rounded-md border border-[var(--line)] px-3 py-1.5 text-sm hover:bg-[var(--surface)]"
        @click="refresh()"
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
        <label class="text-sm" :class="tab === 'units' ? '' : 'sm:col-span-2'">
          <span class="text-[var(--muted)]">Name</span>
          <input
            v-model="form.name"
            required
            maxlength="255"
            class="mt-1 block w-full rounded-md border border-[var(--line)] px-3 py-1.5"
          />
        </label>
        <label v-if="tab === 'units'" class="text-sm">
          <span class="text-[var(--muted)]">Abbreviation</span>
          <input
            v-model="form.abbreviation"
            required
            maxlength="20"
            class="mt-1 block w-full rounded-md border border-[var(--line)] px-3 py-1.5"
          />
        </label>
        <label v-if="tab !== 'units'" class="text-sm">
          <span class="text-[var(--muted)]">Slug</span>
          <input
            v-model="form.slug"
            maxlength="255"
            class="mt-1 block w-full rounded-md border border-[var(--line)] px-3 py-1.5"
            placeholder="Optional"
          />
        </label>
        <label v-if="tab === 'categories'" class="text-sm">
          <span class="text-[var(--muted)]">Parent category</span>
          <select
            v-model="form.parentId"
            class="mt-1 block w-full rounded-md border border-[var(--line)] px-3 py-1.5"
          >
            <option value="">None</option>
            <option v-for="c in parentOptions" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </label>
        <label class="flex items-end gap-2 pb-1 text-sm">
          <input v-model="form.isActive" type="checkbox" class="rounded" />
          <span>Active</span>
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

    <div v-if="loading" class="text-sm text-[var(--muted)]">Loading…</div>

    <div v-else-if="canView" class="overflow-hidden rounded-lg border border-[var(--line)] bg-white">
      <table v-if="tab === 'categories'" class="min-w-full text-sm">
        <thead class="border-b border-[var(--line)] bg-[var(--surface)] text-left text-[var(--muted)]">
          <tr>
            <th class="px-3 py-2 font-medium">Name</th>
            <th class="px-3 py-2 font-medium">Slug</th>
            <th class="px-3 py-2 font-medium">Parent</th>
            <th class="px-3 py-2 font-medium">Status</th>
            <th v-if="canManage" class="px-3 py-2 font-medium">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="categories.length === 0">
            <td colspan="5" class="px-3 py-6 text-[var(--muted)]">No categories yet.</td>
          </tr>
          <tr v-for="item in categories" :key="item.id" class="border-t border-[var(--line)]">
            <td class="px-3 py-2">{{ item.name }}</td>
            <td class="px-3 py-2 font-mono text-xs text-[var(--muted)]">{{ item.slug || '—' }}</td>
            <td class="px-3 py-2 text-[var(--muted)]">{{ parentName(item.parentId) }}</td>
            <td class="px-3 py-2">
              <span
                class="rounded-full px-2 py-0.5 text-xs"
                :class="item.isActive ? 'bg-emerald-50 text-emerald-800' : 'bg-gray-100 text-gray-600'"
              >
                {{ item.isActive ? 'Active' : 'Inactive' }}
              </span>
            </td>
            <td v-if="canManage" class="px-3 py-2">
              <button type="button" class="mr-2 text-[var(--brand)] hover:underline" @click="openEditCategory(item)">
                Edit
              </button>
              <button
                type="button"
                class="text-red-700 hover:underline"
                @click="removeItem('categories', item.id, item.name)"
              >
                Delete
              </button>
            </td>
          </tr>
        </tbody>
      </table>

      <table v-else-if="tab === 'brands'" class="min-w-full text-sm">
        <thead class="border-b border-[var(--line)] bg-[var(--surface)] text-left text-[var(--muted)]">
          <tr>
            <th class="px-3 py-2 font-medium">Name</th>
            <th class="px-3 py-2 font-medium">Slug</th>
            <th class="px-3 py-2 font-medium">Status</th>
            <th v-if="canManage" class="px-3 py-2 font-medium">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="brands.length === 0">
            <td colspan="4" class="px-3 py-6 text-[var(--muted)]">No brands yet.</td>
          </tr>
          <tr v-for="item in brands" :key="item.id" class="border-t border-[var(--line)]">
            <td class="px-3 py-2">{{ item.name }}</td>
            <td class="px-3 py-2 font-mono text-xs text-[var(--muted)]">{{ item.slug || '—' }}</td>
            <td class="px-3 py-2">
              <span
                class="rounded-full px-2 py-0.5 text-xs"
                :class="item.isActive ? 'bg-emerald-50 text-emerald-800' : 'bg-gray-100 text-gray-600'"
              >
                {{ item.isActive ? 'Active' : 'Inactive' }}
              </span>
            </td>
            <td v-if="canManage" class="px-3 py-2">
              <button type="button" class="mr-2 text-[var(--brand)] hover:underline" @click="openEditBrand(item)">
                Edit
              </button>
              <button
                type="button"
                class="text-red-700 hover:underline"
                @click="removeItem('brands', item.id, item.name)"
              >
                Delete
              </button>
            </td>
          </tr>
        </tbody>
      </table>

      <table v-else class="min-w-full text-sm">
        <thead class="border-b border-[var(--line)] bg-[var(--surface)] text-left text-[var(--muted)]">
          <tr>
            <th class="px-3 py-2 font-medium">Name</th>
            <th class="px-3 py-2 font-medium">Abbreviation</th>
            <th class="px-3 py-2 font-medium">Status</th>
            <th v-if="canManage" class="px-3 py-2 font-medium">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="units.length === 0">
            <td colspan="4" class="px-3 py-6 text-[var(--muted)]">No units yet.</td>
          </tr>
          <tr v-for="item in units" :key="item.id" class="border-t border-[var(--line)]">
            <td class="px-3 py-2">{{ item.name }}</td>
            <td class="px-3 py-2 font-mono text-xs">{{ item.abbreviation }}</td>
            <td class="px-3 py-2">
              <span
                class="rounded-full px-2 py-0.5 text-xs"
                :class="item.isActive ? 'bg-emerald-50 text-emerald-800' : 'bg-gray-100 text-gray-600'"
              >
                {{ item.isActive ? 'Active' : 'Inactive' }}
              </span>
            </td>
            <td v-if="canManage" class="px-3 py-2">
              <button type="button" class="mr-2 text-[var(--brand)] hover:underline" @click="openEditUnit(item)">
                Edit
              </button>
              <button
                type="button"
                class="text-red-700 hover:underline"
                @click="removeItem('units', item.id, item.name)"
              >
                Delete
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
