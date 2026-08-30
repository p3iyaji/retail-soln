<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { userHasPermission } from '@/lib/rbac'

type RoleRow = {
  id: number
  code: string
  name: string
  permissions: string[]
}

type PermissionRow = {
  id: number
  name: string
  scope: string
}

const auth = useAuthStore()
const canView = computed(() => userHasPermission(auth.user, 'roles.view'))
const canManage = computed(() => userHasPermission(auth.user, 'roles.manage'))

const roles = ref<RoleRow[]>([])
const allPermissions = ref<PermissionRow[]>([])
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const message = ref('')
const filter = ref('')
const editingId = ref<number | null>(null)
const editName = ref('')
const editPermissions = ref<string[]>([])

const filteredRoles = computed(() => {
  const q = filter.value.trim().toLowerCase()
  if (!q) return roles.value
  return roles.value.filter(
    (role) =>
      role.name.toLowerCase().includes(q) ||
      role.code.toLowerCase().includes(q) ||
      role.permissions.some((p) => p.toLowerCase().includes(q)),
  )
})

const permissionGroups = computed(() => {
  const groups = new Map<string, PermissionRow[]>()
  for (const perm of allPermissions.value) {
    const key = perm.name.includes('.') ? perm.name.split('.')[0] : 'other'
    const list = groups.get(key) ?? []
    list.push(perm)
    groups.set(key, list)
  }
  return [...groups.entries()].sort(([a], [b]) => a.localeCompare(b))
})

function isProtectedRole(code: string): boolean {
  return code === 'super_admin' && auth.user?.role !== 'super_admin'
}

async function loadRoles() {
  const res = await api.get<{ data: RoleRow[] }>('/roles')
  roles.value = res.data
}

async function loadPermissions() {
  const res = await api.get<{ data: PermissionRow[] }>('/permissions')
  allPermissions.value = res.data
}

async function refresh() {
  loading.value = true
  error.value = ''
  try {
    await Promise.all([loadRoles(), loadPermissions()])
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load roles'
    roles.value = []
  } finally {
    loading.value = false
  }
}

function startEdit(role: RoleRow) {
  if (!canManage.value || isProtectedRole(role.code)) return
  editingId.value = role.id
  editName.value = role.name
  editPermissions.value = [...role.permissions]
  message.value = ''
  error.value = ''
}

function cancelEdit() {
  editingId.value = null
  editName.value = ''
  editPermissions.value = []
}

function togglePermission(name: string, checked: boolean) {
  if (checked) {
    if (!editPermissions.value.includes(name)) {
      editPermissions.value = [...editPermissions.value, name]
    }
    return
  }
  editPermissions.value = editPermissions.value.filter((p) => p !== name)
}

async function saveEdit() {
  if (!editingId.value || saving.value) return
  if (editPermissions.value.length === 0) {
    error.value = 'Select at least one permission.'
    return
  }

  saving.value = true
  error.value = ''
  message.value = ''
  try {
    const res = await api.patch<{ data: RoleRow }>(`/roles/${editingId.value}`, {
      name: editName.value.trim(),
      permissions: editPermissions.value,
    })
    const idx = roles.value.findIndex((r) => r.id === editingId.value)
    if (idx >= 0) {
      roles.value[idx] = res.data
    }
    message.value = `Saved ${res.data.name} permissions.`
    cancelEdit()
    if (auth.user?.role === res.data.code) {
      await auth.fetchMe()
    }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to save role'
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  if (canView.value) {
    void refresh()
  } else {
    loading.value = false
  }
})
</script>

