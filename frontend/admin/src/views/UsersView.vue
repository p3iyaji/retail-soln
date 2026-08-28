<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

type StoreAccess = { id: number; name: string; isDefault: boolean }

type UserRow = {
  id: number
  name: string
  email: string
  role: string
  permissions: string[]
  storeAccess: StoreAccess[]
}

type RoleOption = {
  id: number
  code: string
  name: string
}

type StoreOption = {
  id: number
  name: string
}

type ListMeta = {
  currentPage: number
  lastPage: number
  total: number
}

const auth = useAuthStore()

const canView = computed(() => auth.user?.permissions?.includes('users.view') === true)
const canManage = computed(() => auth.user?.permissions?.includes('users.manage') === true)
const canAssignRoles = computed(() => auth.user?.permissions?.includes('users.assign-roles') === true)
const canAssignStores = computed(() => auth.user?.permissions?.includes('users.assign-stores') === true)
const canEdit = computed(() => canManage.value || canAssignRoles.value || canAssignStores.value)

const users = ref<UserRow[]>([])
const roles = ref<RoleOption[]>([])
const stores = ref<StoreOption[]>([])
const listMeta = ref<ListMeta>({ currentPage: 1, lastPage: 1, total: 0 })
const page = ref(1)
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const message = ref('')
const showCreate = ref(false)
const editingId = ref<number | null>(null)
const selectedId = ref<number | null>(null)

const createForm = reactive({
  name: '',
  email: '',
  password: '',
  role: 'cashier',
})

const editForm = reactive({
  name: '',
  email: '',
  password: '',
  role: '',
  storeIds: [] as number[],
  defaultStoreId: null as number | null,
})

const formTitle = computed(() => (editingId.value ? 'Edit user' : 'New user'))
const selectedUser = computed(() => users.value.find((u) => u.id === selectedId.value) ?? null)

function formatError(e: unknown, fallback: string): string {
  if (e instanceof ApiError && e.body.errors) {
    return Object.values(e.body.errors).flat()[0] ?? e.message
  }
  return e instanceof ApiError ? e.message : fallback
}

function roleLabel(code: string): string {
  return roles.value.find((r) => r.code === code)?.name ?? code.replace(/_/g, ' ')
}

function resetCreateForm() {
  createForm.name = ''
  createForm.email = ''
  createForm.password = ''
  createForm.role = roles.value[0]?.code ?? 'cashier'
}

function resetEditForm() {
  editingId.value = null
  editForm.name = ''
  editForm.email = ''
  editForm.password = ''
  editForm.role = ''
  editForm.storeIds = []
  editForm.defaultStoreId = null
}

function openCreate() {
  resetCreateForm()
  showCreate.value = true
  editingId.value = null
  message.value = ''
  error.value = ''
}

async function openEdit(user: UserRow) {
  selectedId.value = user.id
  editingId.value = user.id
  showCreate.value = false
  message.value = ''
  error.value = ''

  try {
    const res = await api.get<{ data: UserRow }>(`/users/${user.id}`)
    const detail = res.data
    editForm.name = detail.name
    editForm.email = detail.email
    editForm.password = ''
    editForm.role = detail.role
    editForm.storeIds = detail.storeAccess.map((s) => s.id)
    editForm.defaultStoreId =
      detail.storeAccess.find((s) => s.isDefault)?.id ?? detail.storeAccess[0]?.id ?? null
  } catch (e) {
    error.value = formatError(e, 'Failed to load user')
    editForm.name = user.name
    editForm.email = user.email
    editForm.password = ''
    editForm.role = user.role
    editForm.storeIds = user.storeAccess.map((s) => s.id)
    editForm.defaultStoreId =
      user.storeAccess.find((s) => s.isDefault)?.id ?? user.storeAccess[0]?.id ?? null
  }
}

function closeForms() {
  showCreate.value = false
  resetEditForm()
}

function toggleStore(storeId: number, checked: boolean) {
  if (checked) {
    if (!editForm.storeIds.includes(storeId)) {
      editForm.storeIds.push(storeId)
    }
    if (editForm.defaultStoreId == null) {
      editForm.defaultStoreId = storeId
    }
  } else {
    editForm.storeIds = editForm.storeIds.filter((id) => id !== storeId)
    if (editForm.defaultStoreId === storeId) {
      editForm.defaultStoreId = editForm.storeIds[0] ?? null
    }
  }
}

async function loadUsers() {
  const qs = new URLSearchParams({ per_page: '20', page: String(page.value) })
  const res = await api.get<{ data: UserRow[]; meta: ListMeta }>(`/users?${qs}`)
  users.value = res.data
  listMeta.value = res.meta
}

async function loadRoles() {
  if (!canManage.value && !canAssignRoles.value) return
  try {
    const res = await api.get<{ data: RoleOption[] }>('/roles')
    roles.value = res.data
  } catch {
    roles.value = []
  }
}

