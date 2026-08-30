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

const auth = useAuthStore()
const canView = computed(() => userHasPermission(auth.user, 'roles.view'))

const roles = ref<RoleRow[]>([])
const loading = ref(true)
const error = ref('')
const filter = ref('')

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

async function loadRoles() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.get<{ data: RoleRow[] }>('/roles')
    roles.value = res.data
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load roles'
    roles.value = []
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  if (canView.value) {
    void loadRoles()
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
        <RouterLink to="/users" class="text-[var(--brand-ink)] hover:underline">Users</RouterLink>
        to grant access (for example <code class="text-xs">fulfilment.pick</code> for pack).
        Re-run <code class="text-xs">php artisan db:seed</code> after upgrades to sync the matrix.
      </p>
    </div>

    <p v-if="loading" class="text-sm text-[var(--muted)]">Loading…</p>
    <p v-else-if="!canView" class="text-sm text-[var(--muted)]">
      You need the <code class="text-xs">roles.view</code> permission (Organization Admin).
    </p>
    <template v-else>
      <p v-if="error" class="text-sm text-red-700">{{ error }}</p>

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
          <header class="flex flex-wrap items-baseline justify-between gap-2">
            <h3 class="font-semibold">{{ role.name }}</h3>
            <code class="text-xs text-[var(--muted)]">{{ role.code }}</code>
          </header>
          <p class="mt-1 text-xs text-[var(--muted)]">{{ role.permissions.length }} permissions</p>
          <ul class="mt-3 flex flex-wrap gap-1.5">
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