<template>
  <section class="space-y-6">
    <div>
      <h2 class="text-xl font-semibold tracking-tight">Roles &amp; permissions</h2>
      <p class="mt-1 text-sm text-[var(--muted)]">
        Permissions are bundled into roles. Assign a role on
        <RouterLink to="/users" class="text-[var(--brand-ink)] hover:underline">Users</RouterLink>.
        <span v-if="canManage"> Edit a role below to change its permission set.</span>
        <span v-else> View-only — ask an org admin with <code class="text-xs">roles.manage</code>.</span>
        Running <code class="text-xs">php artisan db:seed</code> resets roles to the shipped matrix.
      </p>
    </div>

    <p v-if="loading" class="text-sm text-[var(--muted)]">Loading…</p>
    <p v-else-if="!canView" class="text-sm text-[var(--muted)]">
      You need the <code class="text-xs">roles.view</code> permission (Organization Admin).
    </p>
    <template v-else>
      <p v-if="error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
        {{ error }}
      </p>
      <p
        v-if="message"
        class="rounded-md border border-teal-200 bg-teal-50 px-3 py-2 text-sm text-teal-900"
      >
        {{ message }}
      </p>

      <label class="block max-w-md text-sm">
        <span class="text-[var(--muted)]">Filter roles or permissions</span>
        <input
          v-model="filter"
          type="search"
          placeholder="e.g. fulfilment, cashier"
          class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2"
        />
      </label>

      <div class="space-y-4">
        <article
          v-for="role in filteredRoles"
          :key="role.id"
          class="rounded-md border border-[var(--line)] bg-[var(--panel)] p-4"
        >
          <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h3 class="font-semibold">{{ role.name }}</h3>
              <code class="text-xs text-[var(--muted)]">{{ role.code }}</code>
              <p class="mt-1 text-xs text-[var(--muted)]">{{ role.permissions.length }} permissions</p>
            </div>
            <button
              v-if="canManage && editingId !== role.id && !isProtectedRole(role.code)"
              type="button"
              class="rounded-md border border-[var(--line)] px-3 py-1.5 text-sm hover:bg-stone-50"
              @click="startEdit(role)"
            >
              Edit
            </button>
            <span
              v-else-if="isProtectedRole(role.code)"
              class="text-xs text-[var(--muted)]"
            >
              Super Admin only
            </span>
          </header>

          <template v-if="editingId === role.id">
            <form class="mt-4 space-y-4 border-t border-[var(--line)] pt-4" @submit.prevent="saveEdit">
              <label class="block max-w-md text-sm">
                <span class="text-[var(--muted)]">Display name</span>
                <input
                  v-model="editName"
                  required
                  maxlength="255"
                  class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2"
                />
              </label>

              <div class="space-y-4">
                <p class="text-sm font-medium">Permissions</p>
                <div
                  v-for="[group, perms] in permissionGroups"
                  :key="group"
                  class="rounded-md border border-[var(--line)] p-3"
                >
                  <p class="mb-2 text-xs font-semibold tracking-wide text-[var(--muted)] uppercase">
                    {{ group }}
                  </p>
                  <ul class="grid gap-2 sm:grid-cols-2">
                    <li v-for="perm in perms" :key="perm.id">
                      <label class="flex items-start gap-2 text-sm">
                        <input
                          type="checkbox"
                          class="mt-0.5 rounded border-[var(--line)]"
                          :checked="editPermissions.includes(perm.name)"
                          @change="togglePermission(perm.name, ($event.target as HTMLInputElement).checked)"
                        />
                        <span>
                          <span class="font-mono text-xs">{{ perm.name }}</span>
                          <span class="ml-1 text-[var(--muted)]">({{ perm.scope }})</span>
                        </span>
                      </label>
                    </li>
                  </ul>
                </div>
              </div>

              <div class="flex flex-wrap gap-2">
                <button
                  type="submit"
                  class="rounded-md bg-[var(--brand)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-60"
                  :disabled="saving"
                >
                  {{ saving ? 'Saving…' : 'Save role' }}
                </button>
                <button
                  type="button"
                  class="rounded-md border border-[var(--line)] px-3 py-1.5 text-sm hover:bg-stone-50"
                  :disabled="saving"
                  @click="cancelEdit"
                >
                  Cancel
                </button>
              </div>
            </form>
          </template>

          <ul v-else class="mt-3 flex flex-wrap gap-1.5">
            <li
              v-for="perm in role.permissions"
              :key="perm"
              class="rounded border border-[var(--line)] bg-stone-50 px-2 py-0.5 font-mono text-xs"
              :class="
                perm.startsWith('fulfilment.')
                  ? 'border-teal-200 bg-teal-50 text-teal-900'
                  : ''
              "
            >
              {{ perm }}
            </li>
          </ul>
        </article>
        <p v-if="filteredRoles.length === 0" class="text-sm text-[var(--muted)]">No roles match.</p>
      </div>
    </template>
  </section>
</template>
