<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

type StoreAccess = { id: number; name: string; isDefault: boolean }
type StoreOption = { id: number; name: string }
type RoleOption = { id: number; code: string; name: string }

type ManagedUser = {
  id: number
  name: string
  email: string
  role: string
  roles: string[]
  storeAccess: StoreAccess[]
}

type ListMeta = {
  currentPage: number
  lastPage: number
  total: number
}

const FALLBACK_ROLES: RoleOption[] = [
  { id: 0, code: 'org_admin', name: 'Organization Admin' },
  { id: 0, code: 'store_manager', name: 'Store Manager' },
  { id: 0, code: 'cashier', name: 'Cashier' },
  { id: 0, code: 'inventory_manager', name: 'Inventory Manager' },
  { id: 0, code: 'procurement_officer', name: 'Procurement Officer' },
  { id: 0, code: 'accountant', name: 'Accountant' },
  { id: 0, code: 'delivery_staff', name: 'Delivery Staff' },
  { id: 0, code: 'super_admin', name: 'Super Admin' },
]

const auth = useAuthStore()
const canView = computed(() => auth.user?.permissions?.includes('users.view') === true)
const canManage = computed(() => auth.user?.permissions?.includes('users.manage') === true)
const canAssignRoles = computed(() => auth.user?.permissions?.includes('users.assign-roles') === true)
const canAssignStores = computed(() => auth.user?.permissions?.includes('users.assign-stores') === true)

const users = ref<ManagedUser[]>([])
const roles = ref<RoleOption[]>([])
const stores = ref<StoreOption[]>([])
const listMeta = ref<ListMeta>({ currentPage: 1, lastPage: 1, total: 0 })
const page = ref(1)
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const message = ref('')
const showCreate = ref(false)
const selectedId = ref<number | null>(null)

const createForm = reactive({
  name: '',
  email: '',
  password: '',
  role: 'cashier',
})

const roleForm = reactive({
  role: '',
})

const storeForm = reactive({
  storeIds: [] as number[],
  defaultStoreId: null as number | null,
})

const selectedUser = computed(() => users.value.find((u) => u.id === selectedId.value) ?? null)

const roleLabel = (code: string) =>
  roles.value.find((r) => r.code === code)?.name ??
  FALLBACK_ROLES.find((r) => r.code === code)?.name ??
  code

async function loadRoles() {
  try {
    const res = await api.get<{ data: RoleOption[] }>('/roles')
    roles.value = res.data
  } catch {
    roles.value = FALLBACK_ROLES
  }
}

async function loadStores() {
  const res = await api.get<{ data: StoreOption[] }>('/stores?per_page=100')
  stores.value = res.data.map((s) => ({ id: s.id, name: s.name }))
}

async function loadUsers() {
  const qs = new URLSearchParams({
    per_page: '20',
    page: String(page.value),
  })
  const res = await api.get<{ data: ManagedUser[]; meta: ListMeta }>(`/users?${qs}`)
  users.value = res.data
  listMeta.value = res.meta
}

async function refresh() {
  if (!canView.value) {
    loading.value = false
    error.value = 'You do not have permission to view users.'
    return
  }

  loading.value = true
  error.value = ''
  message.value = ''
  try {
    await Promise.all([loadUsers(), loadStores(), loadRoles()])
    if (selectedId.value && !users.value.some((u) => u.id === selectedId.value)) {
      selectedId.value = null
    }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load users'
  } finally {
    loading.value = false
  }
}

function openCreate() {
  createForm.name = ''
  createForm.email = ''
  createForm.password = ''
  createForm.role = roles.value[0]?.code ?? 'cashier'
  showCreate.value = true
  message.value = ''
  error.value = ''
}

async function selectUser(user: ManagedUser) {
  selectedId.value = user.id
  roleForm.role = user.role
  storeForm.storeIds = user.storeAccess.map((s) => s.id)
  storeForm.defaultStoreId =
    user.storeAccess.find((s) => s.isDefault)?.id ?? user.storeAccess[0]?.id ?? null
  message.value = ''
  error.value = ''

  // Refresh detail so storeAccess is current
  try {
    const res = await api.get<{ data: ManagedUser }>(`/users/${user.id}`)
    const detail = res.data
    const idx = users.value.findIndex((u) => u.id === detail.id)
    if (idx >= 0) users.value[idx] = detail
    roleForm.role = detail.role
    storeForm.storeIds = detail.storeAccess.map((s) => s.id)
    storeForm.defaultStoreId =
      detail.storeAccess.find((s) => s.isDefault)?.id ?? detail.storeAccess[0]?.id ?? null
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load user detail'
  }
}

function toggleStore(storeId: number) {
  const idx = storeForm.storeIds.indexOf(storeId)
  if (idx >= 0) {
    storeForm.storeIds.splice(idx, 1)
    if (storeForm.defaultStoreId === storeId) {
      storeForm.defaultStoreId = storeForm.storeIds[0] ?? null
    }
  } else {
    storeForm.storeIds.push(storeId)
    if (storeForm.defaultStoreId === null) {
      storeForm.defaultStoreId = storeId
    }
  }
}