async function loadStores() {
  if (!canAssignStores.value) return
  try {
    const res = await api.get<{ data: StoreOption[] }>('/stores?per_page=100')
    stores.value = res.data
  } catch {
    stores.value = []
  }
}

async function refresh() {
  if (!canView.value) {
    loading.value = false
    error.value = 'You do not have permission to view users.'
    return
  }

  loading.value = true
  error.value = ''
  try {
    await Promise.all([loadUsers(), loadRoles(), loadStores()])
  } catch (e) {
    error.value = formatError(e, 'Failed to load users')
  } finally {
    loading.value = false
  }
}

async function onCreate() {
  if (!canManage.value) return

  saving.value = true
  error.value = ''
  message.value = ''
  try {
    const res = await api.post<{ data: UserRow }>('/users', {
      name: createForm.name.trim(),
      email: createForm.email.trim(),
      password: createForm.password,
      role: createForm.role,
    })
    users.value = [res.data, ...users.value]
    listMeta.value.total += 1
    message.value = 'User created'
    showCreate.value = false
    resetCreateForm()
    await loadUsers()
  } catch (e) {
    error.value = formatError(e, 'Create failed')
  } finally {
    saving.value = false
  }
}

async function onSaveEdit() {
  if (!editingId.value || !canEdit.value) return

  saving.value = true
  error.value = ''
  message.value = ''
  try {
    let updated: UserRow | null = null

    if (canManage.value) {
      const body: Record<string, string> = {
        name: editForm.name.trim(),
        email: editForm.email.trim(),
      }
      if (editForm.password.trim() !== '') {
        body.password = editForm.password
      }
      const res = await api.patch<{ data: UserRow }>(`/users/${editingId.value}`, body)
      updated = res.data
    }

    if (canAssignRoles.value) {
      const res = await api.patch<{ data: UserRow }>(`/users/${editingId.value}/roles`, {
        role: editForm.role,
      })
      updated = res.data
    }

    if (canAssignStores.value && editForm.storeIds.length > 0) {
      const res = await api.patch<{ data: UserRow }>(`/users/${editingId.value}/stores`, {
        storeIds: editForm.storeIds,
        defaultStoreId: editForm.defaultStoreId ?? editForm.storeIds[0],
      })
      updated = res.data
    }

    if (updated) {
      const idx = users.value.findIndex((u) => u.id === updated!.id)
      if (idx >= 0) users.value[idx] = updated
      selectedId.value = updated.id
    }

    message.value = 'User updated'
    closeForms()
    await loadUsers()
  } catch (e) {
    error.value = formatError(e, 'Save failed')
  } finally {
    saving.value = false
  }
}

async function goPage(next: number) {
  if (next < 1 || next > listMeta.value.lastPage) return
  page.value = next
  try {
    await loadUsers()
  } catch (e) {
    error.value = formatError(e, 'Failed to load page')
  }
}

function selectUser(user: UserRow) {
  selectedId.value = user.id
}

onMounted(refresh)
</script>