async function createUser() {
  if (!canManage.value) return
  saving.value = true
  error.value = ''
  message.value = ''
  try {
    const res = await api.post<{ data: ManagedUser }>('/users', {
      name: createForm.name.trim(),
      email: createForm.email.trim(),
      password: createForm.password,
      role: createForm.role,
    })
    showCreate.value = false
    message.value = `Created ${res.data.name}`
    await loadUsers()
    await selectUser(res.data)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to create user'
  } finally {
    saving.value = false
  }
}

async function saveRole() {
  if (!canAssignRoles.value || !selectedId.value) return
  saving.value = true
  error.value = ''
  message.value = ''
  try {
    const res = await api.patch<{ data: ManagedUser }>(`/users/${selectedId.value}/roles`, {
      role: roleForm.role,
    })
    const idx = users.value.findIndex((u) => u.id === res.data.id)
    if (idx >= 0) users.value[idx] = { ...users.value[idx], ...res.data }
    message.value = 'Role updated'
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to update role'
  } finally {
    saving.value = false
  }
}

async function saveStores() {
  if (!canAssignStores.value || !selectedId.value) return
  if (storeForm.storeIds.length === 0) {
    error.value = 'Select at least one store'
    return
  }
  saving.value = true
  error.value = ''
  message.value = ''
  try {
    const res = await api.patch<{ data: ManagedUser }>(`/users/${selectedId.value}/stores`, {
      storeIds: storeForm.storeIds,
      defaultStoreId: storeForm.defaultStoreId,
    })
    const idx = users.value.findIndex((u) => u.id === res.data.id)
    if (idx >= 0) users.value[idx] = res.data
    message.value = 'Store access updated'
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to update store access'
  } finally {
    saving.value = false
  }
}

function prevPage() {
  if (page.value > 1) {
    page.value -= 1
    void refresh()
  }
}

function nextPage() {
  if (page.value < listMeta.value.lastPage) {
    page.value += 1
    void refresh()
  }
}