<template>
  <section class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="text-xl font-semibold tracking-tight">Users</h2>
        <p class="mt-1 text-sm text-[var(--muted)]">
          Create staff accounts, assign roles, and map store access.
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
      v-if="showCreate && canManage"
      class="space-y-4 rounded-md border border-[var(--line)] bg-[var(--panel)] p-4"
    >
      <div class="flex items-center justify-between gap-3">
        <h3 class="text-sm font-semibold">{{ formTitle }}</h3>
        <button type="button" class="text-sm text-[var(--muted)] hover:text-[var(--ink)]" @click="closeForms">
          Cancel
        </button>
      </div>
      <form class="grid gap-3 md:grid-cols-2" @submit.prevent="onCreate">
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Name</span>
          <input
            v-model="createForm.name"
            required
            maxlength="255"
            class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2"
          />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Email</span>
          <input
            v-model="createForm.email"
            type="email"
            required
            maxlength="255"
            class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2"
          />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Password</span>
          <input
            v-model="createForm.password"
            type="password"
            required
            minlength="8"
            autocomplete="new-password"
            class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2"
          />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Role</span>
          <select
            v-model="createForm.role"
            required
            class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2"
          >
            <option v-for="role in roles" :key="role.code" :value="role.code">
              {{ role.name }}
            </option>
          </select>
        </label>
        <div class="md:col-span-2">
          <button
            type="submit"
            class="rounded-md bg-[var(--brand)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-60"
            :disabled="saving"
          >
            {{ saving ? 'Creating…' : 'Create user' }}
          </button>
        </div>
      </form>
    </div>

    <div
      v-if="editingId && canEdit"
      class="space-y-4 rounded-md border border-[var(--line)] bg-[var(--panel)] p-4"
    >
      <div class="flex items-center justify-between gap-3">
        <h3 class="text-sm font-semibold">Edit user</h3>
        <button type="button" class="text-sm text-[var(--muted)] hover:text-[var(--ink)]" @click="closeForms">
          Cancel
        </button>
      </div>
      <form class="space-y-4" @submit.prevent="onSaveEdit">
        <div v-if="canManage" class="grid gap-3 md:grid-cols-2">
          <label class="block text-sm">
            <span class="text-[var(--muted)]">Name</span>
            <input
              v-model="editForm.name"
              required
              maxlength="255"
              class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2"
            />
          </label>
          <label class="block text-sm">
            <span class="text-[var(--muted)]">Email</span>
            <input
              v-model="editForm.email"
              type="email"
              required
              maxlength="255"
              class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2"
            />
          </label>
          <label class="block text-sm md:col-span-2">
            <span class="text-[var(--muted)]">New password</span>
            <input
              v-model="editForm.password"
              type="password"
              minlength="8"
              autocomplete="new-password"
              placeholder="Leave blank to keep current password"
              class="mt-1 w-full max-w-md rounded-md border border-[var(--line)] px-3 py-2"
            />
          </label>
        </div>

        <label v-if="canAssignRoles" class="block max-w-md text-sm">
          <span class="text-[var(--muted)]">Role</span>
          <select
            v-model="editForm.role"
            required
            class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2"
          >
            <option v-for="role in roles" :key="role.code" :value="role.code">
              {{ role.name }}
            </option>
          </select>
        </label>

        <div v-if="canAssignStores">
          <p class="text-sm text-[var(--muted)]">Store access</p>
          <ul class="mt-2 space-y-2">
            <li v-for="store in stores" :key="store.id" class="flex flex-wrap items-center gap-3 text-sm">
              <label class="flex items-center gap-2">
                <input
                  type="checkbox"
                  :checked="editForm.storeIds.includes(store.id)"
                  class="rounded border-[var(--line)]"
                  @change="toggleStore(store.id, ($event.target as HTMLInputElement).checked)"
                />
                {{ store.name }}
              </label>
              <label
                v-if="editForm.storeIds.includes(store.id)"
                class="flex items-center gap-1 text-[var(--muted)]"
              >
                <input
                  v-model="editForm.defaultStoreId"
                  type="radio"
                  :value="store.id"
                  name="defaultStore"
                />
                Default
              </label>
            </li>
          </ul>
          <p v-if="stores.length === 0" class="mt-2 text-sm text-[var(--muted)]">No stores available.</p>
        </div>

        <button
          type="submit"
          class="rounded-md bg-[var(--brand)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-60"
          :disabled="saving"
        >
          {{ saving ? 'Saving…' : 'Save changes' }}
        </button>
      </form>
    </div>

    <div v-if="!loading && canView" class="overflow-x-auto rounded-md border border-[var(--line)]">
      <table class="min-w-full text-left text-sm">
        <thead class="border-b border-[var(--line)] bg-stone-50 text-[var(--muted)]">
          <tr>
            <th class="px-3 py-2 font-medium">Name</th>
            <th class="px-3 py-2 font-medium">Email</th>
            <th class="px-3 py-2 font-medium">Role</th>
            <th class="px-3 py-2 font-medium">Stores</th>
            <th class="px-3 py-2 font-medium"></th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="user in users"
            :key="user.id"
            class="cursor-pointer border-b border-[var(--line)] last:border-b-0 hover:bg-stone-50"
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
            <td class="px-3 py-2 text-right" @click.stop>
              <button
                v-if="canEdit"
                type="button"
                class="text-[var(--brand-ink)] hover:underline"
                @click="openEdit(user)"
              >
                Edit
              </button>
            </td>
          </tr>
          <tr v-if="users.length === 0">
            <td colspan="5" class="px-3 py-6 text-center text-[var(--muted)]">No users yet.</td>
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
      v-if="selectedUser && !editingId"
      class="rounded-md border border-[var(--line)] bg-[var(--panel)] p-4 text-sm"
    >
      <h3 class="font-semibold">{{ selectedUser.name }}</h3>
      <dl class="mt-3 grid gap-2 sm:grid-cols-2">
        <div>
          <dt class="text-[var(--muted)]">Email</dt>
          <dd>{{ selectedUser.email }}</dd>
        </div>
        <div>
          <dt class="text-[var(--muted)]">Role</dt>
          <dd>{{ roleLabel(selectedUser.role) }}</dd>
        </div>
        <div class="sm:col-span-2">
          <dt class="text-[var(--muted)]">Store access</dt>
          <dd>
            <template v-if="selectedUser.storeAccess.length">
              <span
                v-for="store in selectedUser.storeAccess"
                :key="store.id"
                class="mr-2 inline-block"
              >
                {{ store.name }}{{ store.isDefault ? ' (default)' : '' }}
              </span>
            </template>
            <template v-else>—</template>
          </dd>
        </div>
      </dl>
    </aside>
  </section>
</template>