onMounted(refresh)
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="text-xl font-semibold tracking-tight">Users</h2>
        <p class="mt-1 text-sm text-[var(--muted)]">
          Create users, assign roles, and manage store access.
        </p>
      </div>
      <button
        v-if="canManage"
        type="button"
        class="rounded-md bg-[var(--brand)] px-3 py-1.5 text-sm text-white hover:opacity-90"
        @click="openCreate"
      >
        New user
      </button>
    </div>

    <p v-if="loading" class="text-sm text-[var(--muted)]">Loading…</p>
    <p
      v-else-if="error"
      class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"
    >
      {{ error }}
    </p>
    <p
      v-else-if="message"
      class="rounded-md border border-teal-200 bg-teal-50 px-3 py-2 text-sm text-teal-900"
    >
      {{ message }}
    </p>

    <section
      v-if="showCreate && canManage"
      class="space-y-3 rounded-md border border-[var(--line)] bg-[var(--panel)] p-4"
    >
      <h3 class="text-sm font-semibold">New user</h3>
      <div class="grid gap-3 md:grid-cols-2">
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Name</span>
          <input
            v-model="createForm.name"
            class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2"
            autocomplete="off"
          />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Email</span>
          <input
            v-model="createForm.email"
            type="email"
            class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2"
            autocomplete="off"
          />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Password</span>
          <input
            v-model="createForm.password"
            type="password"
            class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2"
            autocomplete="new-password"
          />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Role</span>
          <select
            v-model="createForm.role"
            class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2"
          >
            <option v-for="r in roles" :key="r.code" :value="r.code">{{ r.name }} ({{ r.code }})</option>
          </select>
        </label>
      </div>
      <div class="flex gap-2">
        <button
          type="button"
          class="rounded-md bg-[var(--brand)] px-3 py-1.5 text-sm text-white disabled:opacity-60"
          :disabled="saving || !createForm.name.trim() || !createForm.email.trim() || createForm.password.length < 8"
          @click="createUser"
        >
          Create
        </button>
        <button
          type="button"
          class="rounded-md border border-[var(--line)] px-3 py-1.5 text-sm hover:bg-stone-50"
          @click="showCreate = false"
        >
          Cancel
        </button>
      </div>
    </section>

    <div v-if="canView && !loading" class="grid gap-6 lg:grid-cols-[1fr_340px]">
      <section class="space-y-3">
        <div class="overflow-x-auto rounded-md border border-[var(--line)] bg-[var(--panel)]">
          <table class="min-w-full text-left text-sm">
            <thead class="sticky top-0 border-b border-[var(--line)] bg-stone-50 text-xs text-[var(--muted)] uppercase">
              <tr>
                <th class="px-3 py-2 font-medium">Name</th>
                <th class="px-3 py-2 font-medium">Email</th>
                <th class="px-3 py-2 font-medium">Role</th>
                <th class="px-3 py-2 font-medium">Stores</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="user in users"
                :key="user.id"
                class="cursor-pointer border-b border-[var(--line)] last:border-0 hover:bg-stone-50"
                :class="selectedId === user.id ? 'bg-teal-50/60' : ''"
                @click="selectUser(user)"
              >
                <td class="px-3 py-2 font-medium">{{ user.name }}</td>
                <td class="px-3 py-2 text-[var(--muted)]">{{ user.email }}</td>
                <td class="px-3 py-2">{{ roleLabel(user.role) }}</td>
                <td class="px-3 py-2 text-[var(--muted)]">
                  {{
                    user.storeAccess.length
                      ? user.storeAccess.map((s) => s.name).join(', ')
                      : '—'
                  }}
                </td>
              </tr>
              <tr v-if="users.length === 0">
                <td colspan="4" class="px-3 py-4 text-[var(--muted)]">No users found.</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="flex items-center justify-between text-sm text-[var(--muted)]">
          <span>Page {{ listMeta.currentPage }} of {{ listMeta.lastPage }} · {{ listMeta.total }} users</span>
          <div class="flex gap-2">
            <button
              type="button"
              class="rounded-md border border-[var(--line)] px-2 py-1 hover:bg-stone-50 disabled:opacity-40"
              :disabled="page <= 1"
              @click="prevPage"
            >
              Prev
            </button>
            <button
              type="button"
              class="rounded-md border border-[var(--line)] px-2 py-1 hover:bg-stone-50 disabled:opacity-40"
              :disabled="page >= listMeta.lastPage"
              @click="nextPage"
            >
              Next
            </button>
          </div>
        </div>
      </section>

      <aside
        v-if="selectedUser"
        class="space-y-4 rounded-md border border-[var(--line)] bg-[var(--panel)] p-4"
      >
        <div>
          <h3 class="font-semibold">{{ selectedUser.name }}</h3>
          <p class="text-sm text-[var(--muted)]">{{ selectedUser.email }}</p>
        </div>

        <div v-if="canAssignRoles" class="space-y-2">
          <h4 class="text-sm font-medium">Role</h4>
          <select
            v-model="roleForm.role"
            class="w-full rounded-md border border-[var(--line)] px-3 py-2 text-sm"
          >
            <option v-for="r in roles" :key="r.code" :value="r.code">{{ r.name }} ({{ r.code }})</option>
          </select>
          <button
            type="button"
            class="rounded-md border border-[var(--line)] px-3 py-1.5 text-sm hover:bg-stone-50 disabled:opacity-60"
            :disabled="saving || roleForm.role === selectedUser.role"
            @click="saveRole"
          >
            Save role
          </button>
        </div>
        <p v-else class="text-sm text-[var(--muted)]">
          Role: <span class="text-[var(--ink)]">{{ roleLabel(selectedUser.role) }}</span>
        </p>

        <div v-if="canAssignStores" class="space-y-2">
          <h4 class="text-sm font-medium">Store access</h4>
          <ul class="max-h-56 space-y-2 overflow-y-auto text-sm">
            <li v-for="store in stores" :key="store.id" class="flex items-center gap-2">
              <input
                :id="`store-${store.id}`"
                type="checkbox"
                :checked="storeForm.storeIds.includes(store.id)"
                @change="toggleStore(store.id)"
              />
              <label :for="`store-${store.id}`" class="flex-1">{{ store.name }}</label>
              <label
                v-if="storeForm.storeIds.includes(store.id)"
                class="flex items-center gap-1 text-xs text-[var(--muted)]"
              >
                <input
                  v-model="storeForm.defaultStoreId"
                  type="radio"
                  :value="store.id"
                />
                Default
              </label>
            </li>
            <li v-if="stores.length === 0" class="text-[var(--muted)]">No stores available.</li>
          </ul>
          <button
            type="button"
            class="rounded-md bg-[var(--brand)] px-3 py-1.5 text-sm text-white disabled:opacity-60"
            :disabled="saving || storeForm.storeIds.length === 0"
            @click="saveStores"
          >
            Save stores
          </button>
        </div>
        <div v-else class="text-sm text-[var(--muted)]">
          <p class="font-medium text-[var(--ink)]">Stores</p>
          <p>
            {{
              selectedUser.storeAccess.length
                ? selectedUser.storeAccess
                    .map((s) => (s.isDefault ? `${s.name} (default)` : s.name))
                    .join(', ')
                : 'None assigned'
            }}
          </p>
        </div>
      </aside>
      <aside
        v-else
        class="rounded-md border border-dashed border-[var(--line)] p-4 text-sm text-[var(--muted)]"
      >
        Select a user to manage role and store access.
      </aside>
    </div>
  </div>
</template>
